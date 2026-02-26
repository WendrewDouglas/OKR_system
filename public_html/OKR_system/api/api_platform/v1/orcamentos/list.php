<?php
declare(strict_types=1);

/**
 * GET /iniciativas/:id_ini/orcamentos
 * Lista orçamentos de uma iniciativa.
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
$idIni = api_param('id_ini');
$pdo  = api_db();

// Tenant
$st = $pdo->prepare("
  SELECT o2.id_company FROM iniciativas i
    JOIN key_results kr ON kr.id_kr = i.id_kr
    JOIN objetivos o2 ON o2.id_objetivo = kr.id_objetivo
   WHERE i.id_iniciativa = ?
");
$st->execute([$idIni]);
$co = $st->fetchColumn();
if ($co === false || (int)$co !== $cid) {
  api_error('E_NOT_FOUND', 'Iniciativa não encontrada.', 404);
}

$stO = $pdo->prepare("
  SELECT o.id_orcamento, o.valor, o.data_desembolso, o.valor_realizado,
         o.status_aprovacao, o.status_financeiro, o.codigo_orcamento,
         o.justificativa_orcamento, o.comentarios_aprovacao,
         o.dt_criacao, o.dt_aprovacao
    FROM orcamentos o
   WHERE o.id_iniciativa = ?
   ORDER BY o.data_desembolso
");
$stO->execute([$idIni]);
$orcs = $stO->fetchAll();

// Fetch details per orcamento
$result = [];
foreach ($orcs as $o) {
  $stD = $pdo->prepare("
    SELECT id_despesa, valor, data_pagamento, descricao, dt_criacao
      FROM orcamentos_detalhes WHERE id_orcamento = ? ORDER BY data_pagamento
  ");
  $stD->execute([$o['id_orcamento']]);
  $despesas = $stD->fetchAll();

  $totalDespesas = array_sum(array_column($despesas, 'valor'));

  $result[] = [
    'id_orcamento'       => (int)$o['id_orcamento'],
    'valor'              => (float)$o['valor'],
    'data_desembolso'    => $o['data_desembolso'],
    'status_aprovacao'   => $o['status_aprovacao'],
    'status_financeiro'  => $o['status_financeiro'],
    'codigo'             => $o['codigo_orcamento'],
    'justificativa'      => $o['justificativa_orcamento'],
    'total_despesas'     => (float)$totalDespesas,
    'saldo'              => (float)$o['valor'] - $totalDespesas,
    'dt_criacao'         => $o['dt_criacao'],
    'despesas' => array_map(fn($d) => [
      'id_despesa'     => (int)$d['id_despesa'],
      'valor'          => (float)$d['valor'],
      'data_pagamento' => $d['data_pagamento'],
      'descricao'      => $d['descricao'],
    ], $despesas),
  ];
}

api_json(['ok' => true, 'orcamentos' => $result]);
