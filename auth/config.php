<?php
// /OKR_system/auth/config.php
declare(strict_types=1);

/**
 * Carregamento de variáveis de ambiente a partir do .env na raiz do projeto.
 * - Usa vlucas/phpdotenv se disponível.
 * - Fallback: loader simples baseado em file().
 * Mantém defines (DB_HOST, SMTP_HOST, etc.) para compatibilidade com o restante do sistema.
 */

// ----- Descobre caminhos -----
$PROJECT_ROOT = dirname(__DIR__, 1);              // /OKR_system
$ENV_PATH     = $PROJECT_ROOT . '/.env';
$AUTOLOAD     = $PROJECT_ROOT . '/vendor/autoload.php';

// ----- Tenta carregar via Dotenv (se instalado) -----
if (is_file($AUTOLOAD)) {
    require_once $AUTOLOAD;
    if (class_exists(\Dotenv\Dotenv::class)) {
        // safeLoad() não explode se o .env não existir
        \Dotenv\Dotenv::createImmutable($PROJECT_ROOT)->safeLoad();
    }
}

// ----- Fallback loader simples se variáveis essenciais não vierem -----
if (!getenv('DB_HOST') && is_file($ENV_PATH) && is_readable($ENV_PATH)) {
    $lines = file($ENV_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $name  = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        // remove aspas envolventes
        if (($value[0] ?? '') === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
        } elseif (($value[0] ?? '') === "'" && substr($value, -1) === "'") {
            $value = substr($value, 1, -1);
        }
        // injeta em getenv/$_ENV/$_SERVER
        putenv("$name=$value");
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }
}

// ----- Helpers env() -----
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $v = getenv($key);
        return ($v === false) ? $default : $v;
    }
}
if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool {
        $v = env($key);
        if ($v === null) return $default;
        $v = strtolower((string)$v);
        return in_array($v, ['1','true','on','yes'], true);
    }
}
if (!function_exists('env_int')) {
    function env_int(string $key, int $default = 0): int {
        $v = env($key);
        return ($v === null || $v === '') ? $default : (int)$v;
    }
}

// ----- Timezone e log (agora podem vir do .env) -----
date_default_timezone_set((string)env('TIMEZONE', 'America/Sao_Paulo'));

ini_set('log_errors', '1');
$logPath = (string)env('ERROR_LOG_PATH', __DIR__ . '/error_log');
ini_set('error_log', $logPath);

// Opcional: controla exibição de erros por APP_DEBUG
if (env_bool('APP_DEBUG', false)) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
}

// ===== Banco =====
define('DB_HOST',     (string)env('DB_HOST', 'localhost'));
define('DB_NAME',     (string)env('DB_NAME', ''));
define('DB_USER',     (string)env('DB_USER', ''));
define('DB_PASS',     (string)env('DB_PASS', ''));
define('DB_CHARSET',  (string)env('DB_CHARSET', 'utf8mb4'));
define('DB_COLLATION',(string)env('DB_COLLATION', 'utf8mb4_unicode_ci'));

// Opções PDO padrão (seguras)
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    // Garante charset/collation em conexões antigas/hosts antigos
    PDO::MYSQL_ATTR_INIT_COMMAND => sprintf(
        "SET NAMES %s COLLATE %s",
        DB_CHARSET,
        DB_COLLATION
    ),
];

// ===== WhatsApp Cloud API =====
define('WHATSAPP_TOKEN',    (string)env('WHATSAPP_TOKEN', ''));
define('WHATSAPP_PHONE_ID', (string)env('WHATSAPP_PHONE_ID', ''));

// ===== SMTP =====
define('SMTP_HOST',       (string)env('SMTP_HOST', 'smtp.titan.email'));
define('SMTP_USER',       (string)env('SMTP_USER', ''));
define('SMTP_PASS',       (string)env('SMTP_PASS', ''));
define('SMTP_PORT',        env_int('SMTP_PORT', 587)); // força inteiro
define('SMTP_FROM',       (string)env('SMTP_FROM', env('SMTP_USER', '')));
define('SMTP_FROM_NAME',  (string)env('SMTP_FROM_NAME', 'OKR System'));

// ===== APP flags =====
define('APP_ENV',   (string)env('APP_ENV', 'production'));
define('APP_DEBUG',  env_bool('APP_DEBUG', false));


// ===== CAPTCHA / Security =====
define('CAPTCHA_PROVIDER',     (string)env('CAPTCHA_PROVIDER', 'off')); // 'recaptcha' | 'hcaptcha' | 'off'
define('CAPTCHA_SITE_KEY',     (string)env('CAPTCHA_SITE_KEY', ''));
define('CAPTCHA_SECRET',       (string)env('CAPTCHA_SECRET', ''));
define('RECAPTCHA_MIN_SCORE', (float)env('RECAPTCHA_MIN_SCORE', 0.5)); // usado no reCAPTCHA v3
define('APP_TOKEN_PEPPER',     (string)env('APP_TOKEN_PEPPER', 'mude-este-valor'));
