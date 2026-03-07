<?php
declare(strict_types=1);

/**
 * GET /krs/:id_kr/apontamentos
 * Lista apontamentos de um KR.
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
$idKr = api_param('id_kr');
$pdo  = api_db();

// Tenant check
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

$stA = $pdo->prepare("
  SELECT a.id_apontamento, a.id_milestone, a.data_ref AS dt_evidencia,
         a.valor_real, a.observacao, a.justificativa, a.origem,
         a.dt_apontamento, a.usuario_id,
         m.data_ref AS milestone_data, m.valor_esperado
    FROM apontamentos_kr a
    LEFT JOIN milestones_kr m ON m.id_milestone = a.id_milestone
   WHERE a.id_kr = ?
   ORDER BY a.dt_apontamento DESC
");
$stA->execute([$idKr]);

api_json([
  'ok'           => true,
  'apontamentos' => array_map(fn($r) => [
    'id_apontamento'  => (int)$r['id_apontamento'],
    'id_milestone'    => $r['id_milestone'] ? (int)$r['id_milestone'] : null,
    'milestone_data'  => $r['milestone_data'],
    'valor_esperado'  => $r['valor_esperado'] !== null ? (float)$r['valor_esperado'] : null,
    'valor_real'      => $r['valor_real'] !== null ? (float)$r['valor_real'] : null,
    'dt_evidencia'    => $r['dt_evidencia'],
    'observacao'      => $r['observacao'],
    'justificativa'   => $r['justificativa'],
    'origem'          => $r['origem'],
    'dt_apontamento'  => $r['dt_apontamento'],
    'usuario_id'      => $r['usuario_id'],
  ], $stA->fetchAll()),
]);
