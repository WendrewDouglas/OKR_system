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
api_load_helper('auth/helpers/kr_progress.php');

/* === PILARES + OBJETIVOS === */
$st = $pdo->prepare("
  SELECT p.id_pilar, p.descricao_exibicao AS pilar_nome, p.ordem_pilar,
         o.id_objetivo, o.descricao AS obj_descricao, o.dono,
         o.status AS obj_status, o.qualidade,
         u.primeiro_nome AS dono_nome, u.ultimo_nome AS dono_sobrenome,
         ad.path AS dono_avatar_path, ad.filename AS dono_avatar_filename
    FROM dom_pilar_bsc p
    LEFT JOIN objetivos o ON o.pilar_bsc = p.id_pilar AND o.id_company = ?
    LEFT JOIN usuarios u ON u.id_user = o.dono
    LEFT JOIN avatars ad ON ad.id = u.avatar_id
   ORDER BY p.ordem_pilar, o.id_objetivo
");
$st->execute([$cid]);
$rows = $st->fetchAll();

// Collect objective IDs
$objIds = [];
foreach ($rows as $r) {
  if ($r['id_objetivo']) $objIds[] = $r['id_objetivo'];
}

/* === KRS + PROGRESS (helper compartilhado) === */
$today  = date('Y-m-d');
$krByObj = krp_kr_results_for_objetivos($pdo, $objIds, $today);

/* === BUILD PILLARS === */
$pillars = [];

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
      'esperado'        => null,
      'farol'           => 'cinza',
    ];
  }

  if (!$r['id_objetivo']) continue;

  $objId = (int)$r['id_objetivo'];
  $krs   = $krByObj[$objId] ?? [];
  $agg   = krp_aggregate_krs($krs);

  $pillars[$pilarId]['objetivos'][] = [
    'id_objetivo' => $objId,
    'descricao'   => $r['obj_descricao'],
    'status'      => $r['obj_status'],
    'qualidade'   => $r['qualidade'],
    'dono' => [
      'id_user'    => (int)$r['dono'],
      'nome'       => trim(($r['dono_nome'] ?? '') . ' ' . ($r['dono_sobrenome'] ?? '')),
      'avatar_url' => api_avatar_url_from_row(['path' => $r['dono_avatar_path'] ?? null, 'filename' => $r['dono_avatar_filename'] ?? null]),
    ],
    'qtd_krs'  => count($krs),
    'progress' => $agg['progress'],
    'esperado' => $agg['esperado'],
    'farol'    => $agg['farol'],
    'key_results' => array_map(static fn(array $k): array => [
      'id_kr'    => $k['id_kr'],
      'status'   => $k['status'],
      'progress' => $k['p_barra'],
      'esperado' => $k['esperado'],
      'farol'    => $k['farol'],
    ], $krs),
  ];
  $pillars[$pilarId]['total_objetivos']++;
  $pillars[$pilarId]['total_krs'] += count($krs);
}

// Agregação por pilar (média dos objetivos + farol pior-caso)
foreach ($pillars as &$p) {
  $agg = krp_aggregate_objs($p['objetivos']);
  $p['progress'] = $agg['progress'];
  $p['esperado'] = $agg['esperado'];
  $p['farol']    = $agg['farol'];
}
unset($p);

// Sort by ordem
usort($pillars, fn($a, $b) => $a['ordem'] <=> $b['ordem']);

api_json([
  'ok'      => true,
  'pillars' => array_values($pillars),
  'time'    => date('c'),
]);
