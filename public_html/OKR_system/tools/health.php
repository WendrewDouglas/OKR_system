<?php
/**
 * Health Check — OKR System
 *
 * Retorna JSON com status PASS/FAIL/WARN para cada verificação.
 * Protegido por token via query string: ?token=<HEALTH_CHECK_TOKEN>
 *
 * Uso:
 *   curl https://planningbi.com.br/OKR_system/tools/health.php?token=SEU_TOKEN
 */
declare(strict_types=1);

// 1) Carrega config (define constantes DB e carrega .env)
require_once dirname(__DIR__) . '/auth/config.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

// 2) Proteção por token (bypass em CLI — cron roda como usuário do sistema)
if (!$isCli) {
    $expectedToken = (string)env('HEALTH_CHECK_TOKEN', '');
    $givenToken    = (string)($_GET['token'] ?? '');

    if ($expectedToken === '' || !hash_equals($expectedToken, $givenToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

// 3) Checks
$checks = [];

// --- DB alive ---
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    $pdo->query('SELECT 1');
    $checks['db_alive'] = ['status' => 'PASS'];
} catch (Throwable $e) {
    $checks['db_alive'] = ['status' => 'FAIL', 'detail' => $e->getMessage()];
    // Sem DB, não dá para fazer os demais checks
    echo json_encode(['timestamp' => date('c'), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Core tables ---
$coreTables = [
    'usuarios', 'company', 'objetivos', 'key_results', 'milestones_kr',
    'iniciativas', 'rbac_roles', 'rbac_capabilities', 'rbac_role_capability',
    'rbac_user_role', 'rbac_user_capability', 'dom_paginas', 'dom_status_kr',
];
$existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$missing  = array_diff($coreTables, $existing);
$checks['core_tables'] = empty($missing)
    ? ['status' => 'PASS', 'count' => count($coreTables)]
    : ['status' => 'FAIL', 'missing' => array_values($missing)];

// --- Collation check ---
$collStmt = $pdo->query("
    SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND DATA_TYPE IN ('varchar','text','longtext','mediumtext','tinytext','char')
      AND COLLATION_NAME IS NOT NULL
      AND COLLATION_NAME != 'utf8mb4_unicode_ci'
    LIMIT 20
");
$badColl = $collStmt->fetchAll();
$checks['collation'] = empty($badColl)
    ? ['status' => 'PASS']
    : ['status' => 'WARN', 'count' => count($badColl), 'sample' => array_slice($badColl, 0, 5)];

// --- Orphan KRs ---
$orphanKrs = (int)$pdo->query("
    SELECT COUNT(*) FROM key_results k
    LEFT JOIN objetivos o ON o.id_objetivo = k.id_objetivo
    WHERE o.id_objetivo IS NULL
")->fetchColumn();
$checks['orphan_krs'] = $orphanKrs === 0
    ? ['status' => 'PASS']
    : ['status' => 'WARN', 'count' => $orphanKrs];

// --- Orphan iniciativas ---
$orphanIni = (int)$pdo->query("
    SELECT COUNT(*) FROM iniciativas i
    LEFT JOIN key_results k ON k.id_kr = i.id_kr
    WHERE k.id_kr IS NULL
")->fetchColumn();
$checks['orphan_iniciativas'] = $orphanIni === 0
    ? ['status' => 'PASS']
    : ['status' => 'WARN', 'count' => $orphanIni];

// --- Orphan milestones ---
$orphanMs = (int)$pdo->query("
    SELECT COUNT(*) FROM milestones_kr m
    LEFT JOIN key_results k ON k.id_kr = m.id_kr
    WHERE k.id_kr IS NULL
")->fetchColumn();
$checks['orphan_milestones'] = $orphanMs === 0
    ? ['status' => 'PASS']
    : ['status' => 'WARN', 'count' => $orphanMs];

// --- Users without RBAC role ---
$noRole = (int)$pdo->query("
    SELECT COUNT(*) FROM usuarios u
    LEFT JOIN rbac_user_role ur ON ur.user_id = u.id_user
    WHERE ur.user_id IS NULL
")->fetchColumn();
$checks['users_without_role'] = $noRole === 0
    ? ['status' => 'PASS']
    : ['status' => 'WARN', 'count' => $noRole];

// --- PHP version ---
$phpVer = PHP_VERSION;
$checks['php_version'] = version_compare($phpVer, '8.1.0', '>=')
    ? ['status' => 'PASS', 'version' => $phpVer]
    : ['status' => 'WARN', 'version' => $phpVer, 'detail' => 'Recomendado PHP >= 8.1'];

// --- Disk space ---
$freeBytes = @disk_free_space(dirname(__DIR__));
if ($freeBytes !== false) {
    $freeMb = round($freeBytes / 1048576, 1);
    $checks['disk_space_mb'] = $freeMb > 100
        ? ['status' => 'PASS', 'free_mb' => $freeMb]
        : ['status' => 'WARN', 'free_mb' => $freeMb];
} else {
    $checks['disk_space_mb'] = ['status' => 'WARN', 'detail' => 'Não foi possível verificar'];
}

// 4) Output
$overall = 'PASS';
foreach ($checks as $c) {
    if ($c['status'] === 'FAIL') { $overall = 'FAIL'; break; }
    if ($c['status'] === 'WARN') { $overall = 'WARN'; }
}

echo json_encode([
    'timestamp' => date('c'),
    'overall'   => $overall,
    'checks'    => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
