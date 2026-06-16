<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../../auth/crm_db.php';

function crm_migration_ascii_key(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }
    $value = str_replace('&', ' e ', $value);
    $value = preg_replace('/[^a-z0-9\/. -]+/', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function crm_migration_company_root_key(?string $company): ?string
{
    $company = trim((string)$company);
    if ($company === '') {
        return null;
    }

    $key = crm_migration_ascii_key($company);
    if ($key === '') {
        return null;
    }

    $parts = preg_split('/\s+[-|]\s+/', $key);
    if (is_array($parts) && trim((string)($parts[0] ?? '')) !== '') {
        $key = trim((string)$parts[0]);
    }

    $key = str_replace(['s/a', 's.a.'], ' sa ', $key);
    $key = preg_replace('/[^a-z0-9 ]+/', ' ', $key) ?? $key;
    $key = preg_replace('/\s+/', ' ', $key) ?? $key;

    $stopwords = [
        'a', 'e', 'the', 'of', 'and',
        'de', 'da', 'do', 'das', 'dos',
        'grupo', 'group', 'holding',
        'ltda', 'ltd', 'sa', 'me', 'epp', 'eireli',
        'inc', 'corp', 'corporation', 'company', 'co',
        'brasil', 'brazil',
    ];

    foreach (explode(' ', trim($key)) as $token) {
        $token = trim($token);
        if ($token === '' || in_array($token, $stopwords, true)) {
            continue;
        }
        if (strlen($token) < 2) {
            continue;
        }
        return substr($token, 0, 120);
    }

    return null;
}

$pdo = crm_db();
$pdo->beginTransaction();

try {
    $columnExists = (bool)$pdo->query("
        SELECT COUNT(*)
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'crm_accounts'
           AND COLUMN_NAME = 'company_root_key'
    ")->fetchColumn();

    if (!$columnExists) {
        $pdo->exec("
            ALTER TABLE crm_accounts
              ADD COLUMN company_root_key VARCHAR(120) DEFAULT NULL AFTER normalized_name
        ");
    }

    $indexExists = (bool)$pdo->query("
        SELECT COUNT(*)
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'crm_accounts'
           AND INDEX_NAME = 'idx_crm_accounts_root_key'
    ")->fetchColumn();

    if (!$indexExists) {
        $pdo->exec("ALTER TABLE crm_accounts ADD INDEX idx_crm_accounts_root_key (company_root_key)");
    }

    $rows = $pdo->query("
        SELECT id_account, account_name
          FROM crm_accounts
         WHERE company_root_key IS NULL OR company_root_key = ''
         ORDER BY id_account
    ")->fetchAll();

    $updated = 0;
    $st = $pdo->prepare("UPDATE crm_accounts SET company_root_key = ? WHERE id_account = ?");
    foreach ($rows as $row) {
        $rootKey = crm_migration_company_root_key((string)$row['account_name']);
        if ($rootKey === null) {
            continue;
        }
        $st->execute([$rootKey, (int)$row['id_account']]);
        $updated += $st->rowCount();
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

$summary = [
    'database' => CRM_DB_NAME,
    'column' => 'company_root_key',
    'updated_accounts' => $updated,
    'accounts' => (int)$pdo->query("SELECT COUNT(*) FROM crm_accounts")->fetchColumn(),
    'filled_root_keys' => (int)$pdo->query("SELECT COUNT(*) FROM crm_accounts WHERE company_root_key IS NOT NULL AND company_root_key <> ''")->fetchColumn(),
    'suggestion_groups' => (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT company_root_key
              FROM crm_accounts
             WHERE company_root_key IS NOT NULL AND company_root_key <> ''
             GROUP BY company_root_key
            HAVING COUNT(*) > 1
        ) x
    ")->fetchColumn(),
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
