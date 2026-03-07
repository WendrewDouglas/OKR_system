<?php
declare(strict_types=1);

/**
 * PUT /iniciativas/:id/status
 * Atualiza status de uma iniciativa com observação obrigatória.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_param('id');
$pdo  = api_db();

$in = api_input();
api_require_fields($in, ['status', 'observacao']);

$novoStatus = api_str($in['status']);
$observacao = api_str($in['observacao']);

// Tenant
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
  $pdo->prepare("
    UPDATE iniciativas
       SET status = ?,
           observacoes = CONCAT(COALESCE(observacoes,''), '\n[', NOW(), '] ', ?),
           id_user_ult_alteracao = ?,
           dt_ultima_atualizacao = NOW()
     WHERE id_iniciativa = ?
  ")->execute([$novoStatus, $observacao, $uid, $id]);

  // Log status change
  $pdo->prepare("
    INSERT INTO apontamentos_status_iniciativas
      (id_iniciativa, status, observacao, id_user, data_hora, origem_apontamento)
    VALUES (?, ?, ?, ?, NOW(), 'api')
  ")->execute([$id, $novoStatus, $observacao, $uid]);

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json(['ok' => true, 'message' => 'Status atualizado.']);
