<?php
declare(strict_types=1);

/**
 * GET /krs/:id/milestones
 * Retorna milestones do KR.
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
$idKr = api_param('id');
$pdo  = api_db();

// Verify tenant
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

$stM = $pdo->prepare("
  SELECT id_milestone, num_ordem, data_ref, valor_esperado,
         valor_esperado_min, valor_esperado_max,
         valor_real_consolidado, qtde_apontamentos,
         gerado_automatico, editado_manual, bloqueado_para_edicao,
         status_aprovacao, dt_ultimo_apontamento
    FROM milestones_kr
   WHERE id_kr = ?
   ORDER BY data_ref ASC
");
$stM->execute([$idKr]);
$milestones = $stM->fetchAll();

$total = count($milestones);
$items = array_map(fn($m, $i) => [
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
  'ordem_label'        => ($i + 1) . '/' . $total,
], $milestones, array_keys($milestones));

api_json(['ok' => true, 'milestones' => $items, 'total' => $total]);
