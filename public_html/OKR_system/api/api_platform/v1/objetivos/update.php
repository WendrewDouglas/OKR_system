<?php
declare(strict_types=1);

/**
 * PUT /objetivos/:id
 * Atualiza um objetivo existente.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_param('id');
$pdo  = api_db();

// Verify exists + tenant
$st = $pdo->prepare("SELECT id_objetivo, id_company FROM objetivos WHERE id_objetivo = ?");
$st->execute([$id]);
$obj = $st->fetch();
if (!$obj || (int)$obj['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Objetivo não encontrado.', 404);
}

if (!api_has_cap($pdo, $uid, $cid, 'W:objetivo@ORG', ['id_objetivo' => $id])) {
  api_error('E_FORBIDDEN', 'Sem permissão para editar este objetivo.', 403);
}

$in = api_input();
$sets   = [];
$params = [];

$fields = ['descricao', 'pilar_bsc', 'tipo', 'tipo_ciclo', 'observacoes', 'status', 'qualidade'];
foreach ($fields as $f) {
  if (array_key_exists($f, $in)) {
    $sets[]   = "$f = ?";
    $params[] = api_str($in[$f]);
  }
}

if (array_key_exists('dono', $in)) {
  $sets[]   = "dono = ?";
  $params[] = api_int($in['dono'], 'dono');
}

if (array_key_exists('dt_prazo', $in)) {
  $sets[]   = "dt_prazo = ?";
  $params[] = api_date_or_null($in['dt_prazo']);
}

if (empty($sets)) {
  api_error('E_INPUT', 'Nenhum campo para atualizar.', 422);
}

$sets[] = "dt_ultima_atualizacao = NOW()";
$params[] = $id;

$pdo->prepare("UPDATE objetivos SET " . implode(', ', $sets) . " WHERE id_objetivo = ?")->execute($params);

api_json(['ok' => true, 'message' => 'Objetivo atualizado.']);
