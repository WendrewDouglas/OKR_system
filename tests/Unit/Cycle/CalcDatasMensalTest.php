<?php
declare(strict_types=1);

namespace Tests\Unit\Cycle;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CalcDatasMensalTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/cycle_calc.php';
    }

    public static function mensalProvider(): array
    {
        return [
            'jan 2025' => [
                ['ciclo_mensal_mes' => '1', 'ciclo_mensal_ano' => '2025'],
                ['2025-01-01', '2025-01-31'],
            ],
            'fev 2025' => [
                ['ciclo_mensal_mes' => '2', 'ciclo_mensal_ano' => '2025'],
                ['2025-02-01', '2025-02-28'],
            ],
            'fev 2024 (bissexto)' => [
                ['ciclo_mensal_mes' => '2', 'ciclo_mensal_ano' => '2024'],
                ['2024-02-01', '2024-02-29'],
            ],
            'dez 2025' => [
                ['ciclo_mensal_mes' => '12', 'ciclo_mensal_ano' => '2025'],
                ['2025-12-01', '2025-12-31'],
            ],
            'sem mês' => [
                ['ciclo_mensal_ano' => '2025'],
                ['', ''],
            ],
            'sem ano' => [
                ['ciclo_mensal_mes' => '1'],
                ['', ''],
            ],
            'ambos vazios' => [
                [],
                ['', ''],
            ],
        ];
    }

    #[DataProvider('mensalProvider')]
    public function testMensal(array $dados, array $expected): void
    {
        $this->assertSame($expected, calcularDatasCiclo('mensal', $dados));
    }
}
