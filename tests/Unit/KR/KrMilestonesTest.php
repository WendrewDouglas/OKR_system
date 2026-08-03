<?php
declare(strict_types=1);

namespace Tests\Unit\KR;

use PHPUnit\Framework\TestCase;

/**
 * Testes das funções PURAS de auth/helpers/kr_milestones.php — validação e
 * ajuste de milestones previstos (fecho da meta, monotonia, faixas, vencidos)
 * e as estratégias de ajuste (reescalar / distribuir resíduo). Sem DB.
 */
final class KrMilestonesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/kr_milestones.php';
    }

    /* ---------------- fábricas ---------------- */

    private function kr(array $over = []): array
    {
        return array_merge([
            'baseline' => 0.0, 'meta' => 100.0,
            'direcao_metrica' => 'MAIOR_MELHOR',
            'natureza_kr' => 'acumulativo',
            'unidade_medida' => '%',
            'margem_confianca' => 10,
        ], $over);
    }

    /** série a partir de valores; datas mensais a partir de 2026-01-31 */
    private function serie(array $valores, array $editados = []): array
    {
        $out = [];
        foreach ($valores as $i => $v) {
            $out[] = [
                'num_ordem' => $i + 1,
                'data_ref'  => sprintf('2026-%02d-28', $i + 1),
                'valor_esperado' => $v,
                'valor_esperado_min' => null,
                'valor_esperado_max' => null,
                'editado' => in_array($i + 1, $editados, true),
            ];
        }
        return $out;
    }

    private function codigos(array $itens): array
    {
        return array_values(array_unique(array_map(fn($e) => $e['codigo'], $itens)));
    }

    /* ---------------- tolerância / natureza ---------------- */

    public function testToleranciaPorUnidade(): void
    {
        $this->assertSame(0.5, krm_tolerancia('unid'));
        $this->assertSame(0.005, krm_tolerancia('%'));
        $this->assertSame(0.005, krm_tolerancia(null));
    }

    public function testNaturezaAcumulativaEBinaria(): void
    {
        // ids reais do domínio dom_natureza_kr
        $this->assertTrue(krm_natureza_acumulativa('acumulativo'));
        $this->assertTrue(krm_natureza_acumulativa('acumulativo_exponenc'));
        $this->assertFalse(krm_natureza_acumulativa('pontual'));
        $this->assertFalse(krm_natureza_acumulativa('binario'));
        $this->assertTrue(krm_natureza_binaria('binario'));
    }

    /* ---------------- fecho da meta (rígida) ---------------- */

    public function testSerieCoerenteNaoTemErros(): void
    {
        $r = krm_validar_serie($this->kr(), $this->serie([25, 50, 75, 100]));
        $this->assertSame([], $r['erros']);
        $this->assertSame([], $r['avisos']);
    }

    public function testFechoMetaViolado(): void
    {
        $r = krm_validar_serie($this->kr(), $this->serie([25, 50, 75, 90]));
        $this->assertContains('FECHO_META', $this->codigos($r['erros']));
    }

    public function testFechoRespeitaToleranciaDeUnidadeInteira(): void
    {
        $kr = $this->kr(['meta' => 100, 'unidade_medida' => 'unid']);
        // 99.7 arredonda para 100 na régua de inteiros (ε=0.5)
        $r = krm_validar_serie($kr, $this->serie([25, 50, 75, 99.7]));
        $this->assertNotContains('FECHO_META', $this->codigos($r['erros']));
    }

    public function testSerieVazia(): void
    {
        $r = krm_validar_serie($this->kr(), []);
        $this->assertContains('SERIE_VAZIA', $this->codigos($r['erros']));
    }

    /* ---------------- monotonia por natureza ---------------- */

    public function testMonotoniaRigidaParaAcumulativoMaiorMelhor(): void
    {
        $r = krm_validar_serie($this->kr(), $this->serie([25, 60, 40, 100]));
        $this->assertContains('MONOTONIA', $this->codigos($r['erros']));
    }

    public function testMonotoniaBrandaParaPontual(): void
    {
        $kr = $this->kr(['natureza_kr' => 'pontual']);
        $r = krm_validar_serie($kr, $this->serie([25, 60, 40, 100]));
        $this->assertNotContains('MONOTONIA', $this->codigos($r['erros']));
        $this->assertContains('MONOTONIA', $this->codigos($r['avisos']));
    }

    public function testMonotoniaMenorMelhor(): void
    {
        $kr = $this->kr(['baseline' => 100, 'meta' => 20, 'direcao_metrica' => 'MENOR_MELHOR']);
        // subir no meio é violação para menor melhor
        $r = krm_validar_serie($kr, $this->serie([80, 90, 40, 20]));
        $this->assertContains('MONOTONIA', $this->codigos($r['erros']));
        // descida limpa passa
        $r2 = krm_validar_serie($kr, $this->serie([80, 60, 40, 20]));
        $this->assertSame([], $r2['erros']);
    }

    public function testPlatoNaoViolaMonotonia(): void
    {
        $r = krm_validar_serie($this->kr(), $this->serie([25, 25, 75, 100]));
        $this->assertSame([], $r['erros']);
    }

    /* ---------------- faixa plausível (branda) ---------------- */

    public function testForaDaFaixaGeraAviso(): void
    {
        $kr = $this->kr(['natureza_kr' => 'pontual']);
        // 120 > meta antes do fim; -10 < baseline
        $r = krm_validar_serie($kr, $this->serie([-10, 120, 80, 100]));
        $this->assertContains('FORA_FAIXA', $this->codigos($r['avisos']));
    }

    public function testManterAvisaAlemDeTau(): void
    {
        $kr = $this->kr(['baseline' => 50, 'meta' => 50, 'natureza_kr' => 'pontual']);
        // τ=10% de 50 → banda 45..55; 40 distoa
        $r = krm_validar_serie($kr, $this->serie([50, 40, 50, 50]));
        $this->assertContains('FORA_FAIXA', $this->codigos($r['avisos']));
        $this->assertSame([], $r['erros']); // fecho ok (50 = meta)
    }

    /* ---------------- binário ---------------- */

    public function testBinarioSoAceitaBaselineOuMeta(): void
    {
        $kr = $this->kr(['natureza_kr' => 'binario', 'baseline' => 0, 'meta' => 1]);
        $ok  = krm_validar_serie($kr, $this->serie([0, 0, 0, 1]));
        $bad = krm_validar_serie($kr, $this->serie([0, 0.5, 0, 1]));
        $this->assertSame([], $ok['erros']);
        $this->assertContains('BINARIO_VALOR', $this->codigos($bad['erros']));
    }

    /* ---------------- vencido editado ---------------- */

    public function testVencidoEditadoGeraAviso(): void
    {
        // hoje = 2026-02-28 → milestones 1 e 2 vencidos; 2 foi editado
        $r = krm_validar_serie($this->kr(), $this->serie([25, 50, 75, 100], [2]), '2026-02-28');
        $this->assertContains('VENCIDO_EDITADO', $this->codigos($r['avisos']));
        // futuro editado não avisa
        $r2 = krm_validar_serie($this->kr(), $this->serie([25, 50, 75, 100], [4]), '2026-02-28');
        $this->assertNotContains('VENCIDO_EDITADO', $this->codigos($r2['avisos']));
    }

    /* ---------------- INTERVALO_IDEAL ---------------- */

    private function serieIntervalo(array $faixas): array
    {
        $out = [];
        foreach ($faixas as $i => [$mn, $mx]) {
            $out[] = [
                'num_ordem' => $i + 1,
                'data_ref'  => sprintf('2026-%02d-28', $i + 1),
                'valor_esperado' => ($mn + $mx) / 2,
                'valor_esperado_min' => $mn,
                'valor_esperado_max' => $mx,
            ];
        }
        return $out;
    }

    public function testIntervaloFaixaFinalDeveSerADoKR(): void
    {
        $kr = $this->kr(['direcao_metrica' => 'INTERVALO_IDEAL', 'baseline' => 60, 'meta' => 80]);
        $ok  = krm_validar_serie($kr, $this->serieIntervalo([[60, 80], [60, 80]]));
        $bad = krm_validar_serie($kr, $this->serieIntervalo([[60, 80], [65, 75]]));
        $this->assertSame([], $ok['erros']);
        $this->assertContains('FECHO_META', $this->codigos($bad['erros']));
    }

    public function testIntervaloMinMaiorQueMax(): void
    {
        $kr = $this->kr(['direcao_metrica' => 'INTERVALO_IDEAL', 'baseline' => 60, 'meta' => 80]);
        $r = krm_validar_serie($kr, $this->serieIntervalo([[90, 70], [60, 80]]));
        $this->assertContains('MIN_MAIOR_MAX', $this->codigos($r['erros']));
    }

    public function testIntervaloFaixaDisjuntaAvisa(): void
    {
        $kr = $this->kr(['direcao_metrica' => 'INTERVALO_IDEAL', 'baseline' => 60, 'meta' => 80]);
        $r = krm_validar_serie($kr, $this->serieIntervalo([[10, 20], [60, 80]]));
        $this->assertContains('FAIXA_DISJUNTA', $this->codigos($r['avisos']));
    }

    /* ---------------- krm_reescalar ---------------- */

    public function testReescalarPreservaFormaEFecha(): void
    {
        // série fechava em 80; meta é 100 → fator (100-0)/(80-0)=1.25
        $out = krm_reescalar($this->kr(), $this->serie([20, 40, 60, 80]));
        $this->assertSame([25.0, 50.0, 75.0, 100.0], array_column($out, 'valor_esperado'));
    }

    public function testReescalarComBaselineNaoZero(): void
    {
        $kr = $this->kr(['baseline' => 100, 'meta' => 20, 'direcao_metrica' => 'MENOR_MELHOR']);
        $out = krm_reescalar($kr, $this->serie([80, 60, 40, 40]));
        // den = 40-100 = -60; fator = (20-100)/-60 = 4/3 → 100 + (80-100)·4/3 = 73.33
        $this->assertSame(73.33, $out[0]['valor_esperado']);
        $this->assertSame(20.0, $out[3]['valor_esperado']);
    }

    public function testReescalarDegeneradoViraLinear(): void
    {
        // série termina no próprio baseline → rampa linear até a meta
        $out = krm_reescalar($this->kr(), $this->serie([0, 0, 0, 0]));
        $this->assertSame([25.0, 50.0, 75.0, 100.0], array_column($out, 'valor_esperado'));
    }

    public function testReescalarUnidadeInteiraFechaExato(): void
    {
        $kr = $this->kr(['meta' => 10, 'unidade_medida' => 'unid']);
        $out = krm_reescalar($kr, $this->serie([1, 2, 3]));
        $vals = array_column($out, 'valor_esperado');
        $this->assertSame(10.0, end($vals));
        foreach ($vals as $v) $this->assertSame((float)round($v), $v); // inteiros
    }

    /* ---------------- krm_distribuir_residuo ---------------- */

    public function testResiduoPreservaPassadoEFechaFuturo(): void
    {
        // hoje = 2026-02-28: milestones 1,2 vencidos ficam intactos
        $out = krm_distribuir_residuo($this->kr(), $this->serie([25, 50, 60, 80]), '2026-02-28');
        $this->assertNotNull($out);
        // vencidos ficam intactos (valor original, sem cast)
        $this->assertEquals(25, $out[0]['valor_esperado']);
        $this->assertEquals(50, $out[1]['valor_esperado']);
        // âncora A=50, den=80-50=30, fator=(100-50)/30=5/3 → 60→66.67, 80→100
        $this->assertSame(66.67, $out[2]['valor_esperado']);
        $this->assertSame(100.0, $out[3]['valor_esperado']);
    }

    public function testResiduoSemFuturosRetornaNull(): void
    {
        $out = krm_distribuir_residuo($this->kr(), $this->serie([25, 50, 60, 80]), '2026-12-31');
        $this->assertNull($out);
    }

    public function testResiduoTodosFuturosAncoraNoBaseline(): void
    {
        $out = krm_distribuir_residuo($this->kr(), $this->serie([20, 40, 60, 80]), '2025-12-31');
        $this->assertNotNull($out);
        // equivale à reescala total (A = baseline)
        $this->assertSame([25.0, 50.0, 75.0, 100.0], array_column($out, 'valor_esperado'));
    }

    public function testResiduoDegeneradoLinearDaAncoraAteMeta(): void
    {
        // futuro plano (60,60) com vN=60=A? A=50 (último vencido), den=60-50=10 ok;
        // caso den=0: futuros iguais à âncora
        $out = krm_distribuir_residuo($this->kr(), $this->serie([25, 50, 50, 50]), '2026-02-28');
        $this->assertNotNull($out);
        // A=50, den=0 → linear de 50 a 100 em 2 passos: 75, 100
        $this->assertSame(75.0, $out[2]['valor_esperado']);
        $this->assertSame(100.0, $out[3]['valor_esperado']);
    }

    /* ---------------- krm_ajustar_faixa_final ---------------- */

    public function testAjustarFaixaFinalIntervalo(): void
    {
        $kr = $this->kr(['direcao_metrica' => 'INTERVALO_IDEAL', 'baseline' => 60, 'meta' => 80]);
        $out = krm_ajustar_faixa_final($kr, $this->serieIntervalo([[60, 80], [65, 75]]));
        $this->assertSame(60.0, $out[1]['valor_esperado_min']);
        $this->assertSame(80.0, $out[1]['valor_esperado_max']);
    }
}
