<?php
declare(strict_types=1);

/**
 * Validação e ajuste de séries de MILESTONES PREVISTOS — compartilhado web + API.
 *
 * Invariante central: a série deve "fechar" na meta do KR (último milestone =
 * meta; no INTERVALO_IDEAL, a faixa final = faixa do KR). A meta é a única
 * porta de entrada para alterar o valor final — o grid trava o último item.
 *
 * Regras rígidas (bloqueiam o salvamento — códigos em `erros`):
 *   FECHO_META, MONOTONIA (só naturezas acumulativas), MIN_MAIOR_MAX,
 *   BINARIO_VALOR, SERIE_VAZIA.
 * Regras brandas (permitem salvar com justificativa — códigos em `avisos`):
 *   MONOTONIA (naturezas não acumulativas), FORA_FAIXA, FAIXA_DISJUNTA,
 *   VENCIDO_EDITADO.
 *
 * Funções puras (sem DB/sessão), testáveis em tests/Unit/KR.
 */

require_once __DIR__ . '/kr_helpers.php';   // _slugify_nat, unidadeRequerInteiro
require_once __DIR__ . '/kr_progress.php';  // krp_tau_pct, krp_is_intervalo

if (!function_exists('krm_tolerancia')) {
  /** ε de comparação coerente com o arredondamento da geração. */
  function krm_tolerancia(?string $unidade): float {
    return unidadeRequerInteiro($unidade) ? 0.5 : 0.005;
  }
}

if (!function_exists('krm_round')) {
  /** Mesmo arredondamento usado em gerarMilestonesParaKR. */
  function krm_round(float $v, ?string $unidade): float {
    return unidadeRequerInteiro($unidade) ? (float)round($v, 0) : round($v, 2);
  }
}

if (!function_exists('krm_natureza_acumulativa')) {
  /** Acumulativo (constante/exponencial) => trajetória não pode andar para trás. */
  function krm_natureza_acumulativa($naturezaRaw): bool {
    $slug = _slugify_nat((string)$naturezaRaw);
    return in_array($slug, ['acumulativo_constante', 'acumulativo_exponencial'], true);
  }
}

if (!function_exists('krm_natureza_binaria')) {
  function krm_natureza_binaria($naturezaRaw): bool {
    return _slugify_nat((string)$naturezaRaw) === 'binario';
  }
}

if (!function_exists('krm_ordenar')) {
  /** Ordena por num_ordem (fallback data_ref) sem mutar o array de entrada. */
  function krm_ordenar(array $milestones): array {
    usort($milestones, function ($a, $b) {
      $c = ((int)($a['num_ordem'] ?? 0)) <=> ((int)($b['num_ordem'] ?? 0));
      return $c !== 0 ? $c : strcmp((string)($a['data_ref'] ?? ''), (string)($b['data_ref'] ?? ''));
    });
    return $milestones;
  }
}

if (!function_exists('krm_validar_serie')) {
  /**
   * Valida a série de milestones contra o KR.
   *
   * @param array $kr  baseline, meta, direcao_metrica, natureza_kr,
   *                   unidade_medida, margem_confianca
   * @param array $milestones cada: num_ordem, data_ref, valor_esperado,
   *                   valor_esperado_min, valor_esperado_max,
   *                   editado (bool, opcional: alterado nesta requisição)
   * @param string|null $today 'Y-m-d' p/ marcar edição de vencidos (null = pula)
   * @return array ['erros'=>[[codigo,msg,num_ordem?]], 'avisos'=>[...]]
   */
  function krm_validar_serie(array $kr, array $milestones, ?string $today = null): array {
    $erros = []; $avisos = [];
    $ms = krm_ordenar($milestones);
    $N  = count($ms);
    if ($N === 0) {
      return ['erros' => [['codigo' => 'SERIE_VAZIA', 'msg' => 'KR sem milestones.']], 'avisos' => []];
    }

    $B   = (float)($kr['baseline'] ?? 0);
    $M   = (float)($kr['meta'] ?? 0);
    $uni = $kr['unidade_medida'] ?? null;
    $eps = krm_tolerancia(is_string($uni) ? $uni : null);
    $dir = strtolower((string)($kr['direcao_metrica'] ?? ''));

    /* ---------- vencidos editados (todas as direções) ---------- */
    if ($today !== null) {
      foreach ($ms as $m) {
        if (!empty($m['editado']) && (string)($m['data_ref'] ?? '') <= $today) {
          $avisos[] = [
            'codigo' => 'VENCIDO_EDITADO',
            'msg' => 'Milestone vencido teve o previsto alterado (reescreve o histórico do farol).',
            'num_ordem' => (int)($m['num_ordem'] ?? 0),
          ];
        }
      }
    }

    /* ---------- INTERVALO_IDEAL: faixas min/max ---------- */
    if (krp_is_intervalo($kr['direcao_metrica'] ?? null)) {
      $lo = min($B, $M); $hi = max($B, $M);
      foreach ($ms as $m) {
        $n  = (int)($m['num_ordem'] ?? 0);
        $mn = $m['valor_esperado_min']; $mx = $m['valor_esperado_max'];
        if ($mn === null || $mx === null || !is_numeric($mn) || !is_numeric($mx)) {
          $erros[] = ['codigo' => 'MIN_MAIOR_MAX', 'msg' => 'Faixa min/max ausente ou inválida.', 'num_ordem' => $n];
          continue;
        }
        if ((float)$mn > (float)$mx + $eps) {
          $erros[] = ['codigo' => 'MIN_MAIOR_MAX', 'msg' => 'Mínimo maior que o máximo.', 'num_ordem' => $n];
        }
      }
      $last = $ms[$N - 1];
      $mnN = $last['valor_esperado_min']; $mxN = $last['valor_esperado_max'];
      if (is_numeric($mnN) && is_numeric($mxN)
          && (abs((float)$mnN - $lo) > $eps || abs((float)$mxN - $hi) > $eps)) {
        $erros[] = [
          'codigo' => 'FECHO_META',
          'msg' => sprintf('A faixa final (%.2f–%.2f) deve ser igual à faixa do KR (%.2f–%.2f). Ajuste a faixa pelo baseline/meta do KR.',
                           (float)$mnN, (float)$mxN, $lo, $hi),
          'num_ordem' => (int)($last['num_ordem'] ?? $N),
        ];
      }
      // intermediárias sem sobreposição com a faixa do KR: aviso
      for ($i = 0; $i < $N - 1; $i++) {
        $mn = $ms[$i]['valor_esperado_min']; $mx = $ms[$i]['valor_esperado_max'];
        if (is_numeric($mn) && is_numeric($mx) && ((float)$mx < $lo - $eps || (float)$mn > $hi + $eps)) {
          $avisos[] = [
            'codigo' => 'FAIXA_DISJUNTA',
            'msg' => 'Faixa do milestone não intercepta a faixa ideal do KR.',
            'num_ordem' => (int)($ms[$i]['num_ordem'] ?? 0),
          ];
        }
      }
      return ['erros' => $erros, 'avisos' => $avisos];
    }

    /* ---------- MAIOR/MENOR/manter: série de valor único ---------- */
    $isMenor = (strpos($dir, 'menor') !== false);
    $manter  = (abs($M - $B) <= $eps);
    $vals = [];
    foreach ($ms as $m) {
      $v = $m['valor_esperado'];
      if ($v === null || !is_numeric($v)) {
        $erros[] = ['codigo' => 'VALOR_INVALIDO', 'msg' => 'Valor esperado ausente ou não numérico.',
                    'num_ordem' => (int)($m['num_ordem'] ?? 0)];
        return ['erros' => $erros, 'avisos' => $avisos];
      }
      $vals[] = (float)$v;
    }
    $vN = $vals[$N - 1];

    // 1) FECHO DA META (rígida): último milestone = meta do KR
    if (abs($vN - $M) > $eps) {
      $erros[] = [
        'codigo' => 'FECHO_META',
        'msg' => sprintf('A série fecha em %s, mas a meta do KR é %s. Ajuste os milestones ou a meta.',
                         rtrim(rtrim(number_format($vN, 2, ',', '.'), '0'), ','),
                         rtrim(rtrim(number_format($M, 2, ',', '.'), '0'), ',')),
        'num_ordem' => (int)($ms[$N - 1]['num_ordem'] ?? $N),
      ];
    }

    // 2) BINÁRIO (rígida): só baseline ou meta
    if (krm_natureza_binaria($kr['natureza_kr'] ?? null)) {
      foreach ($vals as $i => $v) {
        if (abs($v - $B) > $eps && abs($v - $M) > $eps) {
          $erros[] = ['codigo' => 'BINARIO_VALOR',
                      'msg' => 'KR binário: milestones só admitem o valor do baseline ou da meta.',
                      'num_ordem' => (int)($ms[$i]['num_ordem'] ?? 0)];
        }
      }
      return ['erros' => $erros, 'avisos' => $avisos];
    }

    // 3) MONOTONIA: rígida p/ acumulativo, branda p/ pontual. "Manter" não tem rampa.
    if (!$manter) {
      $acum = krm_natureza_acumulativa($kr['natureza_kr'] ?? null);
      for ($i = 1; $i < $N; $i++) {
        $step = $vals[$i] - $vals[$i - 1];
        $viola = $isMenor ? ($step > $eps) : ($step < -$eps);
        if ($viola) {
          $item = ['codigo' => 'MONOTONIA',
                   'msg' => $isMenor
                     ? 'Plano sobe entre milestones em um KR "menor melhor".'
                     : 'Plano desce entre milestones em um KR "maior melhor".',
                   'num_ordem' => (int)($ms[$i]['num_ordem'] ?? 0)];
          if ($acum) $erros[] = $item; else $avisos[] = $item;
        }
      }
    }

    // 4) FAIXA PLAUSÍVEL (branda)
    if ($manter) {
      // manter: desvio relativo à meta além de τ vira aviso
      $tau = krp_tau_pct($kr['margem_confianca'] ?? null) / 100.0;
      $den = abs($M) > 1e-9 ? abs($M) : 1.0;
      foreach ($vals as $i => $v) {
        if (abs($v - $M) / $den > $tau + 1e-12) {
          $avisos[] = ['codigo' => 'FORA_FAIXA',
                       'msg' => 'Valor distoa da meta de manutenção além da margem de confiança.',
                       'num_ordem' => (int)($ms[$i]['num_ordem'] ?? 0)];
        }
      }
    } else {
      $lo = min($B, $M); $hi = max($B, $M);
      foreach ($vals as $i => $v) {
        if ($v < $lo - $eps || $v > $hi + $eps) {
          $avisos[] = ['codigo' => 'FORA_FAIXA',
                       'msg' => 'Valor planejado fora do intervalo entre baseline e meta.',
                       'num_ordem' => (int)($ms[$i]['num_ordem'] ?? 0)];
        }
      }
    }

    return ['erros' => $erros, 'avisos' => $avisos];
  }
}

if (!function_exists('krm_reescalar')) {
  /**
   * Estratégia "reescalar trajetória": preserva a forma da série e força o
   * fecho na meta: v' = B + (v − B)·(M − B)/(vN − B).
   * Degenerado (vN == B): rampa linear de B a M.
   * Retorna a série completa com valor_esperado atualizado (demais chaves intactas).
   */
  function krm_reescalar(array $kr, array $milestones): array {
    $ms = krm_ordenar($milestones);
    $N  = count($ms);
    if ($N === 0) return $ms;

    $B = (float)($kr['baseline'] ?? 0);
    $M = (float)($kr['meta'] ?? 0);
    $uni = is_string($kr['unidade_medida'] ?? null) ? $kr['unidade_medida'] : null;
    $vN = (float)($ms[$N - 1]['valor_esperado'] ?? 0);

    $den = $vN - $B;
    for ($i = 0; $i < $N; $i++) {
      if (abs($den) <= 1e-9) {
        $novo = $B + ($M - $B) * (($i + 1) / $N); // fallback linear
      } else {
        $v = (float)($ms[$i]['valor_esperado'] ?? 0);
        $novo = $B + ($v - $B) * ($M - $B) / $den;
      }
      $ms[$i]['valor_esperado'] = krm_round($novo, $uni);
    }
    // arredondamento nunca pode quebrar o fecho
    $ms[$N - 1]['valor_esperado'] = krm_round($M, $uni);
    return $ms;
  }
}

if (!function_exists('krm_distribuir_residuo')) {
  /**
   * Estratégia "ajustar só futuros": mantém milestones vencidos intactos e
   * reescala apenas o trecho futuro, ancorado no último valor não futuro
   * (ou no baseline, se todos forem futuros): tail-rescale até fechar em M.
   * @return array|null série ajustada, ou null se não há milestones futuros
   *                    (caller deve sugerir "reescalar" ou mudança de prazo).
   */
  function krm_distribuir_residuo(array $kr, array $milestones, string $today): ?array {
    $ms = krm_ordenar($milestones);
    $N  = count($ms);
    if ($N === 0) return $ms;

    $M   = (float)($kr['meta'] ?? 0);
    $B   = (float)($kr['baseline'] ?? 0);
    $uni = is_string($kr['unidade_medida'] ?? null) ? $kr['unidade_medida'] : null;

    $firstFuture = null;
    for ($i = 0; $i < $N; $i++) {
      if ((string)($ms[$i]['data_ref'] ?? '') > $today) { $firstFuture = $i; break; }
    }
    if ($firstFuture === null) return null;

    $A  = ($firstFuture === 0) ? $B : (float)($ms[$firstFuture - 1]['valor_esperado'] ?? $B);
    $vN = (float)($ms[$N - 1]['valor_esperado'] ?? 0);
    $den = $vN - $A;
    $futCount = $N - $firstFuture;

    for ($i = $firstFuture; $i < $N; $i++) {
      if (abs($den) <= 1e-9) {
        $novo = $A + ($M - $A) * (($i - $firstFuture + 1) / $futCount); // linear A→M
      } else {
        $v = (float)($ms[$i]['valor_esperado'] ?? 0);
        $novo = $A + ($v - $A) * ($M - $A) / $den;
      }
      $ms[$i]['valor_esperado'] = krm_round($novo, $uni);
    }
    $ms[$N - 1]['valor_esperado'] = krm_round($M, $uni);
    return $ms;
  }
}

if (!function_exists('krm_ajustar_faixa_final')) {
  /** INTERVALO_IDEAL: força a faixa do último milestone = faixa do KR. */
  function krm_ajustar_faixa_final(array $kr, array $milestones): array {
    $ms = krm_ordenar($milestones);
    $N  = count($ms);
    if ($N === 0) return $ms;
    $B = (float)($kr['baseline'] ?? 0);
    $M = (float)($kr['meta'] ?? 0);
    $uni = is_string($kr['unidade_medida'] ?? null) ? $kr['unidade_medida'] : null;
    $ms[$N - 1]['valor_esperado_min'] = krm_round(min($B, $M), $uni);
    $ms[$N - 1]['valor_esperado_max'] = krm_round(max($B, $M), $uni);
    $ms[$N - 1]['valor_esperado']     = krm_round(($B + $M) / 2, $uni);
    return $ms;
  }
}
