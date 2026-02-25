<?php
declare(strict_types=1);

namespace Tests\Unit\KR;

use PHPUnit\Framework\TestCase;

class GerarSerieDatasTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/kr_helpers.php';
    }

    public function testMensalQ1(): void
    {
        $datas = gerarSerieDatas('2025-01-01', '2025-03-31', 'mensal');
        $this->assertNotEmpty($datas);
        // Deve incluir a data final
        $this->assertSame('2025-03-31', end($datas));
        // Deve ter pelo menos 2 pontos (jan, fev ou mar)
        $this->assertGreaterThanOrEqual(2, count($datas));
    }

    public function testSemanal(): void
    {
        $datas = gerarSerieDatas('2025-01-01', '2025-01-31', 'semanal');
        $this->assertNotEmpty($datas);
        $this->assertSame('2025-01-31', end($datas));
        // ~4 semanas em janeiro + data final
        $this->assertGreaterThanOrEqual(3, count($datas));
    }

    public function testQuinzenal(): void
    {
        $datas = gerarSerieDatas('2025-01-01', '2025-03-31', 'quinzenal');
        $this->assertNotEmpty($datas);
        $this->assertSame('2025-03-31', end($datas));
    }

    public function testTrimestral(): void
    {
        $datas = gerarSerieDatas('2025-01-01', '2025-12-31', 'trimestral');
        $this->assertNotEmpty($datas);
        $this->assertSame('2025-12-31', end($datas));
    }

    public function testSemestral(): void
    {
        $datas = gerarSerieDatas('2025-01-01', '2025-12-31', 'semestral');
        $this->assertNotEmpty($datas);
        $this->assertSame('2025-12-31', end($datas));
    }

    public function testAnual(): void
    {
        $datas = gerarSerieDatas('2025-01-01', '2025-12-31', 'anual');
        $this->assertNotEmpty($datas);
        $this->assertSame('2025-12-31', end($datas));
    }

    public function testRangeInvertidoRetornaPeloMenosUmaData(): void
    {
        $datas = gerarSerieDatas('2025-06-01', '2025-01-01', 'mensal');
        $this->assertNotEmpty($datas);
    }

    public function testMesmoDiaRetornaUmaData(): void
    {
        $datas = gerarSerieDatas('2025-01-15', '2025-01-15', 'mensal');
        $this->assertCount(1, $datas);
        $this->assertSame('2025-01-15', $datas[0]);
    }

    public function testSemDuplicatas(): void
    {
        $datas = gerarSerieDatas('2025-01-01', '2025-06-30', 'mensal');
        $this->assertSame(array_values(array_unique($datas)), $datas);
    }
}
