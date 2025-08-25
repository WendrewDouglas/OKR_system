<?php
// views/detalhe_okr.php – layout com iconografia no padrão do home.php

/* ===================== MODO AJAX (ENDPOINTS) ===================== */
if (isset($_GET['ajax'])) {
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);

  session_start();
  require_once __DIR__ . '/../auth/config.php';
  require_once __DIR__ . '/../auth/functions.php';

  header('Content-Type: application/json; charset=utf-8');

  if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
  }

  try {
    $pdo = new PDO(
      "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
      DB_USER,
      DB_PASS,
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]
    );
  } catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
  }

  // Helpers
  $g = static function(array $row, string $k, $d = null) { return array_key_exists($k, $row) ? $row[$k] : $d; };

  $colExists = static function(PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $key = "$table.$col";
    if (isset($cache[$key])) return $cache[$key];
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
    $st->execute(['c'=>$col]);
    $cache[$key] = (bool)$st->fetch();
    return $cache[$key];
  };

  $findKrUserIdCol = static function(PDO $pdo): ?string {
    $st = $pdo->query("SHOW COLUMNS FROM `key_results`");
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
      if (in_array($p,$names,true) && stripos($types[$p]??'', 'int')!==false) return $p;
    }
    foreach ($names as $n) {
      if (preg_match('/respons|owner|dono/i',$n) && preg_match('/(^id_|_id$)/i',$n) && stripos($types[$n]??'','int')!==false) {
        return $n;
      }
    }
    return null;
  };

  $getUserNameById = static function(PDO $pdo, $id): ?string {
    static $cache = [];
    $id = (int)$id;
    if ($id<=0) return null;
    if (isset($cache[$id])) return $cache[$id];
    $st = $pdo->prepare("SELECT primeiro_nome FROM usuarios WHERE id_user = :id LIMIT 1");
    $st->execute(['id'=>$id]);
    $name = $st->fetchColumn();
    $cache[$id] = $name ?: null;
    return $cache[$id];
  };

  $action = $_GET['ajax'];

  /* ---------- LISTA DE KRs (responsável do KR como primeiro_nome) ---------- */
  if ($action === 'load_krs') {
    $id_objetivo = isset($_GET['id_objetivo']) ? (int)$_GET['id_objetivo'] : 0;
    if ($id_objetivo <= 0) { echo json_encode(['success'=>false,'error'=>'id_objetivo inválido']); exit; }

    $krUserIdCol = $findKrUserIdCol($pdo);
    $hasRespText = $colExists($pdo,'key_results','responsavel');

    $select = "
      SELECT
        kr.id_kr, kr.key_result_num, kr.descricao, kr.farol, kr.status,
        kr.tipo_frequencia_milestone, kr.baseline, kr.meta, kr.unidade_medida,
        kr.direcao_metrica, kr.data_fim, kr.dt_novo_prazo
    ";
    $join = "";
    if ($krUserIdCol) {
      $select .= ",
        kr.`$krUserIdCol` AS kr_user_id,
        u.primeiro_nome AS responsavel_nome
      ";
      $join = " LEFT JOIN usuarios u ON u.id_user = kr.`$krUserIdCol` ";
    }
    if ($hasRespText) $select .= ", kr.responsavel AS responsavel_text";

    $sql = $select . "
      FROM key_results kr
      $join
      WHERE kr.id_objetivo = :id
      ORDER BY kr.key_result_num ASC, kr.dt_ultima_atualizacao DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute(['id'=>$id_objetivo]);
    $rows = $st->fetchAll();

    $out = [];
    foreach ($rows as $r) {
      $nome = $r['responsavel_nome'] ?? null;
      if (!$nome && isset($r['responsavel_text'])) {
        $txt = trim((string)$r['responsavel_text']);
        if ($txt !== '') {
          if (ctype_digit($txt)) $nome = $getUserNameById($pdo, (int)$txt) ?: $txt;
          else $nome = $txt;
        }
      }
      if (!$nome && !empty($r['kr_user_id'] ?? null)) $nome = $getUserNameById($pdo, (int)$r['kr_user_id']);

      $out[] = [
        'id_kr' => $r['id_kr'],
        'key_result_num' => $r['key_result_num'],
        'descricao' => $r['descricao'],
        'farol' => $r['farol'],
        'status' => $r['status'],
        'tipo_frequencia_milestone' => $r['tipo_frequencia_milestone'],
        'baseline' => $r['baseline'],
        'meta' => $r['meta'],
        'unidade_medida' => $r['unidade_medida'],
        'direcao_metrica' => $r['direcao_metrica'],
        'data_fim' => $r['data_fim'],
        'dt_novo_prazo' => $r['dt_novo_prazo'],
        'responsavel' => $nome ?: '—',
      ];
    }

    echo json_encode(['success'=>true,'krs'=>$out]); exit;
  }

  /* ---------- DETALHE DO KR ---------- */
  if ($action === 'kr_detail') {
    $id_kr = $_GET['id_kr'] ?? '';
    if (!$id_kr) { echo json_encode(['success'=>false,'error'=>'id_kr inválido']); exit; }

    $st = $pdo->prepare("
      SELECT id_kr, id_objetivo, key_result_num, descricao, farol, status,
             baseline, meta, unidade_medida, direcao_metrica,
             tipo_frequencia_milestone, dt_ultima_atualizacao
      FROM key_results WHERE id_kr = :id LIMIT 1
    ");
    $st->execute(['id'=>$id_kr]);
    $kr = $st->fetch();
    if (!$kr) { echo json_encode(['success'=>false,'error'=>'KR não encontrado']); exit; }

    $stmM = $pdo->prepare("
      SELECT COALESCE(dt_prevista, data_prevista) AS data_prevista,
             COALESCE(valor_esperado, esperado)   AS valor_esperado,
             COALESCE(valor_real, realizado)      AS valor_real,
             dt_evidencia
      FROM milestones_kr WHERE id_kr = :id ORDER BY data_prevista ASC
    ");
    $stmM->execute(['id'=>$id_kr]);
    $milestones = $stmM->fetchAll();

    $labels=[]; $esp=[]; $real=[];
    foreach ($milestones as $m) {
      $labels[] = $g($m,'data_prevista');
      $esp[]    = (float)$g($m,'valor_esperado',0);
      $real[]   = is_null($g($m,'valor_real',null)) ? null : (float)$m['valor_real'];
    }

    $stmI = $pdo->prepare("SELECT COUNT(*) AS t FROM iniciativas WHERE id_kr=:id");
    $stmI->execute(['id'=>$id_kr]); $tIni = (int)($stmI->fetch()['t'] ?? 0);

    $stmA = $pdo->prepare("
      SELECT COALESCE(SUM(o.valor),0) AS v
      FROM orcamentos o INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
      WHERE i.id_kr = :id
    "); $stmA->execute(['id'=>$id_kr]); $aprov = (float)($stmA->fetch()['v'] ?? 0);

    $stmR = $pdo->prepare("
      SELECT COALESCE(SUM(od.valor),0) AS v
      FROM orcamentos_detalhes od
      INNER JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
      INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
      WHERE i.id_kr = :id
    "); $stmR->execute(['id'=>$id_kr]); $realiz = (float)($stmR->fetch()['v'] ?? 0);

    $prox = null;
    foreach ($milestones as $m) {
      $d = $g($m,'data_prevista');
      if ($d && strtotime($d) >= strtotime(date('Y-m-d'))) { $prox = $m; break; }
    }

    echo json_encode([
      'success'=>true,'kr'=>$kr,'milestones'=>$milestones,
      'chart'=>['labels'=>$labels,'esperado'=>$esp,'real'=>$real],
      'agregados'=>[
        'iniciativas'=>$tIni,
        'orcamento'=>['aprovado'=>$aprov,'realizado'=>$realiz,'saldo'=>max(0,$aprov-$realiz)],
        'proximo_milestone'=>$prox
      ]
    ]); exit;
  }

  /* ---------- LISTA DE INICIATIVAS (responsável pelo primeiro_nome) ---------- */
  if ($action === 'iniciativas_list') {
    $id_kr = $_GET['id_kr'] ?? '';
    if (!$id_kr) { echo json_encode(['success'=>false,'error'=>'id_kr inválido']); exit; }

    $stmI = $pdo->prepare("
      SELECT i.id_iniciativa, i.num_iniciativa, i.descricao, i.status, i.dt_prazo,
             i.id_user_responsavel, u.primeiro_nome AS responsavel_nome
      FROM iniciativas i
      LEFT JOIN usuarios u ON u.id_user = i.id_user_responsavel
      WHERE i.id_kr=:id
      ORDER BY i.num_iniciativa ASC, i.dt_criacao ASC
    ");
    $stmI->execute(['id'=>$id_kr]);
    $iniciativas = $stmI->fetchAll();

    $resp=[];
    foreach ($iniciativas as $ini) {
      $id_ini = $ini['id_iniciativa'];
      $stmA = $pdo->prepare("SELECT COALESCE(SUM(valor),0) AS aprovado, MIN(id_orcamento) AS id_orc FROM orcamentos WHERE id_iniciativa=:id");
      $stmA->execute(['id'=>$id_ini]); $oa = $stmA->fetch() ?: [];
      $aprov = (float)($oa['aprovado'] ?? 0); $id_orc = $oa['id_orc'] ?? null;

      if ($id_orc) {
        $stmR = $pdo->prepare("SELECT COALESCE(SUM(valor),0) AS realizado FROM orcamentos_detalhes WHERE id_orcamento=:o");
        $stmR->execute(['o'=>$id_orc]); $real = (float)($stmR->fetch()['realizado'] ?? 0);
      } else {
        $stmR2 = $pdo->prepare("
          SELECT COALESCE(SUM(od.valor),0) AS realizado
          FROM orcamentos_detalhes od
          INNER JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
          WHERE o.id_iniciativa=:id
        ");
        $stmR2->execute(['id'=>$id_ini]); $real = (float)($stmR2->fetch()['realizado'] ?? 0);
      }

      $resp[] = [
        'id_iniciativa'=>$id_ini,
        'num_iniciativa'=>(int)$ini['num_iniciativa'],
        'descricao'=>$ini['descricao'],
        'status'=>$ini['status'],
        'dt_prazo'=>$ini['dt_prazo'],
        'responsavel'=>$ini['responsavel_nome'] ?: '—',
        'orcamento'=>[
          'aprovado'=>$aprov,'realizado'=>$real,'saldo'=>max(0,$aprov-$real),
          'id_orcamento'=>$id_orc
        ]
      ];
    }

    echo json_encode(['success'=>true,'iniciativas'=>$resp]); exit;
  }

  /* ---------- CRIAR INICIATIVA ---------- */
  if ($action === 'create_iniciativa') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
      http_response_code(403); echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']); exit;
    }
    $id_kr = $_POST['id_kr'] ?? '';
    $descricao = trim($_POST['descricao'] ?? '');
    $id_resp = $_POST['id_user_responsavel'] ?? null;
    $prazo = $_POST['dt_prazo'] ?? null;
    $incl = isset($_POST['incluir_orcamento']) && $_POST['incluir_orcamento']=='1';
    $v_orc = (float)($_POST['valor_orcamento'] ?? 0);
    $just  = trim($_POST['justificativa_orcamento'] ?? '');

    if (!$id_kr || !$descricao) { echo json_encode(['success'=>false,'error'=>'Campos obrigatórios ausentes']); exit; }

    $id_ini = 'INI_' . bin2hex(random_bytes(8));
    $stN = $pdo->prepare("SELECT COALESCE(MAX(num_iniciativa),0)+1 AS prox FROM iniciativas WHERE id_kr=:id");
    $stN->execute(['id'=>$id_kr]); $num = (int)($stN->fetch()['prox'] ?? 1);

    $stI = $pdo->prepare("
      INSERT INTO iniciativas (
        id_iniciativa, id_kr, num_iniciativa, descricao,
        status, status_aprovacao, id_user_criador, dt_criacao,
        id_user_responsavel, dt_prazo
      ) VALUES (
        :id,:kr,:n,:d,'Ativa','pendente',:u,CURDATE(),:r,:p
      )
    ");
    $stI->execute([
      'id'=>$id_ini,'kr'=>$id_kr,'n'=>$num,'d'=>$descricao,'u'=>$_SESSION['user_id'],'r'=>$id_resp,'p'=>$prazo
    ]);

    $id_orc = null;
    if ($incl && $v_orc>0) {
      $stO = $pdo->prepare("
        INSERT INTO orcamentos (id_iniciativa,valor,data_desembolso,status_aprovacao,id_user_criador,dt_criacao,justificativa_orcamento)
        VALUES (:ini,:v,CURDATE(),'pendente',:u,CURDATE(),:j)
      ");
      $stO->execute(['ini'=>$id_ini,'v'=>$v_orc,'u'=>$_SESSION['user_id'],'j'=>$just]);
      $id_orc = (int)$pdo->lastInsertId();
    }

    echo json_encode(['success'=>true,'id_iniciativa'=>$id_ini,'num_iniciativa'=>$num,'id_orcamento'=>$id_orc]); exit;
  }

  /* ---------- CRIAR DESPESA ---------- */
  if ($action === 'create_despesa') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
      http_response_code(403); echo json_encode(['success'=>false,'error'=>'Token CSRF inválido']); exit;
    }
    $id_orc = (int)($_POST['id_orcamento'] ?? 0);
    $valor  = (float)($_POST['valor'] ?? 0);
    $desc   = trim($_POST['descricao'] ?? '');
    $dt     = $_POST['data_pagamento'] ?? null;

    if ($id_orc<=0 || $valor<=0) { echo json_encode(['success'=>false,'error'=>'Dados inválidos para despesa']); exit; }

    $st = $pdo->prepare("
      INSERT INTO orcamentos_detalhes (id_orcamento,valor,descricao,data_pagamento,id_user_criador,dt_criacao)
      VALUES (:o,:v,:d,:dt,:u,NOW())
    ");
    $st->execute(['o'=>$id_orc,'v'=>$valor,'d'=>$desc,'dt'=>$dt,'u'=>$_SESSION['user_id']]);
    echo json_encode(['success'=>true,'id_despesa'=>(int)$pdo->lastInsertId()]); exit;
  }

  echo json_encode(['success'=>false,'error'=>'Ação inválida']); exit;
}

/* ===================== MODO PÁGINA ===================== */
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (!isset($_SESSION['user_id'])) { header('Location: /OKR_system/views/login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );
} catch (PDOException $e) { http_response_code(500); die("Erro ao conectar: ".$e->getMessage()); }

$id_objetivo = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id_objetivo<=0 && preg_match('#/detalhe_okr/([0-9]+)#', $_SERVER['REQUEST_URI'] ?? '', $m)) { $id_objetivo = (int)$m[1]; }
if ($id_objetivo<=0) { http_response_code(400); die('id_objetivo inválido.'); }

/* Objetivo + nome do dono (primeiro_nome) */
$g = static function(array $row, string $k, $d='—'){ return array_key_exists($k,$row) && $row[$k]!==null && $row[$k]!=='' ? $row[$k] : $d; };

$st = $pdo->prepare("
  SELECT o.id_objetivo,
         o.descricao AS nome_objetivo, o.descricao, o.pilar_bsc, o.tipo, o.status,
         o.status_aprovacao, o.dono, u.primeiro_nome AS dono_nome,
         o.dt_criacao, o.dt_prazo, o.dt_conclusao, o.qualidade, o.observacoes
  FROM objetivos o
  LEFT JOIN usuarios u ON u.id_user = o.dono
  WHERE o.id_objetivo=:id
  LIMIT 1
");
$st->execute(['id'=>$id_objetivo]); $objetivo = $st->fetch();
if (!$objetivo) { http_response_code(404); die('Objetivo não encontrado.'); }

$stK = $pdo->prepare("
  SELECT COUNT(*) AS total_krs,
         SUM(CASE WHEN kr.farol='vermelho' THEN 1 ELSE 0 END) AS criticos,
         SUM(CASE WHEN kr.farol='amarelo'  THEN 1 ELSE 0 END) AS em_risco
  FROM key_results kr WHERE kr.id_objetivo=:id
"); $stK->execute(['id'=>$id_objetivo]); $kpi = $stK->fetch() ?: ['total_krs'=>0,'criticos'=>0,'em_risco'=>0];

$stI = $pdo->prepare("SELECT COUNT(*) AS total FROM iniciativas i INNER JOIN key_results kr ON kr.id_kr=i.id_kr WHERE kr.id_objetivo=:id");
$stI->execute(['id'=>$id_objetivo]); $tIni = (int)($stI->fetch()['total'] ?? 0);

$stIC = $pdo->prepare("
  SELECT COUNT(DISTINCT i.id_iniciativa) AS com_orc
  FROM iniciativas i INNER JOIN key_results kr ON kr.id_kr=i.id_kr
  INNER JOIN orcamentos o ON o.id_iniciativa=i.id_iniciativa
  WHERE kr.id_objetivo=:id
"); $stIC->execute(['id'=>$id_objetivo]); $comOrc = (int)($stIC->fetch()['com_orc'] ?? 0);

$stOA = $pdo->prepare("
  SELECT COALESCE(SUM(o.valor),0) AS v
  FROM orcamentos o
  INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
  INNER JOIN key_results kr ON kr.id_kr=i.id_kr
  WHERE kr.id_objetivo=:id
"); $stOA->execute(['id'=>$id_objetivo]); $aprovObj = (float)($stOA->fetch()['v'] ?? 0);

$stOR = $pdo->prepare("
  SELECT COALESCE(SUM(od.valor),0) AS v
  FROM orcamentos_detalhes od
  INNER JOIN orcamentos o ON o.id_orcamento=od.id_orcamento
  INNER JOIN iniciativas i ON i.id_iniciativa=o.id_iniciativa
  INNER JOIN key_results kr ON kr.id_kr=i.id_kr
  WHERE kr.id_objetivo=:id
"); $stOR->execute(['id'=>$id_objetivo]); $realObj = (float)($stOR->fetch()['v'] ?? 0);
$saldoObj = max(0, $aprovObj - $realObj);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Detalhe do Objetivo – OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
  <script src="/OKR_system/assets/chart.4.4.0.min.js"></script>

  <style>
    body{ background:#fff !important; color:#111; }
    :root{ --chat-w:0px; }
    .content{ background:transparent; }
    main.okr-detail{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; }

    :root{
      --bg-soft:#171b21; --card:#12161c; --muted:#a6adbb; --text:#eaeef6;
      --gold:#f6c343; --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20); --btn:#0e131a;
    }

    /* Breadcrumb */
    .crumbs{ color:#333; font-size:.9rem; display:flex; align-items:center; gap:6px; }
    .crumbs a{ color:#0c4a6e; text-decoration:none; }
    .crumbs .sep{ opacity:.5; margin:0 2px; }
    .crumbs i{ opacity:.8; }

    /* Header Objetivo */
    .obj-card{ position:relative; background:linear-gradient(180deg, var(--card), #0d1117); border:1px solid var(--border); border-radius:16px; padding:16px 44px 16px 16px; box-shadow:var(--shadow); color:var(--text); overflow:hidden; }
    .obj-title{ font-size:1.35rem; font-weight:900; margin:0 0 8px; letter-spacing:.2px; display:flex; align-items:center; gap:8px; }
    .obj-title i{ color:var(--gold); }
    .obj-meta-pills{ display:flex; flex-wrap:wrap; gap:8px; }
    .pill{ display:inline-flex; align-items:center; gap:8px; background:#0e131a; border:1px solid var(--border); color:#a6adbb; padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
    .pill i{ font-size:.9rem; opacity:.9; }
    .obj-actions{ display:flex; gap:10px; margin-top:12px; }
    .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:10px 14px; border-radius:12px; font-weight:700; }
    .btn:hover{ border-color:#2a3342; transform:translateY(-1px); transition:.15s; }
    .btn-primary{ background:#1f2937; }
    .btn-outline{ background:transparent; }
    .btn-sm{ padding:7px 10px; font-size:.86rem; border-radius:10px; }
    .btn-gold{ background:var(--gold); border-color:var(--gold); color:#1f2937; }
    .btn-gold:hover{ filter:brightness(0.95); }
    .obj-dates{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
    .obj-dates .pill{ background:#0c1118; }

  .share-fab{
    position:absolute; top:30px; right:30px; left:auto;
    background:transparent; border:none; color:var(--gold);
    font-size:1.1rem; padding:6px; cursor:pointer; line-height:1;
  }
  .share-fab:hover{ opacity:.9; transform:translateY(-1px); transition:.15s; }


    /* KPIs */
    .kpi-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
    @media (max-width:1100px){ .kpi-grid{ grid-template-columns:repeat(2,1fr); } }
    @media (max-width:650px){ .kpi-grid{ grid-template-columns:1fr; } }
    .kpi{ background:linear-gradient(180deg, var(--card), #18190eff); border:1px solid var(--border); border-radius:14px; padding:14px; box-shadow:var(--shadow); color:#eaeef6; }
    .kpi-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; color:var(--muted); font-size:.9rem; }
    .kpi-value{ font-weight:900; font-size:1.55rem; }
    .kpi-sub{ color:var(--muted); font-size:.85rem; }
    .kpi-icon{ width:40px; height:40px; border-radius:12px; display:grid; place-items:center; border:1px solid var(--border); color:#c7d2fe; background:rgba(96,165,250,.12); }
    .kpi-icon.success{ color:#86efac; background:rgba(34,197,94,.12); }
    .kpi-icon.money{ color:#fde68a; background:rgba(246,195,67,.12); }

    /* Filtros */
    .filters{ display:flex; align-items:center; gap:10px; }
    .chips{ display:flex; flex-wrap:wrap; gap:8px; }
    .chip{ background:#0e131a; border:1px solid var(--border); color:#a6adbb; padding:6px 10px; border-radius:999px; font-weight:700; font-size:.78rem; display:inline-flex; align-items:center; gap:6px; }
    .chip i{ opacity:.9; }

    /* KRs */
    .kr-list{ display:flex; flex-direction:column; gap:10px; }
    .kr-card{ background:#0f1420; border:1px solid var(--border); border-radius:14px; padding:10px 12px; box-shadow:var(--shadow); color:#eaeef6; }
    .kr-head{ display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
    .kr-title{ font-weight:800; display:flex; align-items:center; gap:8px; color: var(--gold); }
    .meta-line{ display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
    .meta-pill{ display:inline-flex; align-items:center; gap:6px; background:#0b0f14; border:1px solid var(--border); color:#a6adbb; padding:5px 8px; border-radius:999px; font-size:.78rem; font-weight:700; }
    .meta-pill i{ font-size:.85rem; }

    .kr-actions{ display:flex; gap:8px; flex-wrap:nowrap; align-items:center; }
    @media (max-width:560px){ .kr-actions{ flex-wrap:wrap; } }

    .kr-toggle{ background:#0b0f14; border:1px solid var(--border); color:#a6adbb; width:36px; height:36px; border-radius:10px; display:grid; place-items:center; cursor:pointer; }
    .kr-toggle.gold{ border-color:var(--gold); color:var(--gold); }
    .kr-toggle i{ transition:transform .2s ease; }
    .kr-card.open .kr-toggle i{ transform:rotate(180deg); }

    .kr-body{ max-height:0; overflow:hidden; transition:max-height .25s ease, opacity .2s ease; opacity:0; }
    .kr-card.open .kr-body{ max-height:1200px; opacity:1; margin-top:10px; }

    /* Abas */
    .tabs{ border-bottom:1px solid var(--border); display:flex; gap:6px; }
    .tab{ background:transparent; border:1px solid var(--border); border-bottom:none; padding:8px 12px; border-radius:10px 10px 0 0; color:#a6adbb; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
    .tab.active{ background:#0e131a; color:#eaeef6; }
    .tabpane{ display:none; padding:12px; background:#0e131a; border:1px solid var(--border); border-radius:0 12px 12px 12px; }
    .tabpane.active{ display:block; }

    /* Tabelas */
    .table{ width:100%; border-collapse:collapse; }
    .table th, .table td{ border-bottom:1px dashed #1e2636; padding:8px 6px; text-align:left; font-size:.92rem; color:#a6adbb; }
    .table th{ color:#cbd5e1; white-space:nowrap; }
    .th-ico{ opacity:.85; margin-right:6px; }

    /* Drawers */
    .drawer{ position:fixed; top:0; right:-560px; width:520px; max-width:92vw; height:100%; background:#0f1420; border-left:1px solid #223047; box-shadow:-10px 0 40px rgba(0,0,0,.35); transition:right .25s ease; z-index:2000; color:#e5e7eb; display:flex; flex-direction:column; }
    .drawer.show{ right:0; }
    .drawer header{ padding:14px 16px; border-bottom:1px solid #1f2a3a; background:#0b101a; display:flex; justify-content:space-between; align-items:center; }
    .drawer .body{ padding:14px 16px; overflow:auto; }
    .drawer .actions{ padding:12px 16px; border-top:1px solid #1f2a3a; display:flex; justify-content:flex-end; gap:10px; background:#0b101a; }

    input[type="text"], input[type="date"], input[type="number"], textarea, select{
      width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none;
    }
    label{ display:block; margin-bottom:6px; color:#cbd5e1; font-size:.9rem; }

    /* Toast */
    .toast{ position:fixed; bottom:20px; right:20px; background:#0b7a44; color:#eafff5; padding:12px 14px; border-radius:10px; font-weight:700; box-shadow:0 10px 30px rgba(0,0,0,.25); z-index:3000; }
    .toast.error{ background:#7a1020; color:#ffe4e6; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="okr-detail">

      <!-- Breadcrumb com ícones -->
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <a href="/OKR_system/meus_okrs"><i class="fa-solid fa-bullseye"></i> Meus OKRs</a>
        <span class="sep">/</span>
        <span><i class="fa-solid fa-circle-info"></i> Detalhe do Objetivo</span>
      </div>

      <!-- HEADER -->
      <section class="obj-card">
        <!-- ícone de compartilhar no canto superior esquerdo -->
        <button class="share-fab" aria-label="Compartilhar" title="Compartilhar"
                onclick="navigator.clipboard.writeText(location.href)">
          <svg width="22" height="22" viewBox="0 0 24 24"
              fill="none" stroke="currentColor" stroke-width="1.5"
              stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <!-- nós mais afastados: direita/topo, esquerda/centro, direita/baixo -->
            <circle cx="20" cy="4"  r="3"></circle>
            <circle cx="4"  cy="12" r="3"></circle>
            <circle cx="20" cy="20" r="3"></circle>
            <!-- hastes mais longas entre os nós -->
            <path d="M7 12 L17 6"></path>
            <path d="M7 12 L17 18"></path>
          </svg>
        </button>

        <h1 class="obj-title"><i class="fa-solid fa-bullseye"></i>
          <?= htmlspecialchars($g($objetivo,'nome_objetivo',$g($objetivo,'descricao','Objetivo'))) ?>
        </h1>

        <div class="obj-meta-pills">
          <span class="pill" title="Pilar BSC"><i class="fa-solid fa-layer-group"></i><?= htmlspecialchars($g($objetivo,'pilar_bsc')) ?></span>
          <span class="pill" title="Tipo do objetivo"><i class="fa-solid fa-tag"></i><?= htmlspecialchars($g($objetivo,'tipo')) ?></span>
          <span class="pill" title="Dono (responsável)"><i class="fa-solid fa-user-tie"></i><?= htmlspecialchars($g($objetivo,'dono_nome',$g($objetivo,'dono'))) ?></span>
          <span class="pill" title="Status"><i class="fa-solid fa-clipboard-check"></i><?= htmlspecialchars($g($objetivo,'status')) ?></span>
          <span class="pill" title="Aprovação"><i class="fa-regular fa-circle-check"></i><?= htmlspecialchars($g($objetivo,'status_aprovacao')) ?></span>
        </div>

        <?php if ($g($objetivo,'observacoes','') !== '—'): ?>
          <div class="obj-meta-pills" style="margin-top:8px">
            <span class="pill" style="max-width:100%; white-space:normal;"><i class="fa-regular fa-note-sticky"></i><strong>Obs.:</strong>&nbsp;<?= nl2br(htmlspecialchars($objetivo['observacoes'])) ?></span>
          </div>
        <?php endif; ?>

        <div class="obj-actions">
          <a class="btn btn-outline" href="/OKR_system/objetivos_editar.php?id=<?= (int)$objetivo['id_objetivo'] ?>"><i class="fa-regular fa-pen-to-square"></i>&nbsp;Editar</a>
          <a class="btn btn-outline" href="/OKR_system/views/novo_key_result.php?id_objetivo=<?= (int)$objetivo['id_objetivo'] ?>"><i class="fa-solid fa-plus"></i>&nbsp;Novo KR</a>
          <button class="btn btn-outline" onclick="window.print()"><i class="fa-regular fa-file-lines"></i>&nbsp;Exportar</button>
        </div>

        <div class="obj-dates">
          <span class="pill" title="Data de criação"><i class="fa-regular fa-calendar-plus"></i><?= htmlspecialchars($g($objetivo,'dt_criacao')) ?></span>
          <span class="pill" title="Prazo"><i class="fa-regular fa-calendar-days"></i><?= htmlspecialchars($g($objetivo,'dt_prazo')) ?></span>
          <span class="pill" title="Conclusão"><i class="fa-solid fa-flag-checkered"></i><?= htmlspecialchars($g($objetivo,'dt_conclusao')) ?></span>
          <span class="pill" title="Qualidade"><i class="fa-regular fa-gem"></i><?= htmlspecialchars($g($objetivo,'qualidade')) ?></span>
        </div>
      </section>

      <!-- KPIs com ícones -->
      <section class="kpi-grid">
        <div class="kpi">
          <div class="kpi-head"><span>KRs</span><div class="kpi-icon"><i class="fa-solid fa-list-check"></i></div></div>
          <div class="kpi-value"><?= (int)$kpi['total_krs'] ?></div>
          <div class="kpi-sub">Críticos: <strong><?= (int)$kpi['criticos'] ?></strong> · Em risco: <strong><?= (int)$kpi['em_risco'] ?></strong></div>
        </div>
        <div class="kpi">
          <div class="kpi-head"><span>Iniciativas</span><div class="kpi-icon"><i class="fa-solid fa-diagram-project"></i></div></div>
          <div class="kpi-value"><?= (int)$tIni ?></div>
          <div class="kpi-sub">Com orçamento: <strong><?= (int)$comOrc ?></strong></div>
        </div>
        <div class="kpi">
          <div class="kpi-head"><span>Orçamento aprovado</span><div class="kpi-icon money"><i class="fa-solid fa-coins"></i></div></div>
          <div class="kpi-value">R$ <?= number_format($aprovObj,2,',','.') ?></div>
        </div>
        <div class="kpi">
          <div class="kpi-head"><span>Realizado / Saldo</span><div class="kpi-icon money"><i class="fa-solid fa-wallet"></i></div></div>
          <div class="kpi-value">R$ <?= number_format($realObj,2,',','.') ?> <span style="opacity:.7">/</span> R$ <?= number_format($saldoObj,2,',','.') ?></div>
        </div>
      </section>

      <!-- Filtros -->
      <section class="filters">
        <span style="font-size:.88rem; color:#555;"><i class="fa-solid fa-filter"></i> Filtros:</span>
        <div id="chipsFilters" class="chips"></div>
        <button class="btn btn-outline" id="btnClearFilters" style="margin-left:auto"><i class="fa-solid fa-broom"></i>&nbsp;Limpar filtros</button>
      </section>

      <!-- Lista de KRs -->
      <section id="krContainer" class="kr-list"></section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <!-- Drawer: Nova Iniciativa -->
  <aside id="drawerNovaIni" class="drawer" aria-hidden="true">
    <header>
      <h3 style="margin:0;font-size:1rem"><i class="fa-solid fa-rocket me-1"></i> Nova iniciativa</h3>
      <button class="btn btn-outline" type="button" onclick="toggleDrawer('#drawerNovaIni',false)">Fechar ✕</button>
    </header>
    <div class="body">
      <form id="formNovaIniciativa">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="id_kr" id="ni_id_kr">
        <div class="mb-2">
          <label><i class="fa-regular fa-rectangle-list"></i> Descrição</label>
          <textarea name="descricao" rows="3" required></textarea>
        </div>
        <div class="mb-2">
          <label><i class="fa-regular fa-user"></i> Responsável (id_user)</label>
          <input type="text" name="id_user_responsavel" placeholder="ex.: 12">
        </div>
        <div class="mb-2">
          <label><i class="fa-regular fa-calendar-days"></i> Prazo</label>
          <input type="date" name="dt_prazo">
        </div>
        <hr style="border-color:#1f2a3a; opacity:.6; margin:12px 0">
        <div class="mb-2" style="display:flex; align-items:center; gap:8px;">
          <input id="ni_sw_orc" type="checkbox" name="incluir_orcamento" value="1" style="width:18px;height:18px;">
          <label for="ni_sw_orc" style="margin:0;"><i class="fa-solid fa-coins"></i> Incluir orçamento nesta iniciativa</label>
        </div>
        <div id="ni_orc_group" style="display:none;">
          <div class="mb-2">
            <label><i class="fa-solid fa-sack-dollar"></i> Valor aprovado</label>
            <input type="number" step="0.01" name="valor_orcamento" placeholder="0,00">
          </div>
          <div class="mb-2">
            <label><i class="fa-regular fa-comment-dots"></i> Justificativa</label>
            <input type="text" name="justificativa_orcamento" placeholder="Motivo do orçamento">
          </div>
          <div class="mb-1" style="color:#9aa4b2; font-size:.85rem;">
            <i class="fa-solid fa-hourglass-half"></i> Status de aprovação inicia como <em>pendente</em>.
          </div>
        </div>
      </form>
    </div>
    <div class="actions">
      <button class="btn btn-outline" type="button" onclick="toggleDrawer('#drawerNovaIni',false)">Cancelar</button>
      <button class="btn btn-primary" type="button" id="btnSalvarIni"><i class="fa-regular fa-floppy-disk"></i> Salvar</button>
    </div>
  </aside>

  <!-- Drawer: Lançar Despesa -->
  <aside id="drawerDespesa" class="drawer" aria-hidden="true">
    <header>
      <h3 style="margin:0;font-size:1rem"><i class="fa-solid fa-file-invoice-dollar"></i> Lançar despesa</h3>
      <button class="btn btn-outline" type="button" onclick="toggleDrawer('#drawerDespesa',false)">Fechar ✕</button>
    </header>
    <div class="body">
      <form id="formDespesa">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="id_orcamento" id="desp_id_orcamento">
        <div class="mb-2"><label><i class="fa-solid fa-money-bill-wave"></i> Valor</label><input type="number" step="0.01" name="valor" required></div>
        <div class="mb-2"><label><i class="fa-regular fa-calendar-check"></i> Data de pagamento</label><input type="date" name="data_pagamento" required></div>
        <div class="mb-2"><label><i class="fa-regular fa-note-sticky"></i> Descrição</label><input type="text" name="descricao" placeholder="Ex.: parcela, serviço, etc."></div>
      </form>
    </div>
    <div class="actions">
      <button class="btn btn-outline" type="button" onclick="toggleDrawer('#drawerDespesa',false)">Cancelar</button>
      <button class="btn btn-primary" type="button" id="btnSalvarDesp"><i class="fa-regular fa-floppy-disk"></i> Lançar</button>
    </div>
  </aside>

  <script>
    // ================== Utils ==================
    const csrfToken = "<?= htmlspecialchars($csrf) ?>";
    const idObjetivo = <?= (int)$id_objetivo ?>;
    const SCRIPT = "<?= $_SERVER['SCRIPT_NAME'] ?>";
    const $ = (s,p=document)=>p.querySelector(s);
    const $$ = (s,p=document)=>Array.from(p.querySelectorAll(s));
    function fmtNum(x){ if(x===null||x===undefined||isNaN(x)) return '—'; return Number(x).toLocaleString('pt-BR',{maximumFractionDigits:2}); }
    function fmtBRL(x){ return (Number(x)||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'}); }
    function escapeHtml(s){ return (s??'').toString().replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
    function truncate(s,n){ if(!s)return''; return s.length>n?s.slice(0,n-1)+'…':s; }
    function toast(msg, ok=true){ const t=document.createElement('div'); t.className='toast'+(ok?'':' error'); t.textContent=msg; document.body.appendChild(t); setTimeout(()=>t.remove(),2800); }
    function toggleDrawer(sel, show=true){ const el=$(sel); if(!el) return; if(show){ el.classList.add('show'); } else { el.classList.remove('show'); } }

    function badgeFarol(v){
      v = (v||'').toLowerCase();
      if (v.includes('vermelho')) return `<i class="fa-solid fa-circle" style="color:#ef4444"></i> Vermelho`;
      if (v.includes('amarelo'))  return `<i class="fa-solid fa-circle" style="color:#f6c343"></i> Amarelo`;
      if (v.includes('verde'))    return `<i class="fa-solid fa-circle" style="color:#22c55e"></i> Verde`;
      return `<i class="fa-regular fa-circle" style="color:#6b7280"></i> —`;
    }
    function respLabel(kr){ return kr.responsavel ?? '—'; }

    // ================== KR List ==================
    async function loadKRs(){
      const cont = $('#krContainer');
      cont.innerHTML = `<div class="chip"><i class="fa-solid fa-circle-notch fa-spin"></i> Carregando KRs...</div>`;
      const res = await fetch(`${SCRIPT}?ajax=load_krs&id_objetivo=${idObjetivo}`);
      const data = await res.json();
      if(!data.success){
        cont.innerHTML = `<div class="chip" style="background:#5b1b1b;color:#ffe4e6;border-color:#7a1020"><i class="fa-solid fa-triangle-exclamation"></i> Erro ao carregar</div>`;
        return;
      }

      cont.innerHTML = '';
      data.krs.forEach(kr=>{
        const id = kr.id_kr;
        cont.insertAdjacentHTML('beforeend', `
          <article class="kr-card" data-id="${id}">
            <div class="kr-head">
              <div>
                <div class="kr-title"><i class="fa-solid fa-flag"></i> KR${kr.key_result_num ? ' '+kr.key_result_num : ''}: ${escapeHtml(truncate(kr.descricao||'', 160))}</div>
                <div class="meta-line">
                  <span class="meta-pill" title="Status"><i class="fa-solid fa-clipboard-check"></i>${escapeHtml(kr.status||'—')}</span>
                  <span class="meta-pill" title="Responsável do KR"><i class="fa-regular fa-user"></i>${escapeHtml(respLabel(kr))}</span>
                  <span class="meta-pill" title="Farol">${badgeFarol(kr.farol)}</span>
                  <span class="meta-pill" title="Meta"><i class="fa-solid fa-bullseye"></i>${fmtNum(kr.meta)} ${escapeHtml(kr.unidade_medida||'')}</span>
                  <span class="meta-pill" title="Baseline"><i class="fa-solid fa-gauge"></i>${fmtNum(kr.baseline)} ${escapeHtml(kr.unidade_medida||'')}</span>
                  <span class="meta-pill" title="Frequência de apontamento"><i class="fa-solid fa-clock-rotate-left"></i>${escapeHtml(kr.tipo_frequencia_milestone||'—')}</span>
                </div>
              </div>
              <div class="kr-actions">
                <button class="btn btn-gold btn-sm" data-act="apont" data-id="${id}"><i class="fa-regular fa-pen-to-square"></i> Novo apontamento</button>
                <button class="btn btn-outline btn-sm" data-act="nova" data-id="${id}"><i class="fa-solid fa-screwdriver-wrench"></i> Incluir iniciativa</button>
                <button class="kr-toggle gold" title="Expandir" data-act="toggle" data-id="${id}">
                  <i class="fa-solid fa-chevron-down"></i>
                </button>
              </div>
            </div>
            <div class="kr-body">
              ${renderTabs(id)}
            </div>
          </article>
        `);
      });
    }

    function renderTabs(id){
      return `
        <div class="tabs" data-tabs="${id}">
          <button class="tab active" data-tab="resumo-${id}"><i class="fa-solid fa-chart-line"></i> Resumo</button>
          <button class="tab" data-tab="ms-${id}"><i class="fa-solid fa-flag-checkered"></i> Milestones & Apontamentos</button>
          <button class="tab" data-tab="ini-${id}"><i class="fa-solid fa-diagram-project"></i> Iniciativas</button>
          <button class="tab" data-tab="orc-${id}"><i class="fa-solid fa-coins"></i> Orçamento</button>
          <button class="tab" data-tab="log-${id}"><i class="fa-regular fa-comments"></i> Log & Discussões</button>
        </div>
        <div class="tabpane active" id="resumo-${id}">
          <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;">
            <div><canvas id="chart_${id}" height="110"></canvas></div>
            <div class="kpi">
              <div class="kpi-head"><span>Iniciativas do KR</span><div class="kpi-icon"><i class="fa-solid fa-rocket"></i></div></div>
              <div class="kpi-value" id="kpi_ini_${id}">—</div>
              <div class="kpi-head" style="margin-top:10px"><span>Orçamento do KR</span><div class="kpi-icon money"><i class="fa-solid fa-coins"></i></div></div>
              <div class="kpi-sub"><strong>Aprovado:</strong> <span id="orc_aprov_${id}">—</span></div>
              <div class="kpi-sub"><strong>Realizado:</strong> <span id="orc_real_${id}">—</span></div>
              <div class="kpi-sub"><strong>Saldo:</strong> <span id="orc_saldo_${id}">—</span></div>
              <hr style="border-color:#1f2a3a; margin:10px 0; opacity:.7">
              <div class="kpi-head"><span>Próximo milestone</span><div class="kpi-icon success"><i class="fa-regular fa-calendar-check"></i></div></div>
              <div class="kpi-sub" id="prox_ms_${id}">—</div>
            </div>
          </div>
        </div>
        <div class="tabpane" id="ms-${id}">
          <table class="table">
            <thead>
              <tr>
                <th><i class="th-ico fa-regular fa-calendar-days"></i>Data</th>
                <th><i class="th-ico fa-solid fa-bullseye"></i>Esperado</th>
                <th><i class="th-ico fa-solid fa-chart-line"></i>Realizado</th>
                <th><i class="th-ico fa-solid fa-plus-minus"></i>Δ</th>
                <th><i class="th-ico fa-regular fa-file-lines"></i>Data evidência</th>
              </tr>
            </thead>
            <tbody id="tb_ms_${id}"><tr><td colspan="5">Carregando...</td></tr></tbody>
          </table>
        </div>
        <div class="tabpane" id="ini-${id}">
          <div style="display:flex; justify-content:flex-end; margin-bottom:8px;">
            <button class="btn btn-outline"><i class="fa-solid fa-table-columns"></i> Ver Kanban</button>
          </div>
          <table class="table">
            <thead>
              <tr>
                <th><i class="th-ico fa-solid fa-hashtag"></i>#</th>
                <th><i class="th-ico fa-regular fa-rectangle-list"></i>Descrição</th>
                <th><i class="th-ico fa-regular fa-user"></i>Responsável</th>
                <th><i class="th-ico fa-solid fa-clipboard-check"></i>Status</th>
                <th><i class="th-ico fa-regular fa-calendar-days"></i>Prazo</th>
                <th style="text-align:right"><i class="th-ico fa-solid fa-sack-dollar"></i>Aprovado</th>
                <th style="text-align:right"><i class="th-ico fa-solid fa-wallet"></i>Realizado</th>
                <th style="text-align:right"><i class="th-ico fa-solid fa-scale-balanced"></i>Saldo</th>
                <th style="text-align:right"><i class="th-ico fa-solid fa-wrench"></i>Ações</th>
              </tr>
            </thead>
            <tbody id="tb_ini_${id}"><tr><td colspan="9">Carregando...</td></tr></tbody>
          </table>
        </div>
        <div class="tabpane" id="orc-${id}">
          <div class="chip"><i class="fa-solid fa-sack-dollar"></i> Aprovado: <span id="orc2_aprov_${id}" style="font-weight:800;margin-left:6px">—</span></div>
          <div class="chip"><i class="fa-solid fa-wallet"></i> Realizado: <span id="orc2_real_${id}" style="font-weight:800;margin-left:6px">—</span></div>
          <div class="chip"><i class="fa-solid fa-scale-balanced"></i> Saldo: <span id="orc2_saldo_${id}" style="font-weight:800;margin-left:6px">—</span></div>
        </div>
        <div class="tabpane" id="log-${id}">
          <div class="chip"><i class="fa-regular fa-comments"></i> Conecte aqui seu feed/timeline.</div>
        </div>
      `;
    }

    // Delegação: toggle, tabs, botões
    document.addEventListener('click', async (e)=>{
      const btnT = e.target.closest('[data-act="toggle"]');
      if (btnT){
        const card = e.target.closest('.kr-card');
        const id = btnT.getAttribute('data-id');
        const willOpen = !card.classList.contains('open');
        if (willOpen){ card.classList.add('open'); await loadKrDetail(id); await loadIniciativas(id); }
        else { card.classList.remove('open'); }
        return;
      }
      const tabBtn = e.target.closest('.tab');
      if (tabBtn){
        const target = tabBtn.getAttribute('data-tab');
        const wrap = tabBtn.parentElement;
        $$('.tab', wrap).forEach(b=>b.classList.remove('active')); tabBtn.classList.add('active');
        const body = wrap.parentElement;
        $$('.tabpane', body).forEach(p=>p.classList.remove('active')); $('#'+target, body).classList.add('active'); return;
      }
      const btnNova = e.target.closest('[data-act="nova"]');
      if (btnNova){ $('#ni_id_kr').value = btnNova.getAttribute('data-id'); toggleDrawer('#drawerNovaIni', true); return; }
      const btnAp = e.target.closest('[data-act="apont"]');
      if (btnAp){ toast('Conecte este botão ao fluxo de apontamentos.', false); return; }
      const btnDesp = e.target.closest('button[data-act="despesa"]');
      if (btnDesp){ $('#desp_id_orcamento').value = btnDesp.getAttribute('data-id'); toggleDrawer('#drawerDespesa', true); return; }
    });

    // Detalhe KR
    const charts = {};
    async function loadKrDetail(id){
      const res = await fetch(`${SCRIPT}?ajax=kr_detail&id_kr=${encodeURIComponent(id)}`);
      const data = await res.json();
      if(!data.success){ toast('Erro ao carregar KR', false); return; }

      const ctx = document.getElementById(`chart_${id}`);
      if(ctx){
        if(charts[id]) charts[id].destroy();
        charts[id] = new Chart(ctx, {
          type:'line',
          data:{ labels:data.chart.labels||[], datasets:[
            { label:'Esperado', data:data.chart.esperado||[], tension:.3 },
            { label:'Realizado', data:data.chart.real||[], tension:.3 }
          ]},
          options:{ responsive:true, plugins:{legend:{display:true}}, scales:{y:{beginAtZero:true}} }
        });
      }

      setText(`kpi_ini_${id}`, data.agregados.iniciativas ?? '—');
      setText(`orc_aprov_${id}`, fmtBRL(data.agregados.orcamento.aprovado ?? 0));
      setText(`orc_real_${id}`,  fmtBRL(data.agregados.orcamento.realizado ?? 0));
      setText(`orc_saldo_${id}`, fmtBRL(data.agregados.orcamento.saldo ?? 0));
      setText(`orc2_aprov_${id}`, fmtBRL(data.agregados.orcamento.aprovado ?? 0));
      setText(`orc2_real_${id}`,  fmtBRL(data.agregados.orcamento.realizado ?? 0));
      setText(`orc2_saldo_${id}`, fmtBRL(data.agregados.orcamento.saldo ?? 0));

      const p = data.agregados.proximo_milestone;
      if(p && p.data_prevista){
        const delta = (p.valor_real ?? null) !== null ? (p.valor_real - (p.valor_esperado ?? 0)) : null;
        const deltaTxt = delta !== null ? ` (Δ ${fmtNum(delta)})` : '';
        setText(`prox_ms_${id}`, `${p.data_prevista} • Esperado: ${fmtNum(p.valor_esperado)} • Realizado: ${p.valor_real!==null?fmtNum(p.valor_real):'—'}${deltaTxt}`);
      } else { setText(`prox_ms_${id}`, '—'); }

      // Milestones
      const tb = document.getElementById(`tb_ms_${id}`);
      if(tb){
        tb.innerHTML = '';
        const arr = data.milestones || [];
        if(!arr.length){
          tb.innerHTML = `<tr><td colspan="5" style="color:#9aa4b2">Sem milestones cadastrados.</td></tr>`;
        } else {
          arr.forEach(m=>{
            const delta = (m.valor_real ?? null) !== null ? (m.valor_real - (m.valor_esperado ?? 0)) : null;
            tb.insertAdjacentHTML('beforeend', `
              <tr>
                <td>${escapeHtml(m.data_prevista || '—')}</td>
                <td>${fmtNum(m.valor_esperado)}</td>
                <td>${m.valor_real!==null?fmtNum(m.valor_real):'—'}</td>
                <td>${delta!==null?fmtNum(delta):'—'}</td>
                <td>${escapeHtml(m.dt_evidencia || '—')}</td>
              </tr>
            `);
          });
        }
      }
    }
    function setText(id, txt){ const el=document.getElementById(id); if(el) el.textContent=txt; }

    // Iniciativas
    async function loadIniciativas(id){
      const res = await fetch(`${SCRIPT}?ajax=iniciativas_list&id_kr=${encodeURIComponent(id)}`);
      const data = await res.json();
      const tb = document.getElementById(`tb_ini_${id}`);
      if(!tb) return;
      tb.innerHTML = '';
      if(!data.success || !data.iniciativas?.length){
        tb.innerHTML = `<tr><td colspan="9" style="color:#9aa4b2">Sem iniciativas.</td></tr>`; return;
      }
      data.iniciativas.forEach(ini=>{
        const actions = ini.orcamento?.id_orcamento
          ? `<button class="btn btn-outline btn-sm" data-act="despesa" data-id="${ini.orcamento.id_orcamento}"><i class="fa-solid fa-file-invoice-dollar"></i> Lançar despesa</button>`
          : `<span class="chip"><i class="fa-regular fa-circle"></i> Sem orçamento</span>`;
        tb.insertAdjacentHTML('beforeend', `
          <tr>
            <td>${ini.num_iniciativa}</td>
            <td style="color:#d1d5db">${escapeHtml(ini.descricao||'')}</td>
            <td>${escapeHtml(ini.responsavel||'—')}</td>
            <td>${escapeHtml(ini.status||'—')}</td>
            <td>${escapeHtml(ini.dt_prazo||'—')}</td>
            <td style="text-align:right">${fmtBRL(ini.orcamento?.aprovado||0)}</td>
            <td style="text-align:right">${fmtBRL(ini.orcamento?.realizado||0)}</td>
            <td style="text-align:right">${fmtBRL(ini.orcamento?.saldo||0)}</td>
            <td style="text-align:right">${actions}</td>
          </tr>
        `);
      });
    }

    // Forms
    $('#ni_sw_orc')?.addEventListener('change', e=> $('#ni_orc_group').style.display = e.target.checked ? 'block':'none');

    $('#btnSalvarIni')?.addEventListener('click', async ()=>{
      const fd = new FormData($('#formNovaIniciativa'));
      const res = await fetch(`${SCRIPT}?ajax=create_iniciativa`, { method:'POST', body:fd });
      const data = await res.json();
      if(!data.success){ toast(data.error||'Erro ao salvar', false); return; }
      toast('Iniciativa criada com sucesso!'); toggleDrawer('#drawerNovaIni', false);
      await loadIniciativas($('#ni_id_kr').value); $('#formNovaIniciativa').reset(); $('#ni_orc_group').style.display='none';
    });

    $('#btnSalvarDesp')?.addEventListener('click', async ()=>{
      const fd = new FormData($('#formDespesa'));
      const res = await fetch(`${SCRIPT}?ajax=create_despesa`, { method:'POST', body:fd });
      const data = await res.json();
      if(!data.success){ toast(data.error||'Erro ao lançar', false); return; }
      toast('Despesa lançada com sucesso!'); toggleDrawer('#drawerDespesa', false);
      const open = document.querySelector('.kr-card.open'); if(open){ const id=open.getAttribute('data-id'); await loadKrDetail(id); await loadIniciativas(id); }
      $('#formDespesa').reset();
    });

    // Filtros
    $('#btnClearFilters')?.addEventListener('click', ()=> $('#chipsFilters').innerHTML='');

    // Ajuste com chat lateral
    const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
    const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
    function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
    function isOpen(el){ const st=getComputedStyle(el); const vis=st.display!=='none'&&st.visibility!=='hidden'; const w=el.offsetWidth; return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show'); }
    function updateChatWidth(){ const el=findChatEl(); const w=(el && isOpen(el))?el.offsetWidth:0; document.documentElement.style.setProperty('--chat-w',(w||0)+'px'); }
    function setupChatObservers(){ const chat=findChatEl(); if(!chat) return; const mo=new MutationObserver(()=>updateChatWidth()); mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']}); window.addEventListener('resize',updateChatWidth); TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,200)))); updateChatWidth(); }

    document.addEventListener('DOMContentLoaded', ()=>{
      loadKRs(); setupChatObservers();
      const moBody = new MutationObserver(()=>{ if(findChatEl()){ setupChatObservers(); moBody.disconnect(); } });
      moBody.observe(document.body,{childList:true,subtree:true});
    });
  </script>
</body>
</html>
