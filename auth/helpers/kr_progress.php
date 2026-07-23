<?php
declare(strict_types=1);

/**
 * Cálculo unificado de PROGRESSO e FAROL de Key Results — compartilhado web + API.
 *
 * - INTERVALO_IDEAL: lógica nova ponderada por posição do milestone (peso = num_ordem)
 *   + bandas de tolerância (margem_confianca = τ). A barra cresce ao longo do ciclo
 *   (denominador = todos os milestones); o farol usa o realizado vs esperado-até-agora
 *   (denominador = só vencidos).
 * - Demais direções (maior/menor/pontual): cálculo pontual (real vs esperado), inalterado.
 *
 * Por KR retorna: p_barra (0..100), esperado (0..100, marca do tick), p_farol, farol.
 * Agregações (objetivo/pilar): média (AVG) de p_barra/esperado + farol pior-caso.
 */

if (!function_exists('krp_is_intervalo')) {
  function krp_is_intervalo(?string $direcao): bool {
    return strtoupper((string)$direcao) === 'INTERVALO_IDEAL';
  }
}

if (!function_exists('krp_tau_pct')) {
  /** margem_confianca → τ em % (0..100). Aceita 10 (=10%) ou 0.10 (=10%). Default 10. */
  function krp_tau_pct($raw): float {
    if ($raw === null || $raw === '' || !is_numeric($raw)) return 10.0;
    $v = (float)$raw;
    if ($v <= 0) return 10.0;
    return $v > 1.0 ? $v : $v * 100.0; // 0.10 → 10
  }
}

if (!function_exists('krp_status_excluido')) {
  /** Status que NÃO entram no cálculo de progresso/farol (não iniciado / cancelado). */
  function krp_status_excluido(?string $status): bool {
    $s = strtolower(trim((string)$status));
    return in_array($s, [
      'não iniciado', 'nao iniciado', 'nao-iniciado', 'não-iniciado',
      'cancelado', 'cancelada', 'cancelled',
    ], true);
  }
}

if (!function_exists('krp_farol_pior')) {
  /** Roll-up pior-caso: vermelho > amarelo > verde > cinza. */
  function krp_farol_pior(array $farois): string {
    $rank = ['cinza' => 0, 'verde' => 1, 'amarelo' => 2, 'vermelho' => 3];
    $best = 'cinza';
    $bestR = -1;
    $hasAny = false;
    foreach ($farois as $f) {
      if ($f === null || $f === '') continue;
      $hasAny = true;
      $r = $rank[$f] ?? 0;
      if ($r > $bestR) { $bestR = $r; $best = $f; }
    }
    return $hasAny ? $best : 'cinza';
  }
}

if (!function_exists('krp_calc_intervalo')) {
  /**
   * INTERVALO_IDEAL.
   * @param array $kr ['margem_confianca'=>...]
   * @param array $milestones cada: num_ordem, data_ref, valor_esperado_min/max, valor_real_consolidado
   */
  function krp_calc_intervalo(array $kr, array $milestones, string $today): array {
    $tau = krp_tau_pct($kr['margem_confianca'] ?? null);

    $sumW_total = 0.0; // Σ w (todos)
    $sumW_due   = 0.0; // Σ w (vencidos)
    $sumWS_due  = 0.0; // Σ w·s (vencidos), s ∈ {0,100}
    $hasDue = false;
    $N = 0;

    foreach ($milestones as $m) {
      $w = (float)($m['num_ordem'] ?? 0);
      if ($w <= 0) continue;
      $N++;
      $sumW_total += $w;

      $due = ((string)($m['data_ref'] ?? '')) <= $today;
      if (!$due) continue;
      $hasDue = true;
      $sumW_due += $w;

      $s = 0.0; // sem apontamento ou fora da faixa = 0
      $real = $m['valor_real_consolidado'];
      $min  = $m['valor_esperado_min'];
      $max  = $m['valor_esperado_max'];
      if ($real !== null && $min !== null && $max !== null) {
        $r = (float)$real; $lo = (float)$min; $hi = (float)$max;
        if ($lo > $hi) { $tmp = $lo; $lo = $hi; $hi = $tmp; }
        if ($r >= $lo && $r <= $hi) $s = 100.0;
      }
      $sumWS_due += $w * $s;
    }

    if ($N === 0 || $sumW_total <= 0.0) {
      return ['p_barra' => null, 'esperado' => null, 'p_farol' => null, 'farol' => 'cinza'];
    }

    // barra cresce no ciclo (÷ todos); s já em escala 0..100, logo /Σw dá 0..100
    $p_barra  = $sumWS_due / $sumW_total;
    $esperado = $sumW_due / $sumW_total * 100.0;
    $p_farol  = $hasDue ? ($sumWS_due / $sumW_due) : null;

    $farol = 'cinza';
    if ($p_farol !== null) {
      if ($p_farol >= 100.0 - $tau)            $farol = 'verde';
      elseif ($p_farol >= 100.0 - 3.0 * $tau)  $farol = 'amarelo';
      else                                     $farol = 'vermelho';
    }

    return [
      'p_barra'  => round($p_barra, 1),
      'esperado' => round($esperado, 1),
      'p_farol'  => $p_farol !== null ? round($p_farol, 1) : null,
      'farol'    => $farol,
    ];
  }
}

if (!function_exists('krp_calc_pontual')) {
  /** maior/menor/pontual — real vs esperado (inalterado), margem fixa 10% no farol. */
  function krp_calc_pontual(array $kr, array $milestones, string $today): array {
    if (empty($milestones)) {
      return ['p_barra' => null, 'esperado' => null, 'p_farol' => null, 'farol' => 'cinza'];
    }

    usort($milestones, fn($a, $b) => ((int)($a['num_ordem'] ?? 0)) <=> ((int)($b['num_ordem'] ?? 0)));
    $first = $milestones[0];
    $last  = $milestones[count($milestones) - 1];
    $base = isset($first['valor_esperado']) ? (float)$first['valor_esperado'] : null;
    $meta = isset($last['valor_esperado'])  ? (float)$last['valor_esperado']  : null;

    // milestone vencido mais recente + último real consolidado vencido
    $lastDue = null; $lastRealMs = null;
    foreach ($milestones as $m) {
      if (((string)($m['data_ref'] ?? '')) <= $today) {
        $lastDue = $m;
        if ($m['valor_real_consolidado'] !== null) $lastRealMs = $m;
      }
    }
    $real  = $lastRealMs !== null ? (float)$lastRealMs['valor_real_consolidado'] : null;
    $range = ($base !== null && $meta !== null) ? ($meta - $base) : 0.0;

    $dir = strtolower((string)($kr['direcao_metrica'] ?? ''));
    $isMenor = (strpos($dir, 'menor') !== false);

    // KR "manter" (baseline == meta) => range 0: escala o progresso pela própria
    // meta, evitando barra travada em 0% e tick "esperado" inválido.
    $manter = ($base !== null && $meta !== null && abs($range) <= 1e-9);

    // barra: real vs [base..meta] (ou vs meta quando baseline == meta)
    $p_barra = 0.0;
    if ($base !== null && $meta !== null) {
      if (!$manter) {
        $rv = $real !== null ? $real : $base;
        $p_barra = max(0.0, min(100.0, ($rv - $base) / $range * 100.0));
      } elseif ($real !== null) {
        if (abs((float)$meta) > 1e-9) {
          $p_barra = $isMenor
            ? ($real <= 0 ? 100.0 : max(0.0, min(100.0, $meta / $real * 100.0)))
            : max(0.0, min(100.0, $real / $meta * 100.0));
        } else {
          $ok = $isMenor ? ($real <= $meta) : ($real >= $meta);
          $p_barra = $ok ? 100.0 : 0.0;
        }
      }
    }

    // tick esperado: % onde deveria estar no último vencido
    $esperado = null;
    if ($base !== null && $meta !== null && $lastDue !== null) {
      if (!$manter) {
        $espDue = (float)$lastDue['valor_esperado'];
        $esperado = max(0.0, min(100.0, ($espDue - $base) / $range * 100.0));
      } else {
        $esperado = 100.0; // manter: espera-se estar na meta durante todo o ciclo
      }
    }

    // farol pontual — usa a margem de confiança (τ) do KR, com bandas 1×/3×
    // (verde ≤ τ, amarelo ≤ 3τ, vermelho > 3τ), coerente com INTERVALO_IDEAL.
    // Antes era um limiar fixo de 10% (esp×0,90), o que ignorava a margem do KR
    // e não tinha a faixa de atenção até 3τ.
    $tau = krp_tau_pct($kr['margem_confianca'] ?? null) / 100.0; // fração (ex.: 10 => 0.10)
    $farol = 'cinza';
    if ($lastDue === null) {
      $farol = 'cinza';            // ciclo não começou
    } elseif ($real === null) {
      $farol = 'vermelho';         // vencido sem apontamento
    } else {
      $esp = (float)$lastDue['valor_esperado'];
      $den = ($esp == 0.0) ? 1e-12 : abs($esp);
      // desvio relativo "ruim" (>= 0): quanto o realizado ficou pior que o esperado
      $sdev = $isMenor ? max(0.0, ($real - $esp) / $den) : max(0.0, ($esp - $real) / $den);
      if     ($sdev <= $tau)        $farol = 'verde';
      elseif ($sdev <= 3.0 * $tau)  $farol = 'amarelo';
      else                          $farol = 'vermelho';
    }

    return [
      'p_barra'  => round($p_barra, 1),
      'esperado' => $esperado !== null ? round($esperado, 1) : null,
      'p_farol'  => null,
      'farol'    => $farol,
    ];
  }
}

if (!function_exists('krp_calc_kr')) {
  /** Despacha por direção. */
  function krp_calc_kr(array $kr, array $milestones, string $today): array {
    // Status excluído (não iniciado / cancelado) NÃO entra no cálculo de
    // progresso/farol: retorna neutro (cinza). Mantém o farol POR-KR coerente
    // com as agregações (krp_aggregate_*), que já ignoram esses status — assim
    // um KR não iniciado não aparece "Crítico" no card enquanto o objetivo
    // fica cinza. Não altera os agregados (que já pulavam esses KRs).
    if (krp_status_excluido($kr['status'] ?? null)) {
      return ['p_barra' => null, 'esperado' => null, 'p_farol' => null, 'farol' => 'cinza'];
    }
    if (krp_is_intervalo($kr['direcao_metrica'] ?? null)) {
      return krp_calc_intervalo($kr, $milestones, $today);
    }
    return krp_calc_pontual($kr, $milestones, $today);
  }
}

if (!function_exists('krp_aggregate_krs')) {
  /**
   * Agrega KRs (exclui não-iniciado/cancelado) → progress/esperado/farol do objetivo.
   * @param array $krResults cada: p_barra, esperado, farol, status
   */
  function krp_aggregate_krs(array $krResults): array {
    $ps = []; $es = []; $fs = [];
    foreach ($krResults as $k) {
      if (krp_status_excluido($k['status'] ?? null)) continue;
      if (($k['p_barra']  ?? null) !== null) $ps[] = (float)$k['p_barra'];
      if (($k['esperado'] ?? null) !== null) $es[] = (float)$k['esperado'];
      if (($k['farol']    ?? null) !== null) $fs[] = $k['farol'];
    }
    return [
      'progress' => count($ps) ? round(array_sum($ps) / count($ps), 1) : null,
      'esperado' => count($es) ? round(array_sum($es) / count($es), 1) : null,
      'farol'    => krp_farol_pior($fs),
    ];
  }
}

if (!function_exists('krp_aggregate_objs')) {
  /** Agrega objetivos → progress/esperado/farol do pilar. */
  function krp_aggregate_objs(array $objResults): array {
    $ps = []; $es = []; $fs = [];
    foreach ($objResults as $o) {
      if (($o['progress'] ?? null) !== null) $ps[] = (float)$o['progress'];
      if (($o['esperado'] ?? null) !== null) $es[] = (float)$o['esperado'];
      if (($o['farol']    ?? null) !== null) $fs[] = $o['farol'];
    }
    return [
      'progress' => count($ps) ? round(array_sum($ps) / count($ps), 1) : null,
      'esperado' => count($es) ? round(array_sum($es) / count($es), 1) : null,
      'farol'    => krp_farol_pior($fs),
    ];
  }
}

if (!function_exists('krp_kr_results_for_objetivos')) {
  /**
   * Busca KRs + milestones dos objetivos dados e calcula o resultado por KR.
   * @return array [id_objetivo => [ ['id_kr','status','p_barra','esperado','farol'], ... ]]
   */
  function krp_kr_results_for_objetivos(PDO $pdo, array $objIds, ?string $today = null): array {
    if (empty($objIds)) return [];
    $today = $today ?: date('Y-m-d');

    $objIds = array_values($objIds);
    $inObj  = implode(',', array_fill(0, count($objIds), '?'));
    $stKr = $pdo->prepare("
      SELECT id_kr, id_objetivo, baseline, meta, direcao_metrica, margem_confianca, status
        FROM key_results
       WHERE id_objetivo IN ($inObj)
    ");
    $stKr->execute($objIds);
    $krs = $stKr->fetchAll(PDO::FETCH_ASSOC);
    if (empty($krs)) return [];

    $krIds = array_values(array_column($krs, 'id_kr'));
    $msByKr = [];
    if (!empty($krIds)) {
      $inKr = implode(',', array_fill(0, count($krIds), '?'));
      $stMs = $pdo->prepare("
        SELECT id_kr, num_ordem, data_ref,
               valor_esperado, valor_esperado_min, valor_esperado_max, valor_real_consolidado
          FROM milestones_kr
         WHERE id_kr IN ($inKr)
         ORDER BY id_kr, num_ordem
      ");
      $stMs->execute($krIds);
      foreach ($stMs->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $msByKr[$m['id_kr']][] = $m;
      }
    }

    $byObj = [];
    foreach ($krs as $kr) {
      $res = krp_calc_kr($kr, $msByKr[$kr['id_kr']] ?? [], $today);
      $byObj[(int)$kr['id_objetivo']][] = [
        'id_kr'    => $kr['id_kr'],
        'status'   => $kr['status'],
        'p_barra'  => $res['p_barra'],
        'esperado' => $res['esperado'],
        'farol'    => $res['farol'],
      ];
    }
    return $byObj;
  }
}
