<?php
declare(strict_types=1);

/**
 * POST /usuarios/delete-account
 * Auto-exclusão de conta (exigência da Play Store e da App Store).
 * O usuário AUTENTICADO exclui a própria conta e os dados pessoais associados.
 *  - Solo (único usuário da empresa): exclui conta + empresa + dados.
 *  - Caso contrário: reatribui os itens a um admin da empresa e exclui a conta.
 * Body: { "confirm": true }
 * Reaproveita a cascata do fluxo administrativo (usuarios/delete.php).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$pdo  = api_db();

$in = api_input();
if (empty($in['confirm'])) {
  api_error('E_INPUT', 'Confirmação obrigatória para excluir a conta.', 422);
}

$st = $pdo->prepare("SELECT id_user, id_company FROM usuarios WHERE id_user = ?");
$st->execute([$uid]);
$user = $st->fetch();
if (!$user) {
  api_error('E_NOT_FOUND', 'Conta não encontrada.', 404);
}
$userCid = (int)$user['id_company'];

$stCount = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_company = ?");
$stCount->execute([$userCid]);
$totalUsers = (int)$stCount->fetchColumn();
$scenario = ($totalUsers <= 1) ? 'solo' : 'reassign';

$pdo->beginTransaction();
try {
  if ($scenario === 'reassign') {
    // Reatribui os itens do usuário a um admin (ou, na falta, a qualquer outro
    // usuário) da mesma empresa, para não deixar registros órfãos.
    $stAdmin = $pdo->prepare("
      SELECT u.id_user FROM usuarios u
        LEFT JOIN rbac_user_role ur ON ur.user_id = u.id_user
        LEFT JOIN rbac_roles    r  ON r.role_id  = ur.role_id
       WHERE u.id_company = ? AND u.id_user != ?
       ORDER BY (r.role_key IN ('admin_master','user_admin')) DESC, u.id_user ASC
       LIMIT 1
    ");
    $stAdmin->execute([$userCid, $uid]);
    $reassignTo = (int)$stAdmin->fetchColumn();
    if ($reassignTo) {
      $pdo->prepare("UPDATE objetivos SET dono = ? WHERE dono = ?")->execute([$reassignTo, $uid]);
      $pdo->prepare("UPDATE key_results SET responsavel = ? WHERE responsavel = ?")->execute([$reassignTo, $uid]);
      $pdo->prepare("UPDATE iniciativas SET id_user_responsavel = ? WHERE id_user_responsavel = ?")->execute([$reassignTo, $uid]);
      $pdo->prepare("UPDATE iniciativas_envolvidos SET id_user = ? WHERE id_user = ?")->execute([$reassignTo, $uid]);
    }
  }

  // Remove dados pessoais e vínculos do usuário.
  $pdo->prepare("DELETE FROM rbac_user_role WHERE user_id = ?")->execute([$uid]);
  $pdo->prepare("DELETE FROM rbac_user_capability WHERE user_id = ?")->execute([$uid]);
  $pdo->prepare("DELETE FROM notificacoes WHERE id_user = ?")->execute([$uid]);
  // Tokens de push e resets — melhor esforço (tabelas podem não existir em todos os ambientes).
  try { $pdo->prepare("DELETE FROM push_devices WHERE id_user = ?")->execute([$uid]); } catch (\Throwable $e) {}
  try { $pdo->prepare("DELETE FROM usuarios_password_resets WHERE user_id = ?")->execute([$uid]); } catch (\Throwable $e) {}
  $pdo->prepare("DELETE FROM usuarios_credenciais WHERE id_user = ?")->execute([$uid]);
  $pdo->prepare("DELETE FROM usuarios WHERE id_user = ?")->execute([$uid]);

  if ($scenario === 'solo') {
    // Único usuário: remove a empresa e o estilo (dados OKR caem por cascata de FK).
    $pdo->prepare("DELETE FROM company_style WHERE id_company = ?")->execute([$userCid]);
    $pdo->prepare("DELETE FROM company WHERE id_company = ?")->execute([$userCid]);
  }

  $pdo->commit();
} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $e;
}

api_json(['ok' => true, 'scenario' => $scenario, 'message' => 'Conta excluída com sucesso.']);
