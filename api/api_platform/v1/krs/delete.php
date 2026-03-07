<?php
declare(strict_types=1);

/**
 * DELETE /krs/:id
 * Exclui KR com cascade completo.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$idKr = api_param('id');
$pdo  = api_db();

$st = $pdo->prepare("
  SELECT kr.id_kr, kr.id_objetivo, o.id_company
    FROM key_results kr JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
   WHERE kr.id_kr = ?
");
$st->execute([$idKr]);
$kr = $st->fetch();
if (!$kr || (int)$kr['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Key Result não encontrado.', 404);
}

if (!api_has_cap($pdo, $uid, $cid, 'W:kr@ORG', ['id_kr' => $idKr])) {
  api_error('E_FORBIDDEN', 'Sem permissão.', 403);
}

$idObj = $kr['id_objetivo'];

$pdo->beginTransaction();
try {
  // Cascade: orcamentos_detalhes → orcamentos → iniciativas_envolvidos → apontamentos_status_iniciativas → iniciativas
  $iniSt = $pdo->prepare("SELECT id_iniciativa FROM iniciativas WHERE id_kr = ?");
  $iniSt->execute([$idKr]);
  $iniIds = $iniSt->fetchAll(\PDO::FETCH_COLUMN);

  if (!empty($iniIds)) {
    $inIni = implode(',', array_fill(0, count($iniIds), '?'));
    $pdo->prepare("DELETE FROM orcamentos_detalhes WHERE id_orcamento IN (SELECT id_orcamento FROM orcamentos WHERE id_iniciativa IN ($inIni))")->execute($iniIds);
    $pdo->prepare("DELETE FROM orcamentos WHERE id_iniciativa IN ($inIni)")->execute($iniIds);
    $pdo->prepare("DELETE FROM apontamentos_status_iniciativas WHERE id_iniciativa IN ($inIni)")->execute($iniIds);
    $pdo->prepare("DELETE FROM iniciativas_envolvidos WHERE id_iniciativa IN ($inIni)")->execute($iniIds);
    $pdo->prepare("DELETE FROM iniciativas WHERE id_kr = ?")->execute([$idKr]);
  }

  $pdo->prepare("DELETE FROM apontamentos_kr WHERE id_kr = ?")->execute([$idKr]);
  $pdo->prepare("DELETE FROM milestones_kr WHERE id_kr = ?")->execute([$idKr]);
  $pdo->prepare("DELETE FROM key_results WHERE id_kr = ?")->execute([$idKr]);

  // Renumber remaining KRs
  $stRe = $pdo->prepare("SELECT id_kr FROM key_results WHERE id_objetivo = ? ORDER BY key_result_num");
  $stRe->execute([$idObj]);
  $remaining = $stRe->fetchAll(\PDO::FETCH_COLUMN);
  foreach ($remaining as $i => $rId) {
    $pdo->prepare("UPDATE key_results SET key_result_num = ? WHERE id_kr = ?")->execute([$i + 1, $rId]);
  }

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json(['ok' => true, 'id_kr' => $idKr, 'message' => 'Key Result excluído.']);
