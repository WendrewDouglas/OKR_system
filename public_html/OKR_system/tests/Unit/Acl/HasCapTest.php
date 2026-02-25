<?php
declare(strict_types=1);

namespace Tests\Unit\Acl;

use PDO;
use Tests\Helpers\DbTestCase;

/**
 * Testa has_cap() com banco real (transaction + rollback).
 * Precisa de conexão com o banco configurada no .env.
 */
class HasCapTest extends DbTestCase
{
    private int $companyId;
    private int $adminMasterId;
    private int $userAdminId;
    private int $userColabId;
    private int $noRoleUserId;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/acl.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Injeta PDO de teste no ACL
        pdo_conn_override($this->pdo);

        // 1) Company
        $this->pdo->exec("INSERT INTO company (organizacao) VALUES ('TestCo ACL')");
        $this->companyId = (int)$this->pdo->lastInsertId();

        // 2) Usuários
        $insUser = $this->pdo->prepare("
            INSERT INTO usuarios (primeiro_nome, ultimo_nome, email_corporativo, id_company)
            VALUES (:nome, :sobrenome, :email, :company)
        ");

        $ts = time(); // uniqueness suffix
        $insUser->execute(['nome'=>'Admin','sobrenome'=>'Master','email'=>"admin_master_{$ts}@test.local",'company'=>$this->companyId]);
        $this->adminMasterId = (int)$this->pdo->lastInsertId();

        $insUser->execute(['nome'=>'User','sobrenome'=>'Admin','email'=>"user_admin_{$ts}@test.local",'company'=>$this->companyId]);
        $this->userAdminId = (int)$this->pdo->lastInsertId();

        $insUser->execute(['nome'=>'User','sobrenome'=>'Colab','email'=>"user_colab_{$ts}@test.local",'company'=>$this->companyId]);
        $this->userColabId = (int)$this->pdo->lastInsertId();

        $insUser->execute(['nome'=>'No','sobrenome'=>'Role','email'=>"no_role_{$ts}@test.local",'company'=>$this->companyId]);
        $this->noRoleUserId = (int)$this->pdo->lastInsertId();

        // 3) Lookup existing roles (already seeded in prod DB)
        $adminMasterRoleId = (int)$this->pdo->query("SELECT role_id FROM rbac_roles WHERE role_key = 'admin_master' LIMIT 1")->fetchColumn();
        $userAdminRoleId   = (int)$this->pdo->query("SELECT role_id FROM rbac_roles WHERE role_key = 'user_admin' LIMIT 1")->fetchColumn();
        $userColabRoleId   = (int)$this->pdo->query("SELECT role_id FROM rbac_roles WHERE role_key = 'user_colab' LIMIT 1")->fetchColumn();

        if (!$adminMasterRoleId || !$userAdminRoleId || !$userColabRoleId) {
            $this->markTestSkipped('Roles RBAC (admin_master, user_admin, user_colab) não encontrados no banco');
        }

        // 4) Lookup or create capabilities (cap_key has UNIQUE constraint)
        $capWKrOrg = $this->ensureCapability('W:kr@ORG', 'kr', 'W', 'ORG');
        $capRKrOrg = $this->ensureCapability('R:kr@ORG', 'kr', 'R', 'ORG');
        $capWApontOrg = $this->ensureCapability('W:apontamento@ORG', 'apontamento', 'W', 'ORG');

        // 5) Role-capability links (IGNORE duplicate key)
        $insRC = $this->pdo->prepare("INSERT IGNORE INTO rbac_role_capability (role_id, capability_id) VALUES (:role, :cap)");
        $insRC->execute(['role'=>$userAdminRoleId, 'cap'=>$capWKrOrg]);
        $insRC->execute(['role'=>$userColabRoleId, 'cap'=>$capRKrOrg]);
        $insRC->execute(['role'=>$userColabRoleId, 'cap'=>$capWApontOrg]);

        // 6) User-role links
        $insUR = $this->pdo->prepare("INSERT INTO rbac_user_role (user_id, role_id) VALUES (:user, :role)");
        $insUR->execute(['user'=>$this->adminMasterId, 'role'=>$adminMasterRoleId]);
        $insUR->execute(['user'=>$this->userAdminId,   'role'=>$userAdminRoleId]);
        $insUR->execute(['user'=>$this->userColabId,   'role'=>$userColabRoleId]);
    }

    private function ensureCapability(string $capKey, string $resource, string $action, string $scope): int
    {
        $st = $this->pdo->prepare("SELECT capability_id FROM rbac_capabilities WHERE cap_key = ? LIMIT 1");
        $st->execute([$capKey]);
        $id = $st->fetchColumn();
        if ($id) return (int)$id;

        $this->pdo->prepare("
            INSERT INTO rbac_capabilities (cap_key, resource, action, scope) VALUES (?, ?, ?, ?)
        ")->execute([$capKey, $resource, $action, $scope]);
        return (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        pdo_conn_override(null);
        $_SESSION = [];
        parent::tearDown();
    }

    private function setSession(int $userId, int $companyId): void
    {
        $_SESSION['user_id']    = $userId;
        $_SESSION['id_company'] = $companyId;
    }

    public function testAdminMasterBypassAll(): void
    {
        $this->setSession($this->adminMasterId, $this->companyId);
        $this->assertTrue(has_cap('W:kr@ORG'));
        $this->assertTrue(has_cap('R:kr@ORG'));
        $this->assertTrue(has_cap('W:objetivo@ORG'));
    }

    public function testUserAdminWithCapability(): void
    {
        $this->setSession($this->userAdminId, $this->companyId);
        $this->assertTrue(has_cap('W:kr@ORG'));
        // W cobre R
        $this->assertTrue(has_cap('R:kr@ORG'));
    }

    public function testUserWithoutCapability(): void
    {
        $this->setSession($this->userAdminId, $this->companyId);
        // user_admin não tem cap para recurso fictício que não existe no RBAC
        $this->assertFalse(has_cap('W:__nonexistent_resource__@ORG'));
    }

    public function testUserWithoutAnyRole(): void
    {
        $this->setSession($this->noRoleUserId, $this->companyId);
        $this->assertFalse(has_cap('R:kr@ORG'));
    }

    public function testNoSessionReturnsFalse(): void
    {
        $_SESSION = [];
        $this->assertFalse(has_cap('R:kr@ORG'));
    }

    public function testDenyOverrideBlocksAllow(): void
    {
        // user_colab tem R:kr@ORG (apenas ORG, sem SYS que poderia bypass)
        $this->setSession($this->userColabId, $this->companyId);

        // Confirma que sem DENY, a cap funciona
        $this->assertTrue(has_cap('R:kr@ORG'));

        // Aplica DENY ao user_colab para R:kr@ORG
        $capId = $this->ensureCapability('R:kr@ORG', 'kr', 'R', 'ORG');

        $this->pdo->prepare("
            INSERT INTO rbac_user_capability (user_id, capability_id, effect)
            VALUES (:user, :cap, 'DENY')
        ")->execute(['user'=>$this->userColabId, 'cap'=>$capId]);

        $this->assertFalse(has_cap('R:kr@ORG'));
    }
}
