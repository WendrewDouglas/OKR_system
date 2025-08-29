<?php
// /OKR_system/api/mapa_estrategico.php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success'=>false,'error'=>'Não autorizado']); exit;
}

/* ===== Conexão ===== */
try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
  );
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'Erro de conexão']); exit;
}

/* ===== Helpers dinâmicos (mesma filosofia do detalhe_okr) ===== */
$tableExists = static function(PDO $pdo, string $t): bool {
  static $c=[]; if(isset($c[$t])) return $c[$t];
  try{$pdo->query("SHOW COLUMNS FROM `$t`"); return $c[$t]=true;}catch(Throwable $e){return $c[$t]=false;}
};
$colExists = static function(PDO $pdo, string $t, string $cname): bool {
  static $c=[]; $k="$t.$cname"; if(isset($c[$k])) return $c[$k];
  try{$st=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE :c"); $st->execute(['c'=>$cname]);
      return $c[$k]=(bool)$st->fetch();}catch(Throwable $e){return $c[$k]=false;}
};
$getCols = static function(PDO $pdo, string $t): array {
  try{ return $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC) ?: []; }catch(Throwable $e){ return []; }
};

$normPilar = static function($raw){
  $k = mb_strtolower(trim((string)$raw),'UTF-8');
  if ($k==='aprendizado e crescimento' || str_starts_with($k,'apr')) return 'Aprendizado';
  if (str_starts_with($k,'proc')) return 'Processos';
  if (str_starts_with($k,'cli')) return 'Clientes';
  if (str_starts_with($k,'fin')) return 'Financeiro';
  return ucfirst($k ?: 'Outros');
};

/* ===== Descobrir tabelas/colunas ===== */
if (!$tableExists($pdo,'objetivos') || !$tableExists($pdo,'key_results')) {
  echo json_encode(['success'=>false,'error'=>'Tabelas objetivos/key_results não encontradas']); exit;
}
$OBJ = 'objetivos';
$KR  = 'key_results';

/* objetivos: possív. colunas */
$oc = $getCols($pdo,$OBJ);
$have = fn($name)=>array_search($name, array_column($oc,'Field'))!==false;
$OBJ_ID     = $have('id_objetivo') ? 'id_objetivo' : ($have('id') ? 'id' : array_column($oc,'Field')[0]);
$OBJ_DESC   = $have('descricao') ? 'descricao' : ($have('nome') ? 'nome' : $OBJ_ID);
$OBJ_PILAR  = $have('pilar_bsc') ? 'pilar_bsc' : ($have('pilar') ? 'pilar' : null);
$OBJ_TIPO   = $have('tipo') ? 'tipo' : null;
$OBJ_STATUS = $have('status') ? 'status' : null;
$OBJ_DONO   = $have('dono') ? 'dono' : null;
$OBJ_PRAZO  = $have('dt_prazo') ? 'dt_prazo' : ( $have('data_fim') ? 'data_fim' : null);
$OBJ_QUALI  = $have('qualidade') ? 'qualidade' : null;

/* key_results: possív. colunas */
$kc = $getCols($pdo,$KR);
$kHave = fn($name)=>array_search($name, array_column($kc,'Field'))!==false;
$KR_ID        = $kHave('id_kr') ? 'id_kr' : ($kHave('id') ? 'id' : array_column($kc,'Field')[0]);
$KR_OBJ       = $kHave('id_objetivo') ? 'id_objetivo' : null;
$KR_FAROL     = $kHave('farol') ? 'farol' : null;
$KR_STATUS    = $kHave('status') ? 'status' : null;

/* milestones table + colunas */
$MS_T = null; foreach(['milestones_kr','milestones'] as $t){ if($tableExists($pdo,$t)){ $MS_T=$t; break; } }
$MS_DATE  = null; $MS_EXP = null; $MS_REAL = null; $MS_EVD = null; $MS_ORDER = [];
if ($MS_T){
  $mc = $getCols($pdo,$MS_T);
  $mHave = fn($n)=>array_search($n,array_column($mc,'Field'))!==false;
  // data de referência para ordenação
  foreach (['num_ordem','data_ref','dt_prevista','data_prevista','data'] as $cand) if ($mHave($cand)) $MS_ORDER[] = "`$cand`";
  $MS_DATE = $mHave('data_ref') ? 'data_ref' : ($mHave('dt_prevista') ? 'dt_prevista' : ($mHave('data_prevista') ? 'data_prevista' : ($mHave('data') ? 'data' : null)));
  // esperado/real
  $MS_EXP  = $mHave('valor_esperado') ? 'valor_esperado' : ($mHave('esperado') ? 'esperado' : null);
  $MS_REAL = $mHave('valor_real') ? 'valor_real' : ($mHave('realizado') ? 'realizado' : null);
  $MS_EVD  = $mHave('dt_evidencia') ? 'dt_evidencia' : ($mHave('data_evidencia') ? 'data_evidencia' : null);
  if (!$MS_ORDER) $MS_ORDER[] = "`$MS_T`.`$KR_ID`"; // fallback estável
}

/* apontamentos no mês anterior (sem dt_apontamento usa dt_evidencia) */
$prevMonthStart = (new DateTime('first day of previous month'))->format('Y-m-d');
$prevMonthEnd   = (new DateTime('last day of previous month'))->format('Y-m-d');

/* filtros */
$ciclo  = trim($_GET['ciclo']  ?? '');
$dono   = trim($_GET['dono']   ?? '');
$status = trim($_GET['status'] ?? '');

/* ===== Buscar Objetivos ===== */
$where = [];
$bind  = [];

if ($dono !== '' && $OBJ_DONO) { $where[] = "o.`$OBJ_DONO` = :dono"; $bind[':dono'] = $dono; }
if ($status !== '' && $OBJ_STATUS) { $where[] = "o.`$OBJ_STATUS` = :st"; $bind[':st'] = $status; }

/* tenta coluna de ciclo/ano/período se existir */
foreach (['ciclo','periodo','ano'] as $cand) {
  if ($ciclo !== '' && $colExists($pdo,$OBJ,$cand)) { $where[] = "o.`$cand` = :ciclo"; $bind[':ciclo'] = $ciclo; break; }
}

$sqlObj = "
  SELECT
    o.`$OBJ_ID`   AS id_objetivo,
    o.`$OBJ_DESC` AS descricao,
    ".($OBJ_PILAR  ? "o.`$OBJ_PILAR`  AS pilar,"     : "NULL AS pilar,")."
    ".($OBJ_TIPO   ? "o.`$OBJ_TIPO`   AS tipo,"      : "NULL AS tipo,")."
    ".($OBJ_STATUS ? "o.`$OBJ_STATUS` AS status,"    : "NULL AS status,")."
    ".($OBJ_DONO   ? "o.`$OBJ_DONO`   AS dono,"      : "NULL AS dono,")."
    ".($OBJ_PRAZO  ? "o.`$OBJ_PRAZO`  AS dt_prazo,"  : "NULL AS dt_prazo,")."
    ".($OBJ_QUALI  ? "o.`$OBJ_QUALI`  AS qualidade"  : "NULL AS qualidade")."
  FROM `$OBJ` o
  ".($where ? "WHERE ".implode(' AND ', $where) : "")."
  ORDER BY o.`$OBJ_ID` ASC
";
$stObj = $pdo->prepare($sqlObj); $stObj->execute($bind);
$objetivos = $stObj->fetchAll();

/* se não há objetivos, retorna estrutura vazia (evita 500) */
if (!$objetivos) {
  echo json_encode(['success'=>true,'pillars'=>[]]); exit;
}

/* Mapa de donos -> nome (usuarios.primeiro_nome) */
$donoNames = [];
if ($OBJ_DONO && $tableExists($pdo,'usuarios')) {
  try {
    $ids = array_values(array_unique(array_filter(array_map(fn($o)=> (int)($o['dono'] ?? 0), $objetivos))));
    if ($ids){
      $in = implode(',', array_fill(0,count($ids),'?'));
      $st = $pdo->prepare("SELECT id_user, primeiro_nome FROM usuarios WHERE id_user IN ($in)");
      $st->execute($ids);
      foreach ($st as $r) $donoNames[(int)$r['id_user']] = $r['primeiro_nome'];
    }
  } catch(Throwable $e){}
}

/* Carrega KRs de todos os objetivos de uma vez */
$idsObj = array_column($objetivos,'id_objetivo');
$inObj  = implode(',', array_fill(0,count($idsObj),'?'));

$krs = [];
$krsByObj = [];
$critByObj = [];
$qtdKRByObj = [];
if ($KR_OBJ) {
  $sqlKr = "SELECT `$KR_ID` AS id_kr, `$KR_OBJ` AS id_objetivo".
           ($KR_FAROL ? ", `$KR_FAROL` AS farol" : "").
           " FROM `$KR` WHERE `$KR_OBJ` IN ($inObj)";
  $stKr = $pdo->prepare($sqlKr); $stKr->execute($idsObj);
  $krs = $stKr->fetchAll();

  foreach ($krs as $k) {
    $oid = (int)$k['id_objetivo'];
    $krsByObj[$oid][] = $k;
    $qtdKRByObj[$oid] = ($qtdKRByObj[$oid] ?? 0) + 1;
    if ($KR_FAROL && isset($k['farol'])) {
      $f = mb_strtolower(trim((string)$k['farol']),'UTF-8');
      if (str_contains($f,'vermelho')) $critByObj[$oid] = ($critByObj[$oid] ?? 0) + 1;
    }
  }
}

/* ===== Cálculo de progresso do KR (0..100) usando milestones ===== */
function krProgress(PDO $pdo, string $MS_T=null, ?string $MS_EXP=null, ?string $MS_REAL=null, array $MS_ORDER=[], $krId=null): float {
  if (!$MS_T || !$MS_EXP) return 0.0;

  // Base: 1º valor_esperado; Meta: último valor_esperado; Último real: último valor_real setado
  $ordAsc  = $MS_ORDER ? implode(', ', $MS_ORDER).' ASC'  : 'id ASC';
  $ordDesc = $MS_ORDER ? implode(', ', $MS_ORDER).' DESC' : 'id DESC';

  // baseline
  $sqlBase = "SELECT `$MS_EXP` AS v FROM `$MS_T` WHERE id_kr = :id ORDER BY $ordAsc LIMIT 1";
  // meta
  $sqlMeta = "SELECT `$MS_EXP` AS v FROM `$MS_T` WHERE id_kr = :id ORDER BY $ordDesc LIMIT 1";
  // último real
  $realV = null;
  if ($MS_REAL){
    $sqlReal = "SELECT `$MS_REAL` AS v FROM `$MS_T` WHERE id_kr = :id AND `$MS_REAL` IS NOT NULL ORDER BY $ordDesc LIMIT 1";
  }

  try{
    $b = $pdo->prepare($sqlBase); $b->execute(['id'=>$krId]); $base = (float)($b->fetchColumn() ?? 0);
    $m = $pdo->prepare($sqlMeta); $m->execute(['id'=>$krId]); $meta = (float)($m->fetchColumn() ?? 0);
    if (isset($sqlReal)) { $r = $pdo->prepare($sqlReal); $r->execute(['id'=>$krId]); $realV = $r->fetchColumn(); }
    $real = $realV!==null ? (float)$realV : $base;

    $den = ($meta - $base);
    if (abs($den) < 1e-9) return 0.0;
    $p = (($real - $base)/$den)*100.0;
    if ($p<0) $p=0; if ($p>100) $p=100;
    return round($p,1);
  }catch(Throwable $e){
    return 0.0;
  }
}

/* ===== KR sem apontamento no mês anterior ===== */
function krsSemApontMes(PDO $pdo, string $MS_T=null, ?string $MS_DATE=null, ?string $MS_EVD=null, string $start='', string $end='', array $krIds=[]): array {
  if (!$MS_T || (!$MS_DATE && !$MS_EVD) || !$krIds) return [];
  $in = implode(',', array_fill(0,count($krIds),'?'));
  $params = $krIds;
  $cond = [];

  if ($MS_DATE) { $cond[] = "(`$MS_DATE` BETWEEN ? AND ?)"; $params[]=$start; $params[]=$end; }
  if ($MS_EVD)  { $cond[] = "(`$MS_EVD` BETWEEN ? AND ?)";  $params[]=$start; $params[]=$end; }
  $condSql = implode(' OR ', $cond);

  $sql = "
    SELECT id_kr FROM `$MS_T`
    WHERE id_kr IN ($in) AND ($condSql)
    GROUP BY id_kr
  ";
  try{
    $st = $pdo->prepare($sql); $st->execute($params);
    $tem = array_fill_keys(array_column($st->fetchAll(PDO::FETCH_COLUMN), 0), true);
  }catch(Throwable $e){ $tem = []; }

  $out = [];
  foreach ($krIds as $id) if (empty($tem[$id])) $out[] = (int)$id;
  return $out;
}

/* pré-calcular KR progress + sem apontamento por objetivo */
$progressByKr = [];
$krsNoPrevByObj = [];
if ($MS_T && $MS_EXP) {
  foreach ($krs as $k) {
    $progressByKr[(int)$k['id_kr']] = krProgress($pdo, $MS_T, $MS_EXP, $MS_REAL, $MS_ORDER, $k['id_kr']);
  }
  // sem apontamento
  $allKrIds = array_map(fn($k)=> (int)$k['id_kr'], $krs);
  $noPrev = krsSemApontMes($pdo, $MS_T, $MS_DATE, $MS_EVD, $prevMonthStart, $prevMonthEnd, $allKrIds);
  $noPrevSet = array_fill_keys($noPrev, true);
  foreach ($krs as $k) {
    $oid = (int)$k['id_objetivo']; $kid = (int)$k['id_kr'];
    if (!empty($noPrevSet[$kid])) $krsNoPrevByObj[$oid] = ($krsNoPrevByObj[$oid] ?? 0) + 1;
  }
}

/* ===== Montar saída por PILAR ===== */
$byPillar = []; // pilar => { progress, total_objetivos, total_krs, criticos, objetivos:[] }

foreach ($objetivos as $o) {
  $oid = (int)$o['id_objetivo'];
  $pName = $normPilar($o['pilar'] ?? 'Outros');

  $krList = $krsByObj[$oid] ?? [];
  $qtdKR  = $qtdKRByObj[$oid] ?? 0;
  $crit   = $critByObj[$oid] ?? 0;

  // média dos progressos dos KRs
  $progSum=0; $n=0;
  $krsCompact = [];
  foreach ($krList as $k) {
    $kid = (int)$k['id_kr'];
    $p   = (float)($progressByKr[$kid] ?? 0);
    $progSum += $p; $n++;
    $krsCompact[] = ['id_kr'=>$kid, 'progress'=>$p];
  }
  $objProg = $n ? round($progSum/$n, 1) : 0.0;

  $objPayload = [
    'id_objetivo'        => $oid,
    'nome'               => $o['descricao'],
    'descricao'          => $o['descricao'],
    'pilar'              => $pName,
    'tipo'               => $o['tipo'] ?? null,
    'status'             => $o['status'] ?? null,
    'dono'               => $o['dono'] ?? null,
    'dono_nome'          => ($o['dono'] !== null && isset($donoNames[(int)$o['dono']])) ? $donoNames[(int)$o['dono']] : null,
    'prazo'              => $o['dt_prazo'] ?? null,
    'qualidade'          => $o['qualidade'] ?? null,
    'progress'           => $objProg,
    'qtd_kr'             => $qtdKR,
    'krs_sem_apont_mes'  => (int)($krsNoPrevByObj[$oid] ?? 0),
    'krs'                => $krsCompact,
    // ajuste a URL se desejar
    'url'                => "/OKR_system/views/detalhe_okr.php?id=".$oid
  ];

  if (!isset($byPillar[$pName])) {
    $byPillar[$pName] = [
      'pilar'            => $pName,
      'progress'         => 0.0,
      'total_objetivos'  => 0,
      'total_krs'        => 0,
      'criticos'         => 0,
      'objetivos'        => []
    ];
  }
  $byPillar[$pName]['objetivos'][] = $objPayload;
  $byPillar[$pName]['total_objetivos'] += 1;
  $byPillar[$pName]['total_krs']      += $qtdKR;
  $byPillar[$pName]['criticos']       += $crit;
}

/* progresso do pilar = média dos progressos dos objetivos do pilar */
foreach ($byPillar as $k=>$p){
  $sum=0; $n=0;
  foreach ($p['objetivos'] as $o) { $sum += (float)$o['progress']; $n++; }
  $byPillar[$k]['progress'] = $n? round($sum/$n,1) : 0.0;
}

/* Ordenação consistente (opcional) */
$order = ['Aprendizado','Processos','Clientes','Financeiro'];
uksort($byPillar, function($a,$b) use($order){
  $ia = array_search($a,$order,true); $ib = array_search($b,$order,true);
  if ($ia===false) $ia=999; if($ib===false)$ib=999;
  return $ia<=>$ib ?: strcmp($a,$b);
});

/* Saída final */
echo json_encode([
  'success'=>true,
  'pillars'=>array_values($byPillar)
], JSON_UNESCAPED_UNICODE);
