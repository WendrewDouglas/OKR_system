<?php
// views/objetivos_editar.php — Edição de Objetivo (com justificativa obrigatória e reenvio para aprovação)

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (empty($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

/* ===================== ENDPOINTS AJAX ===================== */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');

  try {
    $pdo = new PDO(
      "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
      DB_USER, DB_PASS,
      [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC ]
    );
  } catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Erro de conexão']);
    exit;
  }

  if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Não autorizado']);
    exit;
  }

  $action = $_GET['action'] ?? '';
  $userId = (int)$_SESSION['user_id'];

  // company do usuário
  $st = $pdo->prepare("SELECT id_company, primeiro_nome, ultimo_nome FROM usuarios WHERE id_user=:u LIMIT 1");
  $st->execute([':u'=>$userId]);
  $uRow = $st->fetch();
  if (!$uRow) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Usuário inválido']);
    exit;
  }
  $companyId = (int)($uRow['id_company'] ?? 0);
  $userName  = trim(($uRow['primeiro_nome'] ?? '').' '.($uRow['ultimo_nome'] ?? ''));

  if ($action === 'update' && $_SERVER['REQUEST_METHOD']==='POST') {
    try {
      // CSRF
      $csrf = $_POST['csrf_token'] ?? '';
      if (empty($csrf) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'CSRF inválido']);
        exit;
      }

      $id = $_POST['id_objetivo'] ?? '';
      if (!$id) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'ID do objetivo ausente']);
        exit;
      }

      // Carrega objetivo e valida escopo da company
      $q = $pdo->prepare("SELECT * FROM objetivos WHERE id_objetivo=:id AND id_company=:c LIMIT 1");
      $q->execute([':id'=>$id, ':c'=>$companyId]);
      $obj = $q->fetch();
      if (!$obj) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'Objetivo não encontrado']);
        exit;
      }

      // Inputs
      $nome          = trim((string)($_POST['nome_objetivo'] ?? ''));
      $tipo          = trim((string)($_POST['tipo_objetivo'] ?? ''));
      $pilar         = trim((string)($_POST['pilar_bsc'] ?? ''));
      $status        = trim((string)($_POST['status'] ?? ''));            // <-- NOVO: status do objetivo
      $responsavelCS = trim((string)($_POST['responsavel'] ?? ''));       // CSV com ids (usaremos só 1)
      $obsNovo       = trim((string)($_POST['observacoes'] ?? ''));
      $qualidade     = trim((string)($_POST['qualidade'] ?? ''));         // opcional
      $justEdit      = trim((string)($_POST['justificativa_edicao'] ?? ''));

      // Período (sempre enviado via hidden, calculado no JS; em "personalizado" vem também os months)
      $periodo_inicio = trim((string)($_POST['periodo_inicio'] ?? '')); // YYYY-MM-DD
      $periodo_fim    = trim((string)($_POST['periodo_fim'] ?? ''));

      if ($nome === '' || $tipo === '' || $pilar === '' || $status === '' || $responsavelCS === '') {
        http_response_code(422);
        echo json_encode(['success'=>false,'error'=>'Preencha os campos obrigatórios.']);
        exit;
      }
      if ($justEdit === '') {
        http_response_code(422);
        echo json_encode(['success'=>false,'error'=>'A justificativa de edição é obrigatória.']);
        exit;
      }

      // Valida o status no domínio (FK: objetivos.status -> dom_status_kr.id_status)
      $chkStatus = $pdo->prepare("SELECT 1 FROM dom_status_kr WHERE id_status = ? LIMIT 1");
      $chkStatus->execute([$status]);
      if (!$chkStatus->fetchColumn()) {
        http_response_code(422);
        echo json_encode(['success'=>false,'error'=>'Status inválido.']);
        exit;
      }

      // Sanitiza responsáveis: mantém apenas usuários da mesma company e força exatamente 1
      $ids = array_values(array_filter(array_map('intval', explode(',', $responsavelCS))));
      if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $chk = $pdo->prepare("SELECT id_user FROM usuarios WHERE id_company=? AND id_user IN ($in)");
        $params = array_merge([$companyId], $ids);
        $chk->execute($params);
        $validos = array_column($chk->fetchAll(), 'id_user');
        $ids = array_values(array_intersect($ids, $validos));
      }
      if (!$ids) {
        http_response_code(422);
        echo json_encode(['success'=>false,'error'=>'Nenhum responsável válido da sua empresa foi informado.']);
        exit;
      }
      if (count($ids) !== 1) {
        http_response_code(422);
        echo json_encode(['success'=>false,'error'=>'Selecione exatamente 1 responsável para o objetivo.']);
        exit;
      }
      $dono = (string)$ids[0];        // usamos apenas um
      $responsavelCS = $dono;         // coerência: hidden guarda só o único id

      // Monta bloco de justificativa e atualiza observações (apêndice)
      $obsAnt = (string)($obj['observacoes'] ?? '');
      $stamp  = date('Y-m-d H:i');
      $bloco  = "\n\n---\n[Justificativa de edição em {$stamp} por {$userName}]\n{$justEdit}";
      $obsUpd = trim($obsAnt) !== '' ? ($obsAnt . $bloco) : ("[Histórico de Observações]\n{$bloco}");

      // === Igual ao salvar_objetivo.php ===
      function calcularDatasCiclo(string $tipo_ciclo, array $dados): array {
        $dt_inicio = ''; $dt_prazo = '';
        switch ($tipo_ciclo) {
          case 'anual':
            $y = (int)($dados['ciclo_anual_ano'] ?? 0);
            if ($y) { $dt_inicio="$y-01-01"; $dt_prazo="$y-12-31"; }
            break;
          case 'semestral':
            if (preg_match('/^S([12])\/(\d{4})$/', (string)($dados['ciclo_semestral'] ?? ''), $m)) {
              $s=(int)$m[1]; $y=(int)$m[2];
              $dt_inicio = $s===1 ? "$y-01-01" : "$y-07-01";
              $dt_prazo  = $s===1 ? "$y-06-30" : "$y-12-31";
            }
            break;
          case 'trimestral':
            if (preg_match('/^Q([1-4])\/(\d{4})$/', (string)($dados['ciclo_trimestral'] ?? ''), $m)) {
              $q=(int)$m[1]; $y=(int)$m[2];
              $map=[1=>['01-01','03-31'],2=>['04-01','06-30'],3=>['07-01','09-30'],4=>['10-01','12-31']];
              $dt_inicio="$y-{$map[$q][0]}"; $dt_prazo="$y-{$map[$q][1]}";
            }
            break;
          case 'bimestral':
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', (string)($dados['ciclo_bimestral'] ?? ''), $m)) {
              $m1=(int)$m[1]; $m2=(int)$m[2]; $y=(int)$m[3];
              $dt_inicio = sprintf('%04d-%02d-01', $y, $m1);
              $dt_prazo  = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $m2)));
            }
            break;
          case 'mensal':
            $mm=(int)($dados['ciclo_mensal_mes'] ?? 0);
            $yy=(int)($dados['ciclo_mensal_ano'] ?? 0);
            if ($mm && $yy) { $dt_inicio=sprintf('%04d-%02d-01',$yy,$mm); $dt_prazo=date('Y-m-t', strtotime("$yy-$mm-01")); }
            break;
          case 'personalizado':
            // em "personalizado" recebemos YYYY-MM, transformamos para 1º dia e último dia do mês
            $ini=(string)($dados['ciclo_pers_inicio'] ?? '');
            $fim=(string)($dados['ciclo_pers_fim'] ?? '');
            if ($ini && $fim) { $dt_inicio="$ini-01"; $dt_prazo=date('Y-m-t', strtotime("$fim-01")); }
            break;
        }
        return [$dt_inicio, $dt_prazo];
      }

      // Tipo do ciclo e rótulo "ciclo" (igual ao INSERT)
      $ciclo_tipo = trim((string)($_POST['ciclo_tipo'] ?? ''));
      $ciclo_detalhe = '';
      switch ($ciclo_tipo) {
        case 'anual':
          $ciclo_detalhe = (string)($_POST['ciclo_anual_ano'] ?? '');
          break;
        case 'semestral':
          $ciclo_detalhe = (string)($_POST['ciclo_semestral'] ?? '');
          break;
        case 'trimestral':
          $ciclo_detalhe = (string)($_POST['ciclo_trimestral'] ?? '');
          break;
        case 'bimestral':
          $ciclo_detalhe = (string)($_POST['ciclo_bimestral'] ?? '');
          break;
        case 'mensal':
          $mm = (string)($_POST['ciclo_mensal_mes'] ?? '');
          $yy = (string)($_POST['ciclo_mensal_ano'] ?? '');
          if ($mm && $yy) $ciclo_detalhe = "$mm/$yy";
          break;
        case 'personalizado':
          $ini = (string)($_POST['ciclo_pers_inicio'] ?? '');
          $fim = (string)($_POST['ciclo_pers_fim'] ?? '');
          if ($ini && $fim) $ciclo_detalhe = "$ini a $fim";
          break;
      }

      [$dt_inicio, $dt_prazo] = calcularDatasCiclo($ciclo_tipo, $_POST);
      // Fallback para o que veio do hidden (garantido via JS)
      if (!$dt_inicio || !$dt_prazo) {
        $dt_inicio = $_POST['periodo_inicio'] ?? null;
        $dt_prazo  = $_POST['periodo_fim'] ?? null;
      }

      // Transação
      $pdo->beginTransaction();

      $sql = "UPDATE objetivos
                SET descricao             = :nome,
                    tipo                  = :tipo,
                    pilar_bsc             = :pilar,
                    status                = :status,      -- NOVO
                    dono                  = :dono,
                    observacoes           = :obs,
                    qualidade             = COALESCE(:qualidade, qualidade),
                    status_aprovacao      = 'pendente',
                    aprovador             = NULL,
                    dt_aprovacao          = NULL,
                    comentarios_aprovacao = NULL,
                    tipo_ciclo            = :tipo_ciclo,
                    ciclo                 = :ciclo,
                    dt_inicio             = :dt_inicio,
                    dt_prazo              = :dt_prazo,
                    usuario_ult_alteracao = :user,
                    dt_ultima_atualizacao = NOW()
              WHERE id_objetivo = :id
                AND id_company   = :comp
              LIMIT 1";
      $st = $pdo->prepare($sql);
      $st->execute([
        ':nome'       => $nome,
        ':tipo'       => $tipo,
        ':pilar'      => $pilar,
        ':status'     => $status, // bind do novo campo
        ':dono'       => $dono,
        ':obs'        => ($obsUpd !== '' ? $obsUpd : null),
        ':qualidade'  => ($qualidade !== '' ? $qualidade : null),
        ':tipo_ciclo' => ($ciclo_tipo !== '' ? $ciclo_tipo : ($obj['tipo_ciclo'] ?? null)),
        ':ciclo'      => ($ciclo_detalhe !== '' ? $ciclo_detalhe : ($obj['ciclo'] ?? null)),
        ':dt_inicio'  => ($dt_inicio ?: ($obj['dt_inicio'] ?? null)),
        ':dt_prazo'   => ($dt_prazo  ?: ($obj['dt_prazo']  ?? null)),
        ':user'       => $userId,
        ':id'         => $id,
        ':comp'       => $companyId,
      ]);

      $pdo->commit();

      echo json_encode(['success'=>true, 'id_objetivo'=>$id, 'status_aprovacao'=>'pendente']);
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      http_response_code(500);
      echo json_encode(['success'=>false,'error'=>'Falha ao atualizar: '.$e->getMessage()]);
      exit;
    }
  }

  http_response_code(400);
  echo json_encode(['success'=>false,'error'=>'Ação inválida.']);
  exit;
}

/* ===================== RENDERIZAÇÃO DA PÁGINA ===================== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Conexão
try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC ]
  );
} catch (PDOException $e) {
  http_response_code(500);
  die("Erro ao conectar: ".$e->getMessage());
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// company do usuário
$userId = (int)$_SESSION['user_id'];
$st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=:u LIMIT 1");
$st->execute([':u'=>$userId]);
$companyId = (int)($st->fetchColumn() ?: 0);

// id do objetivo (GET)
$id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if ($id === '') {
  http_response_code(400);
  die('ID do objetivo não informado.');
}

// Carrega objetivo (escopo da company)
$q = $pdo->prepare("SELECT * FROM objetivos WHERE id_objetivo=:id AND id_company=:c LIMIT 1");
$q->execute([':id'=>$id, ':c'=>$companyId]);
$OBJ = $q->fetch();
if (!$OBJ) {
  http_response_code(404);
  die('Objetivo não encontrado ou sem permissão.');
}

// Listas
$users     = [];
$pilares   = $pdo->query("SELECT id_pilar, descricao_exibicao FROM dom_pilar_bsc ORDER BY ordem_pilar")->fetchAll();
$tipos     = $pdo->query("SELECT id_tipo,  descricao_exibicao FROM dom_tipo_objetivo ORDER BY descricao_exibicao")->fetchAll();
$ciclos    = $pdo->query("SELECT id_ciclo, nome_ciclo, descricao FROM dom_ciclos ORDER BY id_ciclo")->fetchAll();
$statuses  = $pdo->query("SELECT id_status, descricao_exibicao FROM dom_status_kr ORDER BY descricao_exibicao")->fetchAll(); // <-- NOVO

if ($companyId) {
  $st = $pdo->prepare("SELECT id_user, primeiro_nome, ultimo_nome FROM usuarios WHERE id_company=:c ORDER BY primeiro_nome");
  $st->execute([':c'=>$companyId]);
  $users = $st->fetchAll();
}

// Pré-preenchimentos (com fallbacks seguros)
$nome          = (string)($OBJ['nome_objetivo'] ?? '');
if ($nome === '' && !empty($OBJ['descricao'])) {
  $nome = (string)$OBJ['descricao'];
}
$tipoSel       = (string)($OBJ['tipo'] ?? '');
$pilarSel      = (string)($OBJ['pilar_bsc'] ?? '');
$statusSel     = (string)($OBJ['status'] ?? ''); // <-- NOVO

// CSV de responsáveis (pode estar vazio em bases antigas)
$respCSV       = trim((string)($OBJ['responsavel'] ?? ''));
if ($respCSV === '' && !empty($OBJ['dono'])) {
  $respCSV = (string)$OBJ['dono'];
}

// Observações/qualidade/período (como já estava)
$obsExistente  = (string)($OBJ['observacoes'] ?? '');
$qualidade     = (string)($OBJ['qualidade'] ?? '');
$periodo_ini   = (string)($OBJ['dt_inicio'] ?? '');
$periodo_fim   = (string)($OBJ['dt_prazo']  ?? '');

// Heurística de UI
$cicloDefault  = 'trimestral';

// Tema (uma vez)
if (!defined('PB_THEME_LINK_EMITTED')) {
  define('PB_THEME_LINK_EMITTED', true);
  echo '<link rel="stylesheet" href="/OKR_system/assets/company_theme.php">';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Editar Objetivo – OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    body{ background:#fff !important; color:#111; }
    :root{ --chat-w:0px; }
    .content{ background:transparent; }
    main.eobj{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; }

    :root{
      --bg-soft:#171b21; --card: var(--bg1, #222222); --muted:#a6adbb; --text:#eaeef6;
      --gold:var(--bg2, #F1C40F); --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20); --btn:#0e131a;
    }

    .crumbs{ color:#333; font-size:.9rem; display:flex; align-items:center; gap:6px; }
    .crumbs a{ color:#0c4a6e; text-decoration:none; }
    .crumbs .sep{ opacity:.5; margin:0 2px; }
    .crumbs i{ opacity:.85; }

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

    .form-card{
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:16px; padding:16px;
      box-shadow:var(--shadow); color:var(--text);
    }
    .form-card h2{ font-size:1.05rem; margin:0 0 12px; letter-spacing:.2px; color:#e5e7eb; }

    .grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .grid-3{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
    @media (max-width:900px){ .grid-2,.grid-3{ grid-template-columns:1fr; }

    }

    label{ display:block; margin-bottom:6px; color:#cbd5e1; font-size:.9rem; }
    input[type="text"], input[type="month"], textarea, select{
      width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none;
    }
    textarea{ resize:vertical; min-height:90px; }

    .multi-select-container { position:relative; }
    .chips-input { display:flex; flex-wrap:wrap; gap:6px; padding:6px; background:#0c1118; border:1px solid #1f2635; border-radius:10px; }
    .chips-input-field { flex:1; border:none; outline:none; min-width:160px; background:transparent; color:#e5e7eb; padding:6px; }
    .chip { background:#101626; border:1px solid #1f2635; border-radius:999px; padding:4px 10px; display:flex; align-items:center; gap:8px; color:#d1d5db; font-size:.88rem; }
    .remove-chip { cursor:pointer; font-weight:900; opacity:.8; }
    .dropdown-list { position:absolute; top:calc(100% + 6px); left:0; width:100%; max-height:240px; overflow:auto; background:#0b1020; border:1px solid #223047; border-radius:10px; z-index:1000; }
    .dropdown-list ul { list-style:none; margin:0; padding:6px; }
    .dropdown-list li { padding:8px 10px; cursor:pointer; color:#e5e7eb; border-radius:8px; }
    .dropdown-list li:hover { background:#101626; }
    .d-none { display:none; }
    .warning-text { color:#fbbf24; font-size:.85rem; margin-top:6px; }

    .helper{ color:#9aa4b2; font-size:.85rem; }
    .save-row{ display:flex; justify-content:center; margin-top:16px; gap:10px; flex-wrap:wrap; }
    .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:10px 14px; border-radius:12px; font-weight:700; }
    .btn:hover{ border-color:#2a3342; transform:translateY(-1px); transition:.15s; }
    .btn-primary{ background:#1f2937; }

    .overlay{ position:fixed; inset:0; display:none; place-items:center; background:rgba(0,0,0,.55); z-index:3000; }
    .overlay.show{ display:grid; }
    .ai-card{ width:min(720px,94vw); background:#0b1020; color:#e6e9f2; border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.35); padding:18px; position:relative; overflow:hidden; border:1px solid #223047; }
    .ai-card::after{ content:""; position:absolute; inset:0;
      background: radial-gradient(800px 260px at 10% -20%, rgba(64,140,255,.18), transparent 60%),
                  radial-gradient(600px 200px at 100% 0%, rgba(0,196,204,.12), transparent 60%); pointer-events:none; }
    .ai-header{ display:flex; align-items:center; gap:12px; margin-bottom:10px; }
    .ai-avatar{ width:44px; height:44px; border-radius:50%; display:grid; place-items:center; color:#fff; font-weight:800;
      background:conic-gradient(from 180deg at 50% 50%, #3b82f6, #06b6d4, #8b5cf6, #3b82f6); box-shadow:0 6px 18px rgba(59,130,246,.35); }
    .ai-title{ font-size:.95rem; opacity:.9; }
    .ai-subtle{ font-size:.85rem; opacity:.7; }
    .ai-bubble{ background:#111833; border:1px solid rgba(255,255,255,.06); border-radius:14px; padding:16px; margin:8px 0 14px; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="eobj">
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <a href="/OKR_system/meus_okrs"><i class="fa-solid fa-bullseye"></i> Meus OKRs</a>
        <span class="sep">/</span>
        <span><i class="fa-regular fa-pen-to-square"></i> Editar Objetivo</span>
      </div>

      <section class="head-card">
        <h1 class="head-title"><i class="fa-solid fa-bullseye"></i>Editar Objetivo</h1>
        <div class="head-meta">
          <span class="pill"><i class="fa-solid fa-circle-info"></i>Atualize os dados e salve. Uma justificativa será solicitada para reenvio à aprovação.</span>
        </div>
      </section>

      <section class="form-card">
        <h2><i class="fa-regular fa-rectangle-list"></i> Dados do Objetivo</h2>

        <form id="editForm" action="?ajax=1&action=update" method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="id_objetivo" value="<?= h($id) ?>">
          <input type="hidden" id="qualidade" name="qualidade" value="<?= h($qualidade) ?>">

          <!-- Sempre enviados ao backend (preenchidos via JS) -->
          <input type="hidden" id="periodo_inicio" name="periodo_inicio" value="<?= h($periodo_ini) ?>">
          <input type="hidden" id="periodo_fim"    name="periodo_fim"    value="<?= h($periodo_fim) ?>">

          <div>
            <label for="nome_objetivo"><i class="fa-regular fa-pen-to-square"></i> Nome do Objetivo <span class="helper">(obrigatório)</span></label>
            <input type="text" id="nome_objetivo" name="nome_objetivo" required value="<?= h($nome) ?>">
          </div>

          <div class="grid-2" style="margin-top:12px;">
            <div>
              <label for="tipo_objetivo"><i class="fa-regular fa-square-check"></i> Tipo de Objetivo <span class="helper">(obrigatório)</span></label>
              <select id="tipo_objetivo" name="tipo_objetivo" required>
                <option value="">Selecione...</option>
                <?php foreach ($tipos as $t): ?>
                  <option value="<?= h($t['id_tipo']) ?>" <?= $tipoSel===$t['id_tipo']?'selected':'' ?>>
                    <?= h($t['descricao_exibicao']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="pilar_bsc"><i class="fa-solid fa-layer-group"></i> Pilar BSC <span class="helper">(obrigatório)</span></label>
              <select id="pilar_bsc" name="pilar_bsc" required>
                <option value="">Selecione...</option>
                <?php foreach ($pilares as $p): ?>
                  <option value="<?= h($p['id_pilar']) ?>" <?= $pilarSel===$p['id_pilar']?'selected':'' ?>>
                    <?= h($p['descricao_exibicao']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- CICLO -->
          <div class="grid-2 align-center" style="margin-top:12px;">
            <div>
              <label for="ciclo_tipo"><i class="fa-regular fa-calendar-days"></i> Ciclo <span class="helper">(opcional)</span></label>
              <select id="ciclo_tipo" name="ciclo_tipo">
                <?php foreach ($ciclos as $c): ?>
                  <option value="<?= h($c['nome_ciclo']) ?>" <?= $c['nome_ciclo']===$cicloDefault ? 'selected' : '' ?>>
                    <?= h($c['descricao']) ?>
                  </option>
                <?php endforeach; ?>
                <option value="personalizado" <?= ($periodo_ini && $periodo_fim)?'selected':'' ?>>Personalizado</option>
              </select>
            </div>

            <!-- Detalhes do ciclo (SEM mostrar datas) -->
            <div id="ciclo_detalhe_wrapper" role="group" aria-labelledby="lblPeriodo">
              <label id="lblPeriodo"><i class="fa-regular fa-calendar"></i> Detalhe do Ciclo</label>

              <div id="ciclo_detalhe_anual" class="detalhe d-none">
                <select id="ciclo_anual_ano" name="ciclo_anual_ano"></select>
              </div>

              <div id="ciclo_detalhe_semestral" class="detalhe d-none">
                <select id="ciclo_semestral" name="ciclo_semestral"></select>
              </div>

              <div id="ciclo_detalhe_trimestral" class="detalhe">
                <select id="ciclo_trimestral" name="ciclo_trimestral"></select>
              </div>

              <div id="ciclo_detalhe_bimestral" class="detalhe d-none">
                <select id="ciclo_bimestral" name="ciclo_bimestral"></select>
              </div>

              <div id="ciclo_detalhe_mensal" class="detalhe d-none">
                <div class="grid-2">
                  <select id="ciclo_mensal_mes" name="ciclo_mensal_mes"></select>
                  <select id="ciclo_mensal_ano" name="ciclo_mensal_ano"></select>
                </div>
              </div>
            </div>
          </div>

          <!-- PERÍODO PERSONALIZADO (só aparece quando ciclo = personalizado) -->
          <div id="periodo_personalizado_box" class="grid-2 d-none" style="margin-top:12px;">
            <div>
              <label for="ciclo_pers_inicio">
                <i class="fa-regular fa-calendar-plus"></i> Início do Período
                <span class="helper">(mês/ano)</span>
              </label>
              <input type="month" id="ciclo_pers_inicio" name="ciclo_pers_inicio"
                     value="<?= $periodo_ini ? h(substr($periodo_ini,0,7)) : '' ?>">
            </div>
            <div>
              <label for="ciclo_pers_fim">
                <i class="fa-regular fa-calendar-check"></i> Fim do Período
                <span class="helper">(mês/ano)</span>
              </label>
              <input type="month" id="ciclo_pers_fim" name="ciclo_pers_fim"
                     value="<?= $periodo_fim ? h(substr($periodo_fim,0,7)) : '' ?>">
            </div>
          </div>

          <!-- STATUS + RESPONSÁVEL (único) -->
          <div class="grid-2" style="margin-top:12px;">
            <!-- STATUS -->
            <div>
              <label for="status"><i class="fa-regular fa-flag"></i> Status do Objetivo <span class="helper">(obrigatório)</span></label>
              <select id="status" name="status" required>
                <option value="">Selecione...</option>
                <?php foreach($statuses as $s): ?>
                  <option value="<?= h($s['id_status']) ?>" <?= $statusSel===$s['id_status'] ? 'selected' : '' ?>>
                    <?= h($s['descricao_exibicao']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- RESPONSÁVEL (APENAS 1) -->
            <div>
              <label><i class="fa-regular fa-user"></i> Responsável <span class="helper">(obrigatório)</span></label>
              <div class="multi-select-container">
                <div class="chips-input" id="responsavel_container">
                  <input type="text" id="responsavel_input" class="chips-input-field" placeholder="Clique para selecionar...">
                </div>
                <div class="dropdown-list d-none" id="responsavel_list">
                  <ul>
                    <?php foreach($users as $u): ?>
                      <li data-id="<?= (int)$u['id_user'] ?>"><?= h($u['primeiro_nome'].' '.$u['ultimo_nome']) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
              <input type="hidden" id="responsavel" name="responsavel" value="<?= h($respCSV) ?>">
              <small id="responsavel_warning" class="warning-text d-none">⚠️ Selecione apenas um responsável.</small>
            </div>
          </div>

          <div style="margin-top:12px;">
            <label for="observacoes"><i class="fa-regular fa-note-sticky"></i> Observações (existentes)</label>
            <textarea id="observacoes" name="observacoes" rows="4" placeholder="Observações gerais (opcional)"><?= h($obsExistente) ?></textarea>
            <small class="helper">A sua justificativa de alteração será solicitada após clicar em salvar e será anexada automaticamente abaixo, com data, hora e autor.</small>
          </div>

          <div class="save-row">
            <button type="button" id="btnSalvar" class="btn btn-primary"><i class="fa-regular fa-floppy-disk"></i> Salvar Alterações</button>
            <a href="/OKR_system/meus_okrs" class="btn"><i class="fa-regular fa-circle-left"></i> Voltar</a>
          </div>
        </form>
      </section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <!-- Modal Justificativa de Edição -->
  <div id="justifyOverlay" class="overlay" aria-hidden="true">
    <div class="ai-card" role="dialog" aria-modal="true">
      <div class="ai-header">
        <div class="ai-avatar">OKR</div>
        <div>
          <div class="ai-title">Justificativa da Edição</div>
          <div class="ai-subtle">Explique de forma objetiva o motivo das alterações. O aprovador verá este texto.</div>
        </div>
      </div>
      <div class="ai-bubble">
        <label for="justificativa_edicao" style="display:block;margin-bottom:6px;color:#cbd5e1;font-size:.9rem;">
          <i class="fa-regular fa-comment"></i> Justificativa <span class="helper">(obrigatório)</span>
        </label>
        <textarea id="justificativa_edicao" rows="5" style="width:100%;background:#0c1118;color:#e5e7eb;border:1px solid #1f2635;border-radius:10px;padding:10px;"></textarea>
      </div>
      <div class="save-row" style="margin-top:0;">
        <button id="cancelJust" class="btn"><i class="fa-regular fa-circle-xmark"></i> Cancelar</button>
        <button id="confirmJust" class="btn btn-primary"><i class="fa-regular fa-paper-plane"></i> Confirmar e Enviar para Aprovação</button>
      </div>
    </div>
  </div>

  <!-- Mensagem de sucesso -->
  <div id="successOverlay" class="overlay" aria-hidden="true">
    <div class="ai-card" role="alertdialog" aria-modal="true">
      <div class="ai-header">
        <div class="ai-avatar">OKR</div>
        <div>
          <div class="ai-title">Alterações salvas ✅</div>
          <div class="ai-subtle">O objetivo foi reenviado para aprovação (status: pendente).</div>
        </div>
      </div>
      <div class="ai-bubble">
        <div class="ai-subtle" id="successText" style="font-size:1rem;opacity:.9">
          Tudo certo. Você será notificado quando o aprovador decidir.
        </div>
      </div>
      <div class="save-row" style="margin-top:0;">
        <a href="/OKR_system/meus_okrs" class="btn btn-primary">Ir para Meus OKRs</a>
      </div>
    </div>
  </div>

  <!-- Loading simples -->
  <div id="loadingOverlay" class="overlay" aria-hidden="true">
    <div class="ai-card" role="dialog" aria-live="polite">
      <div class="ai-header">
        <div class="ai-avatar">...</div>
        <div>
          <div class="ai-title">Salvando…</div>
          <div class="ai-subtle">Aplicando alterações e reenviando para aprovação.</div>
        </div>
      </div>
      <div class="ai-bubble" style="display:flex;align-items:center;gap:10px;">
        <i class="fa-solid fa-spinner fa-spin"></i>
        <span>Aguarde um instante…</span>
      </div>
    </div>
  </div>

  <script>
    // Utilidades
    const $  = (s, r=document)=>r.querySelector(s);
    const $$ = (s, r=document)=>Array.from(r.querySelectorAll(s));
    const show = el => { el?.classList.add('show'); el?.setAttribute('aria-hidden','false'); };
    const hide = el => { el?.classList.remove('show'); el?.setAttribute('aria-hidden','true'); };

    function pad2(n){ return String(n).padStart(2,'0'); }
    function lastDayOfMonth(y,m){ return new Date(y, m+1, 0).getDate(); }
    function toISO(d){ return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`; }

    function computePeriodFromCycle(){
      const tipo = ($('#ciclo_tipo')?.value || 'trimestral').toLowerCase();
      const nowY = new Date().getFullYear();
      let start, end;

      if (tipo === 'anual') {
        const y = parseInt($('#ciclo_anual_ano')?.value || nowY, 10);
        start = new Date(y, 0, 1);
        end   = new Date(y, 11, lastDayOfMonth(y,11));
      } else if (tipo === 'semestral') {
        const v = $('#ciclo_semestral')?.value || '';
        const m = v.match(/^S([12])\/(\d{4})$/);
        if (m) {
          const s = m[1] === '1' ? 0 : 6;
          const y = parseInt(m[2],10);
          start = new Date(y, s, 1);
          const endMonth = s === 0 ? 5 : 11;
          end   = new Date(y, endMonth, lastDayOfMonth(y,endMonth));
        }
      } else if (tipo === 'trimestral') {
        const v = $('#ciclo_trimestral')?.value || '';
        theMatch = v.match(/^Q([1-4])\/(\d{4})$/);
        if (theMatch) {
          const q = parseInt(theMatch[1],10);
          const y = parseInt(theMatch[2],10);
          const sm = (q-1)*3;
          const em = sm+2;
          start = new Date(y, sm, 1);
          end   = new Date(y, em, lastDayOfMonth(y,em));
        }
      } else if (tipo === 'bimestral') {
        const v = $('#ciclo_bimestral')?.value || '';
        const m = v.match(/^(\d{2})-(\d{2})-(\d{4})$/);
        if (m) {
          const m1 = parseInt(m[1],10)-1, m2 = parseInt(m[2],10)-1, y = parseInt(m[3],10);
          start = new Date(y, m1, 1);
          end   = new Date(y, m2, lastDayOfMonth(y,m2));
        }
      } else if (tipo === 'mensal') {
        const mm = parseInt($('#ciclo_mensal_mes')?.value || (new Date().getMonth()+1), 10)-1;
        const yy = parseInt($('#ciclo_mensal_ano')?.value || nowY, 10);
        start = new Date(yy, mm, 1);
        end   = new Date(yy, mm, lastDayOfMonth(yy,mm));
      } else if (tipo === 'personalizado') {
        const ini = $('#ciclo_pers_inicio')?.value || '';
        const fim = $('#ciclo_pers_fim')?.value || '';
        if (/^\d{4}-\d{2}$/.test(ini)) {
          const [y1,m1] = ini.split('-').map(Number);
          start = new Date(y1, m1-1, 1);
        }
        if (/^\d{4}-\d{2}$/.test(fim)) {
          const [y2,m2] = fim.split('-').map(Number);
          end = new Date(y2, m2-1, lastDayOfMonth(y2, m2-1));
        }
      }

      if (!start || !end) {
        const d=new Date(), m=d.getMonth()+1, y=d.getFullYear();
        const q = m<=3?1 : m<=6?2 : m<=9?3 : 4;
        const sm=(q-1)*3, em=sm+2;
        start=new Date(y,sm,1); end=new Date(y,em,lastDayOfMonth(y,em));
      }
      return { startISO: toISO(start), endISO: toISO(end) };
    }

    function updatePeriodHidden(){
      const {startISO, endISO} = computePeriodFromCycle();
      const pi = $('#periodo_inicio'), pf = $('#periodo_fim');
      if (pi) pi.value = startISO;
      if (pf) pf.value = endISO;
    }

    function populateCycles(){
      const anoAtual = new Date().getFullYear();

      const sAnual = $('#ciclo_anual_ano');
      if (sAnual){ for(let y=anoAtual; y<=anoAtual+5; y++) sAnual.add(new Option(String(y), String(y))); }

      const sSem = $('#ciclo_semestral');
      if (sSem){
        for(let y=anoAtual; y<=anoAtual+5; y++){
          sSem.add(new Option(`1º Sem/${y}` , `S1/${y}`));
          sSem.add(new Option(`2º Sem/${y}` , `S2/${y}`));
        }
      }

      const sTri = $('#ciclo_trimestral');
      if (sTri){
        ['Q1','Q2','Q3','Q4'].forEach(q=>{
          for(let y=anoAtual; y<=anoAtual+5; y++) sTri.add(new Option(`${q}/${y}`, `${q}/${y}`));
        });
      }

      const sBim=$('#ciclo_bimestral');
      if (sBim){
        for(let y=anoAtual; y<=anoAtual+5; y++){
          for(let i=0;i<12;i+=2){
            const d1=new Date(y,i), d2=new Date(y,i+1);
            const m1=d1.toLocaleString('pt-BR',{month:'short'});
            const m2=d2.toLocaleString('pt-BR',{month:'short'});
            const lbl=`${m1.charAt(0).toUpperCase()+m1.slice(1)}–${m2.charAt(0).toUpperCase()+m2.slice(1)}/${y}`;
            const val=`${String(d1.getMonth()+1).padStart(2,'0')}-${String(d2.getMonth()+1).padStart(2,'0')}-${y}`;
            sBim.add(new Option(lbl,val));
          }
        }
      }

      const sMes=$('#ciclo_mensal_mes'), sAno=$('#ciclo_mensal_ano');
      if (sMes && sAno){
        const meses=["Janeiro","Fevereiro","Março","Abril","Maio","Junho","Julho","Agosto","Setembro","Outubro","Novembro","Dezembro"];
        meses.forEach((m,i)=>sMes.add(new Option(m,String(i+1).padStart(2,'0'))));
        for(let y=anoAtual; y<=anoAtual+5; y++) sAno.add(new Option(String(y), String(y)));
        if(!sMes.value) sMes.value=String(new Date().getMonth()+1).padStart(2,'0');
        if(!sAno.value) sAno.value=String(anoAtual);
      }
    }

    function toggleCycleDetail(){
      const tipo = ($('#ciclo_tipo')?.value || 'trimestral').toLowerCase();
      const boxes = {
        anual: $('#ciclo_detalhe_anual'),
        semestral: $('#ciclo_detalhe_semestral'),
        trimestral: $('#ciclo_detalhe_trimestral'),
        bimestral: $('#ciclo_detalhe_bimestral'),
        mensal: $('#ciclo_detalhe_mensal'),
      };
      // mostra apenas o detalhe do ciclo escolhido (NUNCA mostra datas aqui)
      Object.entries(boxes).forEach(([k,el])=>{ if(!el) return; el.classList.toggle('d-none', k!==tipo); });

      // período personalizado: só aparece se tipo === personalizado
      const perso = $('#periodo_personalizado_box');
      if (perso) perso.classList.toggle('d-none', tipo !== 'personalizado');

      // se não for personalizado, limpamos os months para evitar lixo
      if (tipo !== 'personalizado') {
        const pi = $('#ciclo_pers_inicio'), pf = $('#ciclo_pers_fim');
        if (pi) pi.value = '';
        if (pf) pf.value = '';
      }

      // atualiza hidden com o período calculado
      updatePeriodHidden();
    }

    function setupOwners(){
      const inputResp   = $('#responsavel_input'),
            listCont    = $('#responsavel_list'),
            containerCh = $('#responsavel_container'),
            hiddenResp  = $('#responsavel'),
            warning     = $('#responsavel_warning');

      function bootstrapChipsFromHidden(){
        // Carrega do hidden e mantém SOMENTE o primeiro id
        const arr = (hiddenResp.value || '').split(',').map(s => s.trim()).filter(Boolean);
        const current = arr.length ? [arr[0]] : [];

        const dict = {};
        listCont.querySelectorAll('li').forEach(li => dict[li.dataset.id] = li.textContent);

        containerCh.querySelectorAll('.chip').forEach(c=>c.remove());

        if (!current.length) { inputResp.style.display = 'block'; return; }

        const id = current[0];
        const text = dict[id];
        if (!text) { inputResp.style.display = 'block'; hiddenResp.value = ''; return; }

        const chip = document.createElement('span');
        chip.className = 'chip';
        const label = document.createElement('span'); label.textContent = text;
        const rem  = document.createElement('span'); rem.className = 'remove-chip'; rem.innerHTML = '&times;';
        rem.onclick = () => { chip.remove(); updateHidden(); inputResp.style.display = 'block'; };
        chip.append(label, rem);
        containerCh.insertBefore(chip, inputResp);
        inputResp.style.display='none';
        updateHidden();
      }

      inputResp.addEventListener('focus', () => listCont.classList.remove('d-none'));
      document.addEventListener('click', e => {
        if (!containerCh.contains(e.target) && !listCont.contains(e.target)) listCont.classList.add('d-none');
      });
      inputResp.addEventListener('input', () => {
        const filter = inputResp.value.toLowerCase();
        listCont.querySelectorAll('li').forEach(li => {
          li.style.display = li.textContent.toLowerCase().includes(filter) ? '' : 'none';
        });
      });

      listCont.querySelectorAll('li').forEach(li => {
        li.addEventListener('click', () => {
          // Remove chip existente (força apenas 1)
          const existing = containerCh.querySelector('.chip');
          if (existing) existing.remove();

          const chip = document.createElement('span');
          chip.className = 'chip';
          const label = document.createElement('span'); label.textContent = li.textContent;
          const rem  = document.createElement('span'); rem.className = 'remove-chip'; rem.innerHTML = '&times;';
          rem.onclick = () => { chip.remove(); updateHidden(); inputResp.style.display = 'block'; };
          chip.append(label, rem);
          containerCh.insertBefore(chip, inputResp);
          inputResp.value = ''; inputResp.style.display='none';
          updateHidden();
        });
      });

      function updateHidden(){
        const ids = Array.from(containerCh.querySelectorAll('.chip')).map(ch => {
          const name = ch.querySelector('span')?.textContent || '';
          const li   = Array.from(listCont.querySelectorAll('li')).find(l => l.textContent === name);
          return li ? li.dataset.id : null;
        }).filter(Boolean);
        hiddenResp.value = ids[0] ? String(ids[0]) : '';
        warning.classList.add('d-none'); // aviso desnecessário com bloqueio hard
      }

      bootstrapChipsFromHidden();
    }

    // Chat lateral (acomoda largura)
    const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
    const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
    function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
    function isOpen(el){ const st=getComputedStyle(el); const vis=st.display!=='none'&&st.visibility!=='hidden'; const w=el.offsetWidth; return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show'); }
    function updateChatWidth(){ const el=findChatEl(); const w=(el && isOpen(el))?el.offsetWidth:0; document.documentElement.style.setProperty('--chat-w',(w||0)+'px'); }
    function setupChatObservers(){ const chat=findChatEl(); if(!chat) return; const mo=new MutationObserver(()=>updateChatWidth()); mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']}); window.addEventListener('resize',updateChatWidth); TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,200)))); updateChatWidth(); }

    // Fluxo de Edição
    document.addEventListener('DOMContentLoaded', () => {
      setupChatObservers();
      populateCycles();

      // Se já vier com dt_inicio/dt_prazo gravados, marcamos "personalizado"
      toggleCycleDetail();
      setupOwners();

      // listeners que impactam no período
      $('#ciclo_tipo')?.addEventListener('change', toggleCycleDetail);
      ['#ciclo_anual_ano','#ciclo_semestral','#ciclo_trimestral','#ciclo_bimestral',
       '#ciclo_mensal_mes','#ciclo_mensal_ano','#ciclo_pers_inicio','#ciclo_pers_fim']
        .forEach(sel => { const el=$(sel); el && el.addEventListener('change', updatePeriodHidden); });

      // inicializa hidden com o período atual
      updatePeriodHidden();

      const form    = $('#editForm');
      const loading = $('#loadingOverlay');
      const justOvr = $('#justifyOverlay');
      const succOvr = $('#successOverlay');

      function setLoading(on){ on ? show(loading) : hide(loading); }

      $('#btnSalvar')?.addEventListener('click', () => {
        const required = ['#nome_objetivo','#tipo_objetivo','#pilar_bsc','#status','#responsavel'];
        for (const sel of required){
          const el = $(sel);
          if (!el || !el.value || (sel==='#responsavel' && (el.value||'').trim()==='')){
            alert('Preencha os campos obrigatórios antes de salvar.');
            return;
          }
        }

        // Validação simples para personalizado: exige meses preenchidos
        if (($('#ciclo_tipo')?.value || '').toLowerCase()==='personalizado') {
          if (!$('#ciclo_pers_inicio')?.value || !$('#ciclo_pers_fim')?.value) {
            alert('Para ciclo personalizado, informe o mês/ano de início e fim.');
            return;
          }
        }

        show(justOvr);
      });

      $('#cancelJust')?.addEventListener('click', () => hide(justOvr));

      $('#confirmJust')?.addEventListener('click', async () => {
        const just = ($('#justificativa_edicao')?.value || '').trim();
        if (!just) { alert('A justificativa de edição é obrigatória.'); return; }

        hide(justOvr);
        setLoading(true);

        try {
          const fd = new FormData(form);
          fd.append('justificativa_edicao', just);

          // garante período calculado/atualizado
          updatePeriodHidden();

          const res  = await fetch(form.action, { method:'POST', body:fd });
          const data = await res.json();

          setLoading(false);

          if (data?.success) {
            show(succOvr);
          } else {
            alert(data?.error || 'Falha ao salvar alterações.');
          }
        } catch (err) {
          console.error(err);
          setLoading(false);
          alert('Erro de rede ao salvar alterações.');
        }
      });
    });
  </script>
</body>
</html>
