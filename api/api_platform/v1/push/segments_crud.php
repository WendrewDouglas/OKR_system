<?php
// GET/POST /push/segments, GET/PUT/DELETE /push/segments/:id
declare(strict_types=1);
$auth = api_require_auth();
$pdo = api_db();
$uid = (int)$auth['sub'];
if (!api_is_admin_master($pdo, $uid)) api_error('E_FORBIDDEN', 'Acesso restrito.', 403);

$method = api_method();
$id = api_param('id') ? api_int(api_param('id'), 'id') : null;

if ($method === 'GET' && !$id) {
  // List
  $rows = $pdo->query("SELECT * FROM push_segments ORDER BY nome")->fetchAll();
  api_json(['ok' => true, 'items' => $rows]);
}

if ($method === 'GET' && $id) {
  $st = $pdo->prepare("SELECT * FROM push_segments WHERE id_segment=?");
  $st->execute([$id]);
  $row = $st->fetch();
  if (!$row) api_error('E_NOT_FOUND', 'Segmento nao encontrado.', 404);
  api_json(['ok' => true, 'segment' => $row]);
}

if ($method === 'POST') {
  $in = api_input();
  $nome = api_str($in['nome'] ?? '');
  $filtersJson = $in['filters_json'] ?? '{}';
  $desc = api_str($in['descricao'] ?? '');
  if (!$nome) api_error('E_INPUT', 'Nome obrigatorio.', 422);
  $pdo->prepare("INSERT INTO push_segments (nome, descricao, filters_json, created_by) VALUES (?,?,?,?)")
    ->execute([$nome, $desc, $filtersJson, $uid]);
  api_json(['ok' => true, 'id_segment' => (int)$pdo->lastInsertId()]);
}

if ($method === 'PUT' && $id) {
  $in = api_input();
  $nome = api_str($in['nome'] ?? '');
  $filtersJson = $in['filters_json'] ?? null;
  $desc = $in['descricao'] ?? null;
  $sets = []; $params = [];
  if ($nome) { $sets[] = 'nome=?'; $params[] = $nome; }
  if ($filtersJson !== null) { $sets[] = 'filters_json=?'; $params[] = $filtersJson; }
  if ($desc !== null) { $sets[] = 'descricao=?'; $params[] = $desc; }
  if (empty($sets)) api_error('E_INPUT', 'Nada para atualizar.', 422);
  $params[] = $id;
  $pdo->prepare("UPDATE push_segments SET " . implode(',', $sets) . " WHERE id_segment=?")->execute($params);
  api_json(['ok' => true]);
}

if ($method === 'DELETE' && $id) {
  $pdo->prepare("DELETE FROM push_segments WHERE id_segment=?")->execute([$id]);
  api_json(['ok' => true]);
}

api_error('E_METHOD', 'Metodo nao suportado.', 405);
