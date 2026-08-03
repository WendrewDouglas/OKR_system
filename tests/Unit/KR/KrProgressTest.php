<?php
declare(strict_types=1);

namespace Tests\Unit\KR;

use PHPUnit\Framework\TestCase;

/**
 * Testes das funções PURAS de auth/helpers/kr_progress.php — o núcleo de
 * progresso/farol (assunto dos últimos ajustes de τ e bandas). Sem DB.
 *
 * Cobre: krp_is_intervalo, krp_tau_pct (normalização da margem de confiança),
 * krp_status_excluido (quais status saem da média) e krp_farol_pior (roll-up).
 */
final class KrProgressTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/auth/helpers/kr_progress.php';
    }

    /* ---------------- krp_is_intervalo ---------------- */

    public function testIsIntervaloVerdadeiroCaseInsensitive(): void
    {
        $this->assertTrue(krp_is_intervalo('INTERVALO_IDEAL'));
        $this->assertTrue(krp_is_intervalo('intervalo_ideal'));
        $this->assertTrue(krp_is_intervalo('Intervalo_Ideal'));
    }

    public function testIsIntervaloFalso(): void
    {
        $this->assertFalse(krp_is_intervalo('MAIOR_MELHOR'));
        $this->assertFalse(krp_is_intervalo('menor_melhor'));
        $this->assertFalse(krp_is_intervalo(null));
        $this->assertFalse(krp_is_intervalo(''));
    }

    /* ---------------- krp_tau_pct ---------------- */

    public function testTauDefaultParaInvalidoOuNaoPositivo(): void
    {
        $this->assertSame(10.0, krp_tau_pct(null));
        $this->assertSame(10.0, krp_tau_pct(''));
        $this->assertSame(10.0, krp_tau_pct('abc'));
        $this->assertSame(10.0, krp_tau_pct(0));
        $this->assertSame(10.0, krp_tau_pct(-5));
    }

    public function testTauFracaoViraPercentual(): void
    {
        // 0..1 é interpretado como fração → *100
        $this->assertSame(10.0, krp_tau_pct(0.10));
        $this->assertSame(25.0, krp_tau_pct(0.25));
        $this->assertSame(5.0,  krp_tau_pct(0.05));
    }

    public function testTauPercentualLiteral(): void
    {
        // > 1 é interpretado como percentual já pronto
        $this->assertSame(15.0, krp_tau_pct(15));
        $this->assertSame(15.0, krp_tau_pct('15'));
        $this->assertSame(1.5,  krp_tau_pct(1.5));
    }

    public function testTauBordaExatamenteUm(): void
    {
        // v > 1.0 é falso para 1.0 → cai no ramo da fração (1.0 * 100)
        $this->assertSame(100.0, krp_tau_pct(1.0));
    }

    /* ---------------- krp_status_excluido ---------------- */

    public function testStatusExcluidos(): void
    {
        // valores reais armazenados (strtolower é byte-wise; acento minúsculo ok)
        foreach (['Não Iniciado', 'não iniciado', 'nao iniciado', 'nao-iniciado',
                  'Cancelado', 'cancelada', 'cancelled', '  Cancelado  '] as $s) {
            $this->assertTrue(krp_status_excluido($s), "deveria excluir: '$s'");
        }
    }

    public function testStatusNaoExcluidos(): void
    {
        foreach (['Em Andamento', 'Concluído', 'Pausado', 'Atrasado', null, ''] as $s) {
            $this->assertFalse(krp_status_excluido($s), 'não deveria excluir: ' . var_export($s, true));
        }
    }

    /* ---------------- krp_farol_pior ---------------- */

    public function testFarolPiorPegaOPiorCaso(): void
    {
        $this->assertSame('vermelho', krp_farol_pior(['verde', 'vermelho', 'amarelo']));
        $this->assertSame('amarelo',  krp_farol_pior(['verde', 'amarelo', 'verde']));
        $this->assertSame('verde',    krp_farol_pior(['verde', 'cinza']));
        $this->assertSame('cinza',    krp_farol_pior(['cinza', 'cinza']));
    }

    public function testFarolPiorVazioOuNulosViraCinza(): void
    {
        $this->assertSame('cinza', krp_farol_pior([]));
        $this->assertSame('cinza', krp_farol_pior([null, '', null]));
    }

    public function testFarolPiorIgnoraNulosMasMantemPior(): void
    {
        $this->assertSame('amarelo', krp_farol_pior([null, 'amarelo', '', null, 'verde']));
        $this->assertSame('vermelho', krp_farol_pior(['', 'verde', 'vermelho', null]));
    }

    /* ---------------- krp_calc_pontual: âncora no baseline/meta do KR ---------------- */

    /** série mensal simples com valores esperados e um real no 2º milestone */
    private function msSerie(array $esperados, array $reais = []): array
    {
        $out = [];
        foreach ($esperados as $i => $v) {
            $out[] = [
                'num_ordem' => $i + 1,
                'data_ref'  => sprintf('2026-%02d-28', $i + 1),
                'valor_esperado' => $v,
                'valor_esperado_min' => null,
                'valor_esperado_max' => null,
                'valor_real_consolidado' => $reais[$i] ?? null,
            ];
        }
        return $out;
    }

    public function testBarraAncoraNoBaselineDoKR(): void
    {
        // baseline 0, meta 100; série gerada começa em 25 (B + Δ/N).
        // Com a âncora no KR, real=50 => barra 50% (e não (50-25)/(100-25)=33%).
        $kr = ['baseline' => 0, 'meta' => 100, 'direcao_metrica' => 'MAIOR_MELHOR', 'margem_confianca' => 10];
        $ms = $this->msSerie([25, 50, 75, 100], [null, 50.0, null, null]);
        $r = krp_calc_pontual($kr, $ms, '2026-02-28');
        $this->assertSame(50.0, $r['p_barra']);
        $this->assertSame('verde', $r['farol']); // real 50 == esperado 50 no vencido
    }

    public function testBarraImuneAEdicaoManualDoPrimeiroMilestone(): void
    {
        // 1º milestone editado para 60 não muda o denominador da barra
        $kr = ['baseline' => 0, 'meta' => 100, 'direcao_metrica' => 'MAIOR_MELHOR', 'margem_confianca' => 10];
        $ms = $this->msSerie([60, 70, 85, 100], [null, 50.0, null, null]);
        $r = krp_calc_pontual($kr, $ms, '2026-02-28');
        $this->assertSame(50.0, $r['p_barra']);
    }

    public function testFallbackParaExtremosDaSerieSemBaselineMeta(): void
    {
        // KR sem baseline/meta numéricos → comportamento legado (extremos da série)
        $kr = ['baseline' => null, 'meta' => null, 'direcao_metrica' => 'MAIOR_MELHOR', 'margem_confianca' => 10];
        $ms = $this->msSerie([25, 50, 75, 100], [null, 62.5, null, null]);
        $r = krp_calc_pontual($kr, $ms, '2026-02-28');
        // base=25, meta=100 → (62.5-25)/75 = 50%
        $this->assertSame(50.0, $r['p_barra']);
    }

    public function testMenorMelhorComAncoraDoKR(): void
    {
        $kr = ['baseline' => 100, 'meta' => 20, 'direcao_metrica' => 'MENOR_MELHOR', 'margem_confianca' => 10];
        $ms = $this->msSerie([80, 60, 40, 20], [null, 60.0, null, null]);
        $r = krp_calc_pontual($kr, $ms, '2026-02-28');
        // (60-100)/(20-100) = 50%
        $this->assertSame(50.0, $r['p_barra']);
        $this->assertSame('verde', $r['farol']);
    }
}
