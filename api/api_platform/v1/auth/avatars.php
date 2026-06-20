<?php
declare(strict_types=1);

/**
 * GET /auth/avatars
 * Lista a galeria padrão (catálogo do web) para o app — mesma base do picker web.
 * Filtros opcionais: ?gender=masculino|feminino|todos & ?tag=... & ?q=...
 */

api_require_auth();
$pdo = api_db();

$gender = isset($_GET['gender']) ? strtolower(trim((string)$_GET['gender'])) : '';
$q      = isset($_GET['q'])      ? trim((string)$_GET['q'])      : '';
$tag    = isset($_GET['tag'])    ? trim((string)$_GET['tag'])    : '';

$where  = ["kind = 'default'", "active = 1", "path LIKE 'gallery/%'"];
$params = [];

if (in_array($gender, ['masculino', 'feminino', 'todos'], true)) {
  $where[]  = "(gender = ? OR gender = 'todos')";
  $params[] = $gender;
}
if ($q !== '') {
  $where[]  = "(filename LIKE ? OR path LIKE ?)";
  $params[] = "%$q%";
  $params[] = "%$q%";
}
if ($tag !== '') {
  $where[]  = "JSON_SEARCH(tags, 'one', ?) IS NOT NULL";
  $params[] = $tag;
}

$sql = "SELECT id, path, filename, format, gender, tags
          FROM avatars
         WHERE " . implode(' AND ', $where) . "
         ORDER BY id";
$st = $pdo->prepare($sql);
$st->execute($params);

$items = array_map(function ($r) {
  return [
    'id'     => (int)$r['id'],
    'url'    => api_avatar_url_from_row(['path' => $r['path'], 'filename' => $r['filename']]),
    'format' => $r['format'],
    'gender' => $r['gender'],
    'tags'   => $r['tags'] ? json_decode($r['tags'], true) : [],
  ];
}, $st->fetchAll());

api_json(['ok' => true, 'items' => $items]);
