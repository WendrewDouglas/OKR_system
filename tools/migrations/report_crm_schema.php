<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$projectRoot = $argv[1] ?? dirname(__DIR__, 2);
$database = $argv[2] ?? 'planni40_crm';
$config = rtrim($projectRoot, '/\\') . '/auth/config.php';

if (!is_file($config)) {
    fwrite(STDERR, "Config not found: {$config}\n");
    exit(1);
}

require $config;

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . $database . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

echo "database={$database}\n";

$tables = $pdo->query("
    SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME LIKE 'crm\_%'
     ORDER BY TABLE_NAME
")->fetchAll();

echo "tables=" . count($tables) . "\n";
foreach ($tables as $table) {
    echo $table['TABLE_NAME'] . "\t" . $table['ENGINE'] . "\t" . $table['TABLE_COLLATION'] . "\n";
}

$counts = [
    'crm_settings',
    'crm_pipeline_stages',
    'crm_tags',
];

foreach ($counts as $table) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    echo "count.{$table}={$count}\n";
}
