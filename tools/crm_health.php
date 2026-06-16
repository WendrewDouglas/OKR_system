<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/crm_db.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/../auth/config.php';
    $token = $_GET['token'] ?? '';
    if (defined('HEALTH_CHECK_TOKEN') && HEALTH_CHECK_TOKEN !== '' && !hash_equals(HEALTH_CHECK_TOKEN, (string)$token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['overall' => 'FAIL', 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

$response = [
    'timestamp' => date(DATE_ATOM),
    'overall' => 'PASS',
    'database' => CRM_DB_NAME,
    'checks' => [],
];

try {
    $pdo = crm_db();
    $pdo->query('SELECT 1')->fetchColumn();
    $response['checks']['db_alive'] = ['status' => 'PASS'];

    $report = crm_schema_report();
    $missing = $report['missing_tables'];
    $response['checks']['schema'] = [
        'status' => empty($missing) ? 'PASS' : 'FAIL',
        'table_count' => $report['table_count'],
        'required_count' => $report['required_count'],
        'missing_tables' => $missing,
    ];
    if (!empty($missing)) {
        $response['overall'] = 'FAIL';
    }

    $response['checks']['seeds'] = [
        'status' => (
            ($report['seed_counts']['crm_settings'] ?? 0) >= 3
            && ($report['seed_counts']['crm_pipeline_stages'] ?? 0) >= 9
            && ($report['seed_counts']['crm_tags'] ?? 0) >= 5
        ) ? 'PASS' : 'FAIL',
        'counts' => $report['seed_counts'],
    ];
    if ($response['checks']['seeds']['status'] !== 'PASS') {
        $response['overall'] = 'FAIL';
    }

    $response['checks']['php_version'] = [
        'status' => 'PASS',
        'version' => PHP_VERSION,
    ];
} catch (Throwable $e) {
    $response['overall'] = 'FAIL';
    $response['checks']['exception'] = [
        'status' => 'FAIL',
        'message' => $isCli ? $e->getMessage() : 'CRM health check failed',
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
