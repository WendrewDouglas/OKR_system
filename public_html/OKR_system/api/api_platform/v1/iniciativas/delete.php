<?php
declare(strict_types=1);

/**
 * DELETE /iniciativas/:id
 * Exclui uma iniciativa com cascade (orçamentos, envolvidos, apontamentos).
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_param('id');
$pdo  = api_db();

$st = $pdo->prepare("
  SELECT i.id_iniciativa, o.id_company
    FROM iniciativas i
    JOIN key_results kr ON kr.id_kr = i.id_kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
   WHERE i.id_iniciativa = ?
");
$st->execute([$id]);
$ini = $st->fetch();
if (!$ini || (int)$ini['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Iniciativa não encontrada.', 404);
}

$pdo->beginTransaction();
try {
  $pdo->prepare("DELETE FROM orcamentos_detalhes WHERE id_orcamento IN (SELECT id_orcamento FROM orcamentos WHERE id_iniciativa = ?)")->execute([$id]);
  $pdo->prepare("DELETE FROM orcamentos WHERE id_iniciativa = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM apontamentos_status_iniciativas WHERE id_iniciativa = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM iniciativas_envolvidos WHERE id_iniciativa = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM iniciativas WHERE id_iniciativa = ?")->execute([$id]);
  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json(['ok' => true, 'message' => 'Iniciativa excluída.']);
