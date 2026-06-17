<?php
/**
 * Funções puras de KR (sem DB nem session).
 * Extraídas de salvar_kr.php para reuso e testabilidade.
 */
declare(strict_types=1);

require_once __DIR__ . '/cycle_calc.php';

if (!function_exists('validarObrigatorios')) {

    function validarObrigatorios(string $modo, array $post): array
    {
        $errors = [];
        if (empty($post['id_objetivo']))              $errors[] = ['field' => 'id_objetivo', 'message' => 'Objetivo associado é obrigatório'];
        if (empty(trim($post['descricao'] ?? '')))    $errors[] = ['field' => 'descricao', 'message' => 'Descrição do KR é obrigatória'];

        $tipo_ciclo = trim($post['ciclo_tipo'] ?? '');
        if ($tipo_ciclo === '') {
            $errors[] = ['field' => 'ciclo_tipo', 'message' => 'Tipo de ciclo é obrigatório'];
        } else {
            switch ($tipo_ciclo) {
                case 'anual':
                    if (empty($post['ciclo_anual_ano'])) $errors[] = ['field' => 'ciclo_anual_ano', 'message' => 'Ano do ciclo anual é obrigatório'];
                    break;
                case 'semestral':
                    if (empty($post['ciclo_semestral'])) $errors[] = ['field' => 'ciclo_semestral', 'message' => 'Semestre do ciclo é obrigatório'];
                    break;
                case 'trimestral':
                    if (empty($post['ciclo_trimestral'])) $errors[] = ['field' => 'ciclo_trimestral', 'message' => 'Trimestre do ciclo é obrigatório'];
                    break;
                case 'bimestral':
                    if (empty($post['ciclo_bimestral'])) $errors[] = ['field' => 'ciclo_bimestral', 'message' => 'Bimestre do ciclo é obrigatório'];
                    break;
                case 'mensal':
                    if (empty($post['ciclo_mensal_mes'])) $errors[] = ['field' => 'ciclo_mensal_mes', 'message' => 'Mês do ciclo é obrigatório'];
                    if (empty($post['ciclo_mensal_ano'])) $errors[] = ['field' => 'ciclo_mensal_ano', 'message' => 'Ano do ciclo é obrigatório'];
                    break;
                case 'personalizado':
                    if (empty($post['ciclo_pers_inicio'])) $errors[] = ['field' => 'ciclo_pers_inicio', 'message' => 'Início do ciclo é obrigatório'];
                    if (empty($post['ciclo_pers_fim']))    $errors[] = ['field' => 'ciclo_pers_fim', 'message' => 'Fim do ciclo é obrigatório'];
                    break;
            }
        }

        if (!isset($post['baseline']) || $post['baseline'] === '' || !is_numeric($post['baseline']))
            $errors[] = ['field' => 'baseline', 'message' => 'Baseline é obrigatória'];
        if (!isset($post['meta']) || $post['meta'] === '' || !is_numeric($post['meta']))
            $errors[] = ['field' => 'meta', 'message' => 'Meta é obrigatória'];

        if ($modo === 'save') {
            $temFreq = array_key_exists('tipo_frequencia_milestone', $post)
                   && trim((string)$post['tipo_frequencia_milestone']) !== '';
            if (!$temFreq) {
                $errors[] = ['field'=>'tipo_frequencia_milestone','message'=>'Frequência de apontamento é obrigatória para salvar'];
            }
        }

        list($ini, $fim) = calcularDatasCiclo($tipo_ciclo, $post);
        if ($tipo_ciclo !== '' && ($ini === '' || $fim === '')) {
            $errors[] = ['field' => 'ciclo_tipo', 'message' => 'Não foi possível derivar o período do ciclo selecionado'];
        }
        return $errors;
    }

}

if (!function_exists('mapScoreToQualidade')) {

    function mapScoreToQualidade(?int $score): ?string
    {
        if ($score === null) return null;
        if ($score <= 2) return 'péssimo';
        if ($score <= 4) return 'ruim';
        if ($score <= 6) return 'moderado';
        if ($score <= 8) return 'bom';
        return 'ótimo';
    }

}

if (!function_exists('_slugify_nat')) {

    function _slugify_nat(string $s): string
    {
        $s = trim($s);
        $s = mb_strtolower($s, 'UTF-8');
        $s = iconv('UTF-8','ASCII//TRANSLIT',$s);
        $s = preg_replace('/\s+/', '_', $s);
        $s = preg_replace('/[^a-z0-9_]/', '', $s);

        if (in_array($s, ['acumulativo','acumulativa','acumulativo_constante','acumulado_constante','constante'], true))
            return 'acumulativo_constante';

        if ($s === 'acumulativo_exponencial' || $s === 'acumulado_exponencial' || $s === 'exponencial' || $s === 'expo' || str_starts_with($s, 'acumulativo_exponen'))
            return 'acumulativo_exponencial';

        if (in_array($s, ['binario','binaria'], true)) return 'binario';
        if (in_array($s, ['pontual','flutuante'], true)) return 'pontual';
        return $s;
    }

}

if (!function_exists('unidadeRequerInteiro')) {

    function unidadeRequerInteiro(?string $u): bool
    {
        $u = strtolower(trim((string)$u));
        $ints = ['unid','itens','pcs','ord','proc','contratos','processos','pessoas','casos','tickets','visitas'];
        return in_array($u, $ints, true);
    }

}

if (!function_exists('gerarSerieDatas')) {

    function gerarSerieDatas(string $data_inicio, string $data_fim, string $freq): array
    {
        $out = [];
        $start = new DateTime($data_inicio);
        $end   = new DateTime($data_fim);
        if ($end < $start) $end = clone $start;

        $freq = strtolower($freq);
        $pushUnique = function(array &$arr, DateTime $d) {
            $iso = $d->format('Y-m-d');
            if (empty($arr) || end($arr) !== $iso) $arr[] = $iso;
        };

        if ($freq === 'semanal' || $freq === 'quinzenal') {
            $stepDays = ($freq === 'semanal') ? 7 : 15;
            $d = (clone $start)->modify("+{$stepDays} days");
            while ($d < $end) { $pushUnique($out, $d); $d->modify("+{$stepDays} days"); }
            $pushUnique($out, $end);
        } else {
            $stepMonths = ['mensal'=>1, 'bimestral'=>2, 'trimestral'=>3, 'semestral'=>6, 'anual'=>12][$freq] ?? 1;
            // Avanço ANCORADO NO 1º DIA DO MÊS. Antes o avanço partia de uma data
            // de "último dia do mês" e usava modify('+N months'), o que sofre o
            // overflow do PHP (31/jan + 1 mês = 03/mar) e PULAVA meses
            // (ex.: trimestral/ano ia para Jul/Out em vez de Jun/Set).
            $firstOfStart = (clone $start)->modify('first day of this month');
            $firstTarget  = (clone $firstOfStart)->modify('+'.($stepMonths-1).' months');
            $firstEnd     = (clone $firstTarget)->modify('last day of this month');
            if ($firstEnd > $end) {
                $pushUnique($out, $end);
            } else {
                $pushUnique($out, $firstEnd);
                $cursorFirst = clone $firstTarget; // 1º dia do mês do marco corrente
                while (true) {
                    $cursorFirst = $cursorFirst->modify('+'.$stepMonths.' months');
                    $d = (clone $cursorFirst)->modify('last day of this month');
                    if ($d < $end) { $pushUnique($out, $d); } else { break; }
                }
                $pushUnique($out, $end);
            }
        }
        if (count($out) === 0) $out[] = $end->format('Y-m-d');
        return $out;
    }

}

if (!function_exists('inferirNaturezaSlug')) {

    /** Preserva o slug do front: 'acumulativo_constante' | 'acumulativo_exponencial' | 'pontual' | 'binario' */
    function inferirNaturezaSlug(PDO $pdo, $naturezaRaw): string {
        $val  = (string)$naturezaRaw;
        $slug = _slugify_nat($val);
        if (in_array($slug, ['acumulativo_constante','acumulativo_exponencial','pontual','binario'], true)) {
            return $slug;
        }

        // Se vier id (numérico), derive pelo texto do domínio
        if (ctype_digit($val)) {
            try {
                $st = $pdo->prepare("SELECT descricao_exibicao FROM dom_natureza_kr WHERE id_natureza = ? LIMIT 1");
                $st->execute([$val]);
                $desc = $st->fetchColumn();
                if ($desc) {
                    $slug2 = _slugify_nat((string)$desc);
                    if (in_array($slug2, ['acumulativo_constante','acumulativo_exponencial','pontual','binario'], true)) {
                        return $slug2;
                    }
                }
            } catch (Throwable $e) {}
        }

        // Fallback conservador
        return 'acumulativo_constante';
    }

}

if (!function_exists('gerarMilestonesParaKR')) {

    function gerarMilestonesParaKR(
        PDO $pdo,
        string $table,
        string $id_kr,
        string $data_inicio,
        string $data_fim,
        string $freqSlug,
        float $baseline,
        float $meta,
        string $naturezaSlug,
        ?string $direcao,
        ?string $unidade_medida
    ): int {
        $datas = gerarSerieDatas($data_inicio, $data_fim, $freqSlug);
        $N = count($datas);

        // zera anteriores
        $pdo->prepare("DELETE FROM {$table} WHERE id_kr = :id_kr")
            ->execute([':id_kr' => $id_kr]);

        // agora inserimos também min/max
        $ins = $pdo->prepare("
            INSERT INTO {$table} (
              id_kr, num_ordem, data_ref,
              valor_esperado, valor_esperado_min, valor_esperado_max,
              gerado_automatico, editado_manual, bloqueado_para_edicao
            )
            VALUES (
              :id_kr, :num_ordem, :data_ref,
              :valor_esperado, :valor_esperado_min, :valor_esperado_max,
              1, 0, 0
            )
        ");

        $isIntUnit = unidadeRequerInteiro($unidade_medida);
        $roundFn = function($v) use ($isIntUnit) {
            return $isIntUnit ? (int)round($v, 0) : round($v, 2);
        };

        // normaliza natureza
        $slug = _slugify_nat($naturezaSlug);
        $isBin   = ($slug === 'binario' || $slug === 'binaria');
        $isConst = ($slug === 'acumulativo_constante' || $slug === 'acumulativa');
        $isExpo  = ($slug === 'acumulativo_exponencial');

        $isIntervalo = strtoupper((string)$direcao) === 'INTERVALO_IDEAL';
        $delta = $meta - $baseline;

        $expoR = function(int $n): float {
            if ($n <= 4)  return 1.8;
            if ($n <= 8)  return 1.5;
            if ($n <= 16) return 1.3;
            if ($n <= 32) return 1.2;
            return 1.12;
        };

        for ($i = 1; $i <= $N; $i++) {
            $dataRef = $datas[$i-1];

            if ($isIntervalo) {
                $lo  = $roundFn(min($baseline, $meta));
                $hi  = $roundFn(max($baseline, $meta));
                $mid = $roundFn(($lo + $hi) / 2);

                $ins->execute([
                    ':id_kr'              => $id_kr,
                    ':num_ordem'          => $i,
                    ':data_ref'           => $dataRef,
                    ':valor_esperado'     => $mid,
                    ':valor_esperado_min' => $lo,
                    ':valor_esperado_max' => $hi,
                ]);
                continue;
            }

            // série única esperada
            $progress = 0.0;
            if ($isBin) {
                $progress = ($i === $N) ? 1.0 : 0.0;
            } elseif ($isConst) {
                $progress = $N > 0 ? ($i / $N) : 1.0;
            } elseif ($isExpo) {
                $r = $expoR($N);
                if (abs($r - 1.0) < 1e-9) $progress = $N > 0 ? ($i / $N) : 1.0;
                else $progress = (pow($r, $i) - 1.0) / (pow($r, $N) - 1.0);
            } else { // pontual
                $progress = ($i === $N) ? 1.0 : 0.0;
            }

            $valor = $roundFn($baseline + $delta * $progress);
            $ins->execute([
                ':id_kr'              => $id_kr,
                ':num_ordem'          => $i,
                ':data_ref'           => $dataRef,
                ':valor_esperado'     => $valor,
                ':valor_esperado_min' => null,
                ':valor_esperado_max' => null,
            ]);
        }

        return $N;
    }

}
