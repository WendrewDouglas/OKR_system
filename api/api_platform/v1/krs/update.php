<?php
declare(strict_types=1);

/**
 * PUT /krs/:id
 * Atualiza um Key Result.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$idKr = api_param('id');
$pdo  = api_db();

// Verify + tenant
$st = $pdo->prepare("
  SELECT kr.id_kr, kr.baseline, kr.meta, kr.direcao_metrica, kr.natureza_kr,
         kr.unidade_medida, kr.margem_confianca, o.id_company
    FROM key_results kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
   WHERE kr.id_kr = ?
");
$st->execute([$idKr]);
$kr = $st->fetch();
if (!$kr || (int)$kr['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Key Result não encontrado.', 404);
}

if (!api_has_cap($pdo, $uid, $cid, 'W:kr@ORG', ['id_kr' => $idKr])) {
  api_error('E_FORBIDDEN', 'Sem permissão.', 403);
}

$in = api_input();

// Valida status contra o domínio (evita 500 do FK; devolve 422 limpo)
if (array_key_exists('status', $in)) {
  api_assert_domain($pdo, 'dom_status_kr', 'id_status', api_str($in['status']), 'status');
}

$sets   = [];
$params = [];

$strFields = ['descricao', 'status', 'unidade_medida', 'direcao_metrica',
              'natureza_kr', 'tipo_kr', 'tipo_frequencia_milestone', 'farol',
              'observacoes'];
foreach ($strFields as $f) {
  if (array_key_exists($f, $in)) {
    $sets[]   = "$f = ?";
    $params[] = api_str($in[$f]);
  }
}

$numFields = ['baseline', 'meta']; // margem_confianca tratada à parte (obrigatória p/ intervalo)
foreach ($numFields as $f) {
  if (array_key_exists($f, $in)) {
    $sets[]   = "$f = ?";
    $params[] = api_float_or_null($in[$f]);
  }
}

// margem_confianca: obrigatória p/ INTERVALO_IDEAL (default 10%).
$isIntervalo = array_key_exists('direcao_metrica', $in)
  && strtoupper(api_str($in['direcao_metrica'])) === 'INTERVALO_IDEAL';
if (array_key_exists('margem_confianca', $in) || $isIntervalo) {
  $m = api_float_or_null($in['margem_confianca'] ?? null);
  if ($isIntervalo && ($m === null || $m <= 0)) {
    $m = 10.0;
  }
  $sets[]   = "margem_confianca = ?";
  $params[] = $m;
}

if (array_key_exists('responsavel', $in)) {
  $sets[]   = "responsavel = ?";
  $params[] = api_int_or_null($in['responsavel']);
}

$dateFields = ['data_inicio', 'data_fim', 'dt_novo_prazo'];
foreach ($dateFields as $f) {
  if (array_key_exists($f, $in)) {
    $sets[]   = "$f = ?";
    $params[] = api_date_or_null($in[$f]);
  }
}

if (empty($sets)) {
  api_error('E_INPUT', 'Nenhum campo para atualizar.', 422);
}

// ==== Coerência milestones × meta ====
// Se baseline/meta mudarem, a série de milestones deixa de fechar na meta e o
// progresso/farol passariam a medir contra o valor errado. Ajusta a série na
// mesma operação (default: reescalar; aceita estrategia_ajuste = 'residuo').
api_load_helper('auth/helpers/kr_milestones.php');

$newBaseline = array_key_exists('baseline', $in) ? api_float_or_null($in['baseline']) : (float)$kr['baseline'];
$newMeta     = array_key_exists('meta', $in)     ? api_float_or_null($in['meta'])     : (float)$kr['meta'];
$newDirecao  = array_key_exists('direcao_metrica', $in) ? api_str($in['direcao_metrica']) : (string)$kr['direcao_metrica'];

$metaOuBaseMudou = (abs(((float)$newBaseline) - (float)$kr['baseline']) > 1e-9)
                || (abs(((float)$newMeta) - (float)$kr['meta']) > 1e-9);

// Mudança de TIPO de direção (intervalo ↔ valor único) reestrutura a série
// (min/max vs valor) e apaga apontamentos — só pelo fluxo web, que avisa.
if (krp_is_intervalo($kr['direcao_metrica'] ?? null) !== krp_is_intervalo($newDirecao ?: null)) {
  $stCnt = $pdo->prepare("SELECT COUNT(*) FROM milestones_kr WHERE id_kr = ?");
  $stCnt->execute([$idKr]);
  if ((int)$stCnt->fetchColumn() > 0) {
    api_error('E_DIRECAO_ESTRUTURAL',
      'Alterar entre intervalo e valor único recria os milestones e apaga apontamentos. Faça essa alteração pela plataforma web.', 422);
  }
}

$msAjustados = 0;
$msSerieAjustada = null;
if ($metaOuBaseMudou) {
  $stMs = $pdo->prepare("
    SELECT id_milestone, num_ordem, data_ref, valor_esperado,
           valor_esperado_min, valor_esperado_max
      FROM milestones_kr
     WHERE id_kr = ?
     ORDER BY num_ordem
  ");
  $stMs->execute([$idKr]);
  $msRows = $stMs->fetchAll();

  if ($msRows) {
    $krNovo = [
      'baseline'         => $newBaseline,
      'meta'             => $newMeta,
      'direcao_metrica'  => $newDirecao ?: null,
      'natureza_kr'      => array_key_exists('natureza_kr', $in) ? api_str($in['natureza_kr']) : $kr['natureza_kr'],
      'unidade_medida'   => array_key_exists('unidade_medida', $in) ? api_str($in['unidade_medida']) : $kr['unidade_medida'],
      'margem_confianca' => $kr['margem_confianca'],
    ];
    $isIntervalo = krp_is_intervalo($krNovo['direcao_metrica']);
    $estrategia  = strtolower(api_str($in['estrategia_ajuste'] ?? 'reescalar'));

    if ($isIntervalo) {
      $msSerieAjustada = krm_ajustar_faixa_final($krNovo, $msRows);
    } elseif ($estrategia === 'residuo') {
      $msSerieAjustada = krm_distribuir_residuo($krNovo, $msRows, date('Y-m-d'));
      if ($msSerieAjustada === null) {
        api_error('E_SEM_FUTUROS', 'Não há milestones futuros para distribuir o ajuste. Use estrategia_ajuste=reescalar.', 422);
      }
    } else {
      $msSerieAjustada = krm_reescalar($krNovo, $msRows);
    }
  }
}
// ==== [/coerência] ====

$sets[] = "dt_ultima_atualizacao = NOW()";
$params[] = $idKr;

$pdo->beginTransaction();
try {
  $pdo->prepare("UPDATE key_results SET " . implode(', ', $sets) . " WHERE id_kr = ?")->execute($params);

  if ($msSerieAjustada !== null) {
    $up = $pdo->prepare("
      UPDATE milestones_kr
         SET valor_esperado = :ve, valor_esperado_min = :vmin, valor_esperado_max = :vmax
       WHERE id_milestone = :idms AND id_kr = :idkr
       LIMIT 1
    ");
    foreach ($msSerieAjustada as $m) {
      $up->execute([
        ':ve'   => round((float)$m['valor_esperado'], 2),
        ':vmin' => $m['valor_esperado_min'] !== null ? round((float)$m['valor_esperado_min'], 2) : null,
        ':vmax' => $m['valor_esperado_max'] !== null ? round((float)$m['valor_esperado_max'], 2) : null,
        ':idms' => (int)$m['id_milestone'],
        ':idkr' => $idKr,
      ]);
      $msAjustados++;
    }
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  api_error('E_SERVER', 'Falha ao atualizar Key Result.', 500);
}

api_json(['ok' => true, 'message' => 'Key Result atualizado.', 'milestones_ajustados' => $msAjustados]);
