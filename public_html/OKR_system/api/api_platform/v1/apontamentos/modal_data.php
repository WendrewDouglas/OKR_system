<?php
declare(strict_types=1);

/**
 * GET /krs/:id_kr/apontamentos/modal-data
 * Dados para o modal de registro de apontamentos.
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
$idKr = api_param('id_kr');
$pdo  = api_db();

// Tenant
$st = $pdo->prepare("
  SELECT kr.id_kr, kr.descricao, kr.unidade_medida, kr.baseline, kr.meta,
         kr.direcao_metrica, o.id_company
    FROM key_results kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
   WHERE kr.id_kr = ?
");
$st->execute([$idKr]);
$kr = $st->fetch();
if (!$kr || (int)$kr['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Key Result não encontrado.', 404);
}

// Milestones with fill status
$stM = $pdo->prepare("
  SELECT id_milestone, num_ordem, data_ref, valor_esperado,
         valor_esperado_min, valor_esperado_max,
         valor_real_consolidado, bloqueado_para_edicao
    FROM milestones_kr
   WHERE id_kr = ?
   ORDER BY data_ref ASC
");
$stM->execute([$idKr]);
$milestones = $stM->fetchAll();
$total = count($milestones);

api_json([
  'ok' => true,
  'kr' => [
    'id_kr'           => $kr['id_kr'],
    'descricao'       => $kr['descricao'],
    'unidade_medida'  => $kr['unidade_medida'],
    'baseline'        => (float)$kr['baseline'],
    'meta'            => (float)$kr['meta'],
    'direcao_metrica' => $kr['direcao_metrica'],
  ],
  'milestones' => array_map(fn($m, $i) => [
    'id_milestone'       => (int)$m['id_milestone'],
    'data_ref'           => $m['data_ref'],
    'valor_esperado'     => $m['valor_esperado'] !== null ? (float)$m['valor_esperado'] : null,
    'valor_esperado_min' => $m['valor_esperado_min'] !== null ? (float)$m['valor_esperado_min'] : null,
    'valor_esperado_max' => $m['valor_esperado_max'] !== null ? (float)$m['valor_esperado_max'] : null,
    'valor_real'         => $m['valor_real_consolidado'] !== null ? (float)$m['valor_real_consolidado'] : null,
    'bloqueado'          => (bool)$m['bloqueado_para_edicao'],
    'ordem_label'        => ($i + 1) . '/' . $total,
  ], $milestones, array_keys($milestones)),
]);
