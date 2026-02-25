<?php
// auth/diff_helpers.php
function _parse_date($v){
  if ($v===null || $v==='') return null;
  $v = trim((string)$v);
  $fmts = ['Y-m-d','d/m/Y','d-m-Y','Y-m-d H:i:s','d/m/Y H:i'];
  foreach ($fmts as $f){
    $dt = DateTime::createFromFormat($f, $v);
    if ($dt) { $dt->setTime(0,0,0); return $dt; }
  }
  // fallback: tenta parse livre
  $ts = strtotime($v);
  return $ts ? (new DateTime("@$ts"))->setTime(0,0,0) : null;
}

function normalize_for_diff(string $campo, $v){
  // null/blank -> null
  if ($v === null) return null;
  if (is_string($v)) {
    $v = preg_replace('/\s+/u',' ', trim($v));
    if ($v==='') return null;
  }

  // mapeia tipos por campo
  static $DEC2 = ['valor','baseline','meta','margem_confianca'];
  static $INT  = ['responsavel','id_iniciativa','objetivo_id','id_kr','id_objetivo','id_orcamento'];
  static $DATE = ['data_inicio','data_fim','dt_novo_prazo','dt_criacao','dt_aprovacao'];
  static $ENUM = ['direcao_metrica','tipo_kr','natureza_kr','status','tipo_frequencia_milestone','unidade_medida'];

  if (in_array($campo, $DEC2, true)) {
    // normaliza decimais para 2 casas (evita 555 vs 555.00)
    if (function_exists('bccomp')) {
      // usa string decimal estável
      return number_format((float)$v, 2, '.', '');
    }
    return sprintf('%.2f', round((float)$v, 2));
  }
  if (in_array($campo, $INT, true)) {
    return (string) (int) $v;
  }
  if (in_array($campo, $DATE, true)) {
    $dt = _parse_date($v);
    return $dt ? $dt->format('Y-m-d') : null; // data canônica
  }
  if (in_array($campo, $ENUM, true)) {
    return mb_strtolower((string)$v, 'UTF-8'); // case-insensitive
  }
  // strings comuns: trim + colapso de espaços
  return is_string($v) ? $v : (string)$v;
}

function equal_field(string $campo, $a, $b): bool {
  return normalize_for_diff($campo, $a) === normalize_for_diff($campo, $b);
}
