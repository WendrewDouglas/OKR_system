<?php
declare(strict_types=1);

namespace Tests\Smoke;

use PHPUnit\Framework\TestCase;
use PDO;

class DbConnectionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = test_pdo();
    }

    public function testSelectOneWorks(): void
    {
        $result = $this->pdo->query('SELECT 1 AS ok')->fetchColumn();
        $this->assertSame('1', (string)$result);
    }

    public function testCoreTables(): void
    {
        $coreTables = [
            'usuarios',
            'company',
            'objetivos',
            'key_results',
            'milestones_kr',
            'iniciativas',
            'rbac_roles',
            'rbac_capabilities',
            'rbac_role_capability',
            'rbac_user_role',
            'rbac_user_capability',
            'dom_paginas',
            'dom_status_kr',
        ];

        $stmt = $this->pdo->query("SHOW TABLES");
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($coreTables as $table) {
            $this->assertContains($table, $existing, "Tabela core '{$table}' não encontrada no banco");
        }
    }
}
