<?php
// views/orcamento.php — Análise de Orçamentos (estilo claro + cards escuros)

declare(strict_types=1);
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (!isset($_SESSION['user_id'])) { header('Location: /OKR_system/views/login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

/* ===================== Conexão ===================== */
try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC ]
  );
} catch(Throwable $e){
  http_response_code(500);
  die('Erro ao conectar ao banco.');
}

/* ===================== Helpers ===================== */
$g = static function(array $row, string $k, $d=null){ return array_key_exists($k,$row) ? $row[$k] : $d; };

$companyOfUser = static function(PDO $pdo, int $idUser): ?string {
  try {
    $st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=:u LIMIT 1");
    $st->execute(['u'=>$idUser]);
    $c = $st->fetchColumn();
    return ($c===false || $c===null || $c==='')? null : (string)$c;
  } catch(Throwable $e){ return null; }
};

/* ===================== AJAX ===================== */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  $action = $_GET['ajax'];
  $uid = (int)$_SESSION['user_id'];
  $userCompany = $companyOfUser($pdo, $uid);

  if ($action === 'filters_init') {
    try {
      $pillars = $pdo->query("SELECT DISTINCT COALESCE(pilar_bsc,'—') AS label FROM objetivos ORDER BY label")->fetchAll();
      if ($userCompany !== null) {
        $st = $pdo->prepare("
          SELECT id_user, TRIM(CONCAT(COALESCE(primeiro_nome,''),' ',COALESCE(ultimo_nome,''))) AS nome
          FROM usuarios WHERE id_company = :c ORDER BY primeiro_nome, ultimo_nome
        "); $st->execute(['c'=>$userCompany]);
      } else {
        $st = $pdo->query("
          SELECT id_user, TRIM(CONCAT(COALESCE(primeiro_nome,''),' ',COALESCE(ultimo_nome,''))) AS nome
          FROM usuarios ORDER BY primeiro_nome, ultimo_nome
        ");
      }
      $responsaveis = $st->fetchAll();
      $aprov = $pdo->query("SELECT DISTINCT COALESCE(status_aprovacao,'pendente') AS s FROM orcamentos ORDER BY s")->fetchAll(PDO::FETCH_COLUMN);
      $fin   = $pdo->query("SELECT DISTINCT COALESCE(status_financeiro,'—') AS s FROM orcamentos ORDER BY s")->fetchAll(PDO::FETCH_COLUMN);

      echo json_encode(['success'=>true,'pillars'=>$pillars,'responsaveis'=>$responsaveis,'status_aprovacao'=>$aprov,'status_financeiro'=>$fin]); exit;
    } catch(Throwable $e){ echo json_encode(['success'=>false,'error'=>'Falha ao carregar filtros']); exit; }
  }

  if ($action === 'list_objetivos') {
    $pilar = trim($_GET['pilar'] ?? '');
    try {
      if ($pilar !== '') { $st=$pdo->prepare("SELECT id_objetivo, descricao FROM objetivos WHERE pilar_bsc=:p ORDER BY descricao"); $st->execute(['p'=>$pilar]); }
      else { $st=$pdo->query("SELECT id_objetivo, descricao FROM objetivos ORDER BY descricao"); }
      echo json_encode(['success'=>true,'items'=>$st->fetchAll()]); exit;
    } catch(Throwable $e){ echo json_encode(['success'=>false,'error'=>'Falha ao listar objetivos']); exit; }
  }

  if ($action === 'list_krs') {
    $id_obj = (int)($_GET['id_objetivo'] ?? 0);
    if ($id_obj<=0){ echo json_encode(['success'=>true,'items'=>[]]); exit; }
    try{
      $st = $pdo->prepare("
        SELECT id_kr, CONCAT('KR ', COALESCE(key_result_num,''), ' — ', LEFT(COALESCE(descricao,''), 120)) AS label
        FROM key_results WHERE id_objetivo=:o ORDER BY key_result_num ASC, id_kr ASC
      "); $st->execute(['o'=>$id_obj]);
      echo json_encode(['success'=>true,'items'=>$st->fetchAll()]); exit;
    } catch(Throwable $e){ echo json_encode(['success'=>false,'error'=>'Falha ao listar KRs']); exit; }
  }

  if ($action === 'search') {
    $pilar = trim($_GET['pilar'] ?? '');
    $id_obj = (int)($_GET['id_objetivo'] ?? 0);
    $id_kr = $_GET['id_kr'] ?? '';
    $resp = (int)($_GET['id_responsavel'] ?? 0);
    $aprov = trim($_GET['status_aprovacao'] ?? '');
    $fin   = trim($_GET['status_financeiro'] ?? '');
    $dini  = trim($_GET['ini'] ?? '');
    $dfim  = trim($_GET['fim'] ?? '');

    $nowY = (int)date('Y');
    if (!preg_match('/^\d{4}\-\d{2}$/', $dini)) $dini = $nowY.'-01';
    if (!preg_match('/^\d{4}\-\d{2}$/', $dfim)) $dfim = $nowY.'-12';
    $dateStart = $dini.'-01';
    $y = (int)substr($dfim,0,4); $m=(int)substr($dfim,5,2); $lastDay = (int)date('t', strtotime("$y-$m-01"));
    $dateEnd = sprintf('%04d-%02d-%02d', $y, $m, $lastDay);

    $where=[]; $binds=[];
    if ($pilar !== '') { $where[]="obj.pilar_bsc=:pilar"; $binds['pilar']=$pilar; }
    if ($id_obj > 0)   { $where[]="obj.id_objetivo=:id_obj"; $binds['id_obj']=$id_obj; }
    if ($id_kr !== '') { $where[]="i.id_kr=:id_kr"; $binds['id_kr']=$id_kr; }
    if ($resp > 0)     { $where[]="i.id_user_responsavel=:resp"; $binds['resp']=$resp; }
    if ($aprov !== '') { $where[]="COALESCE(o.status_aprovacao,'pendente')=:aprov"; $binds['aprov']=$aprov; }
    if ($fin !== '')   { $where[]="COALESCE(o.status_financeiro,'—')=:fin"; $binds['fin']=$fin; }
    $whereSql = $where ? (" AND ".implode(' AND ',$where)) : "";

    try {
      // Totais
      $stA = $pdo->prepare("
        SELECT COALESCE(SUM(o.valor),0) FROM orcamentos o
        INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        INNER JOIN key_results kr ON kr.id_kr=i.id_kr
        INNER JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE 1=1 $whereSql
      "); $stA->execute($binds); $aprovado = (float)$stA->fetchColumn();

      $stR = $pdo->prepare("
        SELECT COALESCE(SUM(od.valor),0) FROM orcamentos_detalhes od
        INNER JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
        INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        INNER JOIN key_results kr ON kr.id_kr=i.id_kr
        INNER JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE 1=1 $whereSql
      "); $stR->execute($binds); $realizado = (float)$stR->fetchColumn();
      $saldo = max(0, $aprovado - $realizado);

      $stCO = $pdo->prepare("
        SELECT COUNT(*) FROM orcamentos o
        INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        INNER JOIN key_results kr ON kr.id_kr=i.id_kr
        INNER JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE 1=1 $whereSql
      "); $stCO->execute($binds); $qOrc = (int)$stCO->fetchColumn();

      $stCD = $pdo->prepare("
        SELECT COUNT(*) FROM orcamentos_detalhes od
        INNER JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
        INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        INNER JOIN key_results kr ON kr.id_kr=i.id_kr
        INNER JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE 1=1 $whereSql
      "); $stCD->execute($binds); $qDesp = (int)$stCD->fetchColumn();

      // Séries mensais no período
      $bindsS = $binds + ['dini'=>$dateStart, 'dfim'=>$dateEnd];

      $stPlan = $pdo->prepare("
        SELECT DATE_FORMAT(o.data_desembolso,'%Y-%m') AS comp, SUM(o.valor) AS v
        FROM orcamentos o
        INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        INNER JOIN key_results kr ON kr.id_kr=i.id_kr
        INNER JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE o.data_desembolso BETWEEN :dini AND :dfim $whereSql
        GROUP BY comp
      "); $stPlan->execute($bindsS); $byP = []; foreach($stPlan as $r) $byP[$r['comp']] = (float)$r['v'];

      $stReal = $pdo->prepare("
        SELECT DATE_FORMAT(od.data_pagamento,'%Y-%m') AS comp, SUM(od.valor) AS v
        FROM orcamentos_detalhes od
        INNER JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
        INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        INNER JOIN key_results kr ON kr.id_kr=i.id_kr
        INNER JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE od.data_pagamento BETWEEN :dini AND :dfim $whereSql
        GROUP BY comp
      "); $stReal->execute($bindsS); $byR = []; foreach($stReal as $r) $byR[$r['comp']] = (float)$r['v'];

      $series=[]; $d=new DateTimeImmutable($dateStart); $end=new DateTimeImmutable($dateEnd);
      $planAc=0; $realAc=0;
      while ($d <= $end) {
        $ym=$d->format('Y-m'); $p=$byP[$ym]??0.0; $r=$byR[$ym]??0.0;
        $planAc+=$p; $realAc+=$r;
        $series[]=['competencia'=>$ym,'planejado'=>$p,'realizado'=>$r,'plan_acum'=>$planAc,'real_acum'=>$realAc];
        $d=$d->modify('+1 month')->modify('first day of this month');
      }

      // Breakdown pilar
      $stB1=$pdo->prepare("
        SELECT obj.pilar_bsc AS pilar, SUM(o.valor) AS aprovado, COALESCE(SUM(od.valor),0) AS realizado
        FROM orcamentos o
        INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        INNER JOIN key_results kr ON kr.id_kr=i.id_kr
        INNER JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        LEFT JOIN orcamentos_detalhes od ON od.id_orcamento=o.id_orcamento
        WHERE 1=1 $whereSql
        GROUP BY obj.pilar_bsc ORDER BY obj.pilar_bsc
      "); $stB1->execute($binds);
      $byPilar=[]; foreach($stB1 as $r){ $ap=(float)$r['aprovado']; $re=(float)$r['realizado']; $byPilar[]=['pilar'=>$g($r,'pilar','—'),'aprovado'=>$ap,'realizado'=>$re,'saldo'=>max(0,$ap-$re)]; }

      // Breakdown responsável
      $stB2=$pdo->prepare("
        SELECT TRIM(CONCAT(COALESCE(u.primeiro_nome,''),' ',COALESCE(u.ultimo_nome,''))) AS responsavel,
               SUM(o.valor) AS aprovado, COALESCE(SUM(od.valor),0) AS realizado
        FROM orcamentos o
        INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        INNER JOIN key_results kr ON kr.id_kr=i.id_kr
        INNER JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        LEFT JOIN usuarios u ON u.id_user=i.id_user_responsavel
        LEFT JOIN orcamentos_detalhes od ON od.id_orcamento=o.id_orcamento
        WHERE 1=1 $whereSql
        GROUP BY u.id_user ORDER BY responsavel
      "); $stB2->execute($binds);
      $byResp=[]; foreach($stB2 as $r){ $ap=(float)$r['aprovado']; $re=(float)$r['realizado']; $byResp[]=['responsavel'=>$g($r,'responsavel','—'),'aprovado'=>$ap,'realizado'=>$re,'saldo'=>max(0,$ap-$re)]; }

      // Tabela Orçamentos
      $stTab=$pdo->prepare("
        SELECT 
          o.id_orcamento, o.id_iniciativa, o.valor, o.data_desembolso,
          COALESCE(o.status_aprovacao,'pendente') AS status_aprovacao,
          COALESCE(o.status_financeiro,'—') AS status_financeiro,
          o.codigo_orcamento, o.justificativa_orcamento,
          i.descricao AS ini_desc, i.num_iniciativa,
          kr.id_kr, kr.key_result_num, kr.descricao AS kr_desc,
          obj.id_objetivo, obj.descricao AS obj_desc, obj.pilar_bsc,
          (SELECT GROUP_CONCAT(TRIM(CONCAT(COALESCE(u2.primeiro_nome,''),' ',COALESCE(u2.ultimo_nome,''))) SEPARATOR ', ')
             FROM orcamentos_envolvidos oe
             LEFT JOIN usuarios u2 ON u2.id_user=oe.id_user
            WHERE oe.id_orcamento=o.id_orcamento) AS envolvidos,
          (SELECT COUNT(*) FROM orcamentos_detalhes od2 
            WHERE od2.id_orcamento=o.id_orcamento AND COALESCE(od2.evidencia_pagamento,'') <> '') AS evidencias_qtd
        FROM orcamentos o
        INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        INNER JOIN key_results kr ON kr.id_kr=i.id_kr
        INNER JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE 1=1 $whereSql
          AND o.data_desembolso BETWEEN :dini AND :dfim
        ORDER BY o.data_desembolso ASC, o.id_orcamento ASC
        LIMIT 300
      "); $stTab->execute($binds + ['dini'=>$dateStart,'dfim'=>$dateEnd]); $orcList=$stTab->fetchAll();

      // Tabela Despesas
      $stDesp=$pdo->prepare("
        SELECT 
          od.id_despesa, od.valor, od.data_pagamento, od.descricao,
          COALESCE(od.evidencia_pagamento,'') <> '' AS tem_evidencia,
          o.id_orcamento, o.codigo_orcamento,
          i.id_iniciativa, i.num_iniciativa, i.descricao AS ini_desc,
          kr.id_kr, kr.key_result_num,
          obj.id_objetivo, obj.descricao AS obj_desc, obj.pilar_bsc
        FROM orcamentos_detalhes od
        INNER JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
        INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        INNER JOIN key_results kr ON kr.id_kr=i.id_kr
        INNER JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE 1=1 $whereSql
          AND od.data_pagamento BETWEEN :dini AND :dfim
        ORDER BY COALESCE(od.data_pagamento, od.dt_criacao) DESC, od.id_despesa DESC
        LIMIT 200
      "); $stDesp->execute($binds + ['dini'=>$dateStart,'dfim'=>$dateEnd]); $despList=$stDesp->fetchAll();

      echo json_encode([
        'success'=>true,
        'totais'=>['aprovado'=>$aprovado,'realizado'=>$realizado,'saldo'=>$saldo,'orcamentos'=>$qOrc,'despesas'=>$qDesp],
        'series'=>$series,
        'by_pilar'=>$byPilar,
        'by_responsavel'=>$byResp,
        'orcamentos'=>$orcList,
        'despesas'=>$despList,
        'periodo'=>['ini'=>$dini,'fim'=>$dfim]
      ]); exit;

    } catch(Throwable $e){ error_log('orcamento search: '.$e->getMessage()); echo json_encode(['success'=>false,'error'=>'Falha na consulta']); exit; }
  }

  echo json_encode(['success'=>false,'error'=>'Ação inválida']); exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Análise de Orçamentos – OKR System</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    /* ===== Estilo alinhado ao seu exemplo (fundo claro + cards escuros) ===== */
    body{ background:#fff !important; color:#111; }
    :root{ --chat-w:0px; }
    .content{ background:transparent; }
    main.orc{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; }

    :root{
      --bg-soft:#171b21; --card:#12161c; --muted:#a6adbb; --text:#eaeef6;
      --gold:#f6c343; --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20); --btn:#0e131a;
    }

    .crumbs{ color:#333; font-size:.9rem; display:flex; align-items:center; gap:6px; }
    .crumbs a{ color:#0c4a6e; text-decoration:none; }
    .crumbs .sep{ opacity:.5; margin:0 2px; }
    .crumbs i{ opacity:.8; }

    .head-card{
      background:linear-gradient(180deg, var(--card), #0d1117);
      border:1px solid var(--border); border-radius:16px; padding:16px;
      box-shadow:var(--shadow); color:var(--text); position:relative; overflow:hidden;
    }
    .head-title{ margin:0; font-size:1.35rem; font-weight:900; letter-spacing:.2px; display:flex; align-items:center; gap:8px; }
    .head-title i{ color:var(--gold); }
    .head-meta{ margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; }
    .pill{ display:inline-flex; align-items:center; gap:8px; background:#0e131a; border:1px solid var(--border); color: var(--muted); padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
    .pill i{ font-size:.9rem; opacity:.9; }
    .pill-gold{ border-color:var(--gold); color:var(--gold); background:rgba(246,195,67,.10); box-shadow:0 0 0 1px rgba(246,195,67,.10), 0 6px 18px rgba(246,195,67,.10); }
    .pill-gold i{ color:var(--gold); }

    .card{ background:linear-gradient(180deg, var(--card), #0e1319); border:1px solid var(--border); border-radius:16px; padding:16px; box-shadow:var(--shadow); color:var(--text); }
    .card h2{ font-size:1.05rem; margin:0 0 12px; letter-spacing:.2px; color:#e5e7eb; display:flex; align-items:center; gap:8px; }
    .card .muted{ color:#9aa4b2; }

    .grid-filters{ display:grid; grid-template-columns:repeat(6,1fr); gap:12px; }
    @media (max-width:1200px){ .grid-filters{ grid-template-columns:repeat(3,1fr);} }
    @media (max-width:700px){ .grid-filters{ grid-template-columns:1fr;} }

    label{ display:block; margin-bottom:6px; color:#cbd5e1; font-size:.9rem; }
    select,input[type="month"]{
      width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none;
    }

    .actions{ display:flex; gap:8px; justify-content:flex-end; margin-top:6px; }
    .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:10px 14px; border-radius:12px; font-weight:700; cursor:pointer; }
    .btn:hover{ border-color:#2a3342; transform:translateY(-1px); transition:.15s; }
    .btn-primary{ background:#1f2937; }
    .btn-warning{ background:#8a6d00; color:#fff7cc; border-color:#8a6d00; }
    .btn-outline{ background:transparent; }

    .kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
    @media (max-width:1000px){ .kpis{ grid-template-columns:repeat(2,1fr); } }
    @media (max-width:560px){ .kpis{ grid-template-columns:1fr; } }
    .kpi-card{ background:linear-gradient(180deg, var(--card), #0b1018); border:1px solid var(--border); border-radius:16px; padding:14px; box-shadow:var(--shadow); }
    .kpi-head{ display:flex; align-items:center; justify-content:space-between; color:#a6adbb; margin-bottom:6px; }
    .kpi-val{ font-size:1.6rem; font-weight:900; }

    .wrap{ display:grid; grid-template-columns:2fr 1fr; gap:12px; }
    @media (max-width:1200px){ .wrap{ grid-template-columns:1fr; } }

    .two{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width:900px){ .two{ grid-template-columns:1fr; } }

    .table-wrap{ overflow:auto; border-radius:12px; }
    table{ width:100%; border-collapse:collapse; }
    th,td{ border-bottom:1px solid #1f2635; padding:10px 8px; text-align:left; color:#cbd5e1; font-size:.93rem; }
    th{ background:#0f1524; position:sticky; top:0; z-index:1; }

    .legend{ display:flex; gap:10px; align-items:center; font-size:.85rem; color:#a6adbb; }
    .badge{ border:1px solid #705e14; background:#3b320a; color:#ffec99; padding:3px 8px; border-radius:999px; font-size:.72rem; }
    .right{ text-align:right; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="orc">
      <!-- Breadcrumb -->
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <span><i class="fa-solid fa-coins"></i> Análise de Orçamentos</span>
      </div>

      <!-- Cabeçalho -->
      <section class="head-card">
        <h1 class="head-title"><i class="fa-solid fa-chart-pie"></i> Análise de Orçamentos</h1>
        <div class="head-meta">
          <span class="pill"><i class="fa-solid fa-layer-group"></i>Visão BSC: Pilar → Objetivo → KR</span>
          <span id="periodBadge" class="pill pill-gold" style="display:none;">
            <i class="fa-regular fa-calendar"></i>
            <strong id="periodText"></strong>
          </span>
        </div>
      </section>

      <!-- Filtros -->
      <section class="card">
        <h2><i class="fa-solid fa-filter"></i> Filtros</h2>
        <div class="grid-filters">
          <div>
            <label><i class="fa-solid fa-layer-group"></i> Pilar BSC</label>
            <select id="f_pilar"></select>
          </div>
          <div>
            <label><i class="fa-solid fa-bullseye"></i> Objetivo</label>
            <select id="f_obj"></select>
          </div>
          <div>
            <label><i class="fa-solid fa-flag"></i> KR</label>
            <select id="f_kr"></select>
          </div>
          <div>
            <label><i class="fa-regular fa-user"></i> Responsável</label>
            <select id="f_resp"></select>
          </div>
          <div>
            <label><i class="fa-regular fa-circle-check"></i> Status de aprovação</label>
            <select id="f_aprov"></select>
          </div>
          <div>
            <label><i class="fa-solid fa-wallet"></i> Status financeiro</label>
            <select id="f_fin"></select>
          </div>
          <div>
            <label><i class="fa-regular fa-calendar"></i> Período (de)</label>
            <input type="month" id="f_ini">
          </div>
          <div>
            <label><i class="fa-regular fa-calendar"></i> Período (até)</label>
            <input type="month" id="f_fim">
          </div>
        </div>
        <div class="actions">
          <button class="btn btn-outline" id="btnClear"><i class="fa-solid fa-broom"></i> Limpar</button>
          <button class="btn btn-primary" id="btnApply"><i class="fa-solid fa-magnifying-glass"></i> Aplicar filtros</button>
        </div>
      </section>

      <!-- KPIs -->
      <section class="kpis">
        <div class="kpi-card">
          <div class="kpi-head"><span>Orçado (Aprovado)</span><i class="fa-solid fa-sack-dollar" style="color:#fde68a"></i></div>
          <div class="kpi-val" id="k_aprov">—</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-head"><span>Realizado</span><i class="fa-solid fa-wallet" style="color:#93c5fd"></i></div>
          <div class="kpi-val" id="k_real">—</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-head"><span>Saldo</span><i class="fa-solid fa-scale-balanced" style="color:#86efac"></i></div>
          <div class="kpi-val" id="k_saldo">—</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-head"><span>Itens</span><i class="fa-solid fa-clipboard-list" style="color:#c7d2fe"></i></div>
          <div class="kpi-val"><span id="k_itens">—</span> <span class="muted" style="font-size:.95rem"> · Despesas: <span id="k_desp">—</span></span></div>
        </div>
      </section>

      <!-- Gráfico + side cards -->
      <section class="wrap">
        <div class="card">
          <h2><i class="fa-solid fa-chart-column"></i> Evolução mensal</h2>
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
            <div class="legend">
              <span><i class="fa-solid fa-square" style="color:#f6c343"></i> Planejado</span>
              <span><i class="fa-solid fa-square" style="color:#60a5fa"></i> Realizado</span>
            </div>
            <div id="segChart">
              <button class="btn btn-outline" data-mode="mensal">Mensal</button>
              <button class="btn btn-outline" data-mode="acum">Acumulado</button>
            </div>
          </div>
          <canvas id="chartMain" height="260"></canvas>
        </div>

        <div class="two">
          <div class="card">
            <h2><i class="fa-solid fa-layer-group"></i> Por Pilar BSC</h2>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Pilar</th><th class="right">Aprovado</th><th class="right">Realizado</th><th class="right">Saldo</th></tr></thead>
                <tbody id="tbPilar"><tr><td colspan="4" class="muted">—</td></tr></tbody>
              </table>
            </div>
          </div>
          <div class="card">
            <h2><i class="fa-regular fa-user"></i> Por Responsável</h2>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Responsável</th><th class="right">Aprovado</th><th class="right">Realizado</th><th class="right">Saldo</th></tr></thead>
                <tbody id="tbResp"><tr><td colspan="4" class="muted">—</td></tr></tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- Tabelas detalhadas -->
      <section class="card">
        <h2><i class="fa-solid fa-coins"></i> Orçamentos</h2>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Competência</th>
                <th class="right">Valor</th>
                <th>Aprovação</th>
                <th>Financeiro</th>
                <th>Pilar</th>
                <th>Objetivo</th>
                <th>KR</th>
                <th>Iniciativa</th>
                <th>Envolvidos</th>
                <th>Evidências</th>
              </tr>
            </thead>
            <tbody id="tbOrc"><tr><td colspan="11" class="muted">—</td></tr></tbody>
          </table>
        </div>
      </section>

      <section class="card">
        <h2><i class="fa-solid fa-file-invoice-dollar"></i> Despesas</h2>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Data pgto</th>
                <th class="right">Valor</th>
                <th>Descrição</th>
                <th>Orçamento</th>
                <th>Pilar</th>
                <th>Objetivo</th>
                <th>KR</th>
                <th>Iniciativa</th>
                <th>Evidência</th>
              </tr>
            </thead>
            <tbody id="tbDesp"><tr><td colspan="9" class="muted">—</td></tr></tbody>
          </table>
        </div>
      </section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script>
    const SCRIPT = "<?= $_SERVER['SCRIPT_NAME'] ?>";
    const $ = (s,p=document)=>p.querySelector(s);
    const $$ = (s,p=document)=>Array.from(p.querySelectorAll(s));
    function fmtBRL(x){ return (Number(x)||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'}); }
    function escapeHtml(s){ return (s??'').toString().replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
    function toDMY(ym){ const [y,m] = (ym||'').split('-'); return `${m}/${y}`; }

    async function loadFilters(){
      const res = await fetch(`${SCRIPT}?ajax=filters_init`);
      const data = await res.json();

      $('#f_pilar').innerHTML = '<option value="">(Todos)</option>' + (data.pillars||[]).map(p=>`<option>${escapeHtml(p.label||'—')}</option>`).join('');
      $('#f_resp').innerHTML  = '<option value="">(Todos)</option>' + (data.responsaveis||[]).map(r=>`<option value="${r.id_user}">${escapeHtml(r.nome||r.id_user)}</option>`).join('');
      $('#f_aprov').innerHTML = '<option value="">(Todos)</option>' + (data.status_aprovacao||[]).map(s=>`<option>${escapeHtml(s||'—')}</option>`).join('');
      $('#f_fin').innerHTML   = '<option value="">(Todos)</option>' + (data.status_financeiro||[]).map(s=>`<option>${escapeHtml(s||'—')}</option>`).join('');

      // período default
      const now = new Date();
      const y = now.getFullYear(); const m = String(now.getMonth()+1).padStart(2,'0');
      $('#f_ini').value = `${y}-01`;
      $('#f_fim').value = `${y}-${m}`;

      await loadObjetivos();
    }

    async function loadObjetivos(){
      const pilar = $('#f_pilar').value || '';
      const res = await fetch(`${SCRIPT}?ajax=list_objetivos&pilar=${encodeURIComponent(pilar)}`);
      const data = await res.json();
      $('#f_obj').innerHTML = '<option value="">(Todos)</option>' + (data.items||[]).map(o=>`<option value="${o.id_objetivo}">${escapeHtml(o.descricao||o.id_objetivo)}</option>`).join('');
      $('#f_kr').innerHTML = '<option value="">(Todos)</option>';
    }

    async function loadKrs(){
      const id_obj = $('#f_obj').value || '';
      if (!id_obj){ $('#f_kr').innerHTML = '<option value="">(Todos)</option>'; return; }
      const res = await fetch(`${SCRIPT}?ajax=list_krs&id_objetivo=${encodeURIComponent(id_obj)}`);
      const data = await res.json();
      $('#f_kr').innerHTML = '<option value="">(Todos)</option>' + (data.items||[]).map(k=>`<option value="${k.id_kr}">${escapeHtml(k.label||k.id_kr)}</option>`).join('');
    }
    $('#f_pilar')?.addEventListener('change', loadObjetivos);
    $('#f_obj')?.addEventListener('change', loadKrs);

    let chartMain;
    async function runSearch(){
      const q = new URLSearchParams({
        pilar: $('#f_pilar').value||'',
        id_objetivo: $('#f_obj').value||'',
        id_kr: $('#f_kr').value||'',
        id_responsavel: $('#f_resp').value||'',
        status_aprovacao: $('#f_aprov').value||'',
        status_financeiro: $('#f_fin').value||'',
        ini: $('#f_ini').value || '',
        fim: $('#f_fim').value || ''
      });
      const res = await fetch(`${SCRIPT}?ajax=search&`+q.toString());
      const data = await res.json();
      if (!data.success){ alert(data.error || 'Falha na consulta'); return; }

      // KPIs
      $('#k_aprov').textContent = fmtBRL(data.totais.aprovado||0);
      $('#k_real').textContent  = fmtBRL(data.totais.realizado||0);
      $('#k_saldo').textContent = fmtBRL(data.totais.saldo||0);
      $('#k_itens').textContent = (data.totais.orcamentos||0).toLocaleString('pt-BR');
      $('#k_desp').textContent  = (data.totais.despesas||0).toLocaleString('pt-BR');

      // Badge período no header
      const pb = document.getElementById('periodBadge'), pt = document.getElementById('periodText');
      if (pt){ pt.textContent = `Período: ${data.periodo.ini} → ${data.periodo.fim}`; pb.style.display='inline-flex'; }

      // Chart
      const labels = (data.series||[]).map(s=>toDMY(s.competencia));
      const mensalP = (data.series||[]).map(s=>s.planejado||0);
      const mensalR = (data.series||[]).map(s=>s.realizado||0);
      const acumP   = (data.series||[]).map(s=>s.plan_acum||0);
      const acumR   = (data.series||[]).map(s=>s.real_acum||0);

      const ctx = document.getElementById('chartMain');
      if (chartMain) chartMain.destroy();
      chartMain = new Chart(ctx, {
        type:'bar',
        data:{ labels, datasets:[
          { label:'Planejado', data: mensalP, backgroundColor:'rgba(246,195,67,.35)', borderColor:'#f6c343', borderWidth:1 },
          { label:'Realizado', data: mensalR, backgroundColor:'rgba(96,165,250,.35)', borderColor:'#60a5fa', borderWidth:1 }
        ]},
        options:{
          responsive:true, maintainAspectRatio:false,
          plugins:{ legend:{ labels:{ color:'#cbd5e1' } } },
          scales:{
            x:{ ticks:{ color:'#a6adbb' }, grid:{ color:'rgba(255,255,255,.06)' } },
            y:{ ticks:{ color:'#a6adbb' }, grid:{ color:'rgba(255,255,255,.06)' }, beginAtZero:true }
          }
        }
      });

      const seg = document.getElementById('segChart');
      if (!seg.dataset.bound){
        seg.dataset.bound='1';
        seg.addEventListener('click', e=>{
          const btn = e.target.closest('button[data-mode]'); if (!btn) return;
          seg.querySelectorAll('button').forEach(b=>b.classList.remove('btn-warning'));
          btn.classList.add('btn-warning');
          const mode = btn.getAttribute('data-mode');
          const ds0 = chartMain.data.datasets[0], ds1 = chartMain.data.datasets[1];
          if (mode==='acum'){ chartMain.config.type='line'; ds0.data = acumP; ds1.data = acumR; }
          else { chartMain.config.type='bar'; ds0.data = mensalP; ds1.data = mensalR; }
          chartMain.update();
        });
        seg.querySelector('button[data-mode="mensal"]').classList.add('btn-warning');
      }

      // Tabelas breakdown
      const tbP = $('#tbPilar'); tbP.innerHTML = '';
      (data.by_pilar||[]).forEach(r=>{
        tbP.insertAdjacentHTML('beforeend', `
          <tr>
            <td>${escapeHtml(r.pilar||'—')}</td>
            <td class="right">${fmtBRL(r.aprovado||0)}</td>
            <td class="right">${fmtBRL(r.realizado||0)}</td>
            <td class="right">${fmtBRL(r.saldo||0)}</td>
          </tr>
        `);
      });
      if (!tbP.children.length) tbP.innerHTML = `<tr><td colspan="4" class="muted">Sem dados</td></tr>`;

      const tbR = $('#tbResp'); tbR.innerHTML = '';
      (data.by_responsavel||[]).forEach(r=>{
        tbR.insertAdjacentHTML('beforeend', `
          <tr>
            <td>${escapeHtml(r.responsavel||'—')}</td>
            <td class="right">${fmtBRL(r.aprovado||0)}</td>
            <td class="right">${fmtBRL(r.realizado||0)}</td>
            <td class="right">${fmtBRL(r.saldo||0)}</td>
          </tr>
        `);
      });
      if (!tbR.children.length) tbR.innerHTML = `<tr><td colspan="4" class="muted">Sem dados</td></tr>`;

      // Tabela Orçamentos
      const tbO = $('#tbOrc'); tbO.innerHTML = '';
      (data.orcamentos||[]).forEach(o=>{
        const comp = (o.data_desembolso||'').slice(0,7);
        tbO.insertAdjacentHTML('beforeend', `
          <tr>
            <td>${o.id_orcamento}</td>
            <td>${escapeHtml(toDMY(comp))}</td>
            <td class="right">${fmtBRL(o.valor||0)}</td>
            <td>${escapeHtml(o.status_aprovacao||'—')}</td>
            <td>${escapeHtml(o.status_financeiro||'—')}</td>
            <td>${escapeHtml(o.pilar_bsc||'—')}</td>
            <td>${escapeHtml(o.obj_desc||'')}</td>
            <td>${escapeHtml(o.key_result_num?('KR '+o.key_result_num):o.id_kr)}</td>
            <td title="${escapeHtml(o.ini_desc||'')}">${escapeHtml((o.num_iniciativa?('#'+o.num_iniciativa+' – '):'') + (o.ini_desc||'')).slice(0,80)}${(o.ini_desc||'').length>80?'…':''}</td>
            <td>${escapeHtml(o.envolvidos||'—')}</td>
            <td>${(o.evidencias_qtd||0) > 0 ? '<span class="badge">ok</span>' : '<span class="muted">—</span>'}</td>
          </tr>
        `);
      });
      if (!tbO.children.length) tbO.innerHTML = `<tr><td colspan="11" class="muted">Sem orçamentos no período/filtros.</td></tr>`;

      // Tabela Despesas
      const tbD = $('#tbDesp'); tbD.innerHTML = '';
      (data.despesas||[]).forEach(d=>{
        tbD.insertAdjacentHTML('beforeend', `
          <tr>
            <td>${escapeHtml((d.data_pagamento||'').split(' ')[0]||'—')}</td>
            <td class="right">${fmtBRL(d.valor||0)}</td>
            <td>${escapeHtml(d.descricao||'—')}</td>
            <td>${escapeHtml(d.codigo_orcamento||('#'+d.id_orcamento))}</td>
            <td>${escapeHtml(d.pilar_bsc||'—')}</td>
            <td>${escapeHtml(d.obj_desc||'')}</td>
            <td>${escapeHtml(d.key_result_num?('KR '+d.key_result_num):d.id_kr)}</td>
            <td title="${escapeHtml(d.ini_desc||'')}">${escapeHtml((d.num_iniciativa?('#'+d.num_iniciativa+' – '):'') + (d.ini_desc||''))}</td>
            <td>${d.tem_evidencia ? '<span class="badge">comprovado</span>' : '<span class="muted">—</span>'}</td>
          </tr>
        `);
      });
      if (!tbD.children.length) tbD.innerHTML = `<tr><td colspan="9" class="muted">Sem despesas no período/filtros.</td></tr>`;
    }

    // Ações
    $('#btnApply')?.addEventListener('click', runSearch);
    $('#btnClear')?.addEventListener('click', async ()=>{
      $('#f_pilar').value=''; await loadObjetivos();
      $('#f_resp').value=''; $('#f_aprov').value=''; $('#f_fin').value='';
      const now = new Date(), y = now.getFullYear(), m = String(now.getMonth()+1).padStart(2,'0');
      $('#f_ini').value = `${y}-01`; $('#f_fim').value = `${y}-${m}`;
      runSearch();
    });

    // Ajuste com chat lateral (compatível com seu exemplo)
    (function chatAdjust(){
      const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
      const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
      function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
      function isOpen(el){ const st=getComputedStyle(el); const vis=st.display!=='none'&&st.visibility!=='hidden'; const w=el.offsetWidth; return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show'); }
      function updateChatWidth(){ const el=findChatEl(); const w=(el && isOpen(el))?el.offsetWidth:0; document.documentElement.style.setProperty('--chat-w',(w||0)+'px'); }
      const chat=findChatEl(); if(chat){ const mo=new MutationObserver(()=>updateChatWidth()); mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']}); window.addEventListener('resize',updateChatWidth); TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,200)))); updateChatWidth(); }
    })();

    // Init
    (async function init(){
      await loadFilters();
      await runSearch();
    })();
  </script>
</body>
</html>
