<?php
declare(strict_types=1);

namespace Tests\Unit\Cycle;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CalcDatasTrimestralTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/cycle_calc.php';
    }

    public static function trimestralProvider(): array
    {
        return [
            'Q1/2025' => [['ciclo_trimestral' => 'Q1/2025'], ['2025-01-01', '2025-03-31']],
            'Q2/2025' => [['ciclo_trimestral' => 'Q2/2025'], ['2025-04-01', '2025-06-30']],
            'Q3/2025' => [['ciclo_trimestral' => 'Q3/2025'], ['2025-07-01', '2025-09-30']],
            'Q4/2025' => [['ciclo_trimestral' => 'Q4/2025'], ['2025-10-01', '2025-12-31']],
            'Q1/2026' => [['ciclo_trimestral' => 'Q1/2026'], ['2026-01-01', '2026-03-31']],
            'inválido' => [['ciclo_trimestral' => 'Q5/2025'], ['', '']],
            'vazio'    => [['ciclo_trimestral' => ''],         ['', '']],
        ];
    }

    #[DataProvider('trimestralProvider')]
    public function testTrimestral(array $dados, array $expected): void
    {
        $this->assertSame($expected, calcularDatasCiclo('trimestral', $dados));
    }
}
