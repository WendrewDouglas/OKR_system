<?php
/**
 * PHPUnit Bootstrap — OKR System Tests
 *
 * Carrega autoload, .env, define constantes DB e funções auxiliares.
 */
declare(strict_types=1);

// 1) Autoload do Composer
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Execute 'composer install' antes de rodar os testes.\n");
    exit(1);
}
require_once $autoload;

// 2) Carrega .env via Dotenv (mesma estratégia do auth/config.php)
$projectRoot = dirname(__DIR__);
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();
}

// 3) Fallback loader (igual ao auth/config.php)
$envFile = $projectRoot . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || (isset($line[0]) && $line[0] === '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $name  = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if (($value[0] ?? '') === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
        } elseif (($value[0] ?? '') === "'" && substr($value, -1) === "'") {
            $value = substr($value, 1, -1);
        }
        if (getenv($name) === false) {
            putenv("$name=$value");
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// 4) Define constantes DB (se ainda não definidas pelo config.php)
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $v = getenv($key);
        return ($v === false) ? $default : $v;
    }
}

if (!defined('DB_HOST'))      define('DB_HOST',      (string)env('DB_HOST', 'localhost'));
if (!defined('DB_NAME'))      define('DB_NAME',      (string)env('DB_NAME', ''));
if (!defined('DB_USER'))      define('DB_USER',      (string)env('DB_USER', ''));
if (!defined('DB_PASS'))      define('DB_PASS',      (string)env('DB_PASS', ''));
if (!defined('DB_CHARSET'))   define('DB_CHARSET',   (string)env('DB_CHARSET', 'utf8mb4'));
if (!defined('DB_COLLATION')) define('DB_COLLATION',  (string)env('DB_COLLATION', 'utf8mb4_unicode_ci'));

// 5) PDO singleton para testes que precisam de DB
function test_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

// 6) Timezone
date_default_timezone_set((string)env('TIMEZONE', 'America/Sao_Paulo'));

// 7) Session (CLI) — necessário para $_SESSION funcionar em testes ACL
if (session_status() === PHP_SESSION_NONE && php_sapi_name() === 'cli') {
    @session_start();
}
