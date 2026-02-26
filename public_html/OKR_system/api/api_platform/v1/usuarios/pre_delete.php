<?php
declare(strict_types=1);

/**
 * GET /usuarios/:id/pre-delete
 * Preview do cenário de exclusão (solo vs reassign).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_int(api_param('id'), 'id');
$pdo  = api_db();

if (!api_is_admin($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Sem permissão.', 403);
}

$st = $pdo->prepare("SELECT id_user, id_company, primeiro_nome, ultimo_nome FROM usuarios WHERE id_user = ?");
$st->execute([$id]);
$user = $st->fetch();
if (!$user) {
  api_error('E_NOT_FOUND', 'Usuário não encontrado.', 404);
}

$userCid = (int)$user['id_company'];

// Count items
$items = [];
$tables = [
  'objetivos'   => "SELECT COUNT(*) FROM objetivos WHERE dono = ?",
  'key_results' => "SELECT COUNT(*) FROM key_results WHERE responsavel = ?",
  'iniciativas' => "SELECT COUNT(*) FROM iniciativas WHERE id_user_responsavel = ?",
];
foreach ($tables as $key => $sql) {
  $stC = $pdo->prepare($sql);
  $stC->execute([$id]);
  $items[$key] = (int)$stC->fetchColumn();
}
$items['total'] = array_sum($items);

// Count company users
$stCount = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_company = ?");
$stCount->execute([$userCid]);
$totalUsers = (int)$stCount->fetchColumn();

$scenario = $totalUsers <= 1 ? 'solo' : 'reassign';

// Find admin to reassign
$adminReassign = null;
if ($scenario === 'reassign') {
  $stAdmin = $pdo->prepare("
    SELECT u.id_user, u.primeiro_nome, u.ultimo_nome FROM usuarios u
      JOIN rbac_user_role ur ON ur.user_id = u.id_user
      JOIN rbac_roles r ON r.role_id = ur.role_id
     WHERE u.id_company = ? AND r.role_key IN ('admin_master','user_admin') AND u.id_user != ?
     LIMIT 1
  ");
  $stAdmin->execute([$userCid, $id]);
  $admin = $stAdmin->fetch();
  if ($admin) {
    $adminReassign = [
      'id_user' => (int)$admin['id_user'],
      'nome'    => trim($admin['primeiro_nome'] . ' ' . ($admin['ultimo_nome'] ?? '')),
    ];
  }
}

api_json([
  'ok'             => true,
  'user'           => [
    'id_user' => (int)$user['id_user'],
    'nome'    => trim($user['primeiro_nome'] . ' ' . ($user['ultimo_nome'] ?? '')),
  ],
  'scenario'       => $scenario,
  'items'          => $items,
  'total_users'    => $totalUsers,
  'admin_reassign' => $adminReassign,
]);
