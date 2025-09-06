<?php
// views/mapa_estrategico.php — Mapa Estratégico
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('display_startup_errors','0'); error_reporting(0);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php'); exit;
}
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function slug($s){
  $s = mb_strtolower(trim((string)$s),'UTF-8');
  $s = @iconv('UTF-8','ASCII//TRANSLIT',$s) ?: $s;
  $s = preg_replace('/[^a-z0-9]+/',' ', $s);
  return trim(preg_replace('/\s+/',' ', $s));
}
function normalizeText($s){ return mb_strtoupper(mb_substr((string)$s,0,1),'UTF-8').mb_strtolower(mb_substr((string)$s,1),'UTF-8'); }

$pdo = null;
try{
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS ?? '',
    [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC ]
  );
}catch(Throwable){ /* silencioso */ }

// ===== Empresa do usuário (obrigatória) =====
$userId = (int)$_SESSION['user_id'];
$companyId = null;
try{
  $st = $pdo->prepare("
    SELECT c.id_company
    FROM usuarios u
    LEFT JOIN company c ON c.id_company = u.id_company
    WHERE u.id_user = :uid
    LIMIT 1
  ");
  $st->execute([':uid'=>$userId]);
  $row = $st->fetch();
  if ($row && !empty($row['id_company'])) {
    $companyId = (int)$row['id_company'];
  }
}catch(Throwable){ /* noop */ }

if (!$companyId) {
  header('Location: /OKR_system/organizacao'); exit;
}
$_SESSION['company_id'] = $companyId;

// ===== Utilitários de schema =====
function table_exists(PDO $pdo, string $table): bool {
  try{ $st=$pdo->prepare("SHOW TABLES LIKE :t"); $st->execute([':t'=>$table]); return (bool)$st->fetchColumn(); }
  catch(Throwable){ return false; }
}
function cols(PDO $pdo, string $table): array {
  try{ $st=$pdo->query("SHOW COLUMNS FROM `$table`"); $out=[]; foreach($st as $r){ $out[]=$r['Field']; } return $out; }
  catch(Throwable){ return []; }
}
function hascol(array $list, string $name): bool { foreach($list as $c){ if (strcasecmp($c,$name)===0) return true; } return false; }

/* ============ INJETAR O TEMA (agora com ?cid=) ============ */
if (!defined('PB_THEME_LINK_EMITTED')) {
  define('PB_THEME_LINK_EMITTED', true);
  $cid = (int)$_SESSION['company_id'];
  $noc = isset($_GET['nocache']) ? '&nocache=1' : '';
  echo '<link rel="stylesheet" href="/OKR_system/assets/company_theme.php?cid='.$cid.$noc.'">';
}

/* ======================= Dados base ======================= */

// Usuários (somente da mesma company) — id -> primeiro_nome
$usuarios=[];
if ($pdo && table_exists($pdo,'usuarios')){
  $st = $pdo->prepare("SELECT id_user, primeiro_nome FROM usuarios WHERE id_company = :cid");
  $st->execute([':cid'=>$companyId]);
  foreach($st as $r){
    $usuarios[(string)$r['id_user']] = $r['primeiro_nome'] ?: $r['id_user'];
  }
}

// Pilares (domínio — não depende de empresa)
$pilares=[];
if ($pdo && table_exists($pdo,'dom_pilar_bsc')){
  $c=cols($pdo,'dom_pilar_bsc');
  $labelCol = hascol($c,'descricao_exibicao')?'descricao_exibicao':(hascol($c,'descricao')?'descricao':'id_pilar');
  $ordCol   = hascol($c,'ordem_pilar')?'ordem_pilar':(hascol($c,'ordem')?'ordem':null);
  $sql="SELECT id_pilar, `$labelCol` AS titulo FROM dom_pilar_bsc".($ordCol?" ORDER BY `$ordCol`":"");
  $colorMap = [
    'financeiro' => '#f39c12',
    'clientes'   => '#27ae60',
    'processos'  => '#2980b9', 'processos internos'=>'#2980b9',
    'aprendizado' => '#8e44ad', 'aprendizado e crescimento'=>'#8e44ad'
  ];
  foreach($pdo->query($sql) as $row){
    $key = slug($row['id_pilar']);
    $pilares[$key] = [
      'titulo' => $row['titulo'],
      'cor'    => $colorMap[$key] ?? '#6c757d'
    ];
  }
}

// Objetivos (somente da company)
$objetivos=[];
if ($pdo && table_exists($pdo,'objetivos')){
  $st = $pdo->prepare("
    SELECT o.id_objetivo, o.descricao, o.pilar_bsc, o.tipo, o.dono, o.status, o.dt_prazo, o.qualidade
    FROM objetivos o
    WHERE o.id_company = :cid
  ");
  $st->execute([':cid'=>$companyId]);
  foreach($st as $r){
    $r['pilar'] = slug($r['pilar_bsc']);
    $objetivos[] = $r;
  }
}

// KRs (somente KRs de objetivos da company)
$krs=[];           // id_kr => ['id_objetivo'=>..,'status'=>..]
$krPorObj=[];      // id_objetivo => [id_kr,...]
if ($pdo && table_exists($pdo,'key_results')){
  $st = $pdo->prepare("
    SELECT kr.id_kr, kr.id_objetivo, kr.status
    FROM key_results kr
    JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
    WHERE o.id_company = :cid
  ");
  $st->execute([':cid'=>$companyId]);
  foreach($st as $r){
    $krs[$r['id_kr']] = ['id_objetivo'=>$r['id_objetivo'], 'status'=>mb_strtolower((string)$r['status'])];
    $krPorObj[$r['id_objetivo']][] = $r['id_kr'];
  }
}

// Status KR agregado -> status do objetivo
function statusObjetivoAgregado(int $id_obj, array $krPorObj, array $krs): string {
  $lista = $krPorObj[$id_obj] ?? [];
  if (!$lista) return 'não iniciado';
  $tot = count($lista);
  $con = 0; $and = 0;
  foreach($lista as $idkr){
    $s = $krs[$idkr]['status'] ?? '';
    if ($s==='concluido') $con++;
    if ($s==='em andamento' || $s==='andamento') $and++;
  }
  if ($tot>0 && $con===$tot) return 'concluído';
  if ($and>0 || $con>0) return 'em andamento';
  return 'não iniciado';
}

/* ======================= Métricas / Gráficos ======================= */
$metrics=[]; // id_objetivo => ['qtd_kr'=>int,'progresso'=>float,'krs_sem_apont_mes'=>int]
$charts=[];  // anc => ['labels'=>[], 'data'=>[], 'color'=>hex]

$msTable=null; foreach(['milestones_kr','milestones'] as $t) if($pdo && table_exists($pdo,$t)) { $msTable=$t; break; }
if ($pdo && $msTable){
  $mc = cols($pdo,$msTable);
  // Detecta nomes de colunas
  $COL_EXP = null; foreach(['valor_esperado','esperado','target','meta'] as $c) if(hascol($mc,$c)){$COL_EXP=$c; break;}
  $COL_REAL= null; foreach(['valor_real','realizado','resultado','alcancado'] as $c) if(hascol($mc,$c)){$COL_REAL=$c; break;}
  $COL_ORD = null; foreach(['num_ordem','data_ref','dt_prevista','data_prevista','data','dt','competencia'] as $c) if(hascol($mc,$c)){$COL_ORD=$c; break;}
  $COL_DATE= null; foreach(['dt_apontamento','data_apontamento','data_apont','apontamento_dt','dt_evidencia','data_evidencia','data_ref','dt_prevista','data_prevista','data','dt','competencia'] as $c) if(hascol($mc,$c)){$COL_DATE=$c; break;}

  // ---- Métricas por objetivo (qtd KR + progresso médio) ----
  if ($COL_EXP && $COL_ORD){
    $ordAsc  = "`$COL_ORD` ASC";
    $ordDesc = "`$COL_ORD` DESC";
    $EXP = "`$COL_EXP`";
    $REAL= $COL_REAL ? "`$COL_REAL`" : "NULL";

    $SUB_BASE = "(SELECT $EXP FROM `$msTable` mmb WHERE mmb.id_kr=kr.id_kr ORDER BY $ordAsc  LIMIT 1)";
    $SUB_META = "(SELECT $EXP FROM `$msTable` mme WHERE mme.id_kr=kr.id_kr ORDER BY $ordDesc LIMIT 1)";
    $SUB_REAL = "(SELECT $REAL FROM `$msTable` mmu WHERE mmu.id_kr=kr.id_kr AND $REAL IS NOT NULL ORDER BY $ordDesc LIMIT 1)";

    // Só KRs da company
    $sqlCountKR = "
      SELECT kr.id_objetivo, COUNT(*) AS qtd_kr
      FROM key_results kr
      JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
      WHERE o.id_company = :cid
      GROUP BY kr.id_objetivo
    ";
    $sqlProgKR  = "
      SELECT kr.id_objetivo,
        CASE WHEN (($SUB_META) - ($SUB_BASE)) <> 0 THEN
          LEAST(100, GREATEST(0,
            ROUND( ( (COALESCE(($SUB_REAL), ($SUB_BASE)) - ($SUB_BASE)) / (($SUB_META) - ($SUB_BASE)) ) * 100, 1)
          ))
        ELSE 0 END AS progresso_kr
      FROM key_results kr
      JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
      WHERE o.id_company = :cid
    ";
    $sqlSum = "
      SELECT o.id_objetivo,
             COALESCE(c.qtd_kr,0) AS qtd_kr,
             COALESCE(avgp.progresso,0) AS progresso
      FROM objetivos o
      LEFT JOIN ($sqlCountKR) c ON c.id_objetivo=o.id_objetivo
      LEFT JOIN (SELECT id_objetivo, AVG(progresso_kr) AS progresso FROM ($sqlProgKR) t GROUP BY id_objetivo) avgp
        ON avgp.id_objetivo=o.id_objetivo
      WHERE o.id_company = :cid
    ";
    $st = $pdo->prepare($sqlSum);
    $st->execute([':cid'=>$companyId]);
    foreach($st as $row){
      $metrics[$row['id_objetivo']] = [
        'qtd_kr'=>(int)$row['qtd_kr'],
        'progresso'=>(float)$row['progresso'],
        'krs_sem_apont_mes'=>0
      ];
    }
  } else {
    // Sem milestones utilizáveis: ao menos conta KR (já filtrados por company)
    foreach($krPorObj as $ido=>$arr){
      $metrics[$ido] = ['qtd_kr'=>count($arr),'progresso'=>0.0,'krs_sem_apont_mes'=>0];
    }
  }

  // ---- Dados para os gráficos (evolução no mês) ----
  if ($COL_DATE && $COL_EXP){
    $EXP="`$COL_EXP`";
    $REAL= $COL_REAL ? "`$COL_REAL`" : "NULL";

    // Base/meta por KR (somente KRs da company)
    $bm = [];
    $sqlBM = "
      SELECT m.id_kr, MIN($EXP) AS base, MAX($EXP) AS meta
      FROM `$msTable` m
      JOIN key_results kr ON kr.id_kr = m.id_kr
      JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
      WHERE o.id_company = :cid
      GROUP BY m.id_kr
    ";
    $st = $pdo->prepare($sqlBM); $st->execute([':cid'=>$companyId]);
    foreach($st as $r){ $bm[$r['id_kr']] = ['base'=>(float)$r['base'], 'meta'=>(float)$r['meta']]; }

    // Intervalo do mês atual
    $ini = (new DateTime('first day of this month'))->format('Y-m-d');
    $fim = (new DateTime('last day of this month'))->format('Y-m-d');

    // Milestones do mês (apenas da company)
    $sql = "
      SELECT m.id_kr, DATE(m.`$COL_DATE`) AS dia, $EXP AS exp, ".($COL_REAL ? "$REAL AS realv" : "NULL AS realv")."
      FROM `$msTable` m
      JOIN key_results kr ON kr.id_kr = m.id_kr
      JOIN objetivos o    ON o.id_objetivo = kr.id_objetivo
      WHERE o.id_company = :cid
        AND m.`$COL_DATE` BETWEEN :ini AND :fim
    ";
    $st=$pdo->prepare($sql); $st->execute([':cid'=>$companyId, ':ini'=>$ini, ':fim'=>$fim]);

    // Mapa auxiliar: pilar por KR (via objetivos já filtrados)
    $pilarPorKr=[];
    foreach($krs as $idkr=>$infokr){
      $id_obj = (int)$infokr['id_objetivo'];
      $pilar_key = null;
      foreach($objetivos as $o){ if((int)$o['id_objetivo']===$id_obj){ $pilar_key = $o['pilar']; break; } }
      if ($pilar_key) $pilarPorKr[$idkr] = $pilar_key;
    }

    $acc = []; // $acc[pilar][dia] = [v1,v2,...]
    while($row=$st->fetch()){
      $idkr = (string)$row['id_kr'];
      $pkey = $pilarPorKr[$idkr] ?? null;
      if (!$pkey || empty($bm[$idkr])) continue;
      $base=$bm[$idkr]['base']; $meta=$bm[$idkr]['meta']; if ($meta==$base) continue;

      $val = (float)($row['realv'] ?? null);
      if (!is_finite($val)) $val = (float)$row['exp'];
      $prog = max(0.0, min(100.0, round((($val-$base)/($meta-$base))*100,1)));
      $d = $row['dia'];

      $acc[$pkey][$d][] = $prog;
    }

    // Construir séries por pilar (carry-forward)
    foreach($pilares as $key=>$info){
      $labels=[]; $data=[]; $last=0.0;
      $dt = new DateTime('first day of this month'); $end = new DateTime('last day of this month');
      while($dt <= $end){
        $d = $dt->format('Y-m-d');
        $labels[] = $dt->format('d/m');
        if (!empty($acc[$key][$d])){
          $avg = array_sum($acc[$key][$d]) / max(1,count($acc[$key][$d]));
          $last = round($avg,1);
        }
        $data[] = $last;
        $dt->modify('+1 day');
      }
      $anc = 'pilar_'.preg_replace('/\s+/','_', $key);
      $charts[$anc] = ['labels'=>$labels, 'data'=>$data, 'color'=>$info['cor']];
    }
  }
}

// Agregados para header (já filtrados por company)
$totalObj = count($objetivos);
$totalKR  = 0; foreach($metrics as $m){ $totalKR += (int)($m['qtd_kr']??0); }
$totalPil = count($pilares);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mapa Estratégico — OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" defer></script>

  <style>
    :root{
      --bg-soft:#171b21; --card:var(--bg1, #222222); --muted:#a6adbb; --text:#eaeef6;
      --gold:var(--bg2, #F1C40F); --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20); --btn:#0e131a;
      --accent:#0c4a6e; --chat-w:0px;
    }
    body{ background:#fff !important; color:#111; }
    .content{ background:transparent; }
    main.mapa{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; }
    .crumbs{ color:#333; font-size:.9rem; display:flex; align-items:center; gap:6px; }
    .crumbs a{ color:var(--accent); text-decoration:none; }
    .crumbs .sep{ opacity:.5; margin:0 2px; }
    .crumbs i{ opacity:.8; }
    .head-card{ background:linear-gradient(180deg, var(--card), #0d1117); border:1px solid var(--border); border-radius:16px; padding:16px; box-shadow:var(--shadow); color:var(--text); position:relative; overflow:hidden; }
    .head-top{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .head-title{ margin:0; font-size:1.35rem; font-weight:900; letter-spacing:.2px; display:flex; align-items:center; gap:8px; }
    .head-title i{ color:var(--gold); }
    .head-meta{ margin-top:10px; display:flex; gap:10px; flex-wrap:wrap; }
    .pill{ display:inline-flex; align-items:center; gap:8px; background:#0e131a; border:1px solid var(--border); color: var(--muted); padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
    .pill i{ font-size:.9rem; opacity:.9; }
    .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:8px 12px; border-radius:10px; font-weight:800; cursor:pointer; }
    .btn:hover{ transform:translateY(-1px); transition:.15s; }
    .btn-gold{ background:var(--gold); color:#111; border:1px solid rgba(246,195,67,.9); padding:10px 16px; border-radius:12px; font-weight:900; white-space:nowrap; box-shadow:0 6px 20px rgba(246,195,67,.22); }
    .btn-gold:hover{ filter:brightness(.96); transform:translateY(-1px); box-shadow:0 10px 28px rgba(246,195,67,.28); }
    .filters{ background:linear-gradient(180deg, var(--card), #0e1319); border:1px solid var(--border); border-radius:14px; padding:14px; box-shadow:var(--shadow); color:var(--text); }
    .filters-grid{ display:grid; grid-template-columns: 1fr auto; gap:12px; align-items:center; }
    .search{ width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none; }
    .anchors{ display:flex; flex-wrap:wrap; gap:8px; justify-content:flex-end; }
    .anchors .pill{ cursor:pointer; }
    .pilar-wrap{ background:linear-gradient(180deg, var(--card), #0e1319); border:1px solid var(--border); border-radius:16px; padding:14px; box-shadow:var(--shadow); color:var(--text); }
    .pilar-head{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px; }
    .pilar-title{ font-weight:900; display:flex; align-items:center; gap:8px; text-transform:lowercase; }
    .pilar-title i{ color:var(--gold); }
    .pilar-progress{ height:18px; background:#0b1422; border:1px solid #1c2b46; border-radius:999px; overflow:hidden; position:relative; margin:8px 0 14px; box-shadow: inset 0 4px 18px rgba(0,0,0,.35); }
    .pilar-progress .bar{ height:100%; width:0%; transition:width .5s ease; background: linear-gradient(90deg, var(--gold), var(--blue)); }
    .pilar-progress .val{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#0a1220; font-weight:900; text-shadow:0 1px 0 rgba(255,255,255,.45); mix-blend-mode:screen; }
    .pilar-grid{ display:grid; grid-template-columns: minmax(360px, 2fr) minmax(320px, 1.2fr); gap:14px; align-items:stretch; }
    @media (max-width: 980px){ .pilar-grid{ grid-template-columns: 1fr; } }
    .cards-grid{ display:grid; grid-template-columns: repeat(2, minmax(240px,1fr)); gap:12px; }
    @media (max-width: 640px){ .cards-grid{ grid-template-columns: 1fr; } }
    .card{ background:linear-gradient(180deg, var(--card), #0e1319); border:1px solid var(--border); border-radius:16px; padding:10px; box-shadow:var(--shadow); color:var(--text); position:relative; display:grid; gap:8px; grid-template-rows:auto auto auto 1fr; overflow:hidden; transition:transform .2s ease, box-shadow .2s ease, max-height .25s ease; max-height: 160px; }
    .card:hover{ transform:translateY(-3px); box-shadow:0 12px 28px rgba(0,0,0,.35); max-height: 360px; }
    .title{ font-weight:900; letter-spacing:.2px; line-height:1.25; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .progress{ height:8px; background:#0f172a; border:1px solid #1f2a44; border-radius:5px; overflow:hidden; }
    .progress .bar{ height:100%; background:var(--bar, #60a5fa); transition:width .35s ease; }
    .row{ display:flex; justify-content:space-between; font-size:.9rem; color:#cbd5e1; }
    .more{ display:grid; gap:8px; opacity:.0; max-height:0; transition:max-height .25s ease, opacity .25s ease; }
    .card:hover .more{ opacity:1; max-height:220px; }
    .badges{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .badge{ font-size:.78rem; border:1px solid var(--border); padding:4px 8px; border-radius:999px; color:#c9d4e5; }
    .b-type{ border:1px dashed #334155; color:#cbd5e1; }
    .b-green{ background:rgba(34,197,94,.12); border-color:#14532d; color:#a7f3d0; }
    .b-blue{ background:rgba(59,130,246,.12); border-color:#1e3a8a; color:#bfdbfe; }
    .b-gray{ background:rgba(148,163,184,.15); border-color:#334155; color:#cbd5e1; }
    .b-warn{ background:rgba(250,204,21,.16); border-color:#705e14; color:#ffec99; }
    .meta{ font-size:.92rem; color:#cbd5e1; display:grid; gap:2px; }
    .link{ position:absolute; inset:0; text-decoration:none; color:inherit; }
    .chart-card{ background:linear-gradient(180deg, var(--card), #0e1319); border:1px solid var(--border); border-radius:16px; padding:12px; box-shadow:var(--shadow); color:#var(--text); display:grid; grid-template-rows:auto 1fr; gap:8px; min-height:280px; }
    .chart-title{ font-weight:900; display:flex; align-items:center; gap:8px; }
    .chart-box{ position:relative; height:100%; min-height:220px; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="mapa">
      <!-- Breadcrumb -->
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <span><i class="fa-solid fa-sitemap"></i> Mapa Estratégico</span>
      </div>

      <!-- Cabeçalho -->
      <section class="head-card">
        <div class="head-top">
          <h1 class="head-title"><i class="fa-solid fa-sitemap"></i> Mapa Estratégico</h1>
          <button class="btn-gold" onclick="location.reload()"><i class="fa-solid fa-rotate"></i> Atualizar</button>
        </div>
        <div class="head-meta">
          <span class="pill"><i class="fa-regular fa-bullseye"></i> Objetivos: <?= (int)$totalObj ?></span>
          <span class="pill"><i class="fa-solid fa-list-check"></i> KRs: <?= (int)$totalKR ?></span>
          <span class="pill"><i class="fa-solid fa-layer-group"></i> Pilares: <?= (int)$totalPil ?></span>
        </div>
      </section>

      <!-- Toolbar (busca + âncoras) -->
      <section class="filters">
        <div class="filters-grid">
          <input id="q" class="search" type="search" placeholder="Pesquisar objetivos por texto…">
          <div class="anchors" id="anchors"></div>
        </div>
      </section>

      <!-- Pilares + Objetivos + Gráficos -->
      <?php
      echo '<script>window.__anchors = [];</script>';

      foreach ($pilares as $pillKey => $info):
        $objs = array_values(array_filter($objetivos, fn($o)=> slug($o['pilar']) === $pillKey));
        $acc=0; $n=0;
        foreach($objs as $o){ if(isset($metrics[$o['id_objetivo']])){ $acc += (float)$metrics[$o['id_objetivo']]['progresso']; $n++; } }
        $pilarProg = $n ? round($acc/$n,1) : 0.0;
        $anc = 'pilar_'.preg_replace('/\s+/','_', $pillKey);
        echo "<script>window.__anchors.push({id:".json_encode($anc).",label:".json_encode(mb_strtolower($info['titulo'],'UTF-8'))."});</script>";
      ?>
      <section id="<?= h($anc) ?>" class="pilar">
        <div class="pilar-wrap" style="border-left:6px solid <?= h($info['cor']) ?>;">
          <div class="pilar-head">
            <div class="pilar-title"><i class="fa-solid fa-layer-group"></i> <?= h(mb_strtolower($info['titulo'],'UTF-8')) ?></div>
            <div class="head-meta" style="margin:0">
              <span class="pill"><i class="fa-solid fa-bullseye"></i> Objetivos: <?= count($objs) ?></span>
              <span class="pill"><i class="fa-solid fa-chart-line"></i> Progresso médio: <?= number_format($pilarProg,1,',','.') ?>%</span>
            </div>
          </div>

          <div class="pilar-progress" title="Progresso médio dos objetivos deste pilar">
            <div class="bar" style="width: <?= (float)$pilarProg ?>%"></div>
            <div class="val"><?= number_format($pilarProg,1,',','.') ?>%</div>
          </div>

          <div class="pilar-grid">
            <!-- Cards à esquerda -->
            <div class="cards-grid" data-cards="<?= h($pillKey) ?>">
              <?php if (!$objs): ?>
                <div class="pill"><i class="fa-regular fa-folder-open"></i> Nenhum objetivo neste pilar.</div>
              <?php else: foreach($objs as $obj):
                $m = $metrics[$obj['id_objetivo']] ?? ['qtd_kr'=>0,'progresso'=>0,'krs_sem_apont_mes'=>0];
                $prog = min(100, (float)$m['progresso']);
                $prazo = 'Sem prazo';
                if(!empty($obj['dt_prazo'])){
                  try{ $prazo=(new DateTime($obj['dt_prazo']))->format('d/m/Y'); }catch(Throwable){ $prazo='Data inválida'; }
                }
                $status = statusObjetivoAgregado((int)$obj['id_objetivo'], $krPorObj, $krs);
                $statusBadge = $status==='concluído' ? 'b-green' : ($status==='em andamento' ? 'b-blue' : 'b-gray');
                $pend = (int)($m['krs_sem_apont_mes'] ?? 0);
                $detailUrl = "/OKR_system/views/detalhe_okr.php?id=" . urlencode((string)$obj['id_objetivo']);
              ?>
              <article class="card" data-text="<?= h(mb_strtolower($obj['descricao'],'UTF-8')) ?>" style="--bar: <?= h($info['cor']) ?>">
                <div class="title"><?= h(normalizeText($obj['descricao'])) ?></div>
                <div class="progress"><div class="bar" style="width: <?= (float)$prog ?>%"></div></div>
                <div class="row"><div>KR: <strong><?= (int)$m['qtd_kr'] ?></strong></div><div>Prog: <strong><?= number_format($prog,1,',','.') ?>%</strong></div></div>

                <div class="more">
                  <div class="badges">
                    <span class="badge b-type"><?= h(normalizeText($obj['tipo'])) ?></span>
                    <span class="badge <?= $statusBadge ?>">Status: <?= h(ucfirst($status)) ?></span>
                    <?php if ($pend>0): ?>
                      <span class="badge b-warn" title="KRs sem apontamento no mês anterior">🔔 <?= $pend ?></span>
                    <?php else: ?>
                      <span class="badge">✅</span>
                    <?php endif; ?>
                  </div>
                  <div class="meta">
                    <div>Dono: <?= h($usuarios[(string)$obj['dono']] ?? $obj['dono']) ?></div>
                    <div>Prazo: <?= h($prazo) ?></div>
                    <div>Qualidade: <?= h($obj['qualidade']) ?></div>
                  </div>
                </div>

                <a class="link" href="<?= h($detailUrl) ?>" title="Abrir objetivo"></a>
              </article>
              <?php endforeach; endif; ?>
            </div>

            <!-- Gráfico à direita -->
            <div class="chart-card">
              <div class="chart-title"><i class="fa-solid fa-chart-line"></i> Evolução no mês</div>
              <div class="chart-box">
                <canvas id="chart_<?= h($anc) ?>"></canvas>
              </div>
            </div>
          </div>
        </div>
      </section>
      <?php endforeach; ?>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

<script>
  // Âncoras dos pilares
  (function(){
    const box = document.getElementById('anchors');
    if (!box || !window.__anchors) return;
    window.__anchors.forEach(a=>{
      const link = document.createElement('a');
      link.className='pill';
      link.href='#'+a.id;
      link.textContent=a.label;
      box.appendChild(link);
    });
  })();

  // Busca de objetivos
  const q = document.getElementById('q');
  if (q){
    q.addEventListener('input', ()=>{
      const term = (q.value||'').toLowerCase().trim();
      document.querySelectorAll('.cards-grid .card').forEach(card=>{
        const txt = (card.getAttribute('data-text')||'').toLowerCase();
        card.style.display = term && !txt.includes(term) ? 'none' : '';
      });
    });
  }

  // Ajuste com chat lateral
  const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
  const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
  function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
  function isOpen(el){ const st=getComputedStyle(el); const vis=st.display!=='none'&&st.visibility!=='hidden'; const w=el.offsetWidth; return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show'); }
  function updateChatWidth(){ const el=findChatEl(); const w=(el && isOpen(el))?el.offsetWidth:0; document.documentElement.style.setProperty('--chat-w',(w||0)+'px'); }
  function setupChatObservers(){ const chat=findChatEl(); if(!chat) return; const mo=new MutationObserver(()=>updateChatWidth()); mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']}); window.addEventListener('resize',updateChatWidth); TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,200)))); updateChatWidth(); }
  document.addEventListener('DOMContentLoaded', ()=>{
    setupChatObservers();
    const moBody = new MutationObserver(()=>{ if(findChatEl()){ setupChatObservers(); moBody.disconnect(); } });
    moBody.observe(document.body,{childList:true,subtree:true});
  });

  // Charts
  window.__charts = <?= json_encode($charts, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  document.addEventListener('DOMContentLoaded', ()=>{
    if (!window.Chart || !window.__charts) return;
    Object.entries(window.__charts).forEach(([anc, cfg])=>{
      const ctx = document.getElementById('chart_'+anc);
      if (!ctx) return;
      const color = cfg.color || '#60a5fa';
      const hexToRGBA = (hex, a)=> {
        const s=hex.replace('#',''); const bigint=parseInt(s,16);
        const r=(bigint>>16)&255, g=(bigint>>8)&255, b=bigint&255;
        return `rgba(${r}, ${g}, ${b}, ${a})`;
      };
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: cfg.labels || [],
          datasets: [{
            data: (cfg.data||[]).map(v=>typeof v==='number'?v:null),
            borderColor: color,
            backgroundColor: hexToRGBA(color, 0.18),
            fill: true,
            tension: 0.35,
            pointRadius: 0,
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          interaction: { mode:'index', intersect:false },
          plugins: {
            legend: { display:false },
            tooltip: { callbacks: { label: (ctx)=> ` ${ctx.formattedValue}%` } }
          },
          scales: {
            x: { grid:{ display:false }, ticks:{ color:'#cbd5e1', maxRotation:0, autoSkip:true } },
            y: { grid:{ color:'rgba(255,255,255,.05)' }, ticks:{ color:'#cbd5e1', callback:(v)=> v+'%' }, min:0, max:100 }
          },
          elements: { line: { borderWidth:2 }, point: { hitRadius:8 } },
          spanGaps: true
        }
      });
    });
  });
</script>
</body>
</html>
