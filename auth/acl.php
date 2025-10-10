<?php
// auth/acl.php

// 0) Carregar config/DB (ajuste o caminho ao seu projeto)
if (!defined('DB_HOST')) {
  require_once __DIR__ . '/../config/db.php'; // <<< ajuste se necessário
}

// 1) Conexão PDO
function pdo_conn(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
  );
  return $pdo;
}

// 2) Utilidades
function parse_cap_key(string $capKey): array {
  // 'W:kr@ORG' => ['W','kr','ORG']
  [$actRes, $scope] = explode('@', $capKey, 2);
  [$act, $res] = explode(':', $actRes, 2);
  return [strtoupper(trim($act)), strtolower(trim($res)), strtoupper(trim($scope))];
}

// W implica R; M implica tudo daquele recurso (inclui R/W/A)
function action_matches(string $grantedAct, string $needAct): bool {
  if ($grantedAct === $needAct) return true;
  if ($grantedAct === 'M') return true;
  if ($grantedAct === 'W' && $needAct === 'R') return true;
  return false;
}

// 3) Gate de página por dom_paginas.requires_cap (use no topo das páginas)
function gate_page_by_path(string $path): void {
  $pdo = pdo_conn();
  $st  = $pdo->prepare("SELECT requires_cap FROM dom_paginas WHERE path=? LIMIT 1");
  $st->execute([$path]);
  $req = $st->fetchColumn();
  if ($req) require_cap($req);
}

// 4) Checagem principal
/**
 * Ex.: require_cap('W:kr@ORG', ['id_objetivo'=>123]);
 * ctx: passe identificadores do recurso (id_objetivo, id_kr, id_iniciativa, id_orcamento, id_ms)
 */
function require_cap(string $capKey, array $ctx = []): void {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user_id'])) {
    http_response_code(401); exit('Não autorizado');
  }
  $userId      = (int)$_SESSION['user_id'];
  $userCompany = (int)($_SESSION['id_company'] ?? 0);
  [$needAct, $needRes, $needScope] = parse_cap_key($capKey);

  $pdo = pdo_conn();

  // 4.1) Roles ativos do usuário
  $rs = $pdo->prepare("
    SELECT r.role_id, r.role_key
    FROM rbac_user_roles ur
    JOIN rbac_roles r ON r.role_id=ur.role_id AND r.is_active=1
    WHERE ur.user_id=? AND (ur.valid_from IS NULL OR ur.valid_from<=NOW())
      AND (ur.valid_to   IS NULL OR ur.valid_to  >=NOW())
  ");
  $rs->execute([$userId]);
  $roles = $rs->fetchAll();
  if (!$roles) { http_response_code(403); exit('Sem perfil'); }
  $roleIds  = array_column($roles, 'role_id');
  $roleKeys = array_column($roles, 'role_key');

  // 4.2) Bypass total para admin_master
  if (in_array('admin_master', $roleKeys, true)) return;

  // 4.3) Capabilities por role (ALLOW)
  $in = implode(',', array_fill(0, count($roleIds), '?'));
  $st = $pdo->prepare("
    SELECT c.cap_key, c.resource, c.action, c.scope, c.conditions_json
    FROM rbac_role_capability rc
    JOIN rbac_capabilities c ON c.capability_id=rc.capability_id
    WHERE rc.effect='ALLOW' AND rc.role_id IN ($in)
  ");
  $st->execute($roleIds);
  $caps = $st->fetchAll();

  // 4.4) Overrides por usuário (ALLOW/DENY)
  $st2 = $pdo->prepare("
    SELECT c.cap_key, c.resource, c.action, c.scope, c.conditions_json, uc.effect
    FROM rbac_user_capability uc
    JOIN rbac_capabilities c ON c.capability_id=uc.capability_id
    WHERE uc.user_id=?
  ");
  $st2->execute([$userId]);
  $userCaps = $st2->fetchAll();

  // 4.5) Mesclar (DENY > ALLOW)
  $effective = [];
  foreach (array_merge($caps, $userCaps) as $row) {
    $key = $row['cap_key'];
    $eff = $row['effect'] ?? 'ALLOW';
    if (!isset($effective[$key]) || $eff === 'DENY') $effective[$key] = $row + ['eff' => $eff];
  }

  // 4.6) Procurar uma capability que atenda ação+recurso+escopo
  $granted = null;
  foreach ($effective as $cap) {
    [$act,$res,$scope] = parse_cap_key($cap['cap_key']);
    if ($res !== $needRes) continue;
    if (!action_matches($act, $needAct)) continue;
    // escopo deve bater exatamente; (se você criar @SYS no futuro, aqui pode aceitar SYS como superset)
    if ($scope !== $needScope) continue;
    $granted = $cap; break;
  }
  if (!$granted) { http_response_code(403); exit('Acesso negado: capability'); }

  // 4.7) same_company (se houver condition ou se o escopo for ORG em recursos de dados)
  $mustCheckTenant = false;
  if (!empty($granted['conditions_json'])) {
    $cond = json_decode($granted['conditions_json'], true);
    if (($cond['type'] ?? null) === 'same_company') $mustCheckTenant = true;
  } else {
    // segurança extra para @ORG em recursos de dados
    if ($needScope === 'ORG' && !in_array($needRes, ['relatorio','user','company','config_okrs','config_notify'], true)) {
      $mustCheckTenant = true;
    }
  }

  if ($mustCheckTenant) {
    $resCompany = resolve_resource_company($pdo, $needRes, $ctx);
    if (!$resCompany || (int)$resCompany !== $userCompany) {
      http_response_code(403); exit('Acesso negado: tenant');
    }
  }

  // 4.8) Regra do colaborador: só pode W:apontamento@ORG se estiver envolvido no KR
  if (in_array('user_colab', $roleKeys, true) && $needRes === 'apontamento' && $needAct === 'W') {
    // id_kr pode ser string (sua tabela okr_kr_envolvidos usa VARCHAR(50))
    $idKr = $ctx['id_kr'] ?? null;
    if (!$idKr) { http_response_code(400); exit('id_kr obrigatório para apontamento'); }
    $q = $pdo->prepare("SELECT 1 FROM okr_kr_envolvidos WHERE id_kr=? AND id_user=? LIMIT 1");
    $q->execute([$idKr, $userId]);
    if (!$q->fetchColumn()) { http_response_code(403); exit('Acesso negado: não envolvido no KR'); }
  }
}

// 5) Descobrir o id_company do recurso tocado (para same_company)
function resolve_resource_company(PDO $pdo, string $resource, array $ctx): ?int {
  switch ($resource) {
    case 'objetivo': {
      $id = $ctx['id_objetivo'] ?? null; if ($id===null) return null;
      $sql="SELECT u.id_company
            FROM objetivos o
            JOIN usuarios u ON u.id_user=o.dono
            WHERE o.id_objetivo=?";
      $st=$pdo->prepare($sql); $st->execute([$id]);
      return ($st->fetchColumn() !== false) ? (int)$st->fetchColumn() : null;
    }
    case 'kr': {
      $id = $ctx['id_kr'] ?? null; if ($id===null) return null;
      $sql="SELECT u.id_company
            FROM key_results k
            JOIN objetivos o ON o.id_objetivo=k.id_objetivo
            JOIN usuarios u ON u.id_user=o.dono
            WHERE k.id_kr=?";
      $st=$pdo->prepare($sql); $st->execute([$id]);
      $val=$st->fetchColumn();
      return $val!==false ? (int)$val : null;
    }
    case 'milestone': {
      $id = $ctx['id_ms'] ?? null; if ($id===null) return null;
      $sql="SELECT u.id_company
            FROM milestones_kr m
            JOIN key_results k ON k.id_kr=m.id_kr
            JOIN objetivos o ON o.id_objetivo=k.id_objetivo
            JOIN usuarios  u ON u.id_user=o.dono
            WHERE m.id_ms=?";
      $st=$pdo->prepare($sql); $st->execute([$id]);
      $val=$st->fetchColumn();
      return $val!==false ? (int)$val : null;
    }
    case 'iniciativa': {
      $id = $ctx['id_iniciativa'] ?? null; if ($id===null) return null;
      $sql="SELECT u.id_company
            FROM iniciativas i
            JOIN key_results k ON k.id_kr=i.id_kr
            JOIN objetivos  o ON o.id_objetivo=k.id_objetivo
            JOIN usuarios   u ON u.id_user=o.dono
            WHERE i.id_iniciativa=?";
      $st=$pdo->prepare($sql); $st->execute([$id]);
      $val=$st->fetchColumn();
      return $val!==false ? (int)$val : null;
    }
    case 'orcamento': {
      $id = $ctx['id_orcamento'] ?? null; if ($id===null) return null;
      $sql="SELECT u.id_company
            FROM orcamentos b
            JOIN iniciativas i ON i.id_iniciativa=b.id_iniciativa
            JOIN key_results k ON k.id_kr=i.id_kr
            JOIN objetivos  o ON o.id_objetivo=k.id_objetivo
            JOIN usuarios   u ON u.id_user=o.dono
            WHERE b.id_orcamento=?";
      $st=$pdo->prepare($sql); $st->execute([$id]);
      $val=$st->fetchColumn();
      return $val!==false ? (int)$val : null;
    }
    case 'apontamento': {
      // apontamento depende de KR → passe id_kr no $ctx
      $idKr = $ctx['id_kr'] ?? null; if ($idKr===null) return null;
      $sql="SELECT u.id_company
            FROM key_results k
            JOIN objetivos o ON o.id_objetivo=k.id_objetivo
            JOIN usuarios u ON u.id_user=o.dono
            WHERE k.id_kr=?";
      $st=$pdo->prepare($sql); $st->execute([$idKr]);
      $val=$st->fetchColumn();
      return $val!==false ? (int)$val : null;
    }
    case 'approval': {
      // use o identificador disponível
      foreach (['id_orcamento','id_iniciativa','id_kr','id_objetivo'] as $k) {
        if (array_key_exists($k, $ctx)) {
          $map = [
            'id_orcamento'  => 'orcamento',
            'id_iniciativa' => 'iniciativa',
            'id_kr'         => 'kr',
            'id_objetivo'   => 'objetivo'
          ];
          return resolve_resource_company($pdo, $map[$k], [$k => $ctx[$k]]);
        }
      }
      return null;
    }
    case 'relatorio':
    case 'user':
    case 'company':
    case 'config_okrs':
    case 'config_notify':
      // páginas administrativas: use a própria sessão
      return (int)($_SESSION['id_company'] ?? 0);
    default:
      return null;
  }
}

// 6) Helper para o menu (esconder item se não puder abrir)
function can_open_path(string $path): bool {
  try {
    gate_page_by_path($path);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}
