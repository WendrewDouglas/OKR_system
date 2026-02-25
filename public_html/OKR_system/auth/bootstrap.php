<?php
declare(strict_types=1);

@ini_set('session.cookie_samesite', 'Lax');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/guards.php';

// garante csrf (se você usa)
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}