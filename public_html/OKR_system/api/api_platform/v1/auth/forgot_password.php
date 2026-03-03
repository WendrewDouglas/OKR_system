<?php
declare(strict_types=1);

/**
 * POST /auth/forgot-password
 * Gera token de reset e envia e-mail.
 */

$in = api_input();
api_require_fields($in, ['email']);
$email = strtolower(api_str($in['email']));

$pdo = api_db();
$st = $pdo->prepare("SELECT id_user, primeiro_nome FROM usuarios WHERE email_corporativo = ? LIMIT 1");
$st->execute([$email]);
$user = $st->fetch();

// Sempre retorna sucesso (não vazar se e-mail existe)
if (!$user) {
  api_json(['ok' => true, 'message' => 'Se o e-mail existir, enviaremos instruções de recuperação.']);
}

$userId = (int)$user['id_user'];

// Rate limiting: max 3 resets per user in 15 min
$stLim = $pdo->prepare("
  SELECT COUNT(*) FROM usuarios_password_resets
   WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
");
$stLim->execute([$userId]);
if ((int)$stLim->fetchColumn() >= 3) {
  api_json(['ok' => true, 'message' => 'Se o e-mail existir, enviaremos instruções de recuperação.']);
}

// Generate selector + verifier (split-token pattern)
$selector = bin2hex(random_bytes(8));
$verifier = bin2hex(random_bytes(32));
$verifierHash = hash('sha256', $verifier);

$stIns = $pdo->prepare("
  INSERT INTO usuarios_password_resets (user_id, selector, verifier_hash, expira_em, created_at, ip_request, user_agent_request)
  VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW(), ?, ?)
");
$stIns->execute([
  $userId,
  $selector,
  $verifierHash,
  $_SERVER['REMOTE_ADDR'] ?? '',
  substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
]);

// Build reset URL (app deep link)
$resetToken = $selector . ':' . $verifier;

// Tenta enviar e-mail se mailer disponível
$ROOT = dirname(__DIR__, 3);
$functionsFile = $ROOT . '/auth/functions.php';
if (is_file($functionsFile)) {
  try {
    require_once $functionsFile;
    if (function_exists('sendPasswordResetEmail')) {
      sendPasswordResetEmail($email, $selector, $verifier);
    }
  } catch (\Throwable $e) {
    api_log('Erro ao enviar email de reset: ' . $e->getMessage());
  }
}

api_json([
  'ok'      => true,
  'message' => 'Se o e-mail existir, enviaremos instruções de recuperação.',
  // Em dev, expor token para teste
  ...((string)getenv('APP_DEBUG') === '1' ? ['debug_token' => $resetToken] : []),
]);
