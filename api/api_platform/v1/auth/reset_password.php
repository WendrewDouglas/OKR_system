<?php
declare(strict_types=1);

/**
 * POST /auth/reset-password
 * Reseta a senha usando o token de recuperação.
 */

$in = api_input();
api_require_fields($in, ['token', 'password']);

$token   = api_str($in['token']);
$newPass = (string)($in['password'] ?? '');

if (strlen($newPass) < 8) {
  api_error('E_INPUT', 'Senha deve ter no mínimo 8 caracteres.', 422);
}

// Parse selector:verifier
$parts = explode(':', $token, 2);
if (count($parts) !== 2) {
  api_error('E_INPUT', 'Token de recuperação inválido.', 400);
}
[$selector, $verifier] = $parts;

$pdo = api_db();

$st = $pdo->prepare("
  SELECT id_reset, user_id, verifier_hash, expira_em
    FROM usuarios_password_resets
   WHERE selector = ?
     AND used_at IS NULL
   ORDER BY created_at DESC
   LIMIT 1
");
$st->execute([$selector]);
$row = $st->fetch();

if (!$row) {
  api_error('E_AUTH', 'Token de recuperação inválido ou já utilizado.', 400);
}

// Check expiry
if (strtotime($row['expira_em']) < time()) {
  api_error('E_AUTH', 'Token de recuperação expirado.', 400);
}

// Verify hash (peppered, matching hashVerifier() from functions.php)
$ROOT = dirname(__DIR__, 4);
$functionsFile = $ROOT . '/auth/functions.php';
if (is_file($functionsFile)) {
  require_once $functionsFile;
}
$calcHash = function_exists('hashVerifier')
  ? hashVerifier($verifier)
  : hash('sha256', (defined('APP_TOKEN_PEPPER') ? APP_TOKEN_PEPPER : '') . $verifier);
if (!hash_equals($row['verifier_hash'], $calcHash)) {
  api_error('E_AUTH', 'Token de recuperação inválido.', 400);
}

$userId = (int)$row['user_id'];
$hash   = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);

$pdo->beginTransaction();
try {
  // Update password
  $stUp = $pdo->prepare("UPDATE usuarios_credenciais SET senha_hash = ? WHERE id_user = ?");
  $stUp->execute([$hash, $userId]);

  // Mark token as used
  $stMark = $pdo->prepare("
    UPDATE usuarios_password_resets
       SET used_at = NOW(), ip_use = ?, user_agent_use = ?
     WHERE id_reset = ?
  ");
  $stMark->execute([
    $_SERVER['REMOTE_ADDR'] ?? '',
    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    $row['id_reset'],
  ]);

  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

api_json(['ok' => true, 'message' => 'Senha alterada com sucesso.']);
