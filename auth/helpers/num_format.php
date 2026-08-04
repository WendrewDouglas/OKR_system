<?php
declare(strict_types=1);

/**
 * Formatação de números pt-BR — SOMENTE PARA EXIBIÇÃO.
 *
 * Padrão em todo o sistema: "." como separador de milhar e "," como decimal.
 *   num_br(1234567)     -> "1.234.567"
 *   num_br(1234.5)      -> "1.234,5"      (auto: até 2 decimais, sem zeros à direita)
 *   num_br(1234.5, 2)   -> "1.234,50"     (decimais fixos)
 *   num_br(null)        -> "—"
 *
 * Espelho JS: window.fmtNum (emitido em views/partials/sidebar.php).
 *
 * IMPORTANTE: nunca use o resultado em WHERE/cálculo/valor submetido — apenas exibição.
 */

if (!function_exists('num_br')) {
    function num_br($v, ?int $dec = null): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if (is_string($v)) {
            $v = trim($v);
            // tolera valores vindos do banco como string ("1234.50")
            if ($v === '' || !is_numeric($v)) {
                return $v === '' ? '—' : $v;
            }
        } elseif (!is_int($v) && !is_float($v)) {
            return (string) $v;
        }
        $f = (float) $v;
        if ($dec !== null) {
            return number_format($f, max(0, $dec), ',', '.');
        }
        // Auto: até 2 decimais, removendo zeros à direita (10,50 -> 10,5; 10,00 -> 10)
        $s = number_format($f, 2, ',', '.');
        $s = rtrim(rtrim($s, '0'), ',');
        return $s;
    }
}

if (!function_exists('num_br_pct')) {
    /** Percentual pt-BR: num_br_pct(87.5) -> "87,5%". Decimais automáticos (até 1). */
    function num_br_pct($v, int $dec = 1): string
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return '—';
        }
        $s = number_format((float) $v, $dec, ',', '.');
        $s = rtrim(rtrim($s, '0'), ',');
        return $s . '%';
    }
}
