<?php
declare(strict_types=1);

namespace Tests\Helpers;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * TestCase base para testes que precisam de banco de dados.
 * Usa transaction + rollback automático para não sujar o banco.
 */
abstract class DbTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = test_pdo();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }
}
