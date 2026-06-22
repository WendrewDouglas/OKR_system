<?php
declare(strict_types=1);

namespace Tests\Integration\Api;

use PHPUnit\Framework\TestCase;
use Tests\Helpers\HttpSmokeClient;

/**
 * Contrato do relatório PDF de objetivo (F3 lote 3).
 * GET /objetivos/:id/relatorio — retorna application/pdf (binário, não JSON).
 *
 * Skip gracioso sem servidor/credenciais. Variáveis:
 *   TEST_BASE_URL
 *   TEST_MASTER_EMAIL/PASS  — admin_master (bypassa cap e tenant; valida geração do PDF)
 *   TEST_OBJ_ID             — id de um objetivo existente
 *   TEST_COLAB_EMAIL/PASS   — usuário sem cap R:relatorio (caso 403)
 *
 * @group api-contract
 */
class RelatorioPdfTest extends TestCase
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

    public function testGeraPdf(): void
    {
        $token = $this->token('TEST_MASTER_EMAIL', 'TEST_MASTER_PASS');
        $obj = $this->env('TEST_OBJ_ID');
        if ($obj === '') {
            $this->markTestSkipped('TEST_OBJ_ID não configurado.');
        }
        $res = $this->http->getWithToken("/api/api_platform/v1/objetivos/$obj/relatorio", $token);
        $this->assertSame(200, $res['http_code'], 'Geração do PDF deveria responder 200.');
        // PDF é binário → não é JSON.
        $this->assertNull($res['json'], 'Resposta de PDF não deveria ser JSON.');
        $this->assertStringStartsWith('%PDF', ltrim($res['body']), 'Corpo deveria ser um PDF.');
    }

    public function testSemCapRelatorioNega(): void
    {
        $token = $this->token('TEST_COLAB_EMAIL', 'TEST_COLAB_PASS');
        $obj = $this->env('TEST_OBJ_ID');
        if ($obj === '') {
            $this->markTestSkipped('TEST_OBJ_ID não configurado.');
        }
        $res = $this->http->getWithToken("/api/api_platform/v1/objetivos/$obj/relatorio", $token);
        // Sem cap R:relatorio (ou objetivo de outra empresa) → 403/404.
        $this->assertContains($res['http_code'], [403, 404]);
    }
}
