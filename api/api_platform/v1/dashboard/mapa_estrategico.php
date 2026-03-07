<?php
declare(strict_types=1);

/**
 * GET /dashboard/mapa-estrategico
 * Retorna dados do mapa estratégico (BSC) com progresso por pilar.
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
if ($cid <= 0) api_error('E_AUTH', 'Company inválida.', 401);

$pdo = api_db();

/* === PILARES + OBJETIVOS === */
$st = $pdo->prepare("
  SELECT p.id_pilar, p.descricao_exibicao AS pilar_nome, p.ordem_pilar,
         o.id_objetivo, o.descricao AS obj_descricao, o.dono,
         o.status AS obj_status, o.qualidade,
         u.primeiro_nome AS dono_nome, u.ultimo_nome AS dono_sobrenome
    FROM dom_pilar_bsc p
    LEFT JOIN objetivos o ON o.pilar_bsc = p.id_pilar AND o.id_company = ?
    LEFT JOIN usuarios u ON u.id_user = o.dono
   ORDER BY p.ordem_pilar, o.id_objetivo
");
$st->execute([$cid]);
$rows = $st->fetchAll();

// Collect objective IDs
$objIds = [];
foreach ($rows as $r) {
  if ($r['id_objetivo']) $objIds[] = $r['id_objetivo'];
}

/* === KRS + PROGRESS === */
$krData = [];
if (!empty($objIds)) {
  $inPh = implode(',', array_fill(0, count($objIds), '?'));
  $stKr = $pdo->prepare("
    SELECT kr.id_kr, kr.id_objetivo, kr.baseline, kr.meta, kr.direcao_metrica,
           kr.status,
           (SELECT m.valor_real_consolidado
              FROM milestones_kr m
             WHERE m.id_kr = kr.id_kr AND m.data_ref <= CURDATE()
               AND m.valor_real_consolidado IS NOT NULL
             ORDER BY m.data_ref DESC LIMIT 1
           ) AS ultimo_real
      FROM key_results kr
     WHERE kr.id_objetivo IN ($inPh)
  ");
  $stKr->execute($objIds);
  foreach ($stKr->fetchAll() as $kr) {
    $krData[$kr['id_objetivo']][] = $kr;
  }
}

/* === BUILD PILLARS === */
$pillars = [];
$current = null;

foreach ($rows as $r) {
  $pilarId = $r['id_pilar'];
  if (!isset($pillars[$pilarId])) {
    $pillars[$pilarId] = [
      'id_pilar'   => $pilarId,
      'pilar_nome' => $r['pilar_nome'],
      'ordem'      => (int)$r['ordem_pilar'],
      'objetivos'  => [],
      'total_objetivos' => 0,
      'total_krs'       => 0,
      'progress'        => null,
    ];
  }

  if (!$r['id_objetivo']) continue;

  $objId = $r['id_objetivo'];
  $krs   = $krData[$objId] ?? [];
  $progressValues = [];

  foreach ($krs as $kr) {
    $base = (float)($kr['baseline'] ?? 0);
    $meta = (float)($kr['meta'] ?? 0);
    $real = $kr['ultimo_real'] !== null ? (float)$kr['ultimo_real'] : $base;
    $range = $meta - $base;
    if (abs($range) > 0.0001) {
      $progressValues[] = min(100, max(0, (($real - $base) / $range) * 100));
    }
  }

  $avgProgress = !empty($progressValues)
    ? round(array_sum($progressValues) / count($progressValues), 1)
    : null;

  $pillars[$pilarId]['objetivos'][] = [
    'id_objetivo' => $objId,
    'descricao'   => $r['obj_descricao'],
    'status'      => $r['obj_status'],
    'qualidade'   => $r['qualidade'],
    'dono' => [
      'id_user' => (int)$r['dono'],
      'nome'    => trim(($r['dono_nome'] ?? '') . ' ' . ($r['dono_sobrenome'] ?? '')),
    ],
    'qtd_krs'  => count($krs),
    'progress' => $avgProgress,
  ];
  $pillars[$pilarId]['total_objetivos']++;
  $pillars[$pilarId]['total_krs'] += count($krs);
}

// Calculate pillar-level averages
foreach ($pillars as &$p) {
  $objProgs = array_filter(array_column($p['objetivos'], 'progress'), fn($v) => $v !== null);
  $p['progress'] = !empty($objProgs) ? round(array_sum($objProgs) / count($objProgs), 1) : null;
}
unset($p);

// Sort by ordem
usort($pillars, fn($a, $b) => $a['ordem'] <=> $b['ordem']);

api_json([
  'ok'      => true,
  'pillars' => array_values($pillars),
  'time'    => date('c'),
]);
