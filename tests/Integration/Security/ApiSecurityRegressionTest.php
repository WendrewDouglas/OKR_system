<?php
declare(strict_types=1);

namespace Tests\Integration\Security;

use PHPUnit\Framework\TestCase;
use Tests\Helpers\HttpSmokeClient;

/**
 * Regressão de segurança da API (api_platform/v1) — nível de endpoint (HTTP).
 *
 * Cada teste corresponde a um achado SEC-xx (docs/ARQUITETURA_E_ROADMAP.md §6).
 * Correções de CÓDIGO aplicadas em 2026-06-16 (Fase 0). Estes testes confirmam o
 * comportamento ponta a ponta contra um servidor de teste.
 *
 * Validação in-process (sem servidor) das correções de SQL: ver SqlSchemaContractTest.
 *
 * Cada teste faz SKIP gracioso quando faltam servidor/credenciais/fixtures (CI verde),
 * e ASSERTA de verdade quando o ambiente está configurado. Variáveis de ambiente:
 *   TEST_BASE_URL            — base do servidor de teste (NUNCA produção)
 *   TEST_ADMIN_EMAIL/PASS    — usuário user_admin da empresa A
 *   TEST_COLAB_EMAIL/PASS    — usuário user_colab (sem caps de escrita) da empresa A
 *   TEST_APPROVER_EMAIL/PASS — aprovador habilitado da empresa A
 *   TEST_KR_ID               — id de um KR válido da empresa A
 *   TEST_OTHER_USER_ID       — id de usuário de OUTRA empresa (cross-tenant)
 *   TEST_OTHER_KR_ID         — id de KR de OUTRA empresa (cross-tenant)
 * Ver tests/Integration/Security/README.md para o seed.
 *
 * @group security-regression
 */
class ApiSecurityRegressionTest extends TestCase
{
    private HttpSmokeClient $http;

    protected function setUp(): void
    {
        $base = getenv('TEST_BASE_URL') ?: ($_ENV['TEST_BASE_URL'] ?? '');
        if ($base === '') {
            $this->markTestSkipped('TEST_BASE_URL não configurado.');
        }
        $this->http = new HttpSmokeClient($base);
    }

    private function env(string $key): string
    {
        return (string)(getenv($key) ?: ($_ENV[$key] ?? ''));
    }

    /** Loga e retorna o token; faz skip se faltar credencial ou servidor. */
    private function tokenFor(string $emailKey, string $passKey): string
    {
        $email = $this->env($emailKey);
        $pass  = $this->env($passKey);
        if ($email === '' || $pass === '') {
            $this->markTestSkipped("$emailKey / $passKey não configurados.");
        }
        $res = $this->http->apiLogin($email, $pass);
        if ($res['http_code'] === 0) {
            $this->markTestSkipped('Servidor de teste não acessível: ' . $res['body']);
        }
        $this->assertSame(200, $res['http_code'], 'Login de teste deveria retornar 200.');
        $token = $res['json']['token'] ?? ($res['json']['data']['token'] ?? '');
        $this->assertNotSame('', $token, 'Login deveria retornar token.');
        return $token;
    }

    private function requireFixture(string $key): string
    {
        $v = $this->env($key);
        if ($v === '') {
            $this->markTestSkipped("Fixture $key não configurada.");
        }
        return $v;
    }

    /** SEC-01 — GET /krs/:id/apontamentos não pode mais retornar 500. */
    public function testApontamentosListNaoRetorna500(): void
    {
        $token = $this->tokenFor('TEST_ADMIN_EMAIL', 'TEST_ADMIN_PASS');
        $kr    = $this->requireFixture('TEST_KR_ID');
        $res = $this->http->getWithToken("/api/api_platform/v1/krs/{$kr}/apontamentos", $token);
        $this->assertSame(200, $res['http_code'], 'Endpoint de apontamentos deveria responder 200.');
        $this->assertTrue(($res['json']['ok'] ?? false) === true);
    }

    /** SEC-02 — user_admin não pode criar usuário admin_master. */
    public function testCreateUsuarioNaoPermiteAdminMasterPorUsuarioComum(): void
    {
        $token = $this->tokenFor('TEST_ADMIN_EMAIL', 'TEST_ADMIN_PASS');
        $res = $this->http->postJsonWithToken('/api/api_platform/v1/usuarios', $token, [
            'primeiro_nome' => 'Escal',
            'email'         => 'escal_' . substr(md5((string)mt_rand()), 0, 8) . '@test.local',
            'password'      => 'senhaforte123',
            'role_key'      => 'admin_master',
        ]);
        $this->assertSame(403, $res['http_code'], 'Atribuir admin_master deveria ser proibido (403).');
    }

    /** SEC-03 — alterar role de usuário de outra empresa é negado. */
    public function testAlterarRoleDeUsuarioDeOutraEmpresaEhNegado(): void
    {
        $token  = $this->tokenFor('TEST_ADMIN_EMAIL', 'TEST_ADMIN_PASS');
        $otherId = $this->requireFixture('TEST_OTHER_USER_ID');
        $res = $this->http->putJsonWithToken("/api/api_platform/v1/usuarios/{$otherId}/role", $token, [
            'role_key' => 'user_colab',
        ]);
        $this->assertContains($res['http_code'], [403, 404], 'Cross-tenant em role deveria ser 403/404.');
    }

    /** SEC-04 — colaborador sem cap não cria iniciativa. */
    public function testIniciativaExigeCapability(): void
    {
        $token = $this->tokenFor('TEST_COLAB_EMAIL', 'TEST_COLAB_PASS');
        $kr    = $this->requireFixture('TEST_KR_ID');
        $res = $this->http->postJsonWithToken('/api/api_platform/v1/iniciativas', $token, [
            'id_kr'     => $kr,
            'descricao' => 'Tentativa sem permissão',
        ]);
        $this->assertSame(403, $res['http_code'], 'Colaborador sem cap deveria receber 403.');
    }

    /** SEC-05 — decidir aprovação de item de outra empresa é negado. */
    public function testDecidirAprovacaoDeOutraEmpresaEhNegado(): void
    {
        $token   = $this->tokenFor('TEST_APPROVER_EMAIL', 'TEST_APPROVER_PASS');
        $otherKr = $this->requireFixture('TEST_OTHER_KR_ID');
        $res = $this->http->postJsonWithToken('/api/api_platform/v1/aprovacoes/decidir', $token, [
            'modulo'  => 'kr',
            'id_ref'  => $otherKr,
            'decisao' => 'aprovado',
        ]);
        $this->assertContains($res['http_code'], [403, 404], 'Cross-tenant em aprovação deveria ser 403/404.');
    }

    /** SEC-06 — colaborador sem cap não cria orçamento. */
    public function testCriarOrcamentoExigeCapability(): void
    {
        $token = $this->tokenFor('TEST_COLAB_EMAIL', 'TEST_COLAB_PASS');
        $res = $this->http->postJsonWithToken('/api/api_platform/v1/orcamentos', $token, [
            'id_iniciativa' => 'qualquer',
            'valor'         => 100,
        ]);
        // 403 (sem cap) — não pode ser 201. 404 só ocorreria se a cap passasse e a iniciativa não existisse.
        $this->assertSame(403, $res['http_code'], 'Colaborador sem cap deveria receber 403.');
    }

    /** SEC-07 — colaborador sem cap não lista usuários. */
    public function testListarUsuariosExigeCapability(): void
    {
        $token = $this->tokenFor('TEST_COLAB_EMAIL', 'TEST_COLAB_PASS');
        $res = $this->http->getWithToken('/api/api_platform/v1/usuarios', $token);
        $this->assertSame(403, $res['http_code'], 'Listar usuários sem cap deveria ser 403.');
    }

    /** SEC-08 — cancelar KR (com justificativa) não pode retornar 500. */
    public function testCancelarKrNaoRetorna500(): void
    {
        $token = $this->tokenFor('TEST_ADMIN_EMAIL', 'TEST_ADMIN_PASS');
        $kr    = $this->requireFixture('TEST_KR_ID');
        $res = $this->http->postJsonWithToken("/api/api_platform/v1/krs/{$kr}/cancelar", $token, [
            'justificativa' => 'Cancelamento de teste de regressão',
        ]);
        $this->assertNotSame(500, $res['http_code'], 'Cancelar KR não deveria mais quebrar (500).');
    }

    /** SEC-09 — status inválido de KR retorna 422 (não 500 do FK). */
    public function testStatusInvalidoDeKrEhRejeitado(): void
    {
        $token = $this->tokenFor('TEST_ADMIN_EMAIL', 'TEST_ADMIN_PASS');
        $kr    = $this->requireFixture('TEST_KR_ID');
        $res = $this->http->putJsonWithToken("/api/api_platform/v1/krs/{$kr}", $token, [
            'status' => '__status_invalido__',
        ]);
        $this->assertSame(422, $res['http_code'], 'Status inválido deveria retornar 422.');
    }
}
