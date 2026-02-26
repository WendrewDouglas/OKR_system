<?php
declare(strict_types=1);

/**
 * PUT /usuarios/:id/role
 * Atribuir role a um usuário.
 * Body: { role_key: "user_colab" }
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_int(api_param('id'), 'id');
$pdo  = api_db();

if (!api_is_admin($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Apenas administradores podem alterar roles.', 403);
}

$in = api_input();
api_require_fields($in, ['role_key']);
$roleKey = api_str($in['role_key']);

// Verify role exists
$stR = $pdo->prepare("SELECT role_id FROM rbac_roles WHERE role_key = ? AND is_active = 1");
$stR->execute([$roleKey]);
$roleId = $stR->fetchColumn();
if (!$roleId) {
  api_error('E_NOT_FOUND', "Role '$roleKey' não encontrada.", 404);
}

// Only admin_master can assign admin_master
if ($roleKey === 'admin_master' && !api_is_admin_master($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Apenas admin_master pode atribuir este role.', 403);
}

// Replace role (UNIQUE constraint: one role per user)
$pdo->beginTransaction();
try {
  $pdo->prepare("DELETE FROM rbac_user_role WHERE user_id = ?")->execute([$id]);
  $pdo->prepare("INSERT INTO rbac_user_role (user_id, role_id, valid_from) VALUES (?, ?, NOW())")->execute([$id, $roleId]);
  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json(['ok' => true, 'message' => "Role '$roleKey' atribuída."]);
