<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$projectRoot = $argv[1] ?? dirname(__DIR__, 2);
$sqlFile = $argv[2] ?? (__DIR__ . '/crm_tables.sql');
$database = $argv[3] ?? 'planni40_crm';

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

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . $database . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
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
       AND TABLE_NAME LIKE 'crm\_%'
")->fetchColumn();

echo "CRM_SCHEMA_OK database={$database} tables={$count}\n";
