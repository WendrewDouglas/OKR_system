<?php
// /app/bootstrap/csrf.php
declare(strict_types=1);

/**
 * CSRF simples via sessão (procedural).
 * Use em formulários/POST.
 */

function csrf_token(): string {
  if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['_csrf_token'];
}

function csrf_validate_or_fail(?string $token): void {
  $sess = $_SESSION['_csrf_token'] ?? '';
  if (!$token || !$sess || !hash_equals((string)$sess, (string)$token)) {
    json_fail(403, 'Falha de segurança (CSRF inválido). Atualize a página e tente novamente.');
  }
}