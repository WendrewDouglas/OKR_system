<?php
// /home2/planni40/public_html/OKR_system/views/includes/okr_helpers.php

declare(strict_types=1);

/**
 * Cria PDO apontando para DB_NAME_DEV quando existir, senão DB_NAME.
 */
function okr_pdo(): PDO {
  $dbName = (defined('DB_NAME_DEV') && DB_NAME_DEV) ? DB_NAME_DEV : DB_NAME;

  return new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . $dbName . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
}

function okr_g(array $row, string $k, $d = null) {
  return array_key_exists($k, $row) ? $row[$k] : $d;
}

function okr_table_exists(PDO $pdo, string $table): bool {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  try { $pdo->query("SHOW COLUMNS FROM `$table`"); return $cache[$table] = true; }
  catch (Throwable $e) { return $cache[$table] = false; }
}

function okr_col_exists(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = "$table.$col";
  if (isset($cache[$key])) return $cache[$key];
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
    $st->execute(['c' => $col]);
    $cache[$key] = (bool)$st->fetch();
    return $cache[$key];
  } catch (Throwable $e) {
    return $cache[$key] = false;
  }
}

function okr_clamp_pct($v): ?int {
  if ($v === null) return null;
  $v = (float)$v;
  if (!is_finite($v)) return null;
  return (int)round(max(0.0, min(100.0, $v)));
}

/**
 * Descobre a coluna responsável (id_user...) em key_results.
 */
function okr_find_kr_user_id_col(PDO $pdo): ?string {
  try { $st = $pdo->query("SHOW COLUMNS FROM `key_results`"); }
  catch (Throwable $e) { return null; }
  $cols = $st->fetchAll();
  if (!$cols) return null;

  $prefer = [
    'id_user_responsavel','id_responsavel','responsavel_id',
    'id_responsavel_kr','id_usuario_responsavel','owner_id',
    'id_owner','id_dono_responsavel','id_dono'
  ];

  $names = array_column($cols,'Field');
  $types = array_column($cols,'Type','Field');

  foreach ($prefer as $p) {
    if (in_array($p,$names,true) && stripos($types[$p] ?? '', 'int') !== false) return $p;
  }
  foreach ($names as $n) {
    if (preg_match('/respons|owner|dono/i',$n) &&
        preg_match('/(^id_|_id$)/i',$n) &&
        stripos($types[$n] ?? '', 'int') !== false) {
      return $n;
    }
  }
  return null;
}

function okr_get_user_name_by_id(PDO $pdo, $id): ?string {
  static $cache = [];
  $id = (int)$id;
  if ($id <= 0) return null;
  if (isset($cache[$id])) return $cache[$id];

  $st = $pdo->prepare("SELECT `primeiro_nome` FROM `usuarios` WHERE `id_user`=:id LIMIT 1");
  $st->execute(['id'=>$id]);
  $name = $st->fetchColumn();

  return $cache[$id] = ($name ?: null);
}

/**
 * Descobre coluna que referencia KR em uma tabela.
 */
function okr_find_kr_id_col(PDO $pdo, string $table): ?string {
  try { $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return null; }

  foreach ($cols as $c) {
    $f = strtolower((string)$c['Field']);
    if (in_array($f, ['id_kr','kr_id','id_key_result','key_result_id'], true)) return $c['Field'];
  }
  return null;
}

/**
 * Fonte NORMALIZADA para milestones:
 * 1) Preferir VIEW v_milestones_kr_normalizado
 * 2) Fallback para milestones_kr ou milestones
 */
function okr_find_milestone_source(PDO $pdo): array {
  // 1) VIEW normalizada
  try {
    $pdo->query("SELECT 1 FROM `v_milestones_kr_normalizado` LIMIT 1");
    return [
      'table' => 'v_milestones_kr_normalizado',
      'is_view' => true,
      'krCol'   => 'id_kr',
      'idCol'   => 'id_milestone',
      'dateCol' => 'data_ref',
      'expCol'  => 'valor_esperado',
      'realCol' => 'valor_real',
      'minCol'  => 'valor_esperado_min',
      'maxCol'  => 'valor_esperado_max',
      'cntCol'  => 'qtde_apontamentos',
    ];
  } catch (Throwable $e) {}

  // 2) Fallback tabelas
  $msTable = null;
  foreach (['milestones_kr','milestones'] as $t) {
    try { $pdo->query("SHOW COLUMNS FROM `$t`"); $msTable = $t; break; } catch (Throwable $e) {}
  }
  if (!$msTable) return ['table'=>null];

  $cols = $pdo->query("SHOW COLUMNS FROM `$msTable`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $has  = function(string $n) use($cols): bool {
    foreach($cols as $c) if (strcasecmp((string)$c['Field'],$n)===0) return true;
    return false;
  };

  $krCol   = $has('id_kr') ? 'id_kr' : (okr_find_kr_id_col($pdo,$msTable) ?: null);
  $idCol   = $has('id_milestone') ? 'id_milestone' : ($has('id_ms') ? 'id_ms' : ($has('id') ? 'id' : null));
  $dateCol = $has('data_ref') ? 'data_ref' : ($has('dt_prevista') ? 'dt_prevista' : ($has('data_prevista') ? 'data_prevista' : null));
  $expCol  = $has('valor_esperado') ? 'valor_esperado' : ($has('esperado') ? 'esperado' : null);

  $realCol = $has('valor_real') ? 'valor_real'
          : ($has('realizado') ? 'realizado'
          : ($has('valor_real_consolidado') ? 'valor_real_consolidado'
          : ($has('valor_apontado') ? 'valor_apontado'
          : ($has('resultado') ? 'resultado' : null))));

  $minCol  = $has('valor_esperado_min') ? 'valor_esperado_min' : ($has('esperado_min') ? 'esperado_min' : null);
  $maxCol  = $has('valor_esperado_max') ? 'valor_esperado_max' : ($has('esperado_max') ? 'esperado_max' : null);
  $cntCol  = $has('qtde_apontamentos') ? 'qtde_apontamentos' : null;

  return [
    'table' => $msTable,
    'is_view' => false,
    'krCol'   => $krCol,
    'idCol'   => $idCol,
    'dateCol' => $dateCol,
    'expCol'  => $expCol,
    'realCol' => $realCol,
    'minCol'  => $minCol,
    'maxCol'  => $maxCol,
    'cntCol'  => $cntCol,
  ];
}

/**
 * Normaliza farol.
 */
function okr_normalize_farol($s): string {
  $s = trim(mb_strtolower((string)$s));
  $s = str_replace(['_', '-'], ' ', $s);
  $s2 = @iconv('UTF-8','ASCII//TRANSLIT',$s);
  if ($s2 !== false && $s2 !== null) $s = $s2;
  $s = preg_replace('/\s+/', ' ', $s);

  if ($s === '' || $s === 'sem apontamento' || $s === 'neutro' || $s === 'cinza') return 'neutro';
  if (preg_match('/vermelh|critic|fora.*trilh|atras|off ?track/', $s)) return 'vermelho';
  if (preg_match('/amar|aten|risco|alert/', $s)) return 'amarelo';
  if (preg_match('/verd|no ?trilho|on ?track|ok/', $s)) return 'verde';
  return 'neutro';
}