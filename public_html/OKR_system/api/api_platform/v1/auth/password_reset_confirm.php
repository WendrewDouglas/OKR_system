<?php
declare(strict_types=1);

/**
 * POST /auth/password_reset_confirm.php
 * Body:
 *  - form-urlencoded: s/v/p/p2 (ou selector/verifier/p/p2)
 *  - JSON: {"s":"...","v":"...","p":"...","p2":"..."} (ou selector/verifier)
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

function read_body_array(): array {
  $data = [];

  // JSON
  $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
  $raw = file_get_contents('php://input') ?: '';
  if ($raw !== '' && (str_contains($ct, 'application/json') || str_starts_with(ltrim($raw), '{'))) {
    $j = json_decode($raw, true);
    if (is_array($j)) $data = $j;
  }

  // merge POST
  if (!empty($_POST) && is_array($_POST)) {
    $data = array_merge($data, $_POST);
  }

  return $data;
}

function v(array $in, string $key): string {
  return trim((string)($in[$key] ?? ''));
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(405, ['ok' => false, 'message' => 'Método não permitido.']);
}

$in = read_body_array();

$selector = v($in, 'selector'); if ($selector === '') $selector = v($in, 's');
$verifier = v($in, 'verifier'); if ($verifier === '') $verifier = v($in, 'v');
$p  = v($in, 'p');
$p2 = v($in, 'p2');

if ($selector === '' || $verifier === '' || $p === '' || $p2 === '') {
  json_out(400, ['ok' => false, 'message' => 'Dados inválidos.']);
}
if ($p !== $p2) {
  json_out(400, ['ok' => false, 'message' => 'As senhas não conferem.']);
}

// política (se existir no functions.php)
if (function_exists('passwordPolicyCheck')) {
  $pol = passwordPolicyCheck($p);
  if (is_array($pol) && ($pol['ok'] ?? false) !== true) {
    json_out(400, ['ok' => false, 'message' => (string)($pol['msg'] ?? 'Senha inválida.')]);
  }
} else {
  if (mb_strlen($p) < 8) {
    json_out(400, ['ok' => false, 'message' => 'A senha deve ter pelo menos 8 caracteres.']);
  }
}

$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

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

  $pdo->beginTransaction();

  // lock do reset + expiração no MySQL (NOW)
  $st = $pdo->prepare("
    SELECT
      id_reset, user_id, expira_em, used_at, selector, verifier_hash,
      (expira_em <= NOW()) AS is_expired
    FROM usuarios_password_resets
    WHERE selector = ?
    LIMIT 1
    FOR UPDATE
  ");
  $st->execute([$selector]);
  $row = $st->fetch();

  if (!$row) {
    $pdo->rollBack();
    json_out(404, ['ok' => false, 'message' => 'Link inválido ou expirado.']);
  }

  if (!empty($row['used_at'])) {
    $pdo->rollBack();
    json_out(410, ['ok' => false, 'message' => 'Este link já foi utilizado.']);
  }

  if ((int)$row['is_expired'] === 1) {
    $pdo->rollBack();
    json_out(410, ['ok' => false, 'message' => 'Este link expirou. Solicite um novo.']);
  }

  $expectedHash = function_exists('hashVerifier')
    ? hashVerifier($verifier)
    : hash('sha256', (defined('APP_TOKEN_PEPPER') ? APP_TOKEN_PEPPER : '') . $verifier);

  if (!hash_equals((string)$row['verifier_hash'], (string)$expectedHash)) {
    $pdo->rollBack();
    json_out(404, ['ok' => false, 'message' => 'Link inválido ou expirado.']);
  }

  $userId = (int)$row['user_id'];

  // hash de senha (se existir helper melhor)
  $hash = function_exists('bestPasswordHash')
    ? bestPasswordHash($p)
    : password_hash($p, PASSWORD_DEFAULT);

  // upsert em usuarios_credenciais
  $chk = $pdo->prepare("SELECT id_user FROM usuarios_credenciais WHERE id_user = ? LIMIT 1");
  $chk->execute([$userId]);
  $exists = (bool)$chk->fetchColumn();

  if ($exists) {
    $up = $pdo->prepare("UPDATE usuarios_credenciais SET senha_hash = ? WHERE id_user = ? LIMIT 1");
    $up->execute([$hash, $userId]);
  } else {
    $ins = $pdo->prepare("INSERT INTO usuarios_credenciais (id_user, senha_hash) VALUES (?, ?)");
    $ins->execute([$userId, $hash]);
  }

  // marca reset como usado (NOW)
  try {
    // tenta com ip_use/user_agent_use (se existirem)
    $mark = $pdo->prepare("
      UPDATE usuarios_password_resets
      SET used_at = NOW(), ip_use = ?, user_agent_use = ?
      WHERE id_reset = ?
      LIMIT 1
    ");
    $mark->execute([$ip, $ua, (int)$row['id_reset']]);
  } catch (Throwable $e) {
    // fallback (se as colunas não existirem)
    $mark = $pdo->prepare("
      UPDATE usuarios_password_resets
      SET used_at = NOW()
      WHERE id_reset = ?
      LIMIT 1
    ");
    $mark->execute([(int)$row['id_reset']]);
  }

  $pdo->commit();

  json_out(200, ['ok' => true, 'message' => 'Senha redefinida com sucesso.']);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  json_out(500, ['ok' => false, 'message' => 'Falha interna ao redefinir senha.']);
}
