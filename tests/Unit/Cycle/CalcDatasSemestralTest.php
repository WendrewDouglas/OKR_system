<?php
declare(strict_types=1);

namespace Tests\Unit\Cycle;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CalcDatasSemestralTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/cycle_calc.php';
    }

    public static function semestralProvider(): array
    {
        return [
            'S1/2025'          => [['ciclo_semestral' => 'S1/2025'], ['2025-01-01', '2025-06-30']],
            'S2/2025'          => [['ciclo_semestral' => 'S2/2025'], ['2025-07-01', '2025-12-31']],
            'S1/2026'          => [['ciclo_semestral' => 'S1/2026'], ['2026-01-01', '2026-06-30']],
            'formato inválido' => [['ciclo_semestral' => 'X1/2025'], ['', '']],
            'vazio'            => [['ciclo_semestral' => ''],        ['', '']],
            'sem campo'        => [[],                                ['', '']],
        ];
    }

    #[DataProvider('semestralProvider')]
    public function testSemestral(array $dados, array $expected): void
    {
        $this->assertSame($expected, calcularDatasCiclo('semestral', $dados));
    }
}
