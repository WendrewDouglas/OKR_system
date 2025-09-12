<?php
// views/dashboard.php

// DEV ONLY (remova em produção)
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

// Redireciona se não estiver logado
if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Conexão
try {
  $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
  $pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  die("Erro ao conectar: ".$e->getMessage());
}

// ====== Dados da empresa do usuário (usuarios.id_user -> company.id_company) ======
$userId = (int)$_SESSION['user_id'];
$company = [
  'id_company'   => null,
  'organizacao'  => null,
  'razao_social' => null,
  'cnpj'         => null,
  'missao'       => null,
  'visao'        => null,
];

try {
  $stmt = $pdo->prepare("
    SELECT
      c.id_company,
      c.organizacao,
      c.razao_social,
      c.cnpj,
      c.missao,
      c.visao
    FROM usuarios u
    LEFT JOIN company c ON c.id_company = u.id_company
    WHERE u.id_user = :uid
    LIMIT 1
  ");
  $stmt->execute([':uid' => $userId]);
  if ($row = $stmt->fetch()) {
    $company = $row;
  }
} catch (Throwable $th) {
  // mantém valores padrão
}

// === SEM fallback para 1: exige empresa vinculada ===
if (empty($company['id_company'])) {
  // Direcione para a página de Organização para o usuário vincular a empresa
  header('Location: /OKR_system/organizacao');
  exit;
}

// Sessão e variável local
$_SESSION['company_id'] = (int)$company['id_company'];
$companyId = (int)$company['id_company'];

$companyName = $company['organizacao'] ?: ($company['razao_social'] ?: 'Sua Empresa');
$companyHasCNPJ = !empty($company['cnpj']); // ajuste se quiser validar 14 dígitos

// ====== Endpoint AJAX: salvar missão/visão ======
if (isset($_GET['ajax']) && $_GET['ajax'] === 'vm') {
  header('Content-Type: application/json; charset=utf-8');

  if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false, 'error'=>'Não autorizado']);
    exit;
  }

  // CSRF
  $token = $_POST['csrf_token'] ?? '';
  if (!$token || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    echo json_encode(['success'=>false, 'error'=>'Falha de segurança (CSRF). Recarregue a página.']);
    exit;
  }

  // Recarrega estado mínimo da empresa
  $stC = $pdo->prepare("
    SELECT c.id_company, c.cnpj, c.missao, c.visao
    FROM usuarios u
    LEFT JOIN company c ON c.id_company = u.id_company
    WHERE u.id_user = :uid
    LIMIT 1
  ");
  $stC->execute([':uid' => $userId]);
  $cRow = $stC->fetch();

  if (!$cRow || empty($cRow['id_company'])) {
    echo json_encode(['success'=>false, 'error'=>'Empresa não encontrada para este usuário.']);
    exit;
  }
  if (empty($cRow['cnpj'])) {
    echo json_encode([
      'success' => false,
      'requireCNPJ' => true,
      'message' => 'Você precisa cadastrar o CNPJ para incluir Missão e Visão.',
      'redirect' => '/OKR_system/organizacao'
    ]);
    exit;
  }

  $tipo  = $_POST['tipo']  ?? '';
  $valor = trim((string)($_POST['valor'] ?? ''));

  if (!in_array($tipo, ['missao','visao'], true)) {
    echo json_encode(['success'=>false, 'error'=>'Tipo inválido.']);
    exit;
  }
  if ($valor === '') {
    echo json_encode(['success'=>false, 'error'=>'O texto não pode ficar vazio.']);
    exit;
  }
  if (mb_strlen($valor) > 2000) {
    echo json_encode(['success'=>false, 'error'=>'O texto excede 2000 caracteres.']);
    exit;
  }

  // Evita reaproveitar o mesmo texto
  $atual = (string)($cRow[$tipo] ?? '');
  if (mb_strtolower(trim($atual)) === mb_strtolower($valor)) {
    echo json_encode(['success'=>false, 'error'=>'O novo texto precisa ser diferente do atual.']);
    exit;
  }

  // Normaliza a apresentação: primeira letra maiúscula, resto minúsculo
  $normalized = mb_strtolower($valor, 'UTF-8');
  $normalized = mb_strtoupper(mb_substr($normalized, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($normalized, 1, null, 'UTF-8');

  try {
    $sql = "UPDATE company SET {$tipo} = :valor, updated_at = NOW() WHERE id_company = :id LIMIT 1";
    $stU = $pdo->prepare($sql);
    $stU->execute([':valor' => $valor, ':id' => $cRow['id_company']]);
  } catch (Throwable $th) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'Erro ao salvar.']);
    exit;
  }

  echo json_encode([
    'success'=>true,
    'tipo'=>$tipo,
    // já retorna normalizado para exibir exatamente como desejado
    'html'=> nl2br(htmlspecialchars($normalized, ENT_QUOTES, 'UTF-8'))
  ]);
  exit;
}

$stTotais = $pdo->prepare("
  SELECT
    (SELECT COUNT(*)
       FROM objetivos o
      WHERE o.id_company = :cid) AS total_obj,

    (SELECT COUNT(*)
       FROM key_results kr
       JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
      WHERE o.id_company = :cid) AS total_kr,

    (SELECT COUNT(*)
       FROM key_results kr
       JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
      WHERE o.id_company = :cid
        AND (kr.dt_conclusao IS NOT NULL
             OR kr.status IN ('Concluído','Concluido','Completo','Finalizado'))) AS total_kr_done,

    /* === KRs CRÍTICOS por milestone vencido === */
    (SELECT COUNT(*)
       FROM key_results kr
       JOIN objetivos o ON o.id_objetivo = kr.id_objetivo
       LEFT JOIN milestones_kr m
              ON m.id_kr = kr.id_kr
             AND m.data_ref = (
                  SELECT MAX(m2.data_ref)
                  FROM milestones_kr m2
                  WHERE m2.id_kr = kr.id_kr
                    AND m2.data_ref <= CURDATE()
             )
      WHERE o.id_company = :cid
        /* considera apenas KRs ainda não concluídos */
        AND (kr.dt_conclusao IS NULL
             AND (kr.status IS NULL OR kr.status NOT IN ('Concluído','Concluido','Completo','Finalizado')))
        /* tem milestone vencido... */
        AND m.id_milestone IS NOT NULL
        /* ...e está crítico: sem apontamento OU abaixo do esperado */
        AND (m.valor_real_consolidado IS NULL OR m.valor_real_consolidado < m.valor_esperado)
    ) AS total_kr_risk
");
$stTotais->execute([':cid' => $companyId]);
$totais = $stTotais->fetch();

// ====== Pilares BSC (AGORA filtrados por empresa) ======
$stPilares = $pdo->prepare("
  SELECT
    p.id_pilar,
    p.descricao_exibicao AS pilar_nome,

    COALESCE(COUNT(DISTINCT o.id_objetivo),0) AS objetivos,
    COALESCE(COUNT(kr.id_kr),0)               AS krs,

    COALESCE(SUM(CASE
      WHEN kr.dt_conclusao IS NOT NULL
        OR kr.status IN ('Concluído','Concluido','Completo','Finalizado') THEN 1
      ELSE 0 END),0) AS krs_concluidos,

    /* === KRs CRÍTICOS por milestone vencido === */
    COALESCE(SUM(
      CASE
        WHEN kr.dt_conclusao IS NULL
         AND (kr.status IS NULL OR kr.status NOT IN ('Concluído','Concluido','Completo','Finalizado'))
         AND m.id_milestone IS NOT NULL
         AND (m.valor_real_consolidado IS NULL OR m.valor_real_consolidado < m.valor_esperado)
        THEN 1 ELSE 0
      END
    ),0) AS krs_risco

  FROM dom_pilar_bsc p
  LEFT JOIN objetivos o
         ON o.pilar_bsc = p.id_pilar
        AND o.id_company = :cid
  LEFT JOIN key_results kr
         ON kr.id_objetivo = o.id_objetivo
  /* último milestone vencido por KR (se existir) */
  LEFT JOIN milestones_kr m
         ON m.id_kr = kr.id_kr
        AND m.data_ref = (
             SELECT MAX(m2.data_ref)
             FROM milestones_kr m2
             WHERE m2.id_kr = kr.id_kr
               AND m2.data_ref <= CURDATE()
        )
  GROUP BY p.id_pilar, p.descricao_exibicao
  ORDER BY p.id_pilar
");
$stPilares->execute([':cid' => $companyId]);
$pilares = $stPilares->fetchAll();

function pct($parte, $todo) { return $todo ? (int)round(($parte/$todo)*100) : 0; }

// Helper de apresentação
function first_upper_rest_lower(string $s): string {
  $s = trim($s);
  if ($s === '') return $s;
  $s = mb_strtolower($s, 'UTF-8');
  $first = mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8');
  return $first . mb_substr($s, 1, null, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard – OKR System</title>

  <!-- CSS globais -->
  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

   <!-- Tema por empresa (depois dos CSS globais) -->
  <link rel="stylesheet"
        href="/OKR_system/assets/company_theme.php?cid=<?= (int)($_SESSION['company_id'] ?? 0) ?>">
  <!-- Para testar sem cache, use temporariamente:  ?cid=<?= (int)($_SESSION['company_id'] ?? 0) ?>&nocache=1 -->

  <style>
    body { background:#fff !important; color:#111; }
    :root{ --chat-w: 0px; }
    .content { background: transparent; }
    main.dashboard-container{
      padding: 24px; display: grid; grid-template-columns: 1fr; gap: 24px;
      margin-right: var(--chat-w); transition: margin-right .25s ease;
    }

    :root{
      --bg-soft:#171b21; --card: var(--bg1, #222222); --muted:#a6adbb; --text:#eaeef6;
      --gold:var(--bg2, #F1C40F); --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20);
    }

    [hidden]{ display:none !important; }

    .vision-mission{ display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 900px){ .vision-mission{ grid-template-columns: 1fr; } }
    .vm-card{
      background: linear-gradient(180deg, var(--card), #0d1117);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 14px 16px;
      box-shadow: var(--shadow);
      position: relative; overflow: hidden;
      color: var(--text);
    }
    .vm-card:before{
      content:""; position:absolute; inset:0;
      background: radial-gradient(500px 100px at 10% -10%, rgba(246,195,67,.10), transparent 40%),
                  radial-gradient(400px 160px at 110% 10%, rgba(96,165,250,.08), transparent 50%);
      pointer-events:none;
    }
    .vm-title{
      display:flex; align-items:center; gap:10px; margin-bottom:6px;
      font-weight:700; letter-spacing:.2px;
    }
    .vm-title .badge{ background: var(--gold); color:#1a1a1a; padding:5px 9px; border-radius:999px; font-size:.72rem; font-weight:800; text-transform:uppercase; }
    .vm-text{ color:var(--muted); line-height:1.45; white-space:pre-line; text-align:left; font-size:.95rem; }
    .vm-text a.vm-edit-link{ color:#eab308; text-decoration:underline dotted; }

    .pillars{ display:grid; grid-template-columns: repeat(4, 1fr); gap:16px; }
    @media (max-width: 1200px){ .pillars{ grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 700px){ .pillars{ grid-template-columns: 1fr; } }
    .pillar-card{
      background: linear-gradient(180deg, var(--card), #0d1117); border: 1px solid var(--border);
      border-radius: 16px; padding: 18px; box-shadow: var(--shadow);
      position:relative; overflow:hidden; transition: transform .2s ease, border-color .2s ease; color: var(--text);
    }
    .pillar-card:hover{ transform: translateY(-2px); border-color: #293140; }
    .pillar-header{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px; }
    .pillar-title{ display:flex; align-items:center; gap:10px; font-weight:700; }
    .pillar-title i{
      color: var(--gold); background: rgba(246,195,67,.12); width: 40px; height: 40px; border-radius: 12px;
      display:grid; place-items:center; border:1px solid rgba(246,195,67,.25);
    }
    .pillar-stats{ display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; margin:12px 0 10px; }
    .stat{ background: #0e131a; border:1px solid var(--border); border-radius: 12px; padding:10px; text-align:center; }
    .stat .label{ font-size:.75rem; color:var(--muted); }
    .stat .value{ font-size:1.25rem; font-weight:800; letter-spacing:.2px; color: var(--text); }
    .progress-wrap{ margin-top:10px; }
    .progress-label{ display:flex; align-items:center; justify-content:space-between; font-size:.85rem; color:var(--muted); margin-bottom:6px;}
    .progress-bar{ width:100%; height:10px; background:#0b0f14; border:1px solid var(--border); border-radius:999px; overflow:hidden; }
    .progress-fill{ height:100%; width:0%; background: linear-gradient(90deg, var(--gold), var(--green)); border-right:1px solid rgba(255,255,255,.15); transition: width 1s ease-in-out; }

    .risk-badge{ display:inline-flex; align-items:center; gap:6px; background: rgba(239,68,68,.12); color: #fecaca; border:1px solid rgba(239,68,68,.35); padding:4px 10px; border-radius:999px; font-size:.8rem; font-weight:700; }
    .pillar-footer{ margin-top: 12px; display:flex; justify-content:center; }

    .kpi-row{ display:grid; grid-template-columns: repeat(4, 1fr); gap:16px; }
    @media (max-width: 1200px){ .kpi-row{ grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 700px){ .kpi-row{ grid-template-columns: 1fr; } }
    .kpi-card{
      background: linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:16px; padding:18px; box-shadow: var(--shadow);
      position:relative; overflow:hidden; color: var(--text);
    }
    .kpi-card .kpi-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; color:var(--muted); font-size:.9rem; }
    .kpi-card .kpi-value{ font-size:2rem; color:var(--gold); font-weight:900; letter-spacing:.3px; }
    .kpi-icon{ width:40px; height:40px; border-radius:12px; display:grid; place-items:center; border:1px solid var(--border); color:#c7d2fe; background:rgba(96,165,250,.12); }
    .kpi-card.success .kpi-icon{ color:#86efac; background:rgba(34,197,94,.12); }
    .kpi-card.danger .kpi-icon{ color:#fca5a5; background:rgba(239,68,68,.12); }

    .modal-backdrop{ position: fixed; inset:0; display:none; place-items:center; background: rgba(0,0,0,.5); z-index: 2000; }
    .modal-backdrop.show{ display:grid; }
    .modal{ width: min(680px, 94vw); background: #0f1420; color: #e5e7eb; border: 1px solid #223047; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,.4); overflow:hidden; }
    .modal header{ display:flex; align-items:center; justify-content:space-between; padding: 14px 16px; border-bottom:1px solid #1f2a3a; background:#0b101a; }
    .modal header h3{ margin:0; font-size:1.05rem; letter-spacing:.2px; }
    .modal .modal-body{ padding: 16px; }
    .modal .modal-actions{ display:flex; gap:10px; justify-content:flex-end; padding: 12px 16px; border-top:1px solid #1f2a3a; background:#0b101a; }
    .btn{ border:1px solid var(--border); background: var(--btn); color:#e5e7eb; padding:10px 14px; border-radius:12px; font-weight:700; }
    .btn:hover{ border-color:#2a3342; transform: translateY(-1px); transition:.15s; }
    .btn-primary{ background: #1f2937; }
    .btn-link{ background: transparent; border: none; color:#93c5fd; text-decoration:underline; padding:0; }
    .textarea{ width:100%; min-height: 150px; resize: vertical; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:12px; padding:12px 12px; line-height:1.5; }
    .helper{ color:#9aa4b2; font-size:.85rem; margin-top:6px; }
    .modal .notice{ background:#111827; border:1px dashed #374151; padding:12px; border-radius:12px; margin-bottom:10px; color:#cbd5e1; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="dashboard-container">

      <!-- Visão & Missão -->
      <section class="vision-mission">
        <!-- VISÃO -->
        <article class="vm-card" id="cardVisao">
          <div class="vm-title">
            <span class="badge">Visão</span><i class="fa-regular fa-eye"></i>
          </div>
          <p class="vm-text" id="visaoText">
            <?php if (!empty($company['visao'])): ?>
              <?= nl2br(htmlspecialchars(first_upper_rest_lower($company['visao']), ENT_QUOTES, 'UTF-8')) ?>
            <?php else: ?>
              Ser referência em excelência operacional e inovação, impulsionando crescimento sustentável e impacto positivo no mercado.<br>
              (Texto de exemplo — <a href="#" class="vm-edit-link" data-type="visao">clique aqui</a> e substitua pela visão oficial).
            <?php endif; ?>
          </p>
        </article>

        <!-- MISSÃO -->
        <article class="vm-card" id="cardMissao">
          <div class="vm-title">
            <span class="badge">Missão</span><i class="fa-solid fa-rocket"></i>
          </div>
          <p class="vm-text" id="missaoText">
            <?php if (!empty($company['missao'])): ?>
              <?= nl2br(htmlspecialchars(first_upper_rest_lower($company['missao']), ENT_QUOTES, 'UTF-8')) ?>
            <?php else: ?>
              Entregar valor contínuo aos clientes por meio de soluções simples, seguras e escaláveis, promovendo a alta performance das equipes.<br>
              (Texto de exemplo — <a href="#" class="vm-edit-link" data-type="missao">clique aqui</a> e substitua pela missão oficial).
            <?php endif; ?>
          </p>
        </article>
      </section>

      <!-- Pilares BSC -->
      <section class="pillars">
        <?php foreach ($pilares as $p):
          $pctPilar = pct((int)$p['krs_concluidos'], (int)$p['krs']);
        ?>
        <div class="pillar-card">
          <div class="pillar-header">
            <div class="pillar-title">
              <i class="fa-solid fa-layer-group"></i>
              <span><?= htmlspecialchars($p['pilar_nome'] ?: 'Pilar') ?></span>
            </div>
          </div>

          <div class="pillar-stats">
            <div class="stat">
              <div class="label">Objetivos</div>
              <div class="value countup" data-target="<?= (int)$p['objetivos'] ?>">0</div>
            </div>
            <div class="stat">
              <div class="label">KRs</div>
              <div class="value countup" data-target="<?= (int)$p['krs'] ?>">0</div>
            </div>
            <div class="stat">
              <div class="label">Concluídos</div>
              <div class="value countup" data-target="<?= (int)$p['krs_concluidos'] ?>">0</div>
            </div>
          </div>

          <div class="progress-wrap">
            <div class="progress-label">
              <span>Progresso do pilar</span>
              <strong><span class="progress-pct"><?= $pctPilar ?></span>%</strong>
            </div>
            <div class="progress-bar">
              <div class="progress-fill" style="width:0%" data-final="<?= $pctPilar ?>"></div>
            </div>
          </div>

          <div class="pillar-footer">
            <span class="risk-badge">
              <i class="fa-solid fa-triangle-exclamation"></i>
              <?= (int)$p['krs_risco'] ?> em risco
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      </section>

      <!-- KPIs gerais -->
      <section class="kpi-row">
        <div class="kpi-card">
          <div class="kpi-head"><span>Total de Objetivos</span><div class="kpi-icon"><i class="fa-solid fa-bullseye"></i></div></div>
          <div class="kpi-value countup" data-target="<?= (int)$totais['total_obj'] ?>">0</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-head"><span>Total de KRs</span><div class="kpi-icon"><i class="fa-solid fa-list-check"></i></div></div>
          <div class="kpi-value countup" data-target="<?= (int)$totais['total_kr'] ?>">0</div>
        </div>
        <div class="kpi-card success">
          <div class="kpi-head"><span>KRs Concluídos</span><div class="kpi-icon"><i class="fa-solid fa-check-double"></i></div></div>
          <div class="kpi-value countup" data-target="<?= (int)$totais['total_kr_done'] ?>">0</div>
        </div>
        <div class="kpi-card danger">
          <div class="kpi-head"><span>KRs em Risco</span><div class="kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div></div>
          <div class="kpi-value countup" data-target="<?= (int)$totais['total_kr_risk'] ?>">0</div>
        </div>
      </section>
    </main>

    <!-- Chat (inalterado) -->
    <?php include __DIR__ . '/partials/chat.php'; ?>
  </div>

  <!-- Metas para JS -->
  <meta id="csrfToken" data-token="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
  <meta id="companyHasCNPJ" data-has="<?= $companyHasCNPJ ? '1':'0' ?>">
  <meta id="companyName" data-name="<?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?>">

  <!-- Modal de Edição (Missão/Visão) -->
  <div class="modal-backdrop" id="vmModalBackdrop" aria-hidden="true" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="vmModalTitle">
      <header>
        <h3 id="vmModalTitle">Editar</h3>
        <button class="btn btn-link" id="vmClose" aria-label="Fechar">Fechar ✕</button>
      </header>
      <div class="modal-body">
        <div class="helper"><i class="fa-solid fa-building"></i> <strong id="companyHeaderName"><?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <label for="vmTextarea" class="helper" id="vmHelper">Atualize o texto abaixo.</label>
        <textarea id="vmTextarea" class="textarea" maxlength="2000" placeholder="Digite aqui sua missão"></textarea>
        <div class="helper"><span id="vmCount">0</span>/2000</div>
      </div>
      <div class="modal-actions">
        <button class="btn" id="vmCancel">Cancelar</button>
        <button class="btn btn-primary" id="vmSave">Salvar</button>
      </div>
    </div>
  </div>

  <!-- Modal de Aviso: CNPJ necessário -->
  <div class="modal-backdrop" id="cnpjModalBackdrop" aria-hidden="true" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cnpjModalTitle">
      <header>
        <h3 id="cnpjModalTitle">CNPJ necessário</h3>
        <button class="btn btn-link" id="cnpjClose" aria-label="Fechar">Fechar ✕</button>
      </header>
      <div class="modal-body">
        <div class="notice">
          Para incluir <strong>Missão</strong> e <strong>Visão</strong>, é necessário cadastrar o <strong>CNPJ</strong> da empresa.
        </div>
        <p class="helper">Acesse a página <em>Organização</em> para concluir o cadastro.</p>
      </div>
      <div class="modal-actions">
        <button class="btn" id="cnpjBack">Voltar</button>
        <a class="btn btn-primary" id="cnpjGo" href="/OKR_system/organizacao">Ir para Organização</a>
      </div>
    </div>
  </div>

  <script>
    // --------- Count-up e progress ----------
    function animateCounter(el, target, duration=900){
      const start=0, t0=performance.now();
      function tick(t){
        const p=Math.min((t-t0)/duration,1);
        el.textContent = Math.floor(start+(target-start)*p).toLocaleString('pt-BR');
        if(p<1) requestAnimationFrame(tick);
      }
      requestAnimationFrame(tick);
    }
    function animateProgressBars(){
      document.querySelectorAll('.progress-fill').forEach(bar=>{
        const to=parseInt(bar.getAttribute('data-final')||'0',10);
        requestAnimationFrame(()=>{ bar.style.width = Math.max(0,Math.min(100,to))+'%'; });
      });
    }

    // --------- Adaptação ao chat ----------
    const CHAT_SELECTORS = ['#chatPanel', '.chat-panel', '.chat-container', '#chat', '.drawer-chat'];
    const TOGGLE_SELECTORS = ['#chatToggle', '.chat-toggle', '.btn-chat-toggle', '.chat-icon', '.chat-open'];

    function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
    function isOpen(el){
      const style = getComputedStyle(el);
      const visible = style.display!=='none' && style.visibility!=='hidden';
      const w = el.offsetWidth;
      return (visible && w>0) || el.classList.contains('open') || el.classList.contains('show') || el.getAttribute('aria-expanded')==='true';
    }
    function updateChatWidth(){ const el = findChatEl(); const w = (el && isOpen(el)) ? el.offsetWidth : 0; document.documentElement.style.setProperty('--chat-w', (w||0)+'px'); }
    function setupChatObservers(){
      const chat = findChatEl(); if(!chat) return;
      const mo = new MutationObserver(()=>updateChatWidth());
      mo.observe(chat, { attributes:true, attributeFilter:['style','class','aria-expanded'] });
      window.addEventListener('resize', updateChatWidth);
      document.querySelectorAll(TOGGLE_SELECTORS.join(',')).forEach(btn=>btn.addEventListener('click', ()=>setTimeout(updateChatWidth, 200)));
      updateChatWidth();
    }

    // --------- Modais ----------
    const vmModalBackdrop = document.getElementById('vmModalBackdrop');
    const vmTitle   = document.getElementById('vmModalTitle');
    const vmHelper  = document.getElementById('vmHelper');
    const vmTextarea= document.getElementById('vmTextarea');
    const vmCount   = document.getElementById('vmCount');
    const vmSaveBtn = document.getElementById('vmSave');
    const vmCancel  = document.getElementById('vmCancel');
    const vmClose   = document.getElementById('vmClose');

    const cnpjModalBackdrop = document.getElementById('cnpjModalBackdrop');
    const cnpjClose = document.getElementById('cnpjClose');
    const cnpjBack  = document.getElementById('cnpjBack');
    const cnpjGo    = document.getElementById('cnpjGo');

    const COMPANY_HAS_CNPJ = (document.getElementById('companyHasCNPJ').dataset.has === '1');
    const COMPANY_NAME = document.getElementById('companyName').dataset.name;

    let currentType = null; // 'missao' | 'visao'

    function openVMModal(type){
      currentType = type;
      vmTitle.textContent = type === 'visao' ? 'Editar Visão' : 'Editar Missão';
      vmHelper.textContent = type === 'visao'
        ? 'Defina a Visão de forma aspiracional e clara.'
        : 'Defina a Missão de forma objetiva e centrada no cliente.';
      document.getElementById('companyHeaderName').textContent = COMPANY_NAME;
      vmTextarea.value = '';
      vmTextarea.placeholder = (type === 'visao') ? 'Digite aqui sua visão' : 'Digite aqui sua missão';
      vmCount.textContent = '0';
      vmModalBackdrop.classList.add('show');
      vmModalBackdrop.removeAttribute('hidden');
      vmTextarea.focus();
    }
    function closeVMModal(){
      vmModalBackdrop.classList.remove('show');
      vmModalBackdrop.setAttribute('hidden', '');
      currentType = null; vmTextarea.value = ''; vmCount.textContent = '0';
    }
    function openCNPJModal(){
      cnpjModalBackdrop.classList.add('show');
      cnpjModalBackdrop.removeAttribute('hidden');
    }
    function closeCNPJModal(){
      cnpjModalBackdrop.classList.remove('show');
      cnpjModalBackdrop.setAttribute('hidden', '');
    }

    vmTextarea && vmTextarea.addEventListener('input', ()=>{ vmCount.textContent = vmTextarea.value.length.toString(); });
    vmCancel && vmCancel.addEventListener('click', closeVMModal);
    vmClose  && vmClose.addEventListener('click', closeVMModal);
    cnpjBack && cnpjBack.addEventListener('click', closeCNPJModal);
    cnpjClose && cnpjClose.addEventListener('click', closeCNPJModal);

    // Abrir via “clique aqui”: valida CNPJ antes de abrir o editor
    document.addEventListener('click', (e)=>{
      const a = e.target.closest('.vm-edit-link');
      if (a){
        e.preventDefault();
        if (!COMPANY_HAS_CNPJ){
          openCNPJModal();
          return;
        }
        openVMModal(a.getAttribute('data-type'));
      }
    });

    // Salvar via AJAX
    vmSaveBtn && vmSaveBtn.addEventListener('click', async ()=>{
      if (!currentType) return;
      const csrf = document.getElementById('csrfToken').dataset.token;
      const valor = vmTextarea.value.trim();

      const u = new URL(window.location.href);
      u.searchParams.set('ajax', 'vm');
      const ajaxUrl = u.toString();

      vmSaveBtn.disabled = true; vmSaveBtn.textContent = 'Salvando...';

      try{
        const params = new URLSearchParams();
        params.set('tipo', currentType);
        params.set('valor', valor);
        params.set('csrf_token', csrf);

        const res = await fetch(ajaxUrl, {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body: params.toString(),
        });

        let data = null;
        try { data = await res.json(); } catch{ data = null; }

        if (!res.ok || !data){
          throw new Error('bad_response');
        }

        if (!data.success){
          if (data.requireCNPJ){
            closeVMModal();
            if (data.redirect) { cnpjGo.setAttribute('href', data.redirect); }
            openCNPJModal();
          } else {
            alert(data.error || 'Erro ao salvar.');
          }
        } else {
          const html = data.html || '';
          if (data.tipo === 'visao'){
            document.getElementById('visaoText').innerHTML = html;
          } else {
            document.getElementById('missaoText').innerHTML = html;
          }
          closeVMModal();
        }
      }catch(err){
        console.error(err);
        alert('Falha na comunicação. Tente novamente.');
      } finally {
        vmSaveBtn.disabled = false; vmSaveBtn.textContent = 'Salvar';
      }
    });

    // --------- Inicialização ----------
    document.addEventListener('DOMContentLoaded', ()=>{
      document.querySelectorAll('.countup[data-target]').forEach(el=>{
        const tgt = parseInt(el.getAttribute('data-target')||'0',10);
        animateCounter(el, tgt, 800 + Math.random()*400);
      });
      animateProgressBars();

      // Chat space
      const CHAT_SELECTORS = ['#chatPanel', '.chat-panel', '.chat-container', '#chat', '.drawer-chat'];
      function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
      function isOpen(el){
        const style = getComputedStyle(el);
        const visible = style.display!=='none' && style.visibility!=='hidden';
        const w = el.offsetWidth;
        return (visible && w>0) || el.classList.contains('open') || el.classList.contains('show') || el.getAttribute('aria-expanded')==='true';
      }
      function updateChatWidth(){ const el = findChatEl(); const w = (el && isOpen(el)) ? el.offsetWidth : 0; document.documentElement.style.setProperty('--chat-w', (w||0)+'px'); }
      const mo = new MutationObserver(()=>updateChatWidth());
      const chat = findChatEl(); if(chat){ mo.observe(chat, { attributes:true, attributeFilter:['style','class','aria-expanded'] }); }
      window.addEventListener('resize', updateChatWidth);
      updateChatWidth();
    });
  </script>
</body>
</html>
