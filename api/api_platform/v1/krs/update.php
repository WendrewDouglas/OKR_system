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
  SELECT kr.id_kr, o.id_company FROM key_results kr
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
$sets   = [];
$params = [];

$strFields = ['descricao', 'status', 'unidade_medida', 'direcao_metrica',
              'natureza_kr', 'tipo_kr', 'tipo_frequencia_milestone', 'farol'];
foreach ($strFields as $f) {
  if (array_key_exists($f, $in)) {
    $sets[]   = "$f = ?";
    $params[] = api_str($in[$f]);
  }
}

$numFields = ['baseline', 'meta', 'margem_confianca'];
foreach ($numFields as $f) {
  if (array_key_exists($f, $in)) {
    $sets[]   = "$f = ?";
    $params[] = api_float_or_null($in[$f]);
  }
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

$sets[] = "dt_ultima_atualizacao = NOW()";
$params[] = $idKr;

$pdo->prepare("UPDATE key_results SET " . implode(', ', $sets) . " WHERE id_kr = ?")->execute($params);

api_json(['ok' => true, 'message' => 'Key Result atualizado.']);
