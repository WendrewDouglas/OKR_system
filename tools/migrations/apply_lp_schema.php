<?php
declare(strict_types=1);

// =============================================================
// Applier CLI do schema das Landing Pages (planni40_lp).
// Espelha tools/migrations/apply_crm_schema.php.
//
// Uso:
//   php tools/migrations/apply_lp_schema.php [projectRoot] [sqlFile] [database]
//
// Padrões:
//   projectRoot = raiz do OKR_system (deduzida)
//   sqlFile     = LP/lp-ia/migrations/001_lp_schema.sql
//   database    = planni40_lp
//
// IMPORTANTE: o schema/database precisa existir e o usuário MySQL precisa ter
// permissão sobre ele (no HostGator, criar o DB no cPanel e atribuir o usuário).
// =============================================================

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$projectRoot = $argv[1] ?? dirname(__DIR__, 2);
$sqlFile     = $argv[2] ?? (dirname(__DIR__, 2) . '/LP/lp-ia/migrations/001_lp_schema.sql');
$database    = $argv[3] ?? 'planni40_lp';

$config = rtrim($projectRoot, '/\\') . '/auth/config.php';
if (!is_file($config)) {
    fwrite(STDERR, "Config not found: {$config}\n");
    exit(1);
}
if (!is_file($sqlFile)) {
    fwrite(STDERR, "SQL file not found: {$sqlFile}\n");
    exit(1);
}

require $config;

// Usa as credenciais LP_DB_* (que por padrão herdam a conta principal do OKR).
$host = defined('LP_DB_HOST') ? LP_DB_HOST : DB_HOST;
$user = defined('LP_DB_USER') ? LP_DB_USER : DB_USER;
$pass = defined('LP_DB_PASS') ? LP_DB_PASS : DB_PASS;

$dsn = 'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4';
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

$sql = file_get_contents($sqlFile);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "SQL file is empty or unreadable.\n");
    exit(1);
}

$pdo->exec($sql);

$count = (int)$pdo->query("
    SELECT COUNT(*)
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME LIKE 'lp\_%'
")->fetchColumn();

echo "LP_SCHEMA_OK database={$database} tables={$count}\n";
