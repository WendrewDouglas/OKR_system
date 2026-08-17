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
// Mesmas duas guardas do views/password_reset_request.php — este caminho estava
// sem nenhuma das duas:
//  1) conta inativa (desligamento) não pede reset;
//  2) lead vindo de formulário público (LP/perspectivas) sem credencial não é
//     elegível, senão qualquer um transformaria um lead em conta autenticada.
$st = $pdo->prepare(
  "SELECT u.id_user, u.primeiro_nome
     FROM usuarios u
     LEFT JOIN usuarios_credenciais c ON c.id_user = u.id_user
    WHERE LOWER(TRIM(u.email_corporativo)) = LOWER(TRIM(?))
      AND u.ativo = 1
      AND NOT (u.origem_cadastro = 'form_perspectivas' AND c.id_user IS NULL)
    LIMIT 1"
);
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
// selector 16 bytes (32 hex) + verifier 32 bytes (64 hex) — matches generateSelectorVerifier()
$selector = bin2hex(random_bytes(16));
$verifier = bin2hex(random_bytes(32));

// Load functions.php early so we can use hashVerifier() (peppered hash)
$ROOT = dirname(__DIR__, 4);
$functionsFile = $ROOT . '/auth/functions.php';
if (is_file($functionsFile)) {
  require_once $functionsFile;
}
$verifierHash = function_exists('hashVerifier')
  ? hashVerifier($verifier)
  : hash('sha256', (defined('APP_TOKEN_PEPPER') ? APP_TOKEN_PEPPER : '') . $verifier);

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

// Tenta enviar e-mail (functions.php já carregado acima)
if (function_exists('sendPasswordResetEmail')) {
  try {
    sendPasswordResetEmail($email, $selector, $verifier);
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
