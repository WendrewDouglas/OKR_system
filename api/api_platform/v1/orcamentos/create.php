<?php
declare(strict_types=1);

/**
 * POST /orcamentos
 * Cria um orçamento vinculado a uma iniciativa.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$pdo  = api_db();

$in = api_input();
api_require_fields($in, ['id_iniciativa', 'valor']);

$idIni = api_str($in['id_iniciativa']);
$valor = api_float($in['valor'], 'valor');
$dataDesembolso = api_date_or_null($in['data_desembolso'] ?? null);
$justificativa  = api_str($in['justificativa_orcamento'] ?? '');

if ($valor <= 0) {
  api_error('E_INPUT', 'Valor deve ser positivo.', 422);
}

// Tenant
$st = $pdo->prepare("
  SELECT o.id_company FROM iniciativas i
    JOIN key_results kr ON kr.id_kr = i.id_kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
   WHERE i.id_iniciativa = ?
");
$st->execute([$idIni]);
$co = $st->fetchColumn();
if ($co === false || (int)$co !== $cid) {
  api_error('E_NOT_FOUND', 'Iniciativa não encontrada.', 404);
}

$pdo->prepare("
  INSERT INTO orcamentos
    (id_iniciativa, valor, data_desembolso, status_aprovacao,
     justificativa_orcamento, id_user_criador, dt_criacao)
  VALUES (?, ?, ?, 'pendente', ?, ?, NOW())
")->execute([$idIni, $valor, $dataDesembolso, $justificativa, $uid]);

$id = (int)$pdo->lastInsertId();

api_json(['ok' => true, 'id_orcamento' => $id, 'message' => 'Orçamento criado.'], 201);
