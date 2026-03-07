<?php
declare(strict_types=1);

/**
 * DELETE /apontamentos/:id
 * Exclui um apontamento e resincroniza o milestone.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$idAp = api_param('id');
$pdo  = api_db();

// Get apontamento
$st = $pdo->prepare("SELECT id_apontamento, id_kr, id_milestone FROM apontamentos_kr WHERE id_apontamento = ?");
$st->execute([$idAp]);
$ap = $st->fetch();
if (!$ap) {
  api_error('E_NOT_FOUND', 'Apontamento não encontrado.', 404);
}

$idKr = $ap['id_kr'];
$idMs = $ap['id_milestone'];

// RBAC
if (!api_has_cap($pdo, $uid, $cid, 'W:apontamento@ORG', ['id_kr' => $idKr])) {
  api_error('E_FORBIDDEN', 'Sem permissão.', 403);
}

$pdo->beginTransaction();
try {
  $pdo->prepare("DELETE FROM apontamentos_kr WHERE id_apontamento = ?")->execute([$idAp]);

  // Resync milestone: use latest remaining apontamento
  if ($idMs) {
    $stLast = $pdo->prepare("
      SELECT valor_real FROM apontamentos_kr
       WHERE id_kr = ? AND id_milestone = ?
       ORDER BY dt_apontamento DESC LIMIT 1
    ");
    $stLast->execute([$idKr, $idMs]);
    $lastVal = $stLast->fetchColumn();

    if ($lastVal !== false) {
      $pdo->prepare("
        UPDATE milestones_kr SET valor_real_consolidado = ?, qtde_apontamentos = GREATEST(qtde_apontamentos - 1, 0)
         WHERE id_milestone = ?
      ")->execute([$lastVal, $idMs]);
    } else {
      $pdo->prepare("
        UPDATE milestones_kr SET valor_real_consolidado = NULL, dt_ultimo_apontamento = NULL, qtde_apontamentos = 0
         WHERE id_milestone = ?
      ")->execute([$idMs]);
    }
  }

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json(['ok' => true, 'message' => 'Apontamento excluído.']);
