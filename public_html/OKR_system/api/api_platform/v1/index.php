<?php
declare(strict_types=1);

require_once __DIR__ . '/_core.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'OPTIONS') {
  api_cors_headers();
  api_no_cache();
  http_response_code(204);
  exit;
}

// Descobre o path relativo ao /v1
$path = (string)($_GET['path'] ?? '');
if ($path === '') {
  $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
  $uri  = strtok($uri, '?') ?: '/';

  $base = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/'); // .../v1
  if ($base !== '' && str_starts_with($uri, $base)) {
    $path = substr($uri, strlen($base));
  } else {
    $path = $uri;
  }
  $path = trim($path, '/');
} else {
  $path = trim($path, '/');
}

// DEBUG: qualquer método
if ($path === 'debug/echo') {
  api_echo_debug();
}

// Ping
if ($method === 'GET' && $path === 'ping') {
  api_json([
    'ok' => true,
    'api' => 'planningbi-okr',
    'version' => 'v1',
    'time' => date('c'),
  ]);
}

// Login
if ($method === 'POST' && $path === 'auth/login') {
  require __DIR__ . '/auth/login.php';
  exit;
}

if ($method === 'GET' && $path === 'auth/me') {
  require __DIR__ . '/auth/me.php';
  exit;
}

if ($method === 'GET' && $path === 'company/me') {
  require __DIR__ . '/company/me.php';
  exit;
}

if ($method === 'GET' && $path === 'dashboard/summary') {
  require __DIR__ . '/dashboard/summary.php';
  exit;
}

api_error('E_NOT_FOUND', 'Rota não encontrada.', 404, ['path' => $path, 'method' => $method]);
