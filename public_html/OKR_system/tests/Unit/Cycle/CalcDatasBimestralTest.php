<?php
declare(strict_types=1);

namespace Tests\Unit\Cycle;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CalcDatasBimestralTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/cycle_calc.php';
    }

    public static function bimestralProvider(): array
    {
        return [
            'jan-fev 2025' => [['ciclo_bimestral' => '01-02-2025'], ['2025-01-01', '2025-02-28']],
            'mar-abr 2025' => [['ciclo_bimestral' => '03-04-2025'], ['2025-03-01', '2025-04-30']],
            'nov-dez 2025' => [['ciclo_bimestral' => '11-12-2025'], ['2025-11-01', '2025-12-31']],
            'jan-fev 2024 (bissexto)' => [['ciclo_bimestral' => '01-02-2024'], ['2024-01-01', '2024-02-29']],
            'formato inválido' => [['ciclo_bimestral' => '1-2-2025'], ['', '']],
            'vazio'            => [['ciclo_bimestral' => ''],          ['', '']],
        ];
    }

    #[DataProvider('bimestralProvider')]
    public function testBimestral(array $dados, array $expected): void
    {
        $this->assertSame($expected, calcularDatasCiclo('bimestral', $dados));
    }
}
