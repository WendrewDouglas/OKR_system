<?php
/**
 * Cálculo de datas de ciclo OKR.
 * Extraído de salvar_kr.php e salvar_objetivo.php para reuso e testabilidade.
 */
declare(strict_types=1);

if (!function_exists('calcularDatasCiclo')) {

    function calcularDatasCiclo(string $tipo, array $d): array
    {
        $ini = $fim = '';
        switch ($tipo) {
            case 'anual':
                if (!empty($d['ciclo_anual_ano'])) {
                    $ano = (int)$d['ciclo_anual_ano'];
                    $ini = sprintf('%04d-01-01', $ano);
                    $fim = sprintf('%04d-12-31', $ano);
                }
                break;
            case 'semestral':
                if (preg_match('/^S([12])\/(\d{4})$/', $d['ciclo_semestral'] ?? '', $m)) {
                    $ano = $m[2];
                    if ($m[1] === '1') { $ini = "$ano-01-01"; $fim = "$ano-06-30"; }
                    else               { $ini = "$ano-07-01"; $fim = "$ano-12-31"; }
                }
                break;
            case 'trimestral':
                if (preg_match('/^Q([1-4])\/(\d{4})$/', $d['ciclo_trimestral'] ?? '', $m)) {
                    $map = [
                        '1'=>['01-01','03-31'],
                        '2'=>['04-01','06-30'],
                        '3'=>['07-01','09-30'],
                        '4'=>['10-01','12-31'],
                    ];
                    $ini = "{$m[2]}-{$map[$m[1]][0]}";
                    $fim = "{$m[2]}-{$map[$m[1]][1]}";
                }
                break;
            case 'bimestral':
                if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $d['ciclo_bimestral'] ?? '', $m)) {
                    $ini = "{$m[3]}-{$m[1]}-01";
                    $fim = date('Y-m-t', strtotime("{$m[3]}-{$m[2]}-01"));
                }
                break;
            case 'mensal':
                if (!empty($d['ciclo_mensal_mes']) && !empty($d['ciclo_mensal_ano'])) {
                    $mes = (int)$d['ciclo_mensal_mes'];
                    $ano = (int)$d['ciclo_mensal_ano'];
                    $ini = sprintf('%04d-%02d-01', $ano, $mes);
                    $fim = date('Y-m-t', strtotime("$ano-$mes-01"));
                }
                break;
            case 'personalizado':
                if (!empty($d['ciclo_pers_inicio']) && !empty($d['ciclo_pers_fim'])) {
                    $ini = $d['ciclo_pers_inicio'].'-01';
                    $fim = date('Y-m-t', strtotime($d['ciclo_pers_fim'].'-01'));
                }
                break;
        }
        return [$ini, $fim];
    }

}
