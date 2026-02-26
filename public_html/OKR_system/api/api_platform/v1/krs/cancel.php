<?php
declare(strict_types=1);

/**
 * POST /krs/:id/cancelar
 * Cancela um KR.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$idKr = api_param('id');
$pdo  = api_db();

$in = api_input();
api_require_fields($in, ['justificativa']);

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

// Find "Cancelado" status
$stS = $pdo->prepare("SELECT id FROM dom_status_kr WHERE LOWER(descricao) LIKE '%cancel%' LIMIT 1");
$stS->execute();
$statusId = $stS->fetchColumn() ?: 'Cancelado';

$pdo->prepare("UPDATE key_results SET status = ?, dt_ultima_atualizacao = NOW() WHERE id_kr = ?")->execute([$statusId, $idKr]);

api_json(['ok' => true, 'message' => 'KR cancelado.']);
