<?php
declare(strict_types=1);

/**
 * GET /usuarios/:id/permissions
 * Retorna capabilities e overrides do usuário.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$pdo  = api_db();
$id   = api_int(api_param('id'), 'id');

if (!api_is_admin($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Sem permissão.', 403);
}

// Role capabilities
$stRole = $pdo->prepare("
  SELECT c.capability_id, c.cap_key, c.resource, c.action, c.scope, 'ROLE' AS source
    FROM rbac_role_capability rc
    JOIN rbac_capabilities c ON c.capability_id = rc.capability_id
    JOIN rbac_user_role ur ON ur.role_id = rc.role_id
   WHERE ur.user_id = ?
");
$stRole->execute([$id]);
$roleCaps = $stRole->fetchAll();

// User overrides
$stUser = $pdo->prepare("
  SELECT c.capability_id, c.cap_key, c.resource, c.action, c.scope,
         uc.effect
    FROM rbac_user_capability uc
    JOIN rbac_capabilities c ON c.capability_id = uc.capability_id
   WHERE uc.user_id = ?
");
$stUser->execute([$id]);
$userCaps = $stUser->fetchAll();

// All available capabilities
$allCaps = $pdo->query("SELECT capability_id, cap_key, resource, action, scope FROM rbac_capabilities ORDER BY resource, action, scope")->fetchAll();

api_json([
  'ok'              => true,
  'role_caps'       => array_map(fn($r) => [
    'capability_id' => (int)$r['capability_id'],
    'cap_key'       => $r['cap_key'],
    'resource'      => $r['resource'],
    'action'        => $r['action'],
    'scope'         => $r['scope'],
  ], $roleCaps),
  'user_overrides'  => array_map(fn($r) => [
    'capability_id' => (int)$r['capability_id'],
    'cap_key'       => $r['cap_key'],
    'effect'        => $r['effect'],
  ], $userCaps),
  'all_capabilities' => array_map(fn($r) => [
    'capability_id' => (int)$r['capability_id'],
    'cap_key'       => $r['cap_key'],
    'resource'      => $r['resource'],
    'action'        => $r['action'],
    'scope'         => $r['scope'],
  ], $allCaps),
]);
