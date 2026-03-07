<?php
declare(strict_types=1);

/**
 * POST /orcamentos/:id/despesas
 * Adiciona uma despesa (detalhe) a um orçamento.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$idOrc = api_int(api_param('id'), 'id');
$pdo  = api_db();

if (!api_has_cap($pdo, $uid, $cid, 'W:orcamento@ORG', ['id_orcamento' => $idOrc])) {
  api_error('E_FORBIDDEN', 'Sem permissão.', 403);
}

$in = api_input();
api_require_fields($in, ['valor', 'data_pagamento']);

$valor = api_float($in['valor'], 'valor');
$dataPag = api_date($in['data_pagamento'], 'data_pagamento');
$desc = api_str($in['descricao'] ?? '');

if ($valor <= 0) {
  api_error('E_INPUT', 'Valor deve ser positivo.', 422);
}

$pdo->prepare("
  INSERT INTO orcamentos_detalhes
    (id_orcamento, valor, data_pagamento, descricao, id_user_criador, dt_criacao)
  VALUES (?, ?, ?, ?, ?, NOW())
")->execute([$idOrc, $valor, $dataPag, $desc ?: null, $uid]);

$idDespesa = (int)$pdo->lastInsertId();

api_json(['ok' => true, 'id_despesa' => $idDespesa, 'message' => 'Despesa registrada.'], 201);
