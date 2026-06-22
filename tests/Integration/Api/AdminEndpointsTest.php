<?php
declare(strict_types=1);

namespace Tests\Integration\Api;

use PHPUnit\Framework\TestCase;
use Tests\Helpers\HttpSmokeClient;

/**
 * Contrato dos endpoints novos da F3 (admin_master): companies + system/health.
 *
 * Skip gracioso sem servidor/credenciais. Variáveis:
 *   TEST_BASE_URL
 *   TEST_MASTER_EMAIL/PASS  — usuário admin_master
 *   TEST_COLAB_EMAIL/PASS   — usuário sem privilégio (para o caso 403)
 *
 * @group api-contract
 */
class AdminEndpointsTest extends TestCase
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

    private function env(string $k): string
    {
        return (string)(getenv($k) ?: ($_ENV[$k] ?? ''));
    }

    private function token(string $emailKey, string $passKey): string
    {
        $email = $this->env($emailKey);
        $pass  = $this->env($passKey);
        if ($email === '' || $pass === '') {
            $this->markTestSkipped("$emailKey / $passKey não configurados.");
        }
        $res = $this->http->apiLogin($email, $pass);
        if ($res['http_code'] === 0) {
            $this->markTestSkipped('Servidor de teste não acessível.');
        }
        $this->assertSame(200, $res['http_code']);
        return $res['json']['token'] ?? ($res['json']['data']['token'] ?? '');
    }

    public function testListCompaniesExigeAdminMaster(): void
    {
        $token = $this->token('TEST_COLAB_EMAIL', 'TEST_COLAB_PASS');
        $res = $this->http->getWithToken('/api/api_platform/v1/companies', $token);
        $this->assertSame(403, $res['http_code'], 'Não-admin_master deveria receber 403.');
    }

    public function testListCompaniesComoMaster(): void
    {
        $token = $this->token('TEST_MASTER_EMAIL', 'TEST_MASTER_PASS');
        $res = $this->http->getWithToken('/api/api_platform/v1/companies', $token);
        $this->assertSame(200, $res['http_code']);
        $this->assertTrue(isset($res['json']['data']), 'Resposta deveria ter envelope `data`.');
        $this->assertTrue(isset($res['json']['pagination']), 'Lista deveria ter `pagination`.');
    }

    public function testSystemHealthComoMaster(): void
    {
        $token = $this->token('TEST_MASTER_EMAIL', 'TEST_MASTER_PASS');
        $res = $this->http->getWithToken('/api/api_platform/v1/system/health', $token);
        $this->assertSame(200, $res['http_code']);
        $overall = $res['json']['data']['overall'] ?? null;
        $this->assertContains($overall, ['PASS', 'WARN', 'FAIL']);
    }

    public function testSystemHealthNegaNaoMaster(): void
    {
        $token = $this->token('TEST_COLAB_EMAIL', 'TEST_COLAB_PASS');
        $res = $this->http->getWithToken('/api/api_platform/v1/system/health', $token);
        $this->assertSame(403, $res['http_code']);
    }
}
