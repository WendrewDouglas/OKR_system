<?php
declare(strict_types=1);

/**
 * GET /responsaveis
 * Lista usuários da mesma empresa (para pickers de responsável).
 */

$auth = api_require_auth();
$cid  = (int)($auth['cid'] ?? 0);
if ($cid <= 0) api_error('E_AUTH', 'Company inválida.', 401);

$pdo = api_db();
$st = $pdo->prepare("
  SELECT u.id_user, u.primeiro_nome, u.ultimo_nome, u.email_corporativo,
         a.path AS avatar_path, a.filename AS avatar_filename
    FROM usuarios u
    LEFT JOIN avatars a ON a.id = u.avatar_id
   WHERE u.id_company = ?
   ORDER BY u.primeiro_nome, u.ultimo_nome
");
$st->execute([$cid]);

$items = array_map(fn($r) => [
  'id_user'       => (int)$r['id_user'],
  'primeiro_nome' => $r['primeiro_nome'],
  'ultimo_nome'   => $r['ultimo_nome'] ?? '',
  'nome_completo' => trim(($r['primeiro_nome'] ?? '') . ' ' . ($r['ultimo_nome'] ?? '')),
  'email'         => $r['email_corporativo'],
  'avatar_url'    => api_avatar_url_from_row(['path' => $r['avatar_path'] ?? null, 'filename' => $r['avatar_filename'] ?? null]),
], $st->fetchAll());

api_json(['ok' => true, 'items' => $items]);
