<?php
declare(strict_types=1);

namespace Tests\Unit\Cycle;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CalcDatasAnualTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/cycle_calc.php';
    }

    public static function anualProvider(): array
    {
        return [
            '2025'      => [['ciclo_anual_ano' => '2025'], ['2025-01-01', '2025-12-31']],
            '2026'      => [['ciclo_anual_ano' => '2026'], ['2026-01-01', '2026-12-31']],
            'ano vazio' => [['ciclo_anual_ano' => ''],     ['', '']],
            'sem campo' => [[],                             ['', '']],
        ];
    }

    #[DataProvider('anualProvider')]
    public function testAnual(array $dados, array $expected): void
    {
        $this->assertSame($expected, calcularDatasCiclo('anual', $dados));
    }
}
