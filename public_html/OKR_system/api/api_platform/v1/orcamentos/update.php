<?php
declare(strict_types=1);

/**
 * PUT /orcamentos/:id
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_int(api_param('id'), 'id');
$pdo  = api_db();

if (!api_has_cap($pdo, $uid, $cid, 'W:orcamento@ORG', ['id_orcamento' => $id])) {
  api_error('E_FORBIDDEN', 'Sem permissão.', 403);
}

$in = api_input();
$sets   = [];
$params = [];

if (array_key_exists('valor', $in)) {
  $sets[]   = "valor = ?";
  $params[] = api_float($in['valor'], 'valor');
}
if (array_key_exists('data_desembolso', $in)) {
  $sets[]   = "data_desembolso = ?";
  $params[] = api_date_or_null($in['data_desembolso']);
}
if (array_key_exists('justificativa_orcamento', $in)) {
  $sets[]   = "justificativa_orcamento = ?";
  $params[] = api_str($in['justificativa_orcamento']);
}

if (empty($sets)) {
  api_error('E_INPUT', 'Nenhum campo para atualizar.', 422);
}

$sets[] = "dt_ultima_atualizacao = NOW()";
$params[] = $id;

$pdo->prepare("UPDATE orcamentos SET " . implode(', ', $sets) . " WHERE id_orcamento = ?")->execute($params);

api_json(['ok' => true, 'message' => 'Orçamento atualizado.']);
