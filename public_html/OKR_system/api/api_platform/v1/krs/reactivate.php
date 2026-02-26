<?php
declare(strict_types=1);

/**
 * POST /krs/:id/reativar
 * Reativa um KR cancelado.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$idKr = api_param('id');
$pdo  = api_db();

$st = $pdo->prepare("
  SELECT kr.id_kr, o.id_company FROM key_results kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
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

// Find "Em Andamento" or "Não Iniciado" status
$stS = $pdo->prepare("SELECT id FROM dom_status_kr WHERE LOWER(descricao) LIKE '%andamento%' LIMIT 1");
$stS->execute();
$statusId = $stS->fetchColumn();
if (!$statusId) {
  $stS2 = $pdo->prepare("SELECT id FROM dom_status_kr WHERE LOWER(descricao) LIKE '%não inic%' LIMIT 1");
  $stS2->execute();
  $statusId = $stS2->fetchColumn() ?: 'Em Andamento';
}

$pdo->prepare("UPDATE key_results SET status = ?, dt_ultima_atualizacao = NOW() WHERE id_kr = ?")->execute([$statusId, $idKr]);

api_json(['ok' => true, 'message' => 'KR reativado.']);
