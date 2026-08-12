<?php
declare(strict_types=1);

// =============================================================
// Aplica o schema do módulo Briefing CRM (tabelas bc_*).
//
// Runner PHP/PDO de propósito: o grant do banco é por host e o cliente
// `mysql` da CLI cai em @localhost, sem acesso. Ver MEMORY.md.
//
// Uso (no servidor, a partir da raiz do repo):
//   php tools/migrations/apply_briefing_crm.php
// =============================================================

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$projectRoot = $argv[1] ?? dirname(__DIR__, 2);
$sqlFile     = $argv[2] ?? (dirname(__DIR__, 2) . '/LP/briefing-crm/migrations/001_bc_schema.sql');

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

// Sem $database no argv: usa DB_NAME (planni40_okr), que é onde o
// módulo grava — mesmo banco do OKR, como o LP/perspectivas.
$database = $argv[3] ?? DB_NAME;

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . $database . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
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

$rows = $pdo->query("
    SELECT TABLE_NAME, TABLE_ROWS
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME LIKE 'bc\\_%'
     ORDER BY TABLE_NAME
")->fetchAll();

echo "BRIEFING_CRM_SCHEMA_OK database={$database} tables=" . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  - {$r['TABLE_NAME']}\n";
}
