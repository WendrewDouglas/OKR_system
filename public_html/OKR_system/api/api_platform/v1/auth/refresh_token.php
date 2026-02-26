<?php
declare(strict_types=1);

/**
 * POST /auth/refresh-token
 * Renova o token JWT se ainda válido.
 */

$auth = api_require_auth();
$uid  = (int)($auth['sub'] ?? 0);
$cid  = (int)($auth['cid'] ?? 0);

if ($uid <= 0) {
  api_error('E_AUTH', 'Token inválido (sub ausente).', 401);
}

// Verify user still exists
$pdo = api_db();
$st = $pdo->prepare("SELECT id_user, id_company FROM usuarios WHERE id_user = ? LIMIT 1");
$st->execute([$uid]);
$user = $st->fetch();

if (!$user) {
  api_error('E_AUTH', 'Usuário não encontrado.', 401);
}

$token = api_issue_token([
  'sub' => (int)$user['id_user'],
  'cid' => (int)($user['id_company'] ?? $cid),
]);

api_json([
  'ok'    => true,
  'token' => $token,
]);
