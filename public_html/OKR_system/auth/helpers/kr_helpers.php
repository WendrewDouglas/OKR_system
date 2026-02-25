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
            $d = clone $start;
            $firstEnd = (clone $d)->modify('last day of this month');
            if ($stepMonths > 1) {
                $tmp = (clone $d)->modify('first day of this month')->modify('+'.($stepMonths-1).' months');
                $firstEnd = $tmp->modify('last day of this month');
            }
            if ($firstEnd > $end) {
                $pushUnique($out, $end);
            } else {
                $pushUnique($out, $firstEnd);
                $d = (clone $firstEnd)->modify('+'.$stepMonths.' months')->modify('last day of this month');
                while ($d < $end) { $pushUnique($out, $d); $d = $d->modify('+'.$stepMonths.' months')->modify('last day of this month'); }
                $pushUnique($out, $end);
            }
        }
        if (count($out) === 0) $out[] = $end->format('Y-m-d');
        return $out;
    }

}
