<?php
declare(strict_types=1);

namespace Tests\Integration\Api;

use PHPUnit\Framework\TestCase;
use Tests\Helpers\HttpSmokeClient;

/**
 * Contrato do reset de estilo (F3 lote 2): DELETE /company/style.
 *
 * Skip gracioso sem servidor/credenciais. Variáveis:
 *   TEST_BASE_URL
 *   TEST_ADMIN_EMAIL/PASS  — admin da empresa (user_admin/admin_master)
 *   TEST_COLAB_EMAIL/PASS  — usuário sem privilégio (caso 403)
 *
 * @group api-contract
 */
class CompanyStyleTest extends TestCase
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
        return $res['json']['token'] ?? ($res['json']['data']['token'] ?? '');
    }

    public function testResetEstiloComoAdmin(): void
    {
        $token = $this->token('TEST_ADMIN_EMAIL', 'TEST_ADMIN_PASS');
        $res = $this->http->deleteWithToken('/api/api_platform/v1/company/style', $token);
        $this->assertSame(200, $res['http_code']);
        $this->assertSame('#222222', $res['json']['data']['bg1_hex'] ?? null);
        $this->assertNull($res['json']['data']['logo_base64'] ?? 'x');
    }

    public function testResetEstiloNegaColaborador(): void
    {
        $token = $this->token('TEST_COLAB_EMAIL', 'TEST_COLAB_PASS');
        $res = $this->http->deleteWithToken('/api/api_platform/v1/company/style', $token);
        $this->assertSame(403, $res['http_code']);
    }
}
