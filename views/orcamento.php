<?php
// views/orcamento.php — Análise de Orçamentos (escopo por company + botão filtrar com texto preto)

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

/* ===================== Company do usuário ===================== */
$userId = (int)$_SESSION['user_id'];
try{
  $stC = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user = :u LIMIT 1");
  $stC->execute([':u'=>$userId]);
  $companyId = (int)($stC->fetchColumn() ?: 0);
  if ($companyId <= 0) { http_response_code(403); die('Usuário sem company vinculada.'); }
} catch(Throwable $e){
  http_response_code(500); die('Falha ao obter company do usuário.');
}

/* ===================== Helpers ===================== */
$g = static function(array $row, string $k, $d=null){ return array_key_exists($k,$row) ? $row[$k] : $d; };

/* ===================== AJAX ===================== */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

  $action = $_GET['ajax'];

  try {
    if ($action === 'filters_init') {
      // Pilares existentes (pelos orçamentos) — escopo por company
      $pillars = [];
      try {
        $st = $pdo->prepare("
          SELECT DISTINCT COALESCE(obj.pilar_bsc,'—') AS label
          FROM orcamentos o
          LEFT JOIN iniciativas i ON i.id_iniciativa = o.id_iniciativa
          LEFT JOIN key_results kr ON kr.id_kr = i.id_kr
          LEFT JOIN objetivos obj ON obj.id_objetivo = kr.id_objetivo
          WHERE obj.id_company = :c
          ORDER BY label
        ");
        $st->execute([':c'=>$companyId]);
        $pillars = $st->fetchAll();
      } catch(Throwable $e){ /* fallback abaixo */ }

      if (!$pillars) {
        try {
          $st = $pdo->prepare("SELECT DISTINCT COALESCE(pilar_bsc,'—') AS label FROM objetivos WHERE id_company = :c ORDER BY label");
          $st->execute([':c'=>$companyId]);
          $pillars = $st->fetchAll();
        } catch(Throwable $e){ $pillars = []; }
      }

      // Responsáveis (criador do orçamento) com nome — escopo por company
      $responsaveis = [];
      try {
        $st = $pdo->prepare("
          SELECT DISTINCT u.id_user,
            TRIM(CONCAT(COALESCE(u.primeiro_nome,''),' ',COALESCE(u.ultimo_nome,''))) AS nome
          FROM orcamentos o
          LEFT JOIN iniciativas i ON i.id_iniciativa = o.id_iniciativa
          LEFT JOIN key_results kr ON kr.id_kr = i.id_kr
          LEFT JOIN objetivos obj ON obj.id_objetivo = kr.id_objetivo
          LEFT JOIN usuarios u ON u.id_user = o.id_user_criador
          WHERE obj.id_company = :c
            AND o.id_user_criador IS NOT NULL AND o.id_user_criador <> ''
          ORDER BY nome
        ");
        $st->execute([':c'=>$companyId]);
        $responsaveis = $st->fetchAll() ?: [];
      } catch(Throwable $e){ $responsaveis = []; }
      foreach ($responsaveis as &$r) { if (empty($r['nome'])) $r['nome'] = (string)$r['id_user']; }

      // Status aprovação/financeiro apenas da company
      $aprov = [];
      $fin = [];
      try {
        $st = $pdo->prepare("
          SELECT DISTINCT COALESCE(o.status_aprovacao,'pendente') AS s
          FROM orcamentos o
          LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
          LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
          LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
          WHERE obj.id_company = :c
          ORDER BY s
        "); $st->execute([':c'=>$companyId]); $aprov = $st->fetchAll(PDO::FETCH_COLUMN);
      } catch(Throwable $e){}
      try {
        $st = $pdo->prepare("
          SELECT DISTINCT COALESCE(o.status_financeiro,'—') AS s
          FROM orcamentos o
          LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
          LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
          LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
          WHERE obj.id_company = :c
          ORDER BY s
        "); $st->execute([':c'=>$companyId]); $fin = $st->fetchAll(PDO::FETCH_COLUMN);
      } catch(Throwable $e){}

      echo json_encode(['success'=>true,'pillars'=>$pillars,'responsaveis'=>$responsaveis,'status_aprovacao'=>$aprov,'status_financeiro'=>$fin]); exit;
    }

    if ($action === 'list_objetivos') {
      $pilar = trim($_GET['pilar'] ?? '');
      try {
        if ($pilar !== '') {
          $st=$pdo->prepare("SELECT id_objetivo, descricao FROM objetivos WHERE id_company=:c AND pilar_bsc=:p ORDER BY descricao");
          $st->execute([':c'=>$companyId, ':p'=>$pilar]);
        } else {
          $st=$pdo->prepare("SELECT id_objetivo, descricao FROM objetivos WHERE id_company=:c ORDER BY descricao");
          $st->execute([':c'=>$companyId]);
        }
        echo json_encode(['success'=>true,'items'=>$st->fetchAll()]); exit;
      } catch(Throwable $e){
        echo json_encode(['success'=>false,'error'=>'Falha ao listar objetivos']); exit;
      }
    }

    if ($action === 'list_krs') {
      $id_obj = trim($_GET['id_objetivo'] ?? '');
      if ($id_obj===''){ echo json_encode(['success'=>true,'items'=>[]]); exit; }
      try{
        $st = $pdo->prepare("
          SELECT kr.id_kr, COALESCE(kr.descricao,'') AS label
          FROM key_results kr
          INNER JOIN objetivos obj ON obj.id_objetivo = kr.id_objetivo
          WHERE kr.id_objetivo=:o AND obj.id_company=:c
          ORDER BY kr.key_result_num ASC
        ");
        $st->execute([':o'=>$id_obj, ':c'=>$companyId]);
        echo json_encode(['success'=>true,'items'=>$st->fetchAll()]); exit;
      } catch(Throwable $e){
        echo json_encode(['success'=>false,'error'=>'Falha ao listar KRs']); exit;
      }
    }

    if ($action === 'search') {
      $pilar = trim($_GET['pilar'] ?? '');
      $id_obj = trim($_GET['id_objetivo'] ?? '');
      $id_kr  = trim($_GET['id_kr'] ?? '');
      $resp   = trim($_GET['id_responsavel'] ?? ''); // criador do orçamento
      $aprovS = trim($_GET['status_aprovacao'] ?? '');
      $finS   = trim($_GET['status_financeiro'] ?? '');
      $dini   = trim($_GET['ini'] ?? '');
      $dfim   = trim($_GET['fim'] ?? '');

      $nowY  = (int)date('Y');
      $nextY = $nowY + 1;
      if (!preg_match('/^\d{4}\-\d{2}$/', $dini)) $dini = sprintf('%04d-01', $nowY);
      if (!preg_match('/^\d{4}\-\d{2}$/', $dfim)) $dfim = sprintf('%04d-12', $nextY);      $dateStart = $dini.'-01';
      $y = (int)substr($dfim,0,4); $m=(int)substr($dfim,5,2); $lastDay = (int)date('t', strtotime("$y-$m-01"));
      $dateEnd = sprintf('%04d-%02d-%02d', $y, $m, $lastDay);

      // Escopo base: company do usuário
      $where   = ["obj.id_company = :cid"];
      $binds   = ['cid'=>$companyId, 'dini'=>$dateStart,'dfim'=>$dateEnd];

      if ($pilar !== '') { $where[]="obj.pilar_bsc=:pilar"; $binds['pilar']=$pilar; }
      if ($id_obj !== ''){ $where[]="obj.id_objetivo=:id_obj"; $binds['id_obj']=$id_obj; }
      if ($id_kr  !== ''){ $where[]="kr.id_kr=:id_kr";       $binds['id_kr']=$id_kr; }
      if ($resp   !== ''){ $where[]="o.id_user_criador=:resp"; $binds['resp']=$resp; }
      if ($aprovS !== ''){ $where[]="COALESCE(o.status_aprovacao,'pendente')=:aprov"; $binds['aprov']=$aprovS; }
      if ($finS   !== ''){ $where[]="COALESCE(o.status_financeiro,'—')=:fin"; $binds['fin']=$finS; }
      $whereSql = $where ? (" AND ".implode(' AND ',$where)) : "";

      // KPIs
      $stA = $pdo->prepare("
        SELECT COALESCE(SUM(o.valor),0) AS aprovado
        FROM orcamentos o
        LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
        LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE o.data_desembolso BETWEEN :dini AND :dfim
        $whereSql
      "); $stA->execute($binds); $aprovado = (float)$stA->fetchColumn();

      $stR = $pdo->prepare("
        SELECT COALESCE(SUM(od.valor),0) AS realizado
        FROM orcamentos_detalhes od
        LEFT JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
        LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
        LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE od.data_pagamento BETWEEN :dini AND :dfim
        $whereSql
      "); $stR->execute($binds); $realizado = (float)$stR->fetchColumn();

      $saldo = max(0, $aprovado - $realizado);

      $stCO = $pdo->prepare("
        SELECT COUNT(*)
        FROM orcamentos o
        LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
        LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE o.data_desembolso BETWEEN :dini AND :dfim
        $whereSql
      "); $stCO->execute($binds); $qOrc = (int)$stCO->fetchColumn();

      $stCD = $pdo->prepare("
        SELECT COUNT(*)
        FROM orcamentos_detalhes od
        LEFT JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
        LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
        LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE od.data_pagamento BETWEEN :dini AND :dfim
        $whereSql
      "); $stCD->execute($binds); $qDesp = (int)$stCD->fetchColumn();

      // Séries
      $stPlan = $pdo->prepare("
        SELECT DATE_FORMAT(o.data_desembolso,'%Y-%m') AS comp, SUM(o.valor) AS v
        FROM orcamentos o
        LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
        LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE o.data_desembolso BETWEEN :dini AND :dfim
        $whereSql
        GROUP BY comp
      "); $stPlan->execute($binds);
      $byP = []; foreach($stPlan as $r) $byP[$r['comp']] = (float)$r['v'];

      $stReal = $pdo->prepare("
        SELECT DATE_FORMAT(od.data_pagamento,'%Y-%m') AS comp, SUM(od.valor) AS v
        FROM orcamentos_detalhes od
        LEFT JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
        LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
        LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE od.data_pagamento BETWEEN :dini AND :dfim
        $whereSql
        GROUP BY comp
      "); $stReal->execute($binds);
      $byR = []; foreach($stReal as $r) $byR[$r['comp']] = (float)$r['v'];

      $series=[]; $d=new DateTimeImmutable($dateStart); $end=new DateTimeImmutable($dateEnd);
      $planAc=0; $realAc=0;
      while ($d <= $end) {
        $ym=$d->format('Y-m'); $p=$byP[$ym]??0.0; $r=$byR[$ym]??0.0;
        $planAc+=$p; $realAc+=$r;
        $series[]=['competencia'=>$ym,'planejado'=>$p,'realizado'=>$r,'plan_acum'=>$planAc,'real_acum'=>$realAc];
        $d=$d->modify('+1 month')->modify('first day of this month');
      }

      // Breakdown por Pilar
      $stB1=$pdo->prepare("
        SELECT COALESCE(obj.pilar_bsc,'—') AS pilar, SUM(o.valor) AS aprovado, COALESCE(SUM(od.valor),0) AS realizado
        FROM orcamentos o
        LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
        LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        LEFT JOIN orcamentos_detalhes od ON od.id_orcamento=o.id_orcamento
             AND od.data_pagamento BETWEEN :dini AND :dfim
        WHERE o.data_desembolso BETWEEN :dini AND :dfim
        $whereSql
        GROUP BY pilar ORDER BY pilar
      "); $stB1->execute($binds);
      $byPilar=[]; foreach($stB1 as $r){ $ap=(float)$r['aprovado']; $re=(float)$r['realizado']; $byPilar[]=['pilar'=>$g($r,'pilar','—'),'aprovado'=>$ap,'realizado'=>$re,'saldo'=>max(0,$ap-$re)]; }

      // Breakdown por Responsável (com nome)
      $stB2=$pdo->prepare("
        SELECT
          COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.primeiro_nome,''),' ',COALESCE(u.ultimo_nome,''))),''), o.id_user_criador, '—') AS responsavel,
          SUM(o.valor) AS aprovado, COALESCE(SUM(od.valor),0) AS realizado
        FROM orcamentos o
        LEFT JOIN usuarios u ON u.id_user = o.id_user_criador
        LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
        LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        LEFT JOIN orcamentos_detalhes od ON od.id_orcamento=o.id_orcamento
             AND od.data_pagamento BETWEEN :dini AND :dfim
        WHERE o.data_desembolso BETWEEN :dini AND :dfim
          AND obj.id_company = :cid
        $whereSql
        GROUP BY o.id_user_criador, responsavel
        ORDER BY responsavel
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
          kr.id_kr, COALESCE(kr.descricao,'') AS kr_desc,
          obj.id_objetivo, COALESCE(obj.descricao,'') AS obj_desc, obj.pilar_bsc,
          (SELECT GROUP_CONCAT(oe.id_user SEPARATOR ', ')
             FROM orcamentos_envolvidos oe
            WHERE oe.id_orcamento=o.id_orcamento) AS envolvidos,
          (SELECT COUNT(*) FROM orcamentos_detalhes od2 
            WHERE od2.id_orcamento=o.id_orcamento AND COALESCE(od2.evidencia_pagamento,'') <> '') AS evidencias_qtd
        FROM orcamentos o
        LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
        LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE o.data_desembolso BETWEEN :dini AND :dfim
          AND obj.id_company = :cid
          $whereSql
        ORDER BY o.data_desembolso ASC, o.id_orcamento ASC
        LIMIT 300
      "); $stTab->execute($binds); $orcList=$stTab->fetchAll();

      // Tabela Despesas
      $stDesp=$pdo->prepare("
        SELECT 
          od.id_despesa, od.valor, od.data_pagamento, LEFT(COALESCE(od.descricao,''),200) AS descricao,
          COALESCE(od.evidencia_pagamento,'') <> '' AS tem_evidencia,
          o.id_orcamento, o.codigo_orcamento,
          i.id_iniciativa, i.num_iniciativa, i.descricao AS ini_desc,
          kr.id_kr, COALESCE(kr.descricao,'') AS kr_desc,
          obj.id_objetivo, COALESCE(obj.descricao,'') AS obj_desc, obj.pilar_bsc
        FROM orcamentos_detalhes od
        LEFT JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
        LEFT JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
        LEFT JOIN key_results kr ON kr.id_kr=i.id_kr
        LEFT JOIN objetivos obj ON obj.id_objetivo=kr.id_objetivo
        WHERE od.data_pagamento BETWEEN :dini AND :dfim
          AND obj.id_company = :cid
          $whereSql
        ORDER BY COALESCE(od.data_pagamento, od.dt_criacao) DESC, od.id_despesa DESC
        LIMIT 200
      "); $stDesp->execute($binds); $despList=$stDesp->fetchAll();

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
    }

    echo json_encode(['success'=>false,'error'=>'Ação inválida']); exit;

  } catch(Throwable $e){
    error_log('orcamento ajax error: '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>'Erro inesperado']); exit;
  }
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
    /* ===== Correções de layout e estilo ===== */
    :root{
      --chat-w:0px;
      --bg-soft:#171b21; --card: var(--bg1, #222222); --muted:#a6adbb; --text:#eaeef6;
      --gold:var(--bg2, #F1C40F); --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20);
    }
    html, body { overflow-x: hidden; }
    body{ background:#fff !important; color:#111; }
    .content{ background:transparent; max-width:100vw; overflow-x:hidden; }

    main.orc{
      padding:24px; display:grid; grid-template-columns:1fr; gap:16px;
      width: calc(100% - var(--chat-w, 0px));
      box-sizing: border-box;
      transition: width .25s ease;
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
    .pill-gold{ border-color:var(--gold); color:var(--gold); background:rgba(246,195,67,.10); box-shadow:0 0 0 1px rgba(246,195,67,.10), 0 6px 18px rgba(246,195,67,.10); }

    .card{ background:linear-gradient(180deg, var(--card), #0e1319); border:1px solid var(--border); border-radius:16px; padding:16px; box-shadow:var(--shadow); color:var(--text); }
    .card h2{ font-size:1.05rem; margin:0 0 12px; letter-spacing:.2px; color:#e5e7eb; display:flex; align-items:center; gap:8px; }
    .card .muted{ color:#9aa4b2; }

    .grid-filters{ display:grid; grid-template-columns:repeat(6,1fr); gap:12px; }
    @media (max-width:1200px){ .grid-filters{ grid-template-columns:repeat(3,1fr);} }
    @media (max-width:700px){ .grid-filters{ grid-template-columns:1fr;} }
    label{ display:block; margin-bottom:6px; color:#cbd5e1; font-size:.9rem; }
    select,input[type="month"]{ width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none; }

    .actions{ display:flex; gap:8px; justify-content:flex-end; margin-top:6px; }
    .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:10px 14px; border-radius:12px; font-weight:700; cursor:pointer; }
    .btn:hover{ border-color:#2a3342; transform:translateY(-1px); transition:.15s; }
    .btn-primary{ background:#1f2937; }
    .btn-warning{ background:#8a6d00; color:#fff7cc; border-color:#8a6d00; }
    .btn-outline{ background:transparent; }

    /* >>> Texto do botão FILTRAR em preto (com fundo claro p/ contraste) */
    #btnApply{
      background:#f6c343;      /* dourado claro */
      border-color:#f6c343;
      color:#000 !important;   /* pedido: texto preto */
    }
    #btnApply:hover{ filter:brightness(0.95); }

    .kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
    @media (max-width:1000px){ .kpis{ grid-template-columns:repeat(2,1fr); } }
    @media (max-width:560px){ .kpis{ grid-template-columns:1fr; } }
    .kpi-card{ background:linear-gradient(180deg, var(--card), #0b1018); border:1px solid var(--border); border-radius:16px; padding:14px; box-shadow:var(--shadow); }
    .kpi-head{ display:flex; align-items:center; justify-content:space-between; color:#a6adbb; margin-bottom:6px; }
    .kpi-val{ font-size:1.6rem; font-weight:900; color: var(--gold)}

    .wrap{ display:grid; grid-template-columns:2fr 1fr; gap:12px; }
    @media (max-width:1200px){ .wrap{ grid-template-columns:1fr; } }
    .two{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width:900px){ .two{ grid-template-columns:1fr; } }

    .table-wrap{ overflow:auto; border-radius:12px; max-width:100%; }
    table{ width:100%; border-collapse:collapse; }
    table.fixed{ table-layout: fixed; }
    th,td{ border-bottom:1px solid #1f2635; padding:10px 8px; text-align:left; color:#cbd5e1; font-size:.93rem; vertical-align:top; }
    th{ background:#0f1524; position:sticky; top:0; z-index:1; }
    .right{ text-align:right; }
    .nowrap{ white-space:nowrap; }

    /* Larguras específicas via colgroup */
    /* Orçamentos */
    .table-orcamentos col.w-id{ width:64px; }
    .table-orcamentos col.w-comp{ width:110px; }
    .table-orcamentos col.w-valor{ width:120px; }
    .table-orcamentos col.w-aprov{ width:140px; }
    .table-orcamentos col.w-obj{ width:26%; }
    .table-orcamentos col.w-kr{ width:22%; }
    .table-orcamentos col.w-ini{ width:26%; }
    .table-orcamentos col.w-env{ width:12%; }
    .table-orcamentos col.w-evi{ width:90px; }

    /* Despesas */
    .table-despesas col.w-data{ width:120px; }
    .table-despesas col.w-valor{ width:120px; }
    .table-despesas col.w-desc{ width:22%; }
    .table-despesas col.w-orc{ width:130px; }
    .table-despesas col.w-obj{ width:20%; }
    .table-despesas col.w-kr{ width:18%; }
    .table-despesas col.w-ini{ width:22%; }
    .table-despesas col.w-evi{ width:110px; }

    /* Clamp de 2 linhas com tooltip */
    .clamp2{
      display:-webkit-box;
      -webkit-line-clamp:2;
      -webkit-box-orient:vertical;
      overflow:hidden;
      white-space:normal;
      line-height:1.25rem;
      max-height:2.5rem;
    }

    .legend{ display:flex; gap:10px; align-items:center; font-size:.85rem; color:#a6adbb; }
    .badge{ border:1px solid #705e14; background:#3b320a; color:#ffec99; padding:3px 8px; border-radius:999px; font-size:.72rem; }

    /* ===== Gráfico com altura controlada ===== */
    .chart-box{ position: relative; width: 100%; height: 340px; }
    @media (max-width: 900px){ .chart-box{ height: 300px; } }
    @media (max-width: 600px){ .chart-box{ height: 260px; } }
    .chart-box canvas{ width: 100% !important; height: 100% !important; display: block; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="orc">
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <span><i class="fa-solid fa-coins"></i> Análise de Orçamentos</span>
      </div>

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
          <div class="chart-box">
            <canvas id="chartMain"></canvas>
          </div>
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

      <!-- Tabela Orçamentos -->
      <section class="card">
        <h2><i class="fa-solid fa-coins"></i> Orçamentos</h2>
        <div class="table-wrap">
          <table class="fixed table-orcamentos">
            <colgroup>
              <col class="w-id"><col class="w-comp"><col class="w-valor"><col class="w-aprov">
              <col class="w-obj"><col class="w-kr"><col class="w-ini">
              <col class="w-env"><col class="w-evi">
            </colgroup>
            <thead>
              <tr>
                <th>#</th>
                <th>Competência</th>
                <th class="right">Valor</th>
                <th>Aprovação</th>
                <th>Objetivo</th>
                <th>KR</th>
                <th>Iniciativa</th>
                <th>Envolvidos</th>
                <th>Evidências</th>
              </tr>
            </thead>
            <tbody id="tbOrc"><tr><td colspan="9" class="muted">—</td></tr></tbody>
          </table>
        </div>
      </section>

      <!-- Tabela Despesas -->
      <section class="card">
        <h2><i class="fa-solid fa-file-invoice-dollar"></i> Despesas</h2>
        <div class="table-wrap">
          <table class="fixed table-despesas">
            <colgroup>
              <col class="w-data"><col class="w-valor"><col class="w-desc"><col class="w-orc">
              <col class="w-obj"><col class="w-kr"><col class="w-ini"><col class="w-evi">
            </colgroup>
            <thead>
              <tr>
                <th>Data pgto</th>
                <th class="right">Valor</th>
                <th>Descrição</th>
                <th>Orçamento</th>
                <th>Objetivo</th>
                <th>KR</th>
                <th>Iniciativa</th>
                <th>Evidência</th>
              </tr>
            </thead>
            <tbody id="tbDesp"><tr><td colspan="8" class="muted">—</td></tr></tbody>
          </table>
        </div>
      </section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script>
    const SCRIPT = window.location.pathname;

    const $ = (s,p=document)=>p.querySelector(s);
    const $$ = (s,p=document)=>Array.from(p.querySelectorAll(s));
    function fmtBRL(x){ return (Number(x)||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'}); }
    function escapeHtml(s){ return (s??'').toString().replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
    function toDMY(ym){ const [y,m] = (ym||'').split('-'); return `${m}/${y}`; }

    async function getJSON(url){
      try{
        const res = await fetch(url, { cache:'no-store' });
        const text = await res.text();
        try{ return JSON.parse(text); }
        catch(e){ console.error('Falha ao parsear JSON. Resposta foi:', text); throw e; }
      }catch(err){
        console.error('Erro de rede/JSON em', url, err);
        alert('Erro ao consultar o servidor. Veja o console para detalhes.');
        return {success:false};
      }
    }

    async function loadFilters(){
      const data = await getJSON(`${SCRIPT}?ajax=filters_init`);
      if (!data.success){ console.warn('filters_init sem sucesso', data); }

      $('#f_pilar').innerHTML = '<option value="">(Todos)</option>' + ((data.pillars||[]).map(p=>`<option>${escapeHtml(p.label||'—')}</option>`).join(''));
      $('#f_resp').innerHTML  = '<option value="">(Todos)</option>' + ((data.responsaveis||[]).map(r=>`<option value="${escapeHtml(r.id_user)}">${escapeHtml(r.nome||r.id_user)}</option>`).join(''));
      $('#f_aprov').innerHTML = '<option value="">(Todos)</option>' + ((data.status_aprovacao||[]).map(s=>`<option>${escapeHtml(s||'—')}</option>`).join(''));
      $('#f_fin').innerHTML   = '<option value="">(Todos)</option>' + ((data.status_financeiro||[]).map(s=>`<option>${escapeHtml(s||'—')}</option>`).join(''));

      // período default = ano corrente
      const now = new Date();
      const y = now.getFullYear();
      const nextY = y + 1;
      $('#f_ini').value = `${y}-01`;
      $('#f_fim').value = `${nextY}-12`;
      await loadObjetivos();
    }

    async function loadObjetivos(){
      const pilar = $('#f_pilar').value || '';
      const data = await getJSON(`${SCRIPT}?ajax=list_objetivos&pilar=${encodeURIComponent(pilar)}`);
      $('#f_obj').innerHTML = '<option value="">(Todos)</option>' + ((data.items||[]).map(o=>`<option value="${o.id_objetivo}">${escapeHtml(o.descricao||o.id_objetivo)}</option>`).join(''));
      $('#f_kr').innerHTML = '<option value="">(Todos)</option>';
    }

    async function loadKrs(){
      const id_obj = $('#f_obj').value || '';
      if (!id_obj){ $('#f_kr').innerHTML = '<option value="">(Todos)</option>'; return; }
      const data = await getJSON(`${SCRIPT}?ajax=list_krs&id_objetivo=${encodeURIComponent(id_obj)}`);
      $('#f_kr').innerHTML = '<option value="">(Todos)</option>' + ((data.items||[]).map(k=>`<option value="${k.id_kr}">${escapeHtml(k.label||k.id_kr)}</option>`).join(''));
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
      const data = await getJSON(`${SCRIPT}?ajax=search&`+q.toString());
      if (!data.success){ console.warn('search sem sucesso', data); return; }

      // KPIs
      $('#k_aprov').textContent = fmtBRL(data.totais.aprovado||0);
      $('#k_real').textContent  = fmtBRL(data.totais.realizado||0);
      $('#k_saldo').textContent = fmtBRL(data.totais.saldo||0);
      $('#k_itens').textContent = (data.totais.orcamentos||0).toLocaleString('pt-BR');
      $('#k_desp').textContent  = (data.totais.despesas||0).toLocaleString('pt-BR');

      // Período
      const pb = document.getElementById('periodBadge'), pt = document.getElementById('periodText');
      if (pt){ pt.textContent = `Período: ${data.periodo.ini} → ${data.periodo.fim}`; pb.style.display='inline-flex'; }

      // Chart datasets
      const labels = (data.series||[]).map(s=>toDMY(s.competencia));
      const mensalP = (data.series||[]).map(s=>s.planejado||0);
      const mensalR = (data.series||[]).map(s=>s.realizado||0);
      const acumP   = (data.series||[]).map(s=>s.plan_acum||0);
      const acumR   = (data.series||[]).map(s=>s.real_acum||0);

      const ctx = document.getElementById('chartMain');
      if (chartMain) chartMain.destroy();
      chartMain = new Chart(ctx, {
        type:'bar',
        data:{
          labels,
          datasets:[
            {
              label:'Planejado',
              data: mensalP,
              backgroundColor:'rgba(246,195,67,.35)',
              borderColor:'#f6c343',
              borderWidth:1,
              maxBarThickness: 28,
              borderRadius: 6,
              borderSkipped: false
            },
            {
              label:'Realizado',
              data: mensalR,
              backgroundColor:'rgba(96,165,250,.35)',
              borderColor:'#60a5fa',
              borderWidth:1,
              maxBarThickness: 28,
              borderRadius: 6,
              borderSkipped: false
            }
          ]
        },
        options:{
          responsive:true,
          maintainAspectRatio:false,
          layout:{ padding:{top:6, right:8, bottom:4, left:8} },
          plugins:{
            legend:{ labels:{ color:'#cbd5e1' } },
            tooltip:{ callbacks:{
              label: (ctx)=> `${ctx.dataset.label}: ` + (Number(ctx.parsed.y)||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'})
            }}
          },
          scales:{
            x:{ ticks:{ color:'#a6adbb' }, grid:{ color:'rgba(255,255,255,.06)' } },
            y:{
              ticks:{
                color:'#a6adbb',
                callback:(v)=> (Number(v)||0).toLocaleString('pt-BR',{notation:'compact', maximumFractionDigits:1})
              },
              grid:{ color:'rgba(255,255,255,.06)' },
              beginAtZero:true,
              grace:'5%'
            }
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

      // ===== Tabela Orçamentos (clamp + tooltip) =====
      const tbO = $('#tbOrc'); tbO.innerHTML = '';
      (data.orcamentos||[]).forEach(o=>{
        const comp = (o.data_desembolso||'').slice(0,7);
        const objFull = o.obj_desc||'';
        const krFull  = o.kr_desc||o.id_kr||'';
        const iniFull = ((o.num_iniciativa?('#'+o.num_iniciativa+' – '):'') + (o.ini_desc||''));
        tbO.insertAdjacentHTML('beforeend', `
          <tr>
            <td class="nowrap">${o.id_orcamento}</td>
            <td class="nowrap">${escapeHtml(toDMY(comp))}</td>
            <td class="right nowrap">${fmtBRL(o.valor||0)}</td>
            <td class="nowrap">${escapeHtml(o.status_aprovacao||'—')}</td>
            <td title="${escapeHtml(objFull)}"><div class="clamp2">${escapeHtml(objFull)}</div></td>
            <td title="${escapeHtml(krFull)}"><div class="clamp2">${escapeHtml(krFull)}</div></td>
            <td title="${escapeHtml(iniFull)}"><div class="clamp2">${escapeHtml(iniFull)}</div></td>
            <td>${escapeHtml(o.envolvidos||'—')}</td>
            <td>${(o.evidencias_qtd||0) > 0 ? '<span class="badge">ok</span>' : '<span class="muted">—</span>'}</td>
          </tr>
        `);
      });
      if (!tbO.children.length) tbO.innerHTML = `<tr><td colspan="9" class="muted">Sem orçamentos no período/filtros.</td></tr>`;

      // ===== Tabela Despesas (clamp + tooltip) =====
      const tbD = $('#tbDesp'); tbD.innerHTML = '';
      (data.despesas||[]).forEach(d=>{
        const objFull = d.obj_desc||'';
        const krFull  = d.kr_desc||d.id_kr||'';
        const iniFull = ((d.num_iniciativa?('#'+d.num_iniciativa+' – '):'') + (d.ini_desc||''));
        tbD.insertAdjacentHTML('beforeend', `
          <tr>
            <td class="nowrap">${escapeHtml((d.data_pagamento||'').split(' ')[0]||'—')}</td>
            <td class="right nowrap">${fmtBRL(d.valor||0)}</td>
            <td>${escapeHtml(d.descricao||'—')}</td>
            <td class="nowrap">${escapeHtml(d.codigo_orcamento||('#'+d.id_orcamento))}</td>
            <td title="${escapeHtml(objFull)}"><div class="clamp2">${escapeHtml(objFull)}</div></td>
            <td title="${escapeHtml(krFull)}"><div class="clamp2">${escapeHtml(krFull)}</div></td>
            <td title="${escapeHtml(iniFull)}"><div class="clamp2">${escapeHtml(iniFull)}</div></td>
            <td>${d.tem_evidencia ? '<span class="badge">comprovado</span>' : '<span class="muted">—</span>'}</td>
          </tr>
        `);
      });
      if (!tbD.children.length) tbD.innerHTML = `<tr><td colspan="8" class="muted">Sem despesas no período/filtros.</td></tr>`;
    }

    $('#btnApply')?.addEventListener('click', runSearch);
    $('#btnClear')?.addEventListener('click', async ()=>{
      $('#f_pilar').value=''; await loadObjetivos();
      $('#f_resp').value=''; $('#f_aprov').value=''; $('#f_fin').value='';
      const now = new Date();
      const y = now.getFullYear();
      const nextY = y + 1;
      $('#f_ini').value = `${y}-01`;
      $('#f_fim').value = `${nextY}-12`;
      runSearch();
    });

    // Ajuste responsivo ao chat lateral
    (function chatAdjust(){
      const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
      const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
      function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
      function chatWidth(){
        const el = findChatEl(); if (!el) return 0;
        const cs = getComputedStyle(el);
        const hidden = (cs.display==='none' || cs.visibility==='hidden' || cs.opacity==='0');
        if (hidden) return 0;
        const rect = el.getBoundingClientRect();
        const vw = Math.max(document.documentElement.clientWidth, window.innerWidth||0);
        if (rect.width && rect.left >= 0 && rect.right <= vw) return rect.width;
        return el.offsetWidth || 0;
      }
      function updateChatWidth(){ document.documentElement.style.setProperty('--chat-w', (chatWidth()||0)+'px'); }
      const el = findChatEl(); if (el){ const mo=new MutationObserver(updateChatWidth); mo.observe(el,{attributes:true, attributeFilter:['style','class','aria-expanded','hidden']}); }
      window.addEventListener('resize', updateChatWidth);
      let poll = setInterval(updateChatWidth, 300); setTimeout(()=>clearInterval(poll), 5000);
      TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,220))));
      updateChatWidth();
    })();

    (async function init(){
      await loadFilters();
      await runSearch();
    })();
  </script>
</body>
</html>
