<?php
declare(strict_types=1);

function require_login(): void {
  if (empty($_SESSION['user_id'])) {
    header('Location: /OKR_system/views/login.php');
    exit;
  }

  // Session timeout: 30 minutos de inatividade
  $timeout = 1800;
  if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header('Location: /OKR_system/views/login.php?expired=1');
    exit;
  }
  $_SESSION['last_activity'] = time();
}

function csrf_token(): string {
  return (string)($_SESSION['csrf_token'] ?? '');
}

function require_csrf(string $token): void {
  if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    exit('CSRF inválido.');
  }
}