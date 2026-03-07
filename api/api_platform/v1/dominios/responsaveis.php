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
  SELECT id_user, primeiro_nome, ultimo_nome, email_corporativo
    FROM usuarios
   WHERE id_company = ?
   ORDER BY primeiro_nome, ultimo_nome
");
$st->execute([$cid]);

$items = array_map(fn($r) => [
  'id_user'       => (int)$r['id_user'],
  'primeiro_nome' => $r['primeiro_nome'],
  'ultimo_nome'   => $r['ultimo_nome'] ?? '',
  'nome_completo' => trim(($r['primeiro_nome'] ?? '') . ' ' . ($r['ultimo_nome'] ?? '')),
  'email'         => $r['email_corporativo'],
], $st->fetchAll());

api_json(['ok' => true, 'items' => $items]);
