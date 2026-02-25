<?php
declare(strict_types=1);

/**
 * OKR_system/api/api_platform/v1/index.php
 * Front Controller / Router da API v1
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function api_get_origin(): string {
    return $_SERVER['HTTP_ORIGIN'] ?? '';
}

function api_set_cors(): void {
    // Se quiser travar, crie no .env: CORS_ALLOWED_ORIGINS="https://seu-dominio.com,http://localhost:5173"
    // Se não existir, liberamos "*" (não usa cookie, então OK para app mobile e dev).
    $origin = api_get_origin();

    $allowed = getenv('CORS_ALLOWED_ORIGINS');
    if ($allowed && trim($allowed) !== '') {
        $list = array_map('trim', explode(',', $allowed));
        if ($origin !== '' && in_array($origin, $list, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
    } else {
        header('Access-Control-Allow-Origin: *');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 600');
}

function api_json(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

api_set_cors();

// Preflight (CORS)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Descobre path (funciona com .htaccess ou querystring)
$path = $_GET['path'] ?? $_GET['r'] ?? '';
if ($path === '') {
    $uri = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
    // tenta extrair tudo após "/api/api_platform/v1/"
    $marker = '/api/api_platform/v1/';
    $pos = strpos($uri, $marker);
    if ($pos !== false) {
        $path = substr($uri, $pos + strlen($marker));
    }
}

$path = trim($path, '/');

// Health
if ($path === '' || $path === 'ping' || $path === 'health') {
    api_json(200, [
        'ok' => true,
        'api' => 'planningbi-okr',
        'version' => 'v1',
        'time' => date('c'),
    ]);
}

// Rotas v1
switch ($path) {

    // Auth
    case 'auth/login':
        require __DIR__ . '/auth/login.php';
        exit;

    default:
        api_json(404, [
            'ok' => false,
            'error' => 'NOT_FOUND',
            'message' => 'Endpoint não encontrado.',
            'path' => $path,
        ]);
}
