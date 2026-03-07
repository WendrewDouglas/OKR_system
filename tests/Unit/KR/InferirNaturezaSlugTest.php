<?php
declare(strict_types=1);

namespace Tests\Unit\KR;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InferirNaturezaSlugTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/kr_helpers.php';
    }

    public static function slugProvider(): array
    {
        return [
            // Slugs diretos
            'acumulativo_constante'      => ['acumulativo_constante',     'acumulativo_constante'],
            'acumulativo_exponencial'    => ['acumulativo_exponencial',   'acumulativo_exponencial'],
            'pontual'                    => ['pontual',                   'pontual'],
            'binario'                    => ['binario',                   'binario'],

            // Aliases → acumulativo_constante
            'acumulativo'                => ['acumulativo',               'acumulativo_constante'],
            'acumulativa'                => ['acumulativa',               'acumulativo_constante'],
            'acumulado_constante'        => ['acumulado_constante',       'acumulativo_constante'],
            'constante'                  => ['constante',                 'acumulativo_constante'],

            // Aliases → acumulativo_exponencial
            'exponencial'                => ['exponencial',               'acumulativo_exponencial'],
            'acumulado_exponencial'      => ['acumulado_exponencial',     'acumulativo_exponencial'],
            'expo'                       => ['expo',                      'acumulativo_exponencial'],

            // Aliases → binario
            'binaria'                    => ['binaria',                   'binario'],

            // Aliases → pontual
            'flutuante'                  => ['flutuante',                 'pontual'],

            // Com espaços e acentos
            'Acumulativo Constante'      => ['Acumulativo Constante',     'acumulativo_constante'],
        ];
    }

    #[DataProvider('slugProvider')]
    public function testSlugifyNat(string $input, string $expected): void
    {
        $this->assertSame($expected, _slugify_nat($input));
    }

    public function testUnidadeRequerInteiroTrue(): void
    {
        $this->assertTrue(unidadeRequerInteiro('unid'));
        $this->assertTrue(unidadeRequerInteiro('itens'));
        $this->assertTrue(unidadeRequerInteiro('pessoas'));
        $this->assertTrue(unidadeRequerInteiro('tickets'));
    }

    public function testUnidadeRequerInteiroFalse(): void
    {
        $this->assertFalse(unidadeRequerInteiro('%'));
        $this->assertFalse(unidadeRequerInteiro('R$'));
        $this->assertFalse(unidadeRequerInteiro(''));
        $this->assertFalse(unidadeRequerInteiro(null));
    }
}
