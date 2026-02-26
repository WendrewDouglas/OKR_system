<?php
declare(strict_types=1);

/**
 * DELETE /objetivos/:id
 * Exclui objetivo com cascade (KRs → Iniciativas → Orçamentos → Apontamentos).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_param('id');
$pdo  = api_db();

$st = $pdo->prepare("SELECT id_objetivo, descricao, id_company FROM objetivos WHERE id_objetivo = ?");
$st->execute([$id]);
$obj = $st->fetch();
if (!$obj || (int)$obj['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Objetivo não encontrado.', 404);
}

if (!api_has_cap($pdo, $uid, $cid, 'W:objetivo@ORG', ['id_objetivo' => $id])) {
  api_error('E_FORBIDDEN', 'Sem permissão para excluir este objetivo.', 403);
}

$pdo->beginTransaction();
try {
  // Get KRs
  $krs = $pdo->prepare("SELECT id_kr FROM key_results WHERE id_objetivo = ?");
  $krs->execute([$id]);
  $krIds = $krs->fetchAll(\PDO::FETCH_COLUMN);

  foreach ($krIds as $krId) {
    // Get initiatives for budget cleanup
    $iniSt = $pdo->prepare("SELECT id_iniciativa FROM iniciativas WHERE id_kr = ?");
    $iniSt->execute([$krId]);
    $iniIds = $iniSt->fetchAll(\PDO::FETCH_COLUMN);

    if (!empty($iniIds)) {
      $inIni = implode(',', array_fill(0, count($iniIds), '?'));
      $pdo->prepare("DELETE FROM orcamentos_detalhes WHERE id_orcamento IN (SELECT id_orcamento FROM orcamentos WHERE id_iniciativa IN ($inIni))")->execute($iniIds);
      $pdo->prepare("DELETE FROM orcamentos WHERE id_iniciativa IN ($inIni)")->execute($iniIds);
      $pdo->prepare("DELETE FROM apontamentos_status_iniciativas WHERE id_iniciativa IN ($inIni)")->execute($iniIds);
      $pdo->prepare("DELETE FROM iniciativas_envolvidos WHERE id_iniciativa IN ($inIni)")->execute($iniIds);
      $pdo->prepare("DELETE FROM iniciativas WHERE id_iniciativa IN ($inIni)")->execute($iniIds);
    }

    $pdo->prepare("DELETE FROM apontamentos_kr WHERE id_kr = ?")->execute([$krId]);
    $pdo->prepare("DELETE FROM milestones_kr WHERE id_kr = ?")->execute([$krId]);
    $pdo->prepare("DELETE FROM key_results WHERE id_kr = ?")->execute([$krId]);
  }

  $pdo->prepare("DELETE FROM objetivos WHERE id_objetivo = ?")->execute([$id]);

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json([
  'ok'           => true,
  'id_objetivo'  => $id,
  'descricao'    => $obj['descricao'],
  'krs_excluidos' => $krIds,
  'message'      => 'Objetivo excluído com sucesso.',
]);
