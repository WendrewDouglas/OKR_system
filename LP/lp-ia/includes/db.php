<?php
declare(strict_types=1);

// =============================================================
// Conexão PDO dedicada ao schema das landing pages (planni40_lp).
// Espelha o padrão de auth/crm_db.php — conta MySQL compartilhada,
// schema isolado. Nunca toca nos schemas OKR/CRM.
// =============================================================

function lp_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Test seam: permite injetar um PDO (ex.: SQLite) em testes de integração.
    // Em produção este global nunca é definido — código inerte.
    if (isset($GLOBALS['__LP_TEST_PDO']) && $GLOBALS['__LP_TEST_PDO'] instanceof PDO) {
        return $pdo = $GLOBALS['__LP_TEST_PDO'];
    }

    $host     = defined('LP_DB_HOST') ? LP_DB_HOST : (defined('DB_HOST') ? DB_HOST : 'localhost');
    $name     = defined('LP_DB_NAME') ? LP_DB_NAME : 'planni40_lp';
    $user     = defined('LP_DB_USER') ? LP_DB_USER : (defined('DB_USER') ? DB_USER : '');
    $pass     = defined('LP_DB_PASS') ? LP_DB_PASS : (defined('DB_PASS') ? DB_PASS : '');
    $charset  = defined('LP_DB_CHARSET') ? LP_DB_CHARSET : 'utf8mb4';
    $collation = defined('LP_DB_COLLATION') ? LP_DB_COLLATION : 'utf8mb4_unicode_ci';

    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=' . $charset;

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => sprintf('SET NAMES %s COLLATE %s', $charset, $collation),
    ]);

    return $pdo;
}

/**
 * Resolve o id da landing pelo slug. Lança se não existir/ativa.
 */
function lp_landing_id(string $slug): int
{
    static $cache = [];
    if (isset($cache[$slug])) {
        return $cache[$slug];
    }

    $stmt = lp_db()->prepare(
        'SELECT id FROM lp_landings WHERE slug = ? AND active = 1 LIMIT 1'
    );
    $stmt->execute([$slug]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        throw new RuntimeException('Landing não encontrada ou inativa: ' . $slug);
    }

    return $cache[$slug] = (int) $id;
}
