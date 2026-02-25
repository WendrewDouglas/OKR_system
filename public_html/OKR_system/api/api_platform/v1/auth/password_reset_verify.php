<?php
declare(strict_types=1);

/**
 * GET /auth/password_reset_verify.php
 * Query:
 *  - selector/verifier
 *  - ou s/v (compat)
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

function require_first(array $paths): void {
  foreach ($paths as $p) {
    if (is_file($p)) { require_once $p; return; }
  }
  json_out(500, ['ok' => false, 'message' => 'Configuração interna ausente.']);
}

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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  json_out(405, ['ok' => false, 'message' => 'Método não permitido.']);
}

$selector = trim((string)($_GET['selector'] ?? $_GET['s'] ?? ''));
$verifier = trim((string)($_GET['verifier'] ?? $_GET['v'] ?? ''));

if ($selector === '' || $verifier === '') {
  json_out(404, ['ok' => false, 'error' => 'E_INVALID', 'message' => 'Link inválido ou expirado.']);
}

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

  $st = $pdo->prepare("
    SELECT
      id_reset, user_id, expira_em, used_at, selector, verifier_hash,
      (expira_em <= NOW()) AS is_expired
    FROM usuarios_password_resets
    WHERE selector = ?
    LIMIT 1
  ");
  $st->execute([$selector]);
  $row = $st->fetch();

  if (!$row) {
    json_out(404, ['ok' => false, 'error' => 'E_INVALID', 'message' => 'Link inválido ou expirado.']);
  }

  if (!empty($row['used_at'])) {
    json_out(410, ['ok' => false, 'message' => 'Este link já foi utilizado.']);
  }

  if ((int)$row['is_expired'] === 1) {
    json_out(410, ['ok' => false, 'message' => 'Este link expirou. Solicite um novo.']);
  }

  $expectedHash = function_exists('hashVerifier')
    ? hashVerifier($verifier)
    : hash('sha256', (defined('APP_TOKEN_PEPPER') ? APP_TOKEN_PEPPER : '') . $verifier);

  if (!hash_equals((string)$row['verifier_hash'], (string)$expectedHash)) {
    json_out(404, ['ok' => false, 'error' => 'E_INVALID', 'message' => 'Link inválido ou expirado.']);
  }

  json_out(200, [
    'ok' => true,
    'reset' => [
      'id_reset' => (int)$row['id_reset'],
      'user_id' => (int)$row['user_id'],
      'expira_em' => (string)$row['expira_em'],
    ],
  ]);
} catch (Throwable $e) {
  json_out(500, ['ok' => false, 'message' => 'Falha interna ao validar link.']);
}
