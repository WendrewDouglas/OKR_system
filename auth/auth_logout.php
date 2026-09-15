<?php
// auth/auth_logout.php — encerra a sessão (link "Sair" do menu do avatar em partials/header.php)
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', [
    'expires'  => time() - 42000,
    'path'     => $p['path'],
    'domain'   => $p['domain'],
    'secure'   => $p['secure'],
    'httponly' => $p['httponly'],
    'samesite' => $p['samesite'] ?: 'Lax',
  ]);
}

session_destroy();

// Evita que o "voltar" do navegador reexiba a última tela logada a partir do cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: /OKR_system/login?logout=1', true, 302);
exit;
