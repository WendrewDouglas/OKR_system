<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../auth/crm_db.php';

$pdo = crm_db();

function scalar_count(PDO $pdo, string $table): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

function grouped_count(PDO $pdo, string $table, string $column): array
{
    $rows = $pdo->query("
        SELECT COALESCE(CAST(`{$column}` AS CHAR), '(null)') AS label, COUNT(*) AS total
          FROM `{$table}`
         GROUP BY `{$column}`
         ORDER BY total DESC, label ASC
    ")->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $out[(string)$row['label']] = (int)$row['total'];
    }
    return $out;
}

$report = [
    'timestamp' => date(DATE_ATOM),
    'database' => CRM_DB_NAME,
    'counts' => [
        'crm_import_batches' => scalar_count($pdo, 'crm_import_batches'),
        'crm_import_rows' => scalar_count($pdo, 'crm_import_rows'),
        'crm_accounts' => scalar_count($pdo, 'crm_accounts'),
        'crm_contacts' => scalar_count($pdo, 'crm_contacts'),
        'crm_contact_channels' => scalar_count($pdo, 'crm_contact_channels'),
        'crm_contact_positions' => scalar_count($pdo, 'crm_contact_positions'),
        'crm_conversations' => scalar_count($pdo, 'crm_conversations'),
        'crm_messages' => scalar_count($pdo, 'crm_messages'),
        'crm_activities' => scalar_count($pdo, 'crm_activities'),
        'crm_opportunities' => scalar_count($pdo, 'crm_opportunities'),
        'crm_tasks' => scalar_count($pdo, 'crm_tasks'),
    ],
    'distributions' => [
        'import_status' => grouped_count($pdo, 'crm_import_batches', 'status'),
        'row_entity_hint' => grouped_count($pdo, 'crm_import_rows', 'entity_hint'),
        'contact_status' => grouped_count($pdo, 'crm_contacts', 'contact_status'),
        'seniority' => grouped_count($pdo, 'crm_contacts', 'seniority'),
        'department' => grouped_count($pdo, 'crm_contacts', 'department'),
        'channel_type' => grouped_count($pdo, 'crm_contact_channels', 'channel_type'),
    ],
    'checks' => [],
];

$report['checks']['expected_raw_rows'] = [
    'status' => $report['counts']['crm_import_rows'] === 2581 ? 'PASS' : 'WARN',
    'expected' => 2581,
    'actual' => $report['counts']['crm_import_rows'],
];
$report['checks']['expected_batches'] = [
    'status' => $report['counts']['crm_import_batches'] === 37 ? 'PASS' : 'WARN',
    'expected' => 37,
    'actual' => $report['counts']['crm_import_batches'],
];
$report['checks']['contacts_loaded'] = [
    'status' => $report['counts']['crm_contacts'] > 1000 ? 'PASS' : 'WARN',
    'actual' => $report['counts']['crm_contacts'],
];
$report['checks']['messages_loaded'] = [
    'status' => $report['counts']['crm_messages'] === 859 ? 'PASS' : 'WARN',
    'expected' => 859,
    'actual' => $report['counts']['crm_messages'],
];

$report['overall'] = 'PASS';
foreach ($report['checks'] as $check) {
    if (($check['status'] ?? '') !== 'PASS') {
        $report['overall'] = 'WARN';
        break;
    }
}

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
