<?php
declare(strict_types=1);

namespace Tests\Smoke;

use PHPUnit\Framework\TestCase;
use Tests\Helpers\HttpSmokeClient;

/**
 * Smoke tests para endpoints de login.
 * Requerem o servidor Apache rodando.
 */
class ApiLoginSmokeTest extends TestCase
{
    private HttpSmokeClient $client;

    protected function setUp(): void
    {
        // Prioridade: env var do sistema > phpunit.xml > skip
        $baseUrl = getenv('TEST_BASE_URL') ?: ($_ENV['TEST_BASE_URL'] ?? '');
        if ($baseUrl === '') {
            $this->markTestSkipped('TEST_BASE_URL não configurado');
        }
        $this->client = new HttpSmokeClient($baseUrl);
    }

    public function testApiLoginInvalidCredentials(): void
    {
        $res = $this->client->apiLogin('invalid@test.local', 'wrongpass');
        if ($res['http_code'] === 0) {
            $this->markTestSkipped('Servidor não acessível: ' . $res['body']);
        }
        $this->assertSame(401, $res['http_code'], 'Login inválido deveria retornar 401');
    }

    public function testApiLoginValidCredentials(): void
    {
        $email = $_ENV['TEST_USER_EMAIL'] ?? '';
        $pass  = $_ENV['TEST_USER_PASS'] ?? '';
        if ($email === '' || $pass === '') {
            $this->markTestSkipped('TEST_USER_EMAIL / TEST_USER_PASS não configurados');
        }

        $res = $this->client->apiLogin($email, $pass);
        $this->assertSame(200, $res['http_code']);
        $this->assertNotNull($res['json'], 'Resposta deveria ser JSON');
    }
}
