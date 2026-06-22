<?php
declare(strict_types=1);

namespace Tests\Integration\Api;

use PHPUnit\Framework\TestCase;
use Tests\Helpers\HttpSmokeClient;

/**
 * Contrato da matriz de prioridade (F3 lote 4): GET /matriz-prioridade.
 * Read-only, escopo da empresa (auth + tenant; sem cap especial).
 *
 * @group api-contract
 */
class MatrizPrioridadeTest extends TestCase
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

    public function testExigeAutenticacao(): void
    {
        $res = $this->http->get('/api/api_platform/v1/matriz-prioridade');
        if ($res['http_code'] === 0) {
            $this->markTestSkipped('Servidor de teste não acessível.');
        }
        $this->assertSame(401, $res['http_code'], 'Sem token deveria retornar 401.');
    }

    public function testEstruturaDosQuadrantes(): void
    {
        $email = $this->env('TEST_ADMIN_EMAIL');
        $pass  = $this->env('TEST_ADMIN_PASS');
        if ($email === '' || $pass === '') {
            $this->markTestSkipped('TEST_ADMIN_EMAIL / TEST_ADMIN_PASS não configurados.');
        }
        $login = $this->http->apiLogin($email, $pass);
        if ($login['http_code'] === 0) {
            $this->markTestSkipped('Servidor de teste não acessível.');
        }
        $token = $login['json']['token'] ?? ($login['json']['data']['token'] ?? '');

        $res = $this->http->getWithToken('/api/api_platform/v1/matriz-prioridade', $token);
        $this->assertSame(200, $res['http_code']);
        $data = $res['json']['data'] ?? [];
        $this->assertArrayHasKey('quadrantes', $data);
        foreach (['fazer', 'planejar', 'delegar', 'revisar'] as $q) {
            $this->assertArrayHasKey($q, $data['quadrantes']);
        }
        $this->assertArrayHasKey('total', $data['totais'] ?? []);
    }
}
