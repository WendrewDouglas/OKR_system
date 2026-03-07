<?php
declare(strict_types=1);

/**
 * GET /krs/:id
 * Retorna detalhe do KR com milestones, chart data e agregados.
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
$idKr = api_param('id');
$pdo  = api_db();

$st = $pdo->prepare("
  SELECT kr.*, o.id_company, o.descricao AS obj_descricao,
         u.primeiro_nome AS resp_nome, u.ultimo_nome AS resp_sobrenome
    FROM key_results kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
    LEFT JOIN usuarios u ON u.id_user = kr.responsavel
   WHERE kr.id_kr = ?
");
$st->execute([$idKr]);
$kr = $st->fetch();

if (!$kr || (int)$kr['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Key Result não encontrado.', 404);
}

// Milestones
$stM = $pdo->prepare("
  SELECT id_milestone, num_ordem, data_ref, valor_esperado,
         valor_esperado_min, valor_esperado_max,
         valor_real_consolidado, qtde_apontamentos,
         bloqueado_para_edicao, status_aprovacao
    FROM milestones_kr
   WHERE id_kr = ?
   ORDER BY data_ref ASC
");
$stM->execute([$idKr]);
$milestones = $stM->fetchAll();

// Chart data
$labels = []; $esperado = []; $real = []; $minArr = []; $maxArr = [];
foreach ($milestones as $m) {
  $labels[]   = $m['data_ref'];
  $esperado[] = $m['valor_esperado'] !== null ? (float)$m['valor_esperado'] : null;
  $real[]     = $m['valor_real_consolidado'] !== null ? (float)$m['valor_real_consolidado'] : null;
  $minArr[]   = $m['valor_esperado_min'] !== null ? (float)$m['valor_esperado_min'] : null;
  $maxArr[]   = $m['valor_esperado_max'] !== null ? (float)$m['valor_esperado_max'] : null;
}

// Aggregates
$stIni = $pdo->prepare("SELECT COUNT(*) FROM iniciativas WHERE id_kr = ?");
$stIni->execute([$idKr]);
$cntIni = (int)$stIni->fetchColumn();

$stOrc = $pdo->prepare("
  SELECT COALESCE(SUM(o.valor), 0) AS aprovado,
         COALESCE((SELECT SUM(od.valor) FROM orcamentos_detalhes od
                    WHERE od.id_orcamento IN (SELECT id_orcamento FROM orcamentos WHERE id_iniciativa IN
                      (SELECT id_iniciativa FROM iniciativas WHERE id_kr = ?))), 0) AS realizado
    FROM orcamentos o
    JOIN iniciativas i ON i.id_iniciativa = o.id_iniciativa
   WHERE i.id_kr = ?
");
$stOrc->execute([$idKr, $idKr]);
$orc = $stOrc->fetch();

api_json([
  'ok' => true,
  'kr' => [
    'id_kr'                    => $kr['id_kr'],
    'id_objetivo'              => $kr['id_objetivo'],
    'obj_descricao'            => $kr['obj_descricao'],
    'key_result_num'           => (int)$kr['key_result_num'],
    'descricao'                => $kr['descricao'],
    'status'                   => $kr['status'],
    'status_aprovacao'         => $kr['status_aprovacao'],
    'baseline'                 => (float)$kr['baseline'],
    'meta'                     => (float)$kr['meta'],
    'unidade_medida'           => $kr['unidade_medida'],
    'direcao_metrica'          => $kr['direcao_metrica'],
    'natureza_kr'              => $kr['natureza_kr'],
    'tipo_kr'                  => $kr['tipo_kr'],
    'tipo_frequencia_milestone' => $kr['tipo_frequencia_milestone'],
    'farol'                    => $kr['farol'],
    'margem_confianca'         => $kr['margem_confianca'] !== null ? (float)$kr['margem_confianca'] : null,
    'data_inicio'              => $kr['data_inicio'],
    'data_fim'                 => $kr['data_fim'],
    'dt_ultima_atualizacao'    => $kr['dt_ultima_atualizacao'],
    'responsavel' => $kr['responsavel'] ? [
      'id_user' => (int)$kr['responsavel'],
      'nome'    => trim(($kr['resp_nome'] ?? '') . ' ' . ($kr['resp_sobrenome'] ?? '')),
    ] : null,
  ],
  'milestones' => array_map(fn($m) => [
    'id_milestone'       => (int)$m['id_milestone'],
    'num_ordem'          => (int)$m['num_ordem'],
    'data_ref'           => $m['data_ref'],
    'valor_esperado'     => $m['valor_esperado'] !== null ? (float)$m['valor_esperado'] : null,
    'valor_esperado_min' => $m['valor_esperado_min'] !== null ? (float)$m['valor_esperado_min'] : null,
    'valor_esperado_max' => $m['valor_esperado_max'] !== null ? (float)$m['valor_esperado_max'] : null,
    'valor_real'         => $m['valor_real_consolidado'] !== null ? (float)$m['valor_real_consolidado'] : null,
    'apontamentos'       => (int)$m['qtde_apontamentos'],
    'bloqueado'          => (bool)$m['bloqueado_para_edicao'],
    'status_aprovacao'   => $m['status_aprovacao'],
  ], $milestones),
  'chart' => [
    'labels'   => $labels,
    'esperado' => $esperado,
    'real'     => $real,
    'min'      => $minArr,
    'max'      => $maxArr,
  ],
  'agregados' => [
    'iniciativas' => $cntIni,
    'orcamento'   => [
      'aprovado'  => (float)($orc['aprovado'] ?? 0),
      'realizado' => (float)($orc['realizado'] ?? 0),
      'saldo'     => (float)($orc['aprovado'] ?? 0) - (float)($orc['realizado'] ?? 0),
    ],
  ],
]);
