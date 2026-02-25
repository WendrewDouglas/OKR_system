<?php
declare(strict_types=1);

// Sempre responder em JSON
header('Content-Type: application/json; charset=utf-8');

// ===== Config/env (corrigido) =====
// Este arquivo (ex.: lead_start.php) está em: OKR_system/LP/Quizz-01/auth/
// Precisamos voltar 3 níveis para chegar em OKR_system e então apontar para auth/config.php
$root       = dirname(__DIR__, 3);               // .../OKR_system
$configPath = $root . '/auth/config.php';

if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'server_error',
        'message' => 'Arquivo de configuração não encontrado: ' . $configPath,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $configPath;

// ===== Helpers comuns =====
/**
 * Retorna instância singleton de PDO com opções do config.
 */
function pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
        http_response_code(500);
        echo json_encode([
            'error'   => 'server_error',
            'message' => 'Constantes de conexão (DB_HOST/DB_NAME/DB_USER/DB_PASS) não definidas no config.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');

    // $options pode vir do config.php; garante defaults seguros se não vier.
    $defaultOptions = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    /** @var array|null $options */
    $options = $GLOBALS['options'] ?? [];
    if (!is_array($options)) {
        $options = [];
    }
    $options = $options + $defaultOptions;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'error'   => 'db_connection_error',
            'message' => 'Falha ao conectar no banco: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Converte REMOTE_ADDR para binário (IPv4/IPv6) ou null.
 */
function ip_bin(): ?string {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $bin = @inet_pton($ip);
    return ($bin === false) ? null : $bin;
}

/**
 * Lê JSON do corpo da requisição e retorna array (ou array vazio).
 */
function json_input(): array {
    $raw = file_get_contents('php://input');
    $j   = json_decode($raw ?: '[]', true);
    return is_array($j) ? $j : [];
}

/**
 * Resposta OK em JSON e encerra.
 */
function ok($data = []): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Resposta de erro em JSON (HTTP $code) e encerra.
 */
function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
