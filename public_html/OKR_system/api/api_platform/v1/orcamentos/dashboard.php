<?php
declare(strict_types=1);

/**
 * GET /krs/:id_kr/orcamento-dashboard
 * Dashboard financeiro de um KR (totais, série mensal, por iniciativa).
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
$idKr = api_param('id_kr');
$ano  = (int)($_GET['ano'] ?? date('Y'));
$pdo  = api_db();

// Tenant
$st = $pdo->prepare("
  SELECT o.id_company FROM key_results kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
   WHERE kr.id_kr = ?
");
$st->execute([$idKr]);
$co = $st->fetchColumn();
if ($co === false || (int)$co !== $cid) {
  api_error('E_NOT_FOUND', 'Key Result não encontrado.', 404);
}

// Totals
$stT = $pdo->prepare("
  SELECT
    COALESCE(SUM(orc.valor), 0) AS aprovado,
    COALESCE((
      SELECT SUM(od.valor) FROM orcamentos_detalhes od
       WHERE od.id_orcamento IN (
         SELECT orc2.id_orcamento FROM orcamentos orc2
          JOIN iniciativas i2 ON i2.id_iniciativa = orc2.id_iniciativa
         WHERE i2.id_kr = ?
       )
    ), 0) AS realizado
  FROM orcamentos orc
  JOIN iniciativas i ON i.id_iniciativa = orc.id_iniciativa
  WHERE i.id_kr = ?
");
$stT->execute([$idKr, $idKr]);
$tot = $stT->fetch();
$aprovado  = (float)$tot['aprovado'];
$realizado = (float)$tot['realizado'];

// Monthly series
$stPlan = $pdo->prepare("
  SELECT DATE_FORMAT(orc.data_desembolso, '%Y-%m') AS comp, SUM(orc.valor) AS val
    FROM orcamentos orc
    JOIN iniciativas i ON i.id_iniciativa = orc.id_iniciativa
   WHERE i.id_kr = ? AND YEAR(orc.data_desembolso) = ?
   GROUP BY comp ORDER BY comp
");
$stPlan->execute([$idKr, $ano]);
$planejado = [];
foreach ($stPlan->fetchAll() as $r) $planejado[$r['comp']] = (float)$r['val'];

$stReal = $pdo->prepare("
  SELECT DATE_FORMAT(od.data_pagamento, '%Y-%m') AS comp, SUM(od.valor) AS val
    FROM orcamentos_detalhes od
    JOIN orcamentos orc ON orc.id_orcamento = od.id_orcamento
    JOIN iniciativas i ON i.id_iniciativa = orc.id_iniciativa
   WHERE i.id_kr = ? AND YEAR(od.data_pagamento) = ?
   GROUP BY comp ORDER BY comp
");
$stReal->execute([$idKr, $ano]);
$realizadoM = [];
foreach ($stReal->fetchAll() as $r) $realizadoM[$r['comp']] = (float)$r['val'];

// Build 12-month series
$series = [];
$planAcum = 0;
$realAcum = 0;
for ($m = 1; $m <= 12; $m++) {
  $comp = $ano . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
  $p = $planejado[$comp] ?? 0;
  $r = $realizadoM[$comp] ?? 0;
  $planAcum += $p;
  $realAcum += $r;
  $series[] = [
    'competencia' => $comp,
    'planejado'   => $p,
    'realizado'   => $r,
    'plan_acum'   => $planAcum,
    'real_acum'   => $realAcum,
  ];
}

// Per initiative
$stIni = $pdo->prepare("
  SELECT i.id_iniciativa, i.num_iniciativa, i.descricao,
         COALESCE(SUM(orc.valor), 0) AS aprovado,
         COALESCE((
           SELECT SUM(od.valor) FROM orcamentos_detalhes od
            WHERE od.id_orcamento IN (SELECT id_orcamento FROM orcamentos WHERE id_iniciativa = i.id_iniciativa)
         ), 0) AS realizado
    FROM iniciativas i
    LEFT JOIN orcamentos orc ON orc.id_iniciativa = i.id_iniciativa
   WHERE i.id_kr = ?
   GROUP BY i.id_iniciativa
   ORDER BY i.num_iniciativa
");
$stIni->execute([$idKr]);

api_json([
  'ok'     => true,
  'totais' => [
    'aprovado'  => $aprovado,
    'realizado' => $realizado,
    'saldo'     => $aprovado - $realizado,
  ],
  'series'        => $series,
  'por_iniciativa' => array_map(fn($r) => [
    'id_iniciativa'  => $r['id_iniciativa'],
    'num_iniciativa' => (int)$r['num_iniciativa'],
    'descricao'      => $r['descricao'],
    'aprovado'       => (float)$r['aprovado'],
    'realizado'      => (float)$r['realizado'],
    'saldo'          => (float)$r['aprovado'] - (float)$r['realizado'],
  ], $stIni->fetchAll()),
]);
