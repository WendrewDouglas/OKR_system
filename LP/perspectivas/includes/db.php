<?php
declare(strict_types=1);

// =============================================================
// Conexão PDO do módulo "Perspectivas de Gestão".
// IMPORTANTE: este módulo grava no BANCO PRINCIPAL DO OKR
// (mesmo schema de `usuarios`/`company`), portanto usa DB_* — NÃO LP_DB_*.
// Espelha auth/db.php, com nome próprio para não colidir.
// =============================================================

function pg_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Test seam: permite injetar um PDO (ex.: SQLite) em testes de integração.
    // Em produção este global nunca é definido — código inerte.
    if (isset($GLOBALS['__PG_TEST_PDO']) && $GLOBALS['__PG_TEST_PDO'] instanceof PDO) {
        return $pdo = $GLOBALS['__PG_TEST_PDO'];
    }

    $host      = defined('DB_HOST') ? DB_HOST : 'localhost';
    $name      = defined('DB_NAME') ? DB_NAME : '';
    $user      = defined('DB_USER') ? DB_USER : '';
    $pass      = defined('DB_PASS') ? DB_PASS : '';
    $charset   = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
    $collation = defined('DB_COLLATION') ? DB_COLLATION : 'utf8mb4_unicode_ci';

    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=' . $charset;

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => sprintf('SET NAMES %s COLLATE %s', $charset, $collation),
    ]);

    return $pdo;
}
