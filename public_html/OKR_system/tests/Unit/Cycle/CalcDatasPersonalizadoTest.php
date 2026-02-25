<?php
declare(strict_types=1);

namespace Tests\Unit\Cycle;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CalcDatasPersonalizadoTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/cycle_calc.php';
    }

    public static function personalizadoProvider(): array
    {
        return [
            'Q1 2025' => [
                ['ciclo_pers_inicio' => '2025-01', 'ciclo_pers_fim' => '2025-03'],
                ['2025-01-01', '2025-03-31'],
            ],
            'cross-year' => [
                ['ciclo_pers_inicio' => '2025-11', 'ciclo_pers_fim' => '2026-02'],
                ['2025-11-01', '2026-02-28'],
            ],
            'sem inicio' => [
                ['ciclo_pers_fim' => '2025-03'],
                ['', ''],
            ],
            'sem fim' => [
                ['ciclo_pers_inicio' => '2025-01'],
                ['', ''],
            ],
            'ambos vazios' => [
                [],
                ['', ''],
            ],
        ];
    }

    #[DataProvider('personalizadoProvider')]
    public function testPersonalizado(array $dados, array $expected): void
    {
        $this->assertSame($expected, calcularDatasCiclo('personalizado', $dados));
    }
}
