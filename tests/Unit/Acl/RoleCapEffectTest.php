<?php
declare(strict_types=1);

namespace Tests\Unit\Acl;

use Tests\Helpers\DbTestCase;

/**
 * PARITY-03 — has_cap deve honrar rbac_role_capability.effect.
 *
 * Antes do fix, todo vínculo de role era tratado como ALLOW (o effect era ignorado),
 * então uma capability ligada ao papel com effect='DENY' concedia acesso indevidamente.
 * Depois do fix, um DENY no nível de role bloqueia.
 *
 * Usa uma capability ÚNICA (nenhum outro papel a possui) ligada ao papel global
 * user_colab com effect='DENY', dentro de transação (rollback automático).
 *
 * @group security-regression
 */
class RoleCapEffectTest extends DbTestCase
{
    private int $companyId;
    private int $userId;
    private int $colabRoleId;
    private string $capKey;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/acl.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        pdo_conn_override($this->pdo);

        $this->pdo->exec("INSERT INTO company (organizacao) VALUES ('TestCo RoleEffect')");
        $this->companyId = (int)$this->pdo->lastInsertId();

        $ts = time();
        $this->pdo->prepare("
            INSERT INTO usuarios (primeiro_nome, ultimo_nome, email_corporativo, id_company)
            VALUES ('Role', 'Effect', :email, :company)
        ")->execute(['email' => "role_effect_{$ts}@test.local", 'company' => $this->companyId]);
        $this->userId = (int)$this->pdo->lastInsertId();

        $this->colabRoleId = (int)$this->pdo->query(
            "SELECT role_id FROM rbac_roles WHERE role_key = 'user_colab' LIMIT 1"
        )->fetchColumn();
        if (!$this->colabRoleId) {
            $this->markTestSkipped('Papel user_colab não encontrado no banco.');
        }

        // Capability única para este teste (evita colisão com seeds existentes)
        $this->capKey = "W:__paritytest_{$ts}__@ORG";
        $this->pdo->prepare("
            INSERT INTO rbac_capabilities (cap_key, resource, action, scope)
            VALUES (?, ?, 'W', 'ORG')
        ")->execute([$this->capKey, "__paritytest_{$ts}__"]);
        $capId = (int)$this->pdo->lastInsertId();

        // Liga a capability ao papel com effect='DENY'
        $this->pdo->prepare("
            INSERT INTO rbac_role_capability (role_id, capability_id, effect)
            VALUES (?, ?, 'DENY')
        ")->execute([$this->colabRoleId, $capId]);

        // Usuário recebe o papel user_colab
        $this->pdo->prepare("INSERT INTO rbac_user_role (user_id, role_id) VALUES (?, ?)")
            ->execute([$this->userId, $this->colabRoleId]);

        $_SESSION['user_id']    = $this->userId;
        $_SESSION['id_company'] = $this->companyId;
    }

    protected function tearDown(): void
    {
        pdo_conn_override(null);
        $_SESSION = [];
        parent::tearDown();
    }

    public function testRoleLevelDenyBlocksAccess(): void
    {
        // Pós-fix: DENY no nível de role bloqueia (pré-fix retornava true).
        $this->assertFalse(has_cap($this->capKey));
    }
}
