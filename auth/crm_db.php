<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function crm_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . CRM_DB_HOST . ';dbname=' . CRM_DB_NAME . ';charset=' . CRM_DB_CHARSET;
    $pdo = new PDO($dsn, CRM_DB_USER, CRM_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => sprintf(
            'SET NAMES %s COLLATE %s',
            CRM_DB_CHARSET,
            CRM_DB_COLLATION
        ),
    ]);

    return $pdo;
}

function crm_required_tables(): array
{
    return [
        'crm_accounts',
        'crm_activities',
        'crm_campaigns',
        'crm_campaign_members',
        'crm_consent_events',
        'crm_contacts',
        'crm_contact_channels',
        'crm_contact_positions',
        'crm_conversations',
        'crm_import_batches',
        'crm_import_rows',
        'crm_lead_scores',
        'crm_messages',
        'crm_opportunities',
        'crm_pipeline_stages',
        'crm_segments',
        'crm_segment_members',
        'crm_settings',
        'crm_tags',
        'crm_tag_links',
        'crm_tasks',
    ];
}

function crm_schema_report(): array
{
    $pdo = crm_db();
    $tables = $pdo->query("
        SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME LIKE 'crm\_%'
         ORDER BY TABLE_NAME
    ")->fetchAll();

    $found = array_map(static fn(array $row): string => (string)$row['TABLE_NAME'], $tables);
    $required = crm_required_tables();
    $missing = array_values(array_diff($required, $found));

    $counts = [];
    foreach (['crm_settings', 'crm_pipeline_stages', 'crm_tags'] as $table) {
        if (in_array($table, $found, true)) {
            $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        }
    }

    return [
        'database' => CRM_DB_NAME,
        'table_count' => count($tables),
        'required_count' => count($required),
        'missing_tables' => $missing,
        'tables' => $tables,
        'seed_counts' => $counts,
    ];
}
