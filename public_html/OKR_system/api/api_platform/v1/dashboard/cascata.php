<?php
declare(strict_types=1);

/**
 * GET /dashboard/cascata
 * Retorna a árvore OKR completa (objetivos → KRs → iniciativas).
 * Query params: ?scope=company|meus  &id_objetivo=N
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
if ($cid <= 0) api_error('E_AUTH', 'Company inválida.', 401);

$pdo   = api_db();
$scope = api_str($_GET['scope'] ?? 'company');
$idObj = api_int_or_null($_GET['id_objetivo'] ?? null);

/* === OBJETIVOS === */
$where  = ["o.id_company = ?"];
$params = [$cid];

if ($scope === 'meus') {
  $where[]  = "o.dono = ?";
  $params[] = $uid;
}
if ($idObj !== null) {
  $where[]  = "o.id_objetivo = ?";
  $params[] = $idObj;
}

$wSQL = implode(' AND ', $where);
$objs = $pdo->prepare("
  SELECT o.id_objetivo, o.descricao, o.status, o.status_aprovacao,
         o.pilar_bsc, o.tipo, o.qualidade, o.dono,
         o.dt_criacao, o.dt_prazo,
         u.primeiro_nome AS dono_nome, u.ultimo_nome AS dono_sobrenome
    FROM objetivos o
    LEFT JOIN usuarios u ON u.id_user = o.dono
   WHERE $wSQL
   ORDER BY o.dt_criacao DESC
");
$objs->execute($params);
$objetivos = $objs->fetchAll();

if (empty($objetivos)) {
  api_json(['ok' => true, 'objetivos' => [], 'user_id' => $uid]);
}

$objIds = array_column($objetivos, 'id_objetivo');

/* === KEY RESULTS === */
$inPh = implode(',', array_fill(0, count($objIds), '?'));
$krs = $pdo->prepare("
  SELECT kr.id_kr, kr.id_objetivo, kr.key_result_num, kr.descricao, kr.status,
         kr.baseline, kr.meta, kr.unidade_medida, kr.direcao_metrica,
         kr.farol, kr.data_inicio, kr.data_fim, kr.responsavel,
         u.primeiro_nome AS resp_nome, u.ultimo_nome AS resp_sobrenome
    FROM key_results kr
    LEFT JOIN usuarios u ON u.id_user = kr.responsavel
   WHERE kr.id_objetivo IN ($inPh)
   ORDER BY kr.key_result_num
");
$krs->execute($objIds);
$allKrs = $krs->fetchAll();

$krsByObj = [];
$krIds    = [];
foreach ($allKrs as $kr) {
  $krsByObj[$kr['id_objetivo']][] = $kr;
  $krIds[] = $kr['id_kr'];
}

/* === INICIATIVAS === */
$inisByKr = [];
if (!empty($krIds)) {
  $inKr = implode(',', array_fill(0, count($krIds), '?'));
  $inis = $pdo->prepare("
    SELECT i.id_iniciativa, i.id_kr, i.num_iniciativa, i.descricao,
           i.status, i.dt_prazo, i.id_user_responsavel,
           u.primeiro_nome AS resp_nome, u.ultimo_nome AS resp_sobrenome
      FROM iniciativas i
      LEFT JOIN usuarios u ON u.id_user = i.id_user_responsavel
     WHERE i.id_kr IN ($inKr)
     ORDER BY i.num_iniciativa
  ");
  $inis->execute($krIds);
  foreach ($inis->fetchAll() as $ini) {
    $inisByKr[$ini['id_kr']][] = $ini;
  }
}

/* === BUILD TREE === */
$tree = [];
foreach ($objetivos as $obj) {
  $objKrs = $krsByObj[$obj['id_objetivo']] ?? [];
  $krsFormatted = [];
  foreach ($objKrs as $kr) {
    $krInis = $inisByKr[$kr['id_kr']] ?? [];
    $krsFormatted[] = [
      'id_kr'            => $kr['id_kr'],
      'key_result_num'   => (int)$kr['key_result_num'],
      'descricao'        => $kr['descricao'],
      'status'           => $kr['status'],
      'baseline'         => $kr['baseline'] !== null ? (float)$kr['baseline'] : null,
      'meta'             => $kr['meta'] !== null ? (float)$kr['meta'] : null,
      'unidade_medida'   => $kr['unidade_medida'],
      'direcao_metrica'  => $kr['direcao_metrica'],
      'farol'            => $kr['farol'],
      'data_inicio'      => $kr['data_inicio'],
      'data_fim'         => $kr['data_fim'],
      'responsavel'      => $kr['responsavel'] ? [
        'id_user' => (int)$kr['responsavel'],
        'nome'    => trim(($kr['resp_nome'] ?? '') . ' ' . ($kr['resp_sobrenome'] ?? '')),
      ] : null,
      'iniciativas' => array_map(fn($i) => [
        'id_iniciativa'   => $i['id_iniciativa'],
        'num_iniciativa'  => (int)$i['num_iniciativa'],
        'descricao'       => $i['descricao'],
        'status'          => $i['status'],
        'dt_prazo'        => $i['dt_prazo'],
        'responsavel'     => $i['id_user_responsavel'] ? [
          'id_user' => (int)$i['id_user_responsavel'],
          'nome'    => trim(($i['resp_nome'] ?? '') . ' ' . ($i['resp_sobrenome'] ?? '')),
        ] : null,
      ], $krInis),
    ];
  }

  $tree[] = [
    'id_objetivo'      => $obj['id_objetivo'],
    'descricao'        => $obj['descricao'],
    'status'           => $obj['status'],
    'status_aprovacao' => $obj['status_aprovacao'],
    'pilar_bsc'        => $obj['pilar_bsc'],
    'tipo'             => $obj['tipo'],
    'qualidade'        => $obj['qualidade'],
    'dt_criacao'       => $obj['dt_criacao'],
    'dt_prazo'         => $obj['dt_prazo'],
    'dono' => [
      'id_user' => (int)$obj['dono'],
      'nome'    => trim(($obj['dono_nome'] ?? '') . ' ' . ($obj['dono_sobrenome'] ?? '')),
    ],
    'key_results' => $krsFormatted,
  ];
}

api_json(['ok' => true, 'objetivos' => $tree, 'user_id' => $uid]);
