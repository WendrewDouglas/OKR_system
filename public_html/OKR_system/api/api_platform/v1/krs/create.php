<?php
declare(strict_types=1);

/**
 * POST /krs
 * Cria um Key Result.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$pdo  = api_db();

$in = api_input();
api_require_fields($in, ['id_objetivo', 'descricao', 'baseline', 'meta']);

$idObj   = $in['id_objetivo'];
$desc    = api_str($in['descricao']);
$base    = api_float($in['baseline'], 'baseline');
$meta    = api_float($in['meta'], 'meta');
$unidade = api_str($in['unidade_medida'] ?? '');
$direcao = api_str($in['direcao_metrica'] ?? 'MAIOR_MELHOR');
$natureza = api_str($in['natureza_kr'] ?? '');
$tipoKr  = api_str($in['tipo_kr'] ?? '');
$freqMilestone = api_str($in['tipo_frequencia_milestone'] ?? '');
$responsavel = api_int_or_null($in['responsavel'] ?? null);
$margem  = api_float_or_null($in['margem_confianca'] ?? null);
$autoMilestones = (int)($in['autogerar_milestones'] ?? 1);

// RBAC
if (!api_has_cap($pdo, $uid, $cid, 'W:kr@ORG', ['id_objetivo' => $idObj])) {
  api_error('E_FORBIDDEN', 'Sem permissão para criar KRs neste objetivo.', 403);
}

// Verify objective exists
$stObj = $pdo->prepare("SELECT id_objetivo, id_company FROM objetivos WHERE id_objetivo = ?");
$stObj->execute([$idObj]);
$obj = $stObj->fetch();
if (!$obj || (int)$obj['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Objetivo não encontrado.', 404);
}

// Calculate cycle dates
$cicloTipo = api_str($in['ciclo_tipo'] ?? '');
$dtInicio  = api_str($in['data_inicio'] ?? '');
$dtFim     = api_str($in['data_fim'] ?? '');

if ($cicloTipo !== '' && ($dtInicio === '' || $dtFim === '')) {
  api_load_helper('auth/helpers/cycle_calc.php');
  [$dtInicio, $dtFim] = calcularDatasCiclo($cicloTipo, $in);
}

// Infer nature if not provided
if ($natureza === '') {
  api_load_helper('auth/helpers/kr_helpers.php');
  if (function_exists('inferirNaturezaSlug')) {
    $natureza = inferirNaturezaSlug($base, $meta, $unidade);
  }
}

// For binary: coerce baseline/meta
if ($natureza === 'binario') {
  $base = 0;
  $meta = 1;
}

$pdo->beginTransaction();
try {
  // Sequential number
  $stN = $pdo->prepare("SELECT COALESCE(MAX(key_result_num), 0) + 1 FROM key_results WHERE id_objetivo = ? FOR UPDATE");
  $stN->execute([$idObj]);
  $num = (int)$stN->fetchColumn();

  $idKr = $num . '-' . $idObj;

  $stIns = $pdo->prepare("
    INSERT INTO key_results
      (id_kr, id_objetivo, key_result_num, descricao, baseline, meta,
       unidade_medida, direcao_metrica, natureza_kr, tipo_kr,
       tipo_frequencia_milestone, responsavel, margem_confianca,
       data_inicio, data_fim, status, status_aprovacao,
       id_user_criador, dt_ultima_atualizacao)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Não Iniciado', 'pendente', ?, NOW())
  ");
  $stIns->execute([
    $idKr, $idObj, $num, $desc, $base, $meta,
    $unidade ?: null, $direcao, $natureza ?: null, $tipoKr ?: null,
    $freqMilestone ?: null, $responsavel, $margem,
    $dtInicio ?: null, $dtFim ?: null, $uid,
  ]);

  // Auto-generate milestones
  $milestonesCount = 0;
  if ($autoMilestones && $dtInicio !== '' && $dtFim !== '' && $freqMilestone !== '') {
    api_load_helper('auth/helpers/kr_helpers.php');
    if (function_exists('gerarMilestonesParaKR')) {
      $milestonesCount = gerarMilestonesParaKR(
        $pdo, 'milestones_kr', $idKr,
        $dtInicio, $dtFim, $freqMilestone,
        $base, $meta, $natureza, $direcao, $unidade
      );
    }
  }

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json([
  'ok'             => true,
  'id_kr'          => $idKr,
  'key_result_num' => $num,
  'milestones'     => $milestonesCount,
  'message'        => 'Key Result criado com sucesso.',
], 201);
