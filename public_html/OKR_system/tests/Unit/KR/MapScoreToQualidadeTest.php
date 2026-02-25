<?php
declare(strict_types=1);

namespace Tests\Unit\KR;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MapScoreToQualidadeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/kr_helpers.php';
    }

    public static function scoreProvider(): array
    {
        return [
            'null'           => [null, null],
            '0 → péssimo'   => [0,    'péssimo'],
            '1 → péssimo'   => [1,    'péssimo'],
            '2 → péssimo'   => [2,    'péssimo'],
            '3 → ruim'      => [3,    'ruim'],
            '4 → ruim'      => [4,    'ruim'],
            '5 → moderado'  => [5,    'moderado'],
            '6 → moderado'  => [6,    'moderado'],
            '7 → bom'       => [7,    'bom'],
            '8 → bom'       => [8,    'bom'],
            '9 → ótimo'     => [9,    'ótimo'],
            '10 → ótimo'    => [10,   'ótimo'],
        ];
    }

    #[DataProvider('scoreProvider')]
    public function testMapScoreToQualidade(?int $score, ?string $expected): void
    {
        $this->assertSame($expected, mapScoreToQualidade($score));
    }
}
