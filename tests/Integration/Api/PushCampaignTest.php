<?php
declare(strict_types=1);

namespace Tests\Integration\Api;

use PHPUnit\Framework\TestCase;
use Tests\Helpers\HttpSmokeClient;

/**
 * Contrato de criação/edição de campanha push (F3 lote 5). admin_master.
 * O DISPARO real (POST /push/campaigns/:id/send) envia FCM → validação manual,
 * não automatizado aqui (efeitos colaterais).
 *
 * @group api-contract
 */
class PushCampaignTest extends TestCase
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

    public function testCreateExigeAdminMaster(): void
    {
        $token = $this->token('TEST_COLAB_EMAIL', 'TEST_COLAB_PASS');
        $res = $this->http->postJsonWithToken('/api/api_platform/v1/push/campaigns', $token, [
            'nome_interno' => 'x', 'titulo' => 'x', 'descricao' => 'x',
        ]);
        $this->assertSame(403, $res['http_code']);
    }

    public function testCreateRascunhoComoMaster(): void
    {
        $token = $this->token('TEST_MASTER_EMAIL', 'TEST_MASTER_PASS');
        $res = $this->http->postJsonWithToken('/api/api_platform/v1/push/campaigns', $token, [
            'nome_interno' => 'Teste contrato',
            'titulo'       => 'Olá',
            'descricao'    => 'Mensagem de teste',
            'canal'        => 'inbox',
        ]);
        $this->assertSame(201, $res['http_code']);
        $this->assertArrayHasKey('id_campaign', $res['json']['data'] ?? []);
    }

    public function testCreateValidaCampoObrigatorio(): void
    {
        $token = $this->token('TEST_MASTER_EMAIL', 'TEST_MASTER_PASS');
        $res = $this->http->postJsonWithToken('/api/api_platform/v1/push/campaigns', $token, [
            'titulo' => 'sem nome_interno',
        ]);
        $this->assertSame(422, $res['http_code']);
    }
}
