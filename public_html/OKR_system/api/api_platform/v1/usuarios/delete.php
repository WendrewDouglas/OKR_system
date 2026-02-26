<?php
declare(strict_types=1);

/**
 * DELETE /usuarios/:id
 * Smart delete: solo scenario (cascade company) or reassign (transfer items to admin).
 * Body optional: { scenario: "reassign", reassign_to: userId }
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_int(api_param('id'), 'id');
$pdo  = api_db();

if (!api_is_admin($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Apenas administradores podem excluir usuários.', 403);
}
if ($id === $uid) {
  api_error('E_INPUT', 'Não é possível excluir a si mesmo.', 422);
}

$in = api_input();

// Check user exists and belongs to same company
$st = $pdo->prepare("SELECT id_user, id_company, primeiro_nome FROM usuarios WHERE id_user = ?");
$st->execute([$id]);
$user = $st->fetch();
if (!$user) {
  api_error('E_NOT_FOUND', 'Usuário não encontrado.', 404);
}

$isMaster = api_is_admin_master($pdo, $uid);
if (!$isMaster && (int)$user['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Usuário não encontrado.', 404);
}

$userCid = (int)$user['id_company'];

// Count company users
$stCount = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_company = ?");
$stCount->execute([$userCid]);
$totalUsers = (int)$stCount->fetchColumn();

$scenario = api_str($in['scenario'] ?? ($totalUsers <= 1 ? 'solo' : 'reassign'));

$pdo->beginTransaction();
try {
  if ($scenario === 'reassign') {
    $reassignTo = api_int_or_null($in['reassign_to'] ?? null);
    if (!$reassignTo) {
      // Find company admin
      $stAdmin = $pdo->prepare("
        SELECT u.id_user FROM usuarios u
          JOIN rbac_user_role ur ON ur.user_id = u.id_user
          JOIN rbac_roles r ON r.role_id = ur.role_id
         WHERE u.id_company = ? AND r.role_key IN ('admin_master','user_admin') AND u.id_user != ?
         LIMIT 1
      ");
      $stAdmin->execute([$userCid, $id]);
      $reassignTo = (int)$stAdmin->fetchColumn();
    }
    if (!$reassignTo) {
      api_error('E_INPUT', 'Não há usuário admin para reatribuir. Use cenário solo.', 422);
    }

    // Reassign items
    $pdo->prepare("UPDATE objetivos SET dono = ? WHERE dono = ?")->execute([$reassignTo, $id]);
    $pdo->prepare("UPDATE key_results SET responsavel = ? WHERE responsavel = ?")->execute([$reassignTo, $id]);
    $pdo->prepare("UPDATE iniciativas SET id_user_responsavel = ? WHERE id_user_responsavel = ?")->execute([$reassignTo, $id]);
    $pdo->prepare("UPDATE iniciativas_envolvidos SET id_user = ? WHERE id_user = ?")->execute([$reassignTo, $id]);
  }

  // Cleanup RBAC and related
  $pdo->prepare("DELETE FROM rbac_user_role WHERE user_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM rbac_user_capability WHERE user_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM notificacoes WHERE id_user = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM usuarios_credenciais WHERE id_user = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM usuarios WHERE id_user = ?")->execute([$id]);

  if ($scenario === 'solo') {
    // Delete company
    $pdo->prepare("DELETE FROM company_style WHERE id_company = ?")->execute([$userCid]);
    $pdo->prepare("DELETE FROM company WHERE id_company = ?")->execute([$userCid]);
  }

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json(['ok' => true, 'scenario' => $scenario, 'message' => 'Usuário excluído.']);
