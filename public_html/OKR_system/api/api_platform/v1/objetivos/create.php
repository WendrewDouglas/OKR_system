<?php
declare(strict_types=1);

/**
 * POST /objetivos
 * Cria um novo objetivo.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$pdo  = api_db();

// RBAC
if (!api_has_cap($pdo, $uid, $cid, 'W:objetivo@ORG')) {
  api_error('E_FORBIDDEN', 'Sem permissão para criar objetivos.', 403);
}

$in = api_input();
api_require_fields($in, ['descricao', 'pilar_bsc', 'ciclo_tipo']);

$descricao  = api_str($in['descricao']);
$pilar      = api_str($in['pilar_bsc']);
$tipo       = api_str($in['tipo_objetivo'] ?? '');
$cicloTipo  = api_str($in['ciclo_tipo']);
$observacoes = api_str($in['observacoes'] ?? '');
$dono       = api_int_or_null($in['dono'] ?? null) ?: $uid;

// Calculate cycle dates
api_load_helper('auth/helpers/cycle_calc.php');
$cycleData = [
  'ciclo_anual_ano'   => $in['ciclo_anual_ano'] ?? '',
  'ciclo_semestral'   => $in['ciclo_semestral'] ?? '',
  'ciclo_trimestral'  => $in['ciclo_trimestral'] ?? '',
  'ciclo_bimestral'   => $in['ciclo_bimestral'] ?? '',
  'ciclo_mensal_mes'  => $in['ciclo_mensal_mes'] ?? '',
  'ciclo_mensal_ano'  => $in['ciclo_mensal_ano'] ?? '',
  'ciclo_pers_inicio' => $in['ciclo_pers_inicio'] ?? '',
  'ciclo_pers_fim'    => $in['ciclo_pers_fim'] ?? '',
];
[$dtInicio, $dtPrazo] = calcularDatasCiclo($cicloTipo, $cycleData);

$pdo->beginTransaction();
try {
  $st = $pdo->prepare("
    INSERT INTO objetivos
      (descricao, tipo, pilar_bsc, tipo_ciclo, dono, observacoes,
       dt_inicio, dt_prazo, status, status_aprovacao,
       id_company, id_user_criador, dt_criacao)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendente', 'pendente', ?, ?, NOW())
  ");
  $st->execute([
    $descricao, $tipo ?: null, $pilar, $cicloTipo, $dono, $observacoes ?: null,
    $dtInicio ?: null, $dtPrazo ?: null,
    $cid, $uid,
  ]);
  $idObjetivo = (int)$pdo->lastInsertId();

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json([
  'ok'          => true,
  'id_objetivo' => $idObjetivo,
  'message'     => 'Objetivo criado com sucesso.',
], 201);
