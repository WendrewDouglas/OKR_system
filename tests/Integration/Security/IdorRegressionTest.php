<?php
declare(strict_types=1);

namespace Tests\Integration\Security;

use PDO;
use Throwable;
use Tests\Helpers\DbTestCase;

/**
 * Regressão de IDOR multi-tenant (Fase 1 de segurança).
 *
 * As guardas adicionadas na Fase 1 (require_cap / $assertTenant / checagens
 * manuais em detalhe_okr.php, aprovacao_api.php, usuarios_api.php,
 * relatorio_gerarpdf.php, salvar_iniciativas.php) delegam o isolamento entre
 * empresas a DUAS primitivas do auth/acl.php:
 *
 *   1) resolve_resource_company() — resolve o id_company de um recurso a partir
 *      do seu id (objetivo/kr/iniciativa/orcamento/apontamento).
 *   2) has_cap($cap, $ctx) — nega quando o recurso do $ctx pertence a outra
 *      empresa (mesmo o usuário tendo a capability), e faz bypass p/ admin_master.
 *
 * Este teste seeda DUAS empresas com árvore OKR completa e prova que:
 *   - cada recurso resolve para a empresa correta (nunca vaza entre tenants);
 *   - has_cap nega acesso cross-company e permite same-company;
 *   - admin_master atravessa a fronteira de empresa.
 *
 * Usa transação + rollback (DbTestCase): nada é persistido no banco.
 */
class IdorRegressionTest extends DbTestCase
{
    private int $companyA;
    private int $companyB;
    private int $userA;        // user_admin em A
    private int $userB;        // user_admin em B
    private int $adminMaster;  // admin_master em A

    /** @var array<string,mixed> árvore da empresa A: obj/kr/ini/orc */
    private array $treeA;
    /** @var array<string,mixed> árvore da empresa B */
    private array $treeB;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/acl.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        pdo_conn_override($this->pdo);

        // Papéis já existentes no banco de produção (mesmo padrão do HasCapTest).
        $roleAdminMaster = (int)$this->pdo->query("SELECT role_id FROM rbac_roles WHERE role_key='admin_master' LIMIT 1")->fetchColumn();
        $roleUserAdmin   = (int)$this->pdo->query("SELECT role_id FROM rbac_roles WHERE role_key='user_admin' LIMIT 1")->fetchColumn();
        if (!$roleAdminMaster || !$roleUserAdmin) {
            $this->markTestSkipped('Papéis RBAC (admin_master/user_admin) não encontrados no banco.');
        }

        // Capabilities usadas pelas guardas da Fase 1 (garante e vincula ao user_admin).
        foreach ([
            ['W:kr@ORG','kr','W'], ['R:objetivo@ORG','objetivo','R'],
            ['W:iniciativa@ORG','iniciativa','W'], ['W:orcamento@ORG','orcamento','W'],
            ['R:apontamento@ORG','apontamento','R'],
        ] as [$capKey,$res,$act]) {
            $capId = $this->ensureCapability($capKey, $res, $act, 'ORG');
            $this->pdo->prepare("INSERT IGNORE INTO rbac_role_capability (role_id, capability_id) VALUES (?,?)")
                      ->execute([$roleUserAdmin, $capId]);
        }

        $ts = time() . random_int(100, 999);

        try {
            // Duas empresas isoladas.
            $this->companyA = $this->newCompany("IDOR-A-$ts");
            $this->companyB = $this->newCompany("IDOR-B-$ts");

            // Usuários.
            $this->userA       = $this->newUser("idor_a_$ts",  $this->companyA, $roleUserAdmin);
            $this->userB       = $this->newUser("idor_b_$ts",  $this->companyB, $roleUserAdmin);
            $this->adminMaster = $this->newUser("idor_m_$ts",  $this->companyA, $roleAdminMaster);

            // Árvore OKR completa por empresa.
            $this->treeA = $this->seedTree($this->companyA, "A$ts");
            $this->treeB = $this->seedTree($this->companyB, "B$ts");
        } catch (Throwable $e) {
            // Ambientes sem os domínios (dom_ciclos/dom_status_*) seedados não
            // conseguem montar a árvore: pula em vez de falhar por dado ausente.
            $this->markTestSkipped('Não foi possível montar a árvore OKR de teste: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        pdo_conn_override(null);
        $_SESSION = [];
        parent::tearDown();
    }

    /* ============================ HELPERS ============================ */

    private function ensureCapability(string $capKey, string $resource, string $action, string $scope): int
    {
        $st = $this->pdo->prepare("SELECT capability_id FROM rbac_capabilities WHERE cap_key=? LIMIT 1");
        $st->execute([$capKey]);
        $id = $st->fetchColumn();
        if ($id) return (int)$id;
        $this->pdo->prepare("INSERT INTO rbac_capabilities (cap_key, resource, action, scope) VALUES (?,?,?,?)")
                  ->execute([$capKey, $resource, $action, $scope]);
        return (int)$this->pdo->lastInsertId();
    }

    private function newCompany(string $nome): int
    {
        $this->pdo->prepare("INSERT INTO company (organizacao) VALUES (?)")->execute([$nome]);
        return (int)$this->pdo->lastInsertId();
    }

    private function newUser(string $slug, int $company, int $roleId): int
    {
        $this->pdo->prepare("
            INSERT INTO usuarios (primeiro_nome, ultimo_nome, email_corporativo, id_company)
            VALUES ('Teste','IDOR', ?, ?)
        ")->execute(["{$slug}@test.local", $company]);
        $uid = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO rbac_user_role (user_id, role_id) VALUES (?,?)")->execute([$uid, $roleId]);
        return $uid;
    }

    /**
     * Cria objetivo → KR → iniciativa → orçamento vinculados à empresa.
     * Confia nos DEFAULTs de status/status_aprovacao (FKs) e resolve tipo_ciclo
     * a partir de dom_ciclos.
     *
     * @return array{obj:int,kr:string,ini:string,orc:int}
     */
    private function seedTree(int $company, string $suffix): array
    {
        $ciclo = (string)$this->pdo->query("SELECT nome_ciclo FROM dom_ciclos LIMIT 1")->fetchColumn();
        if ($ciclo === '') {
            throw new \RuntimeException('dom_ciclos vazio');
        }
        $hoje = date('Y-m-d');

        // Objetivo (status/status_aprovacao via DEFAULT).
        $this->pdo->prepare("
            INSERT INTO objetivos (descricao, dono, usuario_criador, id_company, tipo_ciclo, dt_criacao)
            VALUES (?, 'Dono Teste', 'Criador Teste', ?, ?, ?)
        ")->execute(["Objetivo IDOR $suffix", $company, $ciclo, $hoje]);
        $obj = (int)$this->pdo->lastInsertId();

        // Key Result (PK varchar).
        $kr = "kr_$suffix";
        $this->pdo->prepare("
            INSERT INTO key_results (id_kr, id_objetivo, key_result_num, descricao, usuario_criador, dt_criacao)
            VALUES (?, ?, 1, ?, 'Criador Teste', ?)
        ")->execute([$kr, $obj, "KR IDOR $suffix", $hoje]);

        // Iniciativa (PK varchar).
        $ini = "ini_$suffix";
        $this->pdo->prepare("
            INSERT INTO iniciativas (id_iniciativa, id_kr, num_iniciativa, descricao, id_user_criador, dt_criacao)
            VALUES (?, ?, 1, ?, '0', ?)
        ")->execute([$ini, $kr, "Iniciativa IDOR $suffix", $hoje]);

        // Orçamento.
        $this->pdo->prepare("
            INSERT INTO orcamentos (id_iniciativa, valor, data_desembolso, id_user_criador, dt_criacao)
            VALUES (?, 100.00, ?, '0', ?)
        ")->execute([$ini, $hoje, $hoje]);
        $orc = (int)$this->pdo->lastInsertId();

        return ['obj'=>$obj, 'kr'=>$kr, 'ini'=>$ini, 'orc'=>$orc];
    }

    private function setSession(int $userId, int $companyId): void
    {
        $_SESSION['user_id']    = $userId;
        $_SESSION['id_company'] = $companyId;
    }

    /* ================= resolve_resource_company() ================= */

    public function testResolveObjetivoRetornaEmpresaCorreta(): void
    {
        $this->assertSame($this->companyA, resolve_resource_company($this->pdo, 'objetivo', ['id_objetivo'=>$this->treeA['obj']]));
        $this->assertSame($this->companyB, resolve_resource_company($this->pdo, 'objetivo', ['id_objetivo'=>$this->treeB['obj']]));
    }

    public function testResolveKrRetornaEmpresaCorreta(): void
    {
        $this->assertSame($this->companyA, resolve_resource_company($this->pdo, 'kr', ['id_kr'=>$this->treeA['kr']]));
        $this->assertSame($this->companyB, resolve_resource_company($this->pdo, 'kr', ['id_kr'=>$this->treeB['kr']]));
    }

    public function testResolveIniciativaRetornaEmpresaCorreta(): void
    {
        $this->assertSame($this->companyA, resolve_resource_company($this->pdo, 'iniciativa', ['id_iniciativa'=>$this->treeA['ini']]));
        $this->assertSame($this->companyB, resolve_resource_company($this->pdo, 'iniciativa', ['id_iniciativa'=>$this->treeB['ini']]));
    }

    public function testResolveOrcamentoRetornaEmpresaCorreta(): void
    {
        // Também exercita o JOIN orcamentos→iniciativas (usado por add_despesa).
        $this->assertSame($this->companyA, resolve_resource_company($this->pdo, 'orcamento', ['id_orcamento'=>$this->treeA['orc']]));
        $this->assertSame($this->companyB, resolve_resource_company($this->pdo, 'orcamento', ['id_orcamento'=>$this->treeB['orc']]));
    }

    public function testResolveApontamentoRetornaEmpresaCorreta(): void
    {
        // apontamento resolve via id_kr.
        $this->assertSame($this->companyA, resolve_resource_company($this->pdo, 'apontamento', ['id_kr'=>$this->treeA['kr']]));
        $this->assertSame($this->companyB, resolve_resource_company($this->pdo, 'apontamento', ['id_kr'=>$this->treeB['kr']]));
    }

    public function testResolveIdInexistenteRetornaNull(): void
    {
        // As guardas negam quando resolve devolve null (recurso some/id forjado).
        $this->assertNull(resolve_resource_company($this->pdo, 'objetivo', ['id_objetivo'=>999999999]));
        $this->assertNull(resolve_resource_company($this->pdo, 'kr', ['id_kr'=>'__nao_existe__']));
        $this->assertNull(resolve_resource_company($this->pdo, 'orcamento', ['id_orcamento'=>999999999]));
    }

    /* ================= has_cap() cross-company ================= */

    public function testHasCapNegaLeituraDeObjetivoDeOutraEmpresa(): void
    {
        // Cenário do relatorio_gerarpdf.php: userA tenta ler objetivo da empresa B.
        $this->setSession($this->userA, $this->companyA);
        $this->assertTrue(has_cap('R:objetivo@ORG', ['id_objetivo'=>$this->treeA['obj']]), 'mesma empresa deve permitir');
        $this->assertFalse(has_cap('R:objetivo@ORG', ['id_objetivo'=>$this->treeB['obj']]), 'empresa diferente deve negar');
    }

    public function testHasCapNegaEscritaEmKrDeOutraEmpresa(): void
    {
        // Cenário dos handlers destrutivos (delete_kr/cancel_kr) via $assertTenant.
        $this->setSession($this->userA, $this->companyA);
        $this->assertTrue(has_cap('W:kr@ORG', ['id_kr'=>$this->treeA['kr']]));
        $this->assertFalse(has_cap('W:kr@ORG', ['id_kr'=>$this->treeB['kr']]));
    }

    public function testHasCapNegaEscritaEmIniciativaDeOutraEmpresa(): void
    {
        $this->setSession($this->userA, $this->companyA);
        $this->assertTrue(has_cap('W:iniciativa@ORG', ['id_iniciativa'=>$this->treeA['ini']]));
        $this->assertFalse(has_cap('W:iniciativa@ORG', ['id_iniciativa'=>$this->treeB['ini']]));
    }

    public function testHasCapNegaEscritaEmOrcamentoDeOutraEmpresa(): void
    {
        // Cenário do add_despesa.
        $this->setSession($this->userA, $this->companyA);
        $this->assertTrue(has_cap('W:orcamento@ORG', ['id_orcamento'=>$this->treeA['orc']]));
        $this->assertFalse(has_cap('W:orcamento@ORG', ['id_orcamento'=>$this->treeB['orc']]));
    }

    public function testUsuarioBNaoAlcancaRecursosDeA(): void
    {
        // Simétrico: userB (empresa B) não enxerga recursos de A.
        $this->setSession($this->userB, $this->companyB);
        $this->assertFalse(has_cap('R:objetivo@ORG', ['id_objetivo'=>$this->treeA['obj']]));
        $this->assertFalse(has_cap('W:kr@ORG', ['id_kr'=>$this->treeA['kr']]));
        $this->assertTrue(has_cap('W:kr@ORG', ['id_kr'=>$this->treeB['kr']]));
    }

    public function testAdminMasterAtravessaFronteiraDeEmpresa(): void
    {
        // admin_master (empresa A) deve acessar recursos da empresa B.
        $this->setSession($this->adminMaster, $this->companyA);
        $this->assertTrue(has_cap('R:objetivo@ORG', ['id_objetivo'=>$this->treeB['obj']]));
        $this->assertTrue(has_cap('W:kr@ORG', ['id_kr'=>$this->treeB['kr']]));
        $this->assertTrue(has_cap('W:orcamento@ORG', ['id_orcamento'=>$this->treeB['orc']]));
    }
}
