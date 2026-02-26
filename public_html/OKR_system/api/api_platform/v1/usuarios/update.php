<?php
declare(strict_types=1);

/**
 * PUT /usuarios/:id
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);
$id   = api_int(api_param('id'), 'id');
$pdo  = api_db();

// Only admin or self
$isSelf = ($id === $uid);
if (!$isSelf && !api_is_admin($pdo, $uid)) {
  api_error('E_FORBIDDEN', 'Sem permissão.', 403);
}

$in = api_input();
$sets   = [];
$params = [];

$allowed = ['primeiro_nome', 'ultimo_nome', 'telefone'];
foreach ($allowed as $f) {
  if (array_key_exists($f, $in)) {
    $sets[]   = "$f = ?";
    $params[] = api_str($in[$f]);
  }
}

// Admin-only fields
if (api_is_admin($pdo, $uid) && !$isSelf) {
  if (array_key_exists('id_departamento', $in)) {
    $sets[]   = "id_departamento = ?";
    $params[] = api_int_or_null($in['id_departamento']);
  }
  if (array_key_exists('id_nivel_cargo', $in)) {
    $sets[]   = "id_nivel_cargo = ?";
    $params[] = api_int_or_null($in['id_nivel_cargo']);
  }
}

if (empty($sets)) {
  api_error('E_INPUT', 'Nenhum campo para atualizar.', 422);
}

$sets[] = "id_user_alteracao = ?";
$params[] = $uid;
$sets[] = "dt_alteracao = NOW()";
$params[] = $id;

$pdo->prepare("UPDATE usuarios SET " . implode(', ', $sets) . " WHERE id_user = ?")->execute($params);

api_json(['ok' => true, 'message' => 'Usuário atualizado.']);
