<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../auth/crm_db.php';

$limit = 30;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(200, (int)substr($arg, 8)));
    }
}

$pdo = crm_db();

$summary = [
    'database' => CRM_DB_NAME,
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

$st = $pdo->prepare("
    SELECT company_root_key,
           COUNT(*) AS accounts_count,
           GROUP_CONCAT(account_name ORDER BY account_name SEPARATOR ' | ') AS account_names
      FROM crm_accounts
     WHERE company_root_key IS NOT NULL AND company_root_key <> ''
     GROUP BY company_root_key
    HAVING COUNT(*) > 1
     ORDER BY accounts_count DESC, company_root_key ASC
     LIMIT ?
");
$st->bindValue(1, $limit, PDO::PARAM_INT);
$st->execute();
$groups = $st->fetchAll();

echo json_encode([
    'summary' => $summary,
    'groups' => $groups,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
