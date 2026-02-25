<?php
declare(strict_types=1);

require_once __DIR__ . '/../_core.php';

$in = api_input();

$email = strtolower(trim((string)($in['email'] ?? '')));
$pass  = (string)($in['password'] ?? '');

if ($email === '' || $pass === '') {
  api_error('E_INPUT', 'E-mail e senha são obrigatórios.', 400);
}

$pdo = api_db();

// Busca usuário + hash
$sql = "
  SELECT
    u.id_user,
    u.primeiro_nome,
    u.ultimo_nome,
    u.email_corporativo,
    u.id_company,
    u.empresa,
    c.senha_hash
  FROM usuarios u
  INNER JOIN usuarios_credenciais c ON c.id_user = u.id_user
  WHERE LOWER(u.email_corporativo) = :email
  LIMIT 1
";
$st = $pdo->prepare($sql);
$st->execute([':email' => $email]);
$user = $st->fetch();

if (!$user || empty($user['senha_hash']) || !password_verify($pass, (string)$user['senha_hash'])) {
  api_error('E_AUTH', 'E-mail ou senha incorretos.', 401);
}

$token = api_issue_token([
  'sub' => (int)$user['id_user'],
  'cid' => isset($user['id_company']) ? (int)$user['id_company'] : null,
]);

api_json([
  'ok' => true,
  'token' => $token,
  'user' => [
    'id_user' => (int)$user['id_user'],
    'primeiro_nome' => (string)$user['primeiro_nome'],
    'ultimo_nome' => (string)($user['ultimo_nome'] ?? ''),
    'email' => (string)$user['email_corporativo'],
    'id_company' => $user['id_company'],
    'empresa' => $user['empresa'],
  ],
]);
