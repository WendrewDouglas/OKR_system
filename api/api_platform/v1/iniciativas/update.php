<?php
declare(strict_types=1);

/**
 * PUT /iniciativas/:id
 * Atualiza uma iniciativa.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_param('id');
$pdo  = api_db();

// Tenant
$st = $pdo->prepare("
  SELECT i.id_iniciativa, i.id_kr, o.id_company
    FROM iniciativas i
    JOIN key_results kr ON kr.id_kr = i.id_kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
   WHERE i.id_iniciativa = ?
");
$st->execute([$id]);
$ini = $st->fetch();
if (!$ini || (int)$ini['id_company'] !== $cid) {
  api_error('E_NOT_FOUND', 'Iniciativa não encontrada.', 404);
}

$in = api_input();
$sets   = [];
$params = [];

$strFields = ['descricao', 'status', 'observacoes'];
foreach ($strFields as $f) {
  if (array_key_exists($f, $in)) {
    $sets[]   = "$f = ?";
    $params[] = api_str($in[$f]);
  }
}

if (array_key_exists('dt_prazo', $in)) {
  $sets[]   = "dt_prazo = ?";
  $params[] = api_date_or_null($in['dt_prazo']);
}

if (array_key_exists('id_user_responsavel', $in)) {
  $newResp = api_int_or_null($in['id_user_responsavel']);
  $sets[]   = "id_user_responsavel = ?";
  $params[] = $newResp;
}

// Multi-responsável
if (isset($in['responsaveis'])) {
  $responsaveis = is_array($in['responsaveis']) ? $in['responsaveis'] : json_decode((string)$in['responsaveis'], true);
  if (is_array($responsaveis)) {
    $responsaveis = array_map('intval', $responsaveis);
    // Sync junction table
    $pdo->prepare("DELETE FROM iniciativas_envolvidos WHERE id_iniciativa = ?")->execute([$id]);
    $stIns = $pdo->prepare("INSERT INTO iniciativas_envolvidos (id_iniciativa, id_user, dt_inclusao) VALUES (?, ?, NOW())");
    foreach ($responsaveis as $rId) {
      $stIns->execute([$id, $rId]);
    }
    // Denormalize
    if (!empty($responsaveis) && !array_key_exists('id_user_responsavel', $in)) {
      $sets[]   = "id_user_responsavel = ?";
      $params[] = $responsaveis[0];
    }
  }
}

if (empty($sets)) {
  api_error('E_INPUT', 'Nenhum campo para atualizar.', 422);
}

$sets[] = "id_user_ult_alteracao = ?";
$params[] = $uid;
$sets[] = "dt_ultima_atualizacao = NOW()";
$params[] = $id;

$pdo->prepare("UPDATE iniciativas SET " . implode(', ', $sets) . " WHERE id_iniciativa = ?")->execute($params);

api_json(['ok' => true, 'message' => 'Iniciativa atualizada.']);
