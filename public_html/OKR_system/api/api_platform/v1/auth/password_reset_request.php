<?php
declare(strict_types=1);

/**
 * POST /auth/password_reset_request.php
 * Body:
 *  - form-urlencoded: email=... (ou email_corporativo=...)
 *  - JSON: {"email":"..."} (ou {"email_corporativo":"..."})
 *
 * Resposta SEMPRE genérica (anti-enumeração):
 *  {"ok":true,"message":"Se este e-mail estiver cadastrado, você receberá instruções para redefinir a senha."}
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

ini_set('display_errors', '0');
error_reporting(E_ALL);

function json_out(int $status, array $payload): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function generic_ok(): void {
  json_out(200, [
    'ok' => true,
    'message' => 'Se este e-mail estiver cadastrado, você receberá instruções para redefinir a senha.',
  ]);
}

function require_first(array $paths): void {
  foreach ($paths as $p) {
    if (is_file($p)) { require_once $p; return; }
  }
  // aqui pode retornar 500 mesmo (erro interno), mas sem detalhes
  json_out(500, ['ok' => false, 'message' => 'Configuração interna ausente.']);
}

function read_body_array(): array {
  $data = [];

  // JSON
  $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
  $raw = file_get_contents('php://input') ?: '';
  if ($raw !== '' && (str_contains($ct, 'application/json') || str_starts_with(ltrim($raw), '{'))) {
    $j = json_decode($raw, true);
    if (is_array($j)) $data = $j;
  }

  // merge POST (form-urlencoded/multipart)
  if (!empty($_POST) && is_array($_POST)) {
    $data = array_merge($data, $_POST);
  }

  return $data;
}

function read_input_email(array $in): string {
  $email = trim((string)($in['email'] ?? $in['email_corporativo'] ?? ''));
  if ($email === '') $email = trim((string)($_GET['email'] ?? '')); // fallback opcional
  return $email;
}

/* ===================== Bootstrap ===================== */
require_first([
  __DIR__ . '/../../../../auth/config.php',
  __DIR__ . '/../../../auth/config.php',
  __DIR__ . '/../../auth/config.php',
  __DIR__ . '/config.php',
]);

require_first([
  __DIR__ . '/../../../../auth/functions.php',
  __DIR__ . '/../../../auth/functions.php',
  __DIR__ . '/../../auth/functions.php',
  __DIR__ . '/functions.php',
]);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(405, ['ok' => false, 'message' => 'Método não permitido.']);
}

$input = read_body_array();
$email = read_input_email($input);

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  generic_ok(); // anti-enumeração
}

$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

/* ===================== DB ===================== */
try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]
  );
} catch (Throwable $e) {
  generic_ok();
}

/* ===================== Find user + create reset ===================== */
try {
  $st = $pdo->prepare("SELECT id_user FROM usuarios WHERE email_corporativo = ? LIMIT 1");
  $st->execute([$email]);
  $userId = (int)($st->fetchColumn() ?: 0);

  if ($userId <= 0) {
    generic_ok();
  }

  // rate limit (se existir)
  if (function_exists('rateLimitResetRequestOrFail')) {
    try {
      // assinatura do seu functions.php: (PDO $pdo, ?int $userId, string $ip)
      rateLimitResetRequestOrFail($pdo, $userId, $ip);
    } catch (Throwable $e) {
      generic_ok();
    }
  }

  $selector = '';
  $verifier = '';

  if (function_exists('createPasswordReset')) {
    // seu functions.php retorna 3 itens [selector, verifier, expira]; aqui pegamos os 2 primeiros
    [$selector, $verifier] = createPasswordReset($pdo, $userId, $ip, $ua);
  } else {
    // fallback local
    $selector = bin2hex(random_bytes(16));
    $verifier = bin2hex(random_bytes(32));

    $verifierHash = function_exists('hashVerifier')
      ? hashVerifier($verifier)
      : hash('sha256', (defined('APP_TOKEN_PEPPER') ? APP_TOKEN_PEPPER : '') . $verifier);

    $ins = $pdo->prepare("
      INSERT INTO usuarios_password_resets
        (user_id, selector, verifier_hash, created_at, expira_em, used_at, ip_request, user_agent_request)
      VALUES
        (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 60 MINUTE), NULL, ?, ?)
    ");
    $ins->execute([$userId, $selector, $verifierHash, $ip, $ua]);
  }

  // envia e-mail se existir
  if (function_exists('sendPasswordResetEmail')) {
    try { sendPasswordResetEmail($email, $selector, $verifier); } catch (Throwable $e) {}
  }

  generic_ok();
} catch (Throwable $e) {
  generic_ok();
}
