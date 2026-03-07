<?php
declare(strict_types=1);

/**
 * GET /orcamento-resumo
 * Returns a budget overview for the current user's company.
 */

$ctx = api_auth_context();
$cid = $ctx['cid'];
$pdo = api_db();

$st = $pdo->prepare("
    SELECT orc.id_orcamento, orc.id_iniciativa,
           orc.valor AS valor_planejado,
           COALESCE(orc.valor_realizado, 0) AS valor_realizado,
           orc.status_aprovacao, orc.data_desembolso,
           i.descricao AS iniciativa_descricao,
           k.descricao AS kr_descricao
      FROM orcamentos orc
      JOIN iniciativas i ON i.id_iniciativa = orc.id_iniciativa
      JOIN key_results k ON k.id_kr = i.id_kr
      JOIN objetivos o ON o.id_objetivo = k.id_objetivo
     WHERE o.id_company = ?
     ORDER BY orc.data_desembolso DESC
");
$st->execute([$cid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$totalPlanejado = 0;
$totalRealizado = 0;
$orcamentos = [];

foreach ($rows as $r) {
    $plan = (float)$r['valor_planejado'];
    $real = (float)$r['valor_realizado'];
    $totalPlanejado += $plan;
    $totalRealizado += $real;

    $orcamentos[] = [
        'id_orcamento'          => (int)$r['id_orcamento'],
        'id_iniciativa'         => $r['id_iniciativa'],
        'descricao'             => $r['iniciativa_descricao'],
        'kr_descricao'          => $r['kr_descricao'],
        'valor_planejado'       => $plan,
        'valor_realizado'       => $real,
        'status_aprovacao'      => $r['status_aprovacao'],
        'data_desembolso'       => $r['data_desembolso'],
    ];
}

api_json([
    'ok'         => true,
    'totals'     => [
        'planejado'  => round($totalPlanejado, 2),
        'realizado'  => round($totalRealizado, 2),
        'saldo'      => round($totalPlanejado - $totalRealizado, 2),
    ],
    'orcamentos' => $orcamentos,
]);
