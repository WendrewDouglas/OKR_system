<?php
declare(strict_types=1);

// Cookie de sessão endurecido (centralizado — antes só o login setava isto).
@ini_set('session.use_strict_mode', '1');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { @ini_set('session.cookie_secure', '1'); }
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/guards.php';

// garante csrf (se você usa)
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}