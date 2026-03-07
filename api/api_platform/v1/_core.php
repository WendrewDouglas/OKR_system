<?php
declare(strict_types=1);

/**
 * OKR_system/api/api_platform/v1/_core.php
 * Núcleo utilitário da API (CORS, JSON response, input parsing, DB, token, auth)
 */

/* ===================== PATHS / CONFIG ===================== */

// Raiz do OKR_system a partir de /api/api_platform/v1
$ROOT = dirname(__DIR__, 3); // .../OKR_system

// Carrega config do sistema (DB_HOST, DB_NAME, etc.)
require_once $ROOT . '/auth/config.php';

// Log dedicado da API
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error_log');


/* ===================== HEADERS / RESPONSE ===================== */

function api_cors_headers(): void {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
  header('Access-Control-Max-Age: 86400');
}

function api_no_cache(): void {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
}

function api_json(array $data, int $status = 200): void {
  if (!headers_sent()) {
    http_response_code($status);
    api_cors_headers();
    api_no_cache();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
  }
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function api_error(string $code, string $message, int $status = 400, array $extra = []): void {
  api_json(array_merge([
    'ok' => false,
    'error' => $code,
    'message' => $message,
  ], $extra), $status);
}

function api_log(string $msg): void {
  error_log('[api_platform/v1] ' . $msg);
}


/* ===================== INPUT (RAW + JSON + FORM) ===================== */

function api_strip_utf8_bom(string $s): string {
  if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
    return substr($s, 3);
  }
  return $s;
}

/**
 * Lê o body cru e normaliza:
 * - Remove UTF-8 BOM
 * - Detecta e converte UTF-16LE/BE para UTF-8 quando aplicável
 */
function api_raw_body(): string {
  static $raw = null;
  if ($raw !== null) return $raw;

  $tmp = file_get_contents('php://input');
  $raw = ($tmp === false) ? '' : $tmp;

  // 1) UTF-8 BOM (muito comum vindo do PowerShell Set-Content)
  $raw = api_strip_utf8_bom($raw);

  // 2) Se vier em UTF-16 (com \x00), tenta converter para UTF-8
  if ($raw !== '' && strpos($raw, "\x00") !== false) {
    $head2 = substr($raw, 0, 2);

    // "{\x00" ou "[\x00" -> UTF-16LE (provável)
    if ($head2 === "{\x00" || $head2 === "[\x00") {
      $conv = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
      if (is_string($conv) && $conv !== '') $raw = $conv;
    }

    // "\x00{" ou "\x00[" -> UTF-16BE (provável)
    if ($head2 === "\x00{" || $head2 === "\x00[") {
      $conv = @iconv('UTF-16BE', 'UTF-8//IGNORE', $raw);
      if (is_string($conv) && $conv !== '') $raw = $conv;
    }
  }

  return $raw;
}

/**
 * Lê body tolerante:
 * - JSON com ou sem charset
 * - Se não for JSON, ainda tenta decodificar se começar com { ou [
 * - Mescla com $_POST (form-urlencoded)
 */
function api_input(): array {
  static $cached = null;
  if (is_array($cached)) return $cached;

  $ct  = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
  $raw = api_raw_body();
  $data = [];

  $rawTrim   = ltrim($raw);
  $looksJson = ($rawTrim !== '' && ($rawTrim[0] === '{' || $rawTrim[0] === '['));
  $isJsonCt  = str_contains($ct, 'application/json');

  if (($isJsonCt || $looksJson) && $rawTrim !== '') {
    $decoded = json_decode($rawTrim, true);
    if (is_array($decoded)) $data = $decoded;
  }

  // Merge de POST (application/x-www-form-urlencoded ou multipart)
  if (!empty($_POST) && is_array($_POST)) {
    $data = array_merge($data, $_POST);
  }

  $cached = $data;
  return $cached;
}

function api_echo_debug(): void {
  $raw  = api_raw_body();
  $ct   = (string)($_SERVER['CONTENT_TYPE'] ?? '');
  $data = api_input();

  // não expor password
  if (isset($data['password'])) $data['password'] = '***';

  api_json([
    'ok' => true,
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'content_type' => $ct,
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    'raw_len' => strlen($raw),
    'raw_preview' => substr($raw, 0, 200),
    'post_keys' => array_keys($_POST ?? []),
    'data_keys' => array_keys($data),
    'data_preview' => $data,
  ]);
}


/* ===================== DB ===================== */

function api_db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
    throw new RuntimeException('Constantes de banco não carregadas (DB_HOST/DB_NAME/DB_USER).');
  }
  if ((string)DB_NAME === '' || (string)DB_USER === '') {
    throw new RuntimeException('DB_NAME/DB_USER vazios. Verifique o .env do OKR_system.');
  }

  $charset = defined('DB_CHARSET') ? (string)DB_CHARSET : 'utf8mb4';
  $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . $charset;

  $pdo = new PDO($dsn, DB_USER, DB_PASS ?? '', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);

  return $pdo;
}


/* ===================== TOKEN (JWT-lite) ===================== */

function b64url_encode(string $s): string {
  return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_decode(string $s): string {
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4 - $pad);
  return base64_decode(strtr($s, '-_', '+/')) ?: '';
}

function api_token_secret(): string {
  $pepper = (string)(defined('APP_TOKEN_PEPPER') ? APP_TOKEN_PEPPER : getenv('APP_TOKEN_PEPPER'));
  if ($pepper === '') $pepper = 'CHANGE_ME_APP_TOKEN_PEPPER';
  return $pepper;
}

function api_issue_token(array $payload, int $ttlSeconds = 86400): string {
  $now = time();

  $payload['iat'] = $payload['iat'] ?? $now;
  $payload['exp'] = $payload['exp'] ?? ($now + $ttlSeconds);
  $payload['iss'] = $payload['iss'] ?? 'planningbi-okr';
  $payload['ver'] = $payload['ver'] ?? 1;

  $p = b64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  $sig = hash_hmac('sha256', $p, api_token_secret(), true);

  return $p . '.' . b64url_encode($sig);
}

function api_get_bearer_token(): string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
  if (!$h) return '';
  if (stripos($h, 'Bearer ') !== 0) return '';
  return trim(substr($h, 7));
}

function api_verify_token(string $token): ?array {
  $parts = explode('.', $token);
  if (count($parts) !== 2) return null;

  [$p64, $s64] = $parts;

  $sig  = b64url_decode($s64);
  $calc = hash_hmac('sha256', $p64, api_token_secret(), true);
  if (!hash_equals($calc, $sig)) return null;

  $payloadJson = b64url_decode($p64);
  $payload = json_decode($payloadJson, true);
  if (!is_array($payload)) return null;

  if (isset($payload['exp']) && time() > (int)$payload['exp']) return null;

  return $payload;
}

function api_require_auth(): array {
  $t = api_get_bearer_token();
  if ($t === '') api_error('E_AUTH', 'Token ausente.', 401);

  $p = api_verify_token($t);
  if (!$p) api_error('E_AUTH', 'Token inválido ou expirado.', 401);

  return $p;
}


/* ===================== EXCEPTIONS ===================== */

/** Sempre devolver JSON em exceções */
set_exception_handler(function (Throwable $e) {
  api_log('EXCEPTION: ' . get_class($e) . ' | ' . $e->getMessage());
  api_log($e->getFile() . ':' . $e->getLine());

  $debug = ((string)getenv('APP_DEBUG') === '1');
  api_error('E_SERVER', 'Erro interno do servidor.', 500, $debug ? [
    'debug' => [
      'type' => get_class($e),
      'message' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine(),
    ]
  ] : []);
});


/* ===================== ROUTE PARAMS ===================== */

/** @var array<string,string> Populated by router */
$GLOBALS['_route_params'] = [];

function api_param(string $key): string {
  return (string)($GLOBALS['_route_params'][$key] ?? '');
}


/* ===================== VALIDATION HELPERS ===================== */

function api_require_fields(array $data, array $fields): void {
  $missing = [];
  foreach ($fields as $f) {
    if (!isset($data[$f]) || (is_string($data[$f]) && trim($data[$f]) === '')) {
      $missing[] = $f;
    }
  }
  if ($missing) {
    api_error('E_INPUT', 'Campos obrigatórios: ' . implode(', ', $missing) . '.', 422);
  }
}

function api_str(mixed $val): string {
  return trim((string)($val ?? ''));
}

function api_int(mixed $val, string $name = 'value'): int {
  if ($val === null || $val === '') {
    api_error('E_INPUT', "Campo '$name' é obrigatório.", 422);
  }
  if (!is_numeric($val)) {
    api_error('E_INPUT', "Campo '$name' deve ser numérico.", 422);
  }
  return (int)$val;
}

function api_int_or_null(mixed $val): ?int {
  if ($val === null || $val === '') return null;
  return is_numeric($val) ? (int)$val : null;
}

function api_float(mixed $val, string $name = 'value'): float {
  if ($val === null || $val === '') {
    api_error('E_INPUT', "Campo '$name' é obrigatório.", 422);
  }
  if (!is_numeric($val)) {
    api_error('E_INPUT', "Campo '$name' deve ser numérico.", 422);
  }
  return (float)$val;
}

function api_float_or_null(mixed $val): ?float {
  if ($val === null || $val === '') return null;
  return is_numeric($val) ? (float)$val : null;
}

function api_date(string $val, string $name = 'value'): string {
  $val = trim($val);
  if ($val === '') {
    api_error('E_INPUT', "Campo '$name' é obrigatório.", 422);
  }
  $d = \DateTime::createFromFormat('Y-m-d', $val);
  if (!$d || $d->format('Y-m-d') !== $val) {
    api_error('E_INPUT', "Campo '$name' deve estar no formato YYYY-MM-DD.", 422);
  }
  return $val;
}

function api_date_or_null(mixed $val): ?string {
  if ($val === null || trim((string)$val) === '') return null;
  $v = trim((string)$val);
  $d = \DateTime::createFromFormat('Y-m-d', $v);
  return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

function api_enum(string $val, array $allowed, string $name = 'value'): string {
  $val = trim($val);
  if (!in_array($val, $allowed, true)) {
    api_error('E_INPUT', "Campo '$name' deve ser um de: " . implode(', ', $allowed) . '.', 422);
  }
  return $val;
}


/* ===================== PAGINATION ===================== */

function api_pagination_params(): array {
  $page    = max(1, (int)($_GET['page'] ?? 1));
  $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
  return [$page, $perPage];
}

function api_paginated(PDO $pdo, string $dataSql, string $countSql, array $params, int $page, int $perPage): array {
  $stC = $pdo->prepare($countSql);
  $stC->execute($params);
  $total = (int)$stC->fetchColumn();

  $offset = ($page - 1) * $perPage;
  $dataSql .= " LIMIT $perPage OFFSET $offset";

  $stD = $pdo->prepare($dataSql);
  $stD->execute($params);
  $rows = $stD->fetchAll();

  return [
    'items'    => $rows,
    'page'     => $page,
    'per_page' => $perPage,
    'total'    => $total,
    'pages'    => (int)ceil($total / $perPage),
  ];
}


/* ===================== HELPERS ===================== */

function api_method(): string {
  return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

/** Loads business-logic helpers from auth/ */
function api_load_helper(string $relativePath): void {
  $ROOT = dirname(__DIR__, 3);
  $file = $ROOT . '/' . ltrim($relativePath, '/');
  if (!is_file($file)) {
    api_error('E_SERVER', "Helper não encontrado: $relativePath", 500);
  }
  require_once $file;
}
