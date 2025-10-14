<?php
// auth/acl.php

/* ===================== CONFIG / DB ===================== */
if (!defined('DB_HOST')) {
  // ajuste o caminho caso seu projeto use outro arquivo de config
  require_once __DIR__ . '/../config/db.php';
}

function pdo_conn(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
  );
  return $pdo;
}

/* ===================== HELPERS BÁSICOS ===================== */
function parse_cap_key(string $capKey): array {
  // Aceita formatos defensivamente. Ex.: 'W:kr@ORG' → ['W','kr','ORG']
  $capKey = trim($capKey);
  $actRes = $capKey;
  $scope  = '';
  if (strpos($capKey, '@') !== false) {
    [$actRes, $scope] = explode('@', $capKey, 2);
  }
  $act = ''; $res = $actRes;
  if (strpos($actRes, ':') !== false) {
    [$act, $res] = explode(':', $actRes, 2);
  }
  return [strtoupper(trim($act)), strtolower(trim($res)), strtoupper(trim($scope))];
}

function action_matches(string $grantedAct, string $needAct): bool {
  // M ⇒ tudo; W ⇒ também cobre R
  if ($grantedAct === 'M') return true;
  if ($grantedAct === $needAct) return true;
  if ($grantedAct === 'W' && $needAct === 'R') return true;
  return false;
}

function scope_covers(string $grantedScope, string $needScope): bool {
  // Igual cobre; SYS cobre demais (ORG/UNIT/TEAM/OWN).
  if ($grantedScope === $needScope) return true;
  if ($grantedScope === 'SYS' && $needScope !== 'SYS') return true;
  return false;
}

function is_ajax_request(): bool {
  if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  return stripos($accept, 'application/json') !== false;
}

/**
 * Renderiza uma resposta padrão de acesso negado:
 * - AJAX/JSON: 403 + { success:false, error:'NO_PERMISSION', message:'...' }
 * - Página: HTML mínimo com modal e botões (Voltar / Dashboard)
 * Encerra a execução.
 */
function deny_with_modal(string $msg = 'Você não tem permissão para acessar o módulo selecionado. Em caso de dúvidas, acione o gestor de OKR.'): void {
  http_response_code(403);

  if (is_ajax_request()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'success' => false,
      'error'   => 'NO_PERMISSION',
      'message' => $msg
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $dashboard = '/OKR_system/dashboard';
  ?>
  <!DOCTYPE html>
  <html lang="pt-br">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acesso negado</title>
    <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
    <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
    <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
    <style>
      .overlay{ position:fixed; inset:0; display:grid; place-items:center; background:rgba(0,0,0,.55); z-index:9999; }
      .modal-card{ width:min(560px,94vw); background:#0b1020; color:#e6e9f2; border:1px solid #223047; border-radius:16px; padding:18px; box-shadow:0 20px 60px rgba(0,0,0,.35); }
      .cap-title{ font-weight:900; display:flex; gap:8px; align-items:center; margin:0 0 10px; }
      .modal-actions{ display:flex; gap:8px; justify-content:flex-end; margin-top:12px; }
      .btn{ border:1px solid #223047; background:#0e131a; color:#e5e7eb; padding:10px 14px; border-radius:12px; font-weight:800; cursor:pointer; }
      .btn:hover{ transform:translateY(-1px); transition:.15s; }
      .btn-primary{ background:#1f2937; text-decoration:none; display:inline-flex; align-items:center; gap:8px; }
      .btn-ghost{ background:#0c1118; text-decoration:none; display:inline-flex; align-items:center; gap:8px; }
    </style>
  </head>
  <body>
    <div class="overlay" role="dialog" aria-modal="true" aria-labelledby="naTitle">
      <div class="modal-card">
        <h3 id="naTitle" class="cap-title"><i class="fa-solid fa-lock-keyhole"></i> Acesso negado</h3>
        <p><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="modal-actions">
          <button class="btn btn-ghost" onclick="history.back()"><i class="fa-solid fa-arrow-left-long"></i> Voltar</button>
          <a class="btn btn-primary" href="<?= htmlspecialchars($dashboard, ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-gauge"></i> Ir para o Dashboard</a>
        </div>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

/* ===================== CORE RBAC ===================== */

/**
 * Retorna true/false sem interromper a execução.
 * $ctx aceita identificadores do recurso para checar tenant. Ex.:
 *   has_cap('W:apontamento@ORG', ['id_kr'=>$idKr])
 */
function has_cap(string $capKey, array $ctx = []): bool {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user_id'])) {
    // Não autentica aqui; páginas costumam redirecionar por conta própria.
    return false;
  }

  $userId      = (int)$_SESSION['user_id'];
  $userCompany = (int)($_SESSION['id_company'] ?? 0);
  [$needAct, $needRes, $needScope] = parse_cap_key($capKey);

  // Segurança: capKey malformado
  if ($needAct === '' || $needRes === '' || $needScope === '') return false;

  $pdo = pdo_conn();

  // 1) Roles do usuário (tabela correta: rbac_user_role)
  $rs = $pdo->prepare("
    SELECT r.role_id, r.role_key
      FROM rbac_user_role ur
      JOIN rbac_roles r ON r.role_id = ur.role_id AND r.is_active = 1
     WHERE ur.user_id = ?
  ");
  $rs->execute([$userId]);
  $roles = $rs->fetchAll();
  if (!$roles) return false;

  $roleIds  = array_column($roles, 'role_id');
  $roleKeys = array_column($roles, 'role_key');

  // 2) Bypass total para admin_master
  if (in_array('admin_master', $roleKeys, true)) return true;

  // 3) Capabilities por papel (assumimos ALLOW para todos os vínculos)
  $in = implode(',', array_fill(0, count($roleIds), '?'));
  $st = $pdo->prepare("
    SELECT c.cap_key, c.resource, c.action, c.scope
      FROM rbac_role_capability rc
      JOIN rbac_capabilities c ON c.capability_id = rc.capability_id
     WHERE rc.role_id IN ($in)
  ");
  $st->execute($roleIds);
  $capsRole = $st->fetchAll();

  // 4) Overrides do usuário (ALLOW/DENY)
  $st2 = $pdo->prepare("
    SELECT c.cap_key, c.resource, c.action, c.scope, uc.effect
      FROM rbac_user_capability uc
      JOIN rbac_capabilities c ON c.capability_id = uc.capability_id
     WHERE uc.user_id = ?
  ");
  $st2->execute([$userId]);
  $capsUser = $st2->fetchAll();

  // 5) Mescla (DENY > ALLOW)
  $allow = [];
  foreach ($capsRole as $c) {
    $allow[$c['cap_key']] = $c; // base allow
  }
  $deny = [];
  foreach ($capsUser as $row) {
    $eff = strtoupper($row['effect'] ?? 'ALLOW');
    if ($eff === 'DENY') $deny[$row['cap_key']] = true;
    if ($eff === 'ALLOW') $allow[$row['cap_key']] = $row; // reforça allow
  }

  // 6) Procura uma capability que cubra ação+recurso+escopo
  $granted = null;
  foreach ($allow as $capKeyGranted => $cap) {
    if (isset($deny[$capKeyGranted])) continue; // negado explicitamente
    [$gAct, $gRes, $gScope] = parse_cap_key($cap['cap_key']);
    if ($gRes !== $needRes) continue;
    if (!action_matches($gAct, $needAct)) continue;
    if (!scope_covers($gScope, $needScope)) continue;
    $granted = $cap; break;
  }
  if (!$granted) return false;

  // 7) Checagem de tenant (same-company) para recursos de dados em @ORG
  $adminRes = ['relatorio','user','company','config_okrs','config_notify'];
  $mustCheckTenant = ($needScope === 'ORG' && !in_array($needRes, $adminRes, true));

  if ($mustCheckTenant) {
    $resCompany = resolve_resource_company($pdo, $needRes, $ctx);
    if ($resCompany === null || (int)$resCompany !== $userCompany) return false;
  }

  // 8) Regra especial do colaborador: só pode W:apontamento@ORG se estiver envolvido no KR
  if (in_array('user_colab', $roleKeys, true) && $needRes === 'apontamento' && $needAct === 'W') {
    $idKr = $ctx['id_kr'] ?? null;
    if (!$idKr) return false; // precisa informar o KR
    $q = $pdo->prepare("SELECT 1 FROM okr_kr_envolvidos WHERE id_kr = ? AND id_user = ? LIMIT 1");
    $q->execute([$idKr, $userId]);
    if (!$q->fetchColumn()) return false;
  }

  return true;
}

/**
 * Enforça a capability: se não tiver, abre modal (ou JSON 403).
 * Use nas páginas diretamente ou indiretamente via gate_page_by_path().
 */
function require_cap(string $capKey, array $ctx = []): void {
  if (has_cap($capKey, $ctx)) return;
  deny_with_modal();
}

/* ===================== GATE POR CAMINHO ===================== */

/**
 * Lê dom_paginas.requires_cap para o path informado e aplica require_cap().
 * Use no topo dos views: gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');
 */
function gate_page_by_path(string $path): void {
  // Se não houver sessão aberta ainda, não força aqui; a página pode redirecionar para login.
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user_id'])) return;

  $pdo = pdo_conn();
  $st  = $pdo->prepare("SELECT requires_cap FROM dom_paginas WHERE path = ? LIMIT 1");
  $st->execute([$path]);
  $req = $st->fetchColumn();
  if ($req) require_cap((string)$req);
}

/* ===================== TENANT RESOLVER ===================== */
/**
 * Descobre o id_company associado ao recurso (para checagem de tenant).
 * $ctx deve conter o identificador do recurso tocado.
 */
function resolve_resource_company(PDO $pdo, string $resource, array $ctx): ?int {
  switch ($resource) {
    case 'objetivo': {
      $id = $ctx['id_objetivo'] ?? null; if ($id === null) return null;
      $sql = "SELECT u.id_company
                FROM objetivos o
                JOIN usuarios u ON u.id_user = o.dono
               WHERE o.id_objetivo = ?";
      $st = $pdo->prepare($sql);
      $st->execute([$id]);
      $val = $st->fetchColumn();
      return $val !== false ? (int)$val : null;
    }
    case 'kr': {
      $id = $ctx['id_kr'] ?? null; if ($id === null) return null;
      $sql = "SELECT u.id_company
                FROM key_results k
                JOIN objetivos o ON o.id_objetivo = k.id_objetivo
                JOIN usuarios  u ON u.id_user = o.dono
               WHERE k.id_kr = ?";
      $st = $pdo->prepare($sql);
      $st->execute([$id]);
      $val = $st->fetchColumn();
      return $val !== false ? (int)$val : null;
    }
    case 'milestone': {
      $id = $ctx['id_ms'] ?? null; if ($id === null) return null;
      $sql = "SELECT u.id_company
                FROM milestones_kr m
                JOIN key_results k ON k.id_kr = m.id_kr
                JOIN objetivos  o ON o.id_objetivo = k.id_objetivo
                JOIN usuarios   u ON u.id_user = o.dono
               WHERE m.id_ms = ?";
      $st = $pdo->prepare($sql);
      $st->execute([$id]);
      $val = $st->fetchColumn();
      return $val !== false ? (int)$val : null;
    }
    case 'iniciativa': {
      $id = $ctx['id_iniciativa'] ?? null; if ($id === null) return null;
      $sql = "SELECT u.id_company
                FROM iniciativas i
                JOIN key_results k ON k.id_kr = i.id_kr
                JOIN objetivos  o ON o.id_objetivo = k.id_objetivo
                JOIN usuarios   u ON u.id_user = o.dono
               WHERE i.id_iniciativa = ?";
      $st = $pdo->prepare($sql);
      $st->execute([$id]);
      $val = $st->fetchColumn();
      return $val !== false ? (int)$val : null;
    }
    case 'orcamento': {
      $id = $ctx['id_orcamento'] ?? null; if ($id === null) return null;
      $sql = "SELECT u.id_company
                FROM orcamentos b
                JOIN iniciativas i ON i.id_iniciativa = b.id_iniciativa
                JOIN key_results k ON k.id_kr        = i.id_kr
                JOIN objetivos  o  ON o.id_objetivo  = k.id_objetivo
                JOIN usuarios   u  ON u.id_user      = o.dono
               WHERE b.id_orcamento = ?";
      $st = $pdo->prepare($sql);
      $st->execute([$id]);
      $val = $st->fetchColumn();
      return $val !== false ? (int)$val : null;
    }
    case 'apontamento': {
      // precisa do id_kr no contexto
      $idKr = $ctx['id_kr'] ?? null; if ($idKr === null) return null;
      $sql = "SELECT u.id_company
                FROM key_results k
                JOIN objetivos o ON o.id_objetivo = k.id_objetivo
                JOIN usuarios u ON u.id_user     = o.dono
               WHERE k.id_kr = ?";
      $st = $pdo->prepare($sql);
      $st->execute([$idKr]);
      $val = $st->fetchColumn();
      return $val !== false ? (int)$val : null;
    }
    case 'aprovacao': {
      // usa o identificador disponível no contexto para derivar o tenant
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
      return (int)($_SESSION['id_company'] ?? 0);
    default:
      return null;
  }
}

/* ===================== MENU / UTIL ===================== */
/**
 * Retorna se o usuário atual poderia abrir a rota informada
 * (sem interromper execução, útil para esconder itens de menu).
 */
function can_open_path(string $path): bool {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user_id'])) return false;

  $pdo = pdo_conn();
  $st  = $pdo->prepare("SELECT requires_cap FROM dom_paginas WHERE path = ? LIMIT 1");
  $st->execute([$path]);
  $req = $st->fetchColumn();
  if (!$req) return true; // sem requisito → liberado

  return has_cap((string)$req);
}
