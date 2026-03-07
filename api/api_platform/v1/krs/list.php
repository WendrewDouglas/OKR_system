<?php
declare(strict_types=1);

/**
 * GET /objetivos/:id_objetivo/krs
 * Lista Key Results de um objetivo com progresso calculado.
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
$idObj = api_param('id_objetivo');

$pdo = api_db();

// Verify objective belongs to company
$stV = $pdo->prepare("SELECT id_objetivo FROM objetivos WHERE id_objetivo = ? AND id_company = ?");
$stV->execute([$idObj, $cid]);
if (!$stV->fetch()) {
  api_error('E_NOT_FOUND', 'Objetivo não encontrado.', 404);
}

$st = $pdo->prepare("
  SELECT kr.id_kr, kr.key_result_num, kr.descricao, kr.status, kr.status_aprovacao,
         kr.baseline, kr.meta, kr.unidade_medida, kr.direcao_metrica,
         kr.farol, kr.natureza_kr, kr.tipo_frequencia_milestone,
         kr.data_inicio, kr.data_fim, kr.dt_novo_prazo, kr.responsavel,
         kr.dt_ultima_atualizacao,
         u.primeiro_nome AS resp_nome, u.ultimo_nome AS resp_sobrenome
    FROM key_results kr
    LEFT JOIN usuarios u ON u.id_user = kr.responsavel
   WHERE kr.id_objetivo = ?
   ORDER BY kr.key_result_num
");
$st->execute([$idObj]);
$krs = $st->fetchAll();

// Calculate progress for each KR
$result = [];
foreach ($krs as $kr) {
  $idKr = $kr['id_kr'];
  $base = (float)($kr['baseline'] ?? 0);
  $meta = (float)($kr['meta'] ?? 0);

  // Get latest milestone with real value
  $stM = $pdo->prepare("
    SELECT valor_real_consolidado, valor_esperado, data_ref
      FROM milestones_kr
     WHERE id_kr = ? AND data_ref <= CURDATE() AND valor_real_consolidado IS NOT NULL
     ORDER BY data_ref DESC LIMIT 1
  ");
  $stM->execute([$idKr]);
  $ms = $stM->fetch();

  $valorAtual = $ms ? (float)$ms['valor_real_consolidado'] : null;
  $valorEsperado = $ms ? (float)$ms['valor_esperado'] : null;
  $range = $meta - $base;
  $pctAtual = ($valorAtual !== null && abs($range) > 0.0001)
    ? round((($valorAtual - $base) / $range) * 100, 1)
    : null;

  $result[] = [
    'id_kr'                    => $idKr,
    'key_result_num'           => (int)$kr['key_result_num'],
    'descricao'                => $kr['descricao'],
    'status'                   => $kr['status'],
    'status_aprovacao'         => $kr['status_aprovacao'],
    'baseline'                 => $base,
    'meta'                     => $meta,
    'unidade_medida'           => $kr['unidade_medida'],
    'direcao_metrica'          => $kr['direcao_metrica'],
    'farol'                    => $kr['farol'],
    'natureza_kr'              => $kr['natureza_kr'],
    'tipo_frequencia_milestone' => $kr['tipo_frequencia_milestone'],
    'data_inicio'              => $kr['data_inicio'],
    'data_fim'                 => $kr['data_fim'],
    'dt_ultimo_atualizacao'    => $kr['dt_ultima_atualizacao'],
    'responsavel' => $kr['responsavel'] ? [
      'id_user' => (int)$kr['responsavel'],
      'nome'    => trim(($kr['resp_nome'] ?? '') . ' ' . ($kr['resp_sobrenome'] ?? '')),
    ] : null,
    'progress' => [
      'valor_atual'    => $valorAtual,
      'valor_esperado' => $valorEsperado,
      'pct_atual'      => $pctAtual,
    ],
  ];
}

api_json(['ok' => true, 'krs' => $result]);
