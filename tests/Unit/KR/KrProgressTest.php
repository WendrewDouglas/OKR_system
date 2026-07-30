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
}
