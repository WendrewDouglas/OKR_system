<?php
declare(strict_types=1);

/**
 * _middleware.php — RBAC para API (stateless, baseado em token JWT-lite)
 *
 * Adapta a lógica de auth/acl.php para funcionar sem $_SESSION.
 * Requer _core.php carregado antes.
 */

/* ===================== RBAC CORE ===================== */

function parse_cap_key_api(string $capKey): array {
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

function action_matches_api(string $granted, string $need): bool {
  if ($granted === 'M') return true;
  if ($granted === $need) return true;
  if ($granted === 'W' && $need === 'R') return true;
  return false;
}

function scope_covers_api(string $granted, string $need): bool {
  if ($granted === $need) return true;
  if ($granted === 'SYS') return true;
  return false;
}


/* ===================== ROLE CHECK ===================== */

function api_user_role_keys(PDO $pdo, int $userId): array {
  static $cache = [];
  if (isset($cache[$userId])) return $cache[$userId];

  $st = $pdo->prepare("
    SELECT r.role_key
      FROM rbac_user_role ur
      JOIN rbac_roles r ON r.role_id = ur.role_id AND r.is_active = 1
     WHERE ur.user_id = ?
  ");
  $st->execute([$userId]);
  $keys = $st->fetchAll(\PDO::FETCH_COLUMN);
  $cache[$userId] = $keys ?: [];
  return $cache[$userId];
}

function api_is_admin_master(PDO $pdo, int $userId): bool {
  return in_array('admin_master', api_user_role_keys($pdo, $userId), true);
}

function api_is_admin(PDO $pdo, int $userId): bool {
  $keys = api_user_role_keys($pdo, $userId);
  return in_array('admin_master', $keys, true) || in_array('user_admin', $keys, true);
}


/* ===================== TENANT RESOLVER ===================== */

function api_resolve_resource_company(PDO $pdo, string $resource, array $ctx): ?int {
  switch ($resource) {
    case 'objetivo': {
      $id = $ctx['id_objetivo'] ?? null;
      if ($id === null) return null;
      $st = $pdo->prepare("SELECT id_company FROM objetivos WHERE id_objetivo = ?");
      $st->execute([$id]);
      $v = $st->fetchColumn();
      return $v !== false ? (int)$v : null;
    }
    case 'kr': {
      $id = $ctx['id_kr'] ?? null;
      if ($id !== null) {
        $st = $pdo->prepare("
          SELECT o.id_company FROM key_results k
            JOIN objetivos o ON o.id_objetivo = k.id_objetivo
           WHERE k.id_kr = ?
        ");
        $st->execute([$id]);
        $v = $st->fetchColumn();
        return $v !== false ? (int)$v : null;
      }
      $idObj = $ctx['id_objetivo'] ?? null;
      if ($idObj !== null) {
        return api_resolve_resource_company($pdo, 'objetivo', ['id_objetivo' => $idObj]);
      }
      return null;
    }
    case 'iniciativa': {
      $id = $ctx['id_iniciativa'] ?? null;
      if ($id === null) return null;
      $st = $pdo->prepare("
        SELECT o.id_company FROM iniciativas i
          JOIN key_results k ON k.id_kr = i.id_kr
          JOIN objetivos o ON o.id_objetivo = k.id_objetivo
         WHERE i.id_iniciativa = ?
      ");
      $st->execute([$id]);
      $v = $st->fetchColumn();
      return $v !== false ? (int)$v : null;
    }
    case 'orcamento': {
      $id = $ctx['id_orcamento'] ?? null;
      if ($id === null) return null;
      $st = $pdo->prepare("
        SELECT o2.id_company FROM orcamentos b
          JOIN iniciativas i ON i.id_iniciativa = b.id_iniciativa
          JOIN key_results k ON k.id_kr = i.id_kr
          JOIN objetivos o2 ON o2.id_objetivo = k.id_objetivo
         WHERE b.id_orcamento = ?
      ");
      $st->execute([$id]);
      $v = $st->fetchColumn();
      return $v !== false ? (int)$v : null;
    }
    case 'apontamento': {
      $idKr = $ctx['id_kr'] ?? null;
      if ($idKr === null) return null;
      $st = $pdo->prepare("
        SELECT o.id_company FROM key_results k
          JOIN objetivos o ON o.id_objetivo = k.id_objetivo
         WHERE k.id_kr = ?
      ");
      $st->execute([$idKr]);
      $v = $st->fetchColumn();
      return $v !== false ? (int)$v : null;
    }
    default:
      return null;
  }
}


/* ===================== HAS_CAP ===================== */

/**
 * Verifica se o usuário tem a capability solicitada (stateless).
 */
function api_has_cap(PDO $pdo, int $userId, int $userCompany, string $capKey, array $ctx = []): bool {
  [$needAct, $needRes, $needScope] = parse_cap_key_api($capKey);
  if ($needAct === '' || $needRes === '' || $needScope === '') return false;

  // 1) Roles
  $stR = $pdo->prepare("
    SELECT r.role_id, r.role_key
      FROM rbac_user_role ur
      JOIN rbac_roles r ON r.role_id = ur.role_id AND r.is_active = 1
     WHERE ur.user_id = ?
  ");
  $stR->execute([$userId]);
  $roles = $stR->fetchAll();
  if (!$roles) return false;

  $roleIds  = array_column($roles, 'role_id');
  $roleKeys = array_column($roles, 'role_key');

  // 2) admin_master bypass
  if (in_array('admin_master', $roleKeys, true)) return true;

  // 3) Capabilities via role
  $in = implode(',', array_fill(0, count($roleIds), '?'));
  $stC = $pdo->prepare("
    SELECT c.cap_key, c.resource, c.action, c.scope
      FROM rbac_role_capability rc
      JOIN rbac_capabilities c ON c.capability_id = rc.capability_id
     WHERE rc.role_id IN ($in)
  ");
  $stC->execute($roleIds);
  $capsRole = $stC->fetchAll();

  // 4) User overrides
  $stU = $pdo->prepare("
    SELECT c.cap_key, c.resource, c.action, c.scope, uc.effect
      FROM rbac_user_capability uc
      JOIN rbac_capabilities c ON c.capability_id = uc.capability_id
     WHERE uc.user_id = ?
  ");
  $stU->execute([$userId]);
  $capsUser = $stU->fetchAll();

  // 5) Merge (DENY > ALLOW)
  $allow = [];
  foreach ($capsRole as $c) {
    $allow[$c['cap_key']] = $c;
  }
  $deny = [];
  foreach ($capsUser as $row) {
    $eff = strtoupper($row['effect'] ?? 'ALLOW');
    if ($eff === 'DENY')  $deny[$row['cap_key']] = true;
    if ($eff === 'ALLOW') $allow[$row['cap_key']] = $row;
  }

  // 6) Match
  $granted = null;
  foreach ($allow as $ck => $cap) {
    if (isset($deny[$ck])) continue;
    [$gAct, $gRes, $gScope] = parse_cap_key_api($cap['cap_key']);
    if ($gRes !== $needRes) continue;
    if (!action_matches_api($gAct, $needAct)) continue;
    if (!scope_covers_api($gScope, $needScope)) continue;
    $granted = $cap;
    break;
  }
  if (!$granted) return false;

  // 7) Tenant check
  $adminRes = ['relatorio','user','company','config_okrs','config_notify'];
  if ($needScope === 'ORG' && !in_array($needRes, $adminRes, true) && !empty($ctx)) {
    $resCompany = api_resolve_resource_company($pdo, $needRes, $ctx);
    if ($resCompany === null || $resCompany !== $userCompany) return false;
  }

  // 8) Collaborator check for apontamento
  if (in_array('user_colab', $roleKeys, true) && $needRes === 'apontamento' && $needAct === 'W') {
    $idKr = $ctx['id_kr'] ?? null;
    if (!$idKr) return false;
    $q = $pdo->prepare("SELECT 1 FROM okr_kr_envolvidos WHERE id_kr = ? AND id_user = ? LIMIT 1");
    $q->execute([$idKr, $userId]);
    if (!$q->fetchColumn()) return false;
  }

  return true;
}


/* ===================== REQUIRE_CAP ===================== */

/**
 * Exige capability; retorna 403 se não tiver.
 * Usa o token auth do request corrente.
 */
function api_require_cap(string $capKey, array $ctx = []): void {
  $auth = api_require_auth();
  $uid  = (int)($auth['sub'] ?? 0);
  $cid  = (int)($auth['cid'] ?? 0);
  $pdo  = api_db();

  if (!api_has_cap($pdo, $uid, $cid, $capKey, $ctx)) {
    api_error('E_FORBIDDEN', 'Sem permissão para esta ação.', 403);
  }
}

/**
 * Retorna [userId, companyId, payload] do token autenticado.
 * Helper para usar em endpoints que precisam dos dados mas já chamaram api_require_auth.
 */
function api_auth_context(): array {
  $auth = api_require_auth();
  return [
    'uid'     => (int)($auth['sub'] ?? 0),
    'cid'     => (int)($auth['cid'] ?? 0),
    'payload' => $auth,
  ];
}
