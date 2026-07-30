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

// Rate limiting (anti brute-force / credential stuffing) — a API não tem captcha.
require_once dirname(__DIR__, 4) . '/auth/login_throttle.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (login_throttle_check($pdo, $ip, $email)['blocked']) {
  api_error('E_THROTTLE', 'Muitas tentativas. Aguarde alguns minutos e tente novamente.', 429);
}

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
  login_throttle_record($pdo, $ip, $email, false);
  api_error('E_AUTH', 'E-mail ou senha incorretos.', 401);
}
login_throttle_record($pdo, $ip, $email, true);

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
    'avatar_url' => api_avatar_url_for((int)$user['id_user']),
    'avatar_url_thumb' => api_avatar_thumb_for((int)$user['id_user']),
  ],
]);
