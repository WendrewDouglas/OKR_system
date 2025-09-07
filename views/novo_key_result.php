<?php
// views/novo_key_result.php — KR form
// Agora filtra objetivos e usuários SOMENTE da mesma company do usuário logado.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ============ INJETAR O TEMA (uma vez por página) ============ */
if (!defined('PB_THEME_LINK_EMITTED')) {
  define('PB_THEME_LINK_EMITTED', true);
  echo '<link rel="stylesheet" href="/OKR_system/assets/company_theme.php">';
}

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

// Descobre a company do usuário logado
$userId = (int)$_SESSION['user_id'];
$st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user = :u LIMIT 1");
$st->execute([':u' => $userId]);
$companyId = (int)($st->fetchColumn() ?: 0);
if ($companyId <= 0) {
  http_response_code(403);
  die("Usuário sem company vinculada.");
}

// Pré-seleção via GET (validando contra a mesma company)
$prefIdObjetivo = 0;
if (isset($_GET['id_objetivo']))     $prefIdObjetivo = (int)$_GET['id_objetivo'];
elseif (isset($_GET['id']))          $prefIdObjetivo = (int)$_GET['id'];

if ($prefIdObjetivo > 0) {
  $chk = $pdo->prepare("SELECT 1 FROM objetivos WHERE id_objetivo = :id AND id_company = :c LIMIT 1");
  $chk->execute([':id' => $prefIdObjetivo, ':c' => $companyId]);
  if (!$chk->fetchColumn()) {
    // Se não pertencer à company do usuário, ignora a preferência
    $prefIdObjetivo = 0;
  }
}

// ===== Domínios / Listas =====
// OBJETIVOS: apenas da MESMA company
$st = $pdo->prepare("
  SELECT id_objetivo, descricao, status_aprovacao, dt_prazo
  FROM objetivos
  WHERE id_company = :c
  ORDER BY dt_prazo ASC, id_objetivo ASC
");
$st->execute([':c' => $companyId]);
$objetivos = $st->fetchAll();

// USERS (responsável KR): apenas da MESMA company
$st = $pdo->prepare("
  SELECT id_user, primeiro_nome, ultimo_nome
  FROM usuarios
  WHERE id_company = :c
  ORDER BY primeiro_nome, ultimo_nome
");
$st->execute([':c' => $companyId]);
$users = $st->fetchAll();

// Domínios globais (não dependem de company)
$tiposKr   = $pdo->query("SELECT id_tipo, descricao_exibicao FROM dom_tipo_kr ORDER BY descricao_exibicao")->fetchAll();
$naturezas = $pdo->query("SELECT id_natureza, descricao_exibicao FROM dom_natureza_kr ORDER BY descricao_exibicao")->fetchAll();
$ciclos    = $pdo->query("SELECT id_ciclo, nome_ciclo, descricao FROM dom_ciclos ORDER BY id_ciclo")->fetchAll();
$freqs     = $pdo->query("SELECT id_frequencia, descricao_exibicao FROM dom_tipo_frequencia_milestone ORDER BY descricao_exibicao")->fetchAll();
$statusKr  = $pdo->query("SELECT id_status, descricao_exibicao FROM dom_status_kr ORDER BY 1")->fetchAll();

// Garante opção "Quinzenal (15 dias)" no front mesmo que não exista na tabela
$hasQuinzenal = false;
foreach ($freqs as $f) {
  $id  = strtolower(trim((string)$f['id_frequencia']));
  $lbl = strtolower(trim((string)$f['descricao_exibicao']));
  if ($id === 'quinzenal' || strpos($lbl,'quinzen') !== false) { $hasQuinzenal = true; break; }
}
if (!$hasQuinzenal) {
  $freqs[] = ['id_frequencia'=>'quinzenal', 'descricao_exibicao'=>'Quinzenal (15 dias)'];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Novo Key Result – OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
  <style>
    body{ background:#fff !important; color:#111; }
    :root{ --chat-w:0px; }
    .content{ background:transparent; }
    main.nkr{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; }

    :root{
      --bg-soft:#171b21; --card: var(--bg1, #222222); --muted:#a6adbb; --text:#eaeef6;
      --gold:var(--bg2, #F1C40F); --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20);
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

    .pill-gold{
      border-color: var(--gold);
      color: var(--gold);
      background: rgba(246,195,67,.10);
      box-shadow: 0 0 0 1px rgba(246,195,67,.10), 0 6px 18px rgba(246,195,67,.10);
    }
    .pill-gold i{ color: var(--gold); }

    .form-card{
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:16px; padding:16px;
      box-shadow:var(--shadow); color:var(--text);
    }
    .form-card h2{ font-size:1.05rem; margin:0 0 12px; letter-spacing:.2px; color:#e5e7eb; }
    .grid-2{ display:grid; grid-template-columns:2fr 1fr; gap:12px; }
    .grid-3{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
    @media (max-width:900px){ .grid-2,.grid-3{ grid-template-columns:1fr; } }

    label{ display:block; margin-bottom:6px; color:#cbd5e1; font-size:.9rem; }
    input[type="text"], input[type="number"], input[type="month"], input[type="date"], textarea, select{
      width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none;
    }
    textarea{ resize:vertical; min-height:90px; }
    .helper{ color:#9aa4b2; font-size:.85rem; }

    select.has-value{
      border-color: var(--gold) !important;
      box-shadow: 0 0 0 2px rgba(246,195,67,.15);
    }

    .save-row{ display:flex; justify-content:center; margin-top:16px; }
    .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:10px 14px; border-radius:12px; font-weight:700; }
    .btn:hover{ border-color:#2a3342; transform:translateY(-1px); transition:.15s; }
    .btn-primary{ background:#1f2937; }

    .overlay{ position:fixed; inset:0; display:none; place-items:center; background:rgba(0,0,0,.55); z-index:3000; }
    .overlay.show{ display:grid; }
    .ai-card{
      width:min(920px,94vw); background:#0b1020; color:#e6e9f2;
      border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.35); padding:18px; position:relative; overflow:hidden;
      border:1px solid #223047;
    }
    .ai-card::after{
      content:""; position:absolute; inset:0;
      background: radial-gradient(1000px 300px at 10% -20%, rgba(64,140,255,.18), transparent 60%),
                  radial-gradient(700px 220px at 100% 0%, rgba(0,196,204,.12), transparent 60%);
      pointer-events:none;
    }
    .ai-header{ display:flex; align-items:center; gap:12px; margin-bottom:10px; }
    .ai-avatar{ width:44px; height:44px; border-radius:50%; display:grid; place-items:center; color:#fff; font-weight:800;
      background:conic-gradient(from 180deg at 50% 50%, #3b82f6, #06b6d4, #8b5cf6, #3b82f6); box-shadow:0 6px 18px rgba(59,130,246,.35); }
    .ai-title{ font-size:.95rem; opacity:.9; }
    .ai-subtle{ font-size:.85rem; opacity:.7; }
    .ai-bubble{ background:#111833; border:1px solid rgba(255,255,255,.06); border-radius:14px; padding:16px; margin:8px 0 14px; }

    .score-row{ display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:6px; }
    .score-pill{ font-weight:900; font-size:2.25rem; padding:6px 14px; border-radius:12px;
      background:linear-gradient(135deg, rgba(59,130,246,.16), rgba(2,132,199,.12)); border:1px solid rgba(255,255,255,.08); }
    .quality-badge{ padding:4px 10px; border-radius:999px; font-size:.8rem; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.06); text-transform:capitalize; }
    .q-pessimo{ background:rgba(239,68,68,.15); border-color:rgba(239,68,68,.25); }
    .q-ruim{ background:rgba(245,158,11,.15); border-color:rgba(245,158,11,.25); }
    .q-moderado{ background:rgba(14,165,233,.15); border-color:rgba(14,165,233,.25); }
    .q-bom{ background:rgba(34,197,94,.15); border-color:rgba(34,197,94,.25); }
    .q-otimo{ background:rgba(168,85,247,.16); border-color:rgba(168,85,247,.25); }

    .note{ font-size:.82rem; color:#cbd5e1; margin-top:6px; }
    .note strong{ color:#e5e7eb; }

    .ms-preview{ margin-top:14px; border:1px solid #222733; border-radius:12px; overflow:hidden; }
    .ms-preview header{ background:#101626; color:#e5e7eb; padding:10px 12px; font-weight:800; display:flex; align-items:center; gap:8px; }
    .ms-table{ width:100%; border-collapse:collapse; }
    .ms-table th, .ms-table td{ padding:8px 10px; border-top:1px solid #1f2635; }
    .ms-table th{ text-align:left; color:#cbd5e1; font-weight:700; font-size:.88rem; background:#0f1524; }
    .muted{ color:#9aa4b2; font-size:.9rem; }
    .right{ text-align:right; }
    .ms-table tbody {color: #fff}
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="nkr">
      <!-- Breadcrumb -->
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <a href="/OKR_system/meus_okrs"><i class="fa-solid fa-bullseye"></i> Meus OKRs</a>
        <span class="sep">/</span>
        <span><i class="fa-solid fa-circle-plus"></i> Novo Key Result</span>
      </div>

      <!-- Cabeçalho -->
      <section class="head-card">
        <h1 class="head-title"><i class="fa-solid fa-bullseye"></i>Novo Key Result</h1>
        <div class="head-meta">
          <span class="pill"><i class="fa-solid fa-circle-info"></i>Preencha os campos e salve para submeter à aprovação.</span>

          <span id="selectedObjBadge" class="pill pill-gold" style="display:none;">
            <i class="fa-solid fa-bullseye"></i>
            <strong id="selectedObjText"></strong>
          </span>

          <span id="periodBadge" class="pill" style="display:none;">
            <i class="fa-regular fa-calendar"></i>
            <span id="periodText"></span>
          </span>

          <span id="milestoneBadge" class="pill" style="display:none;">
            <i class="fa-solid fa-flag-checkered"></i>
            <span id="milestoneText"></span>
          </span>
        </div>
      </section>

      <!-- Formulário -->
      <section class="form-card">
        <h2><i class="fa-regular fa-rectangle-list"></i> Dados do Key Result</h2>
        <form id="krForm" action="/OKR_system/auth/salvar_kr.php" method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" id="score_ia" name="score_ia" value="">
          <input type="hidden" id="justificativa_ia" name="justificativa_ia" value="">
          <input type="hidden" id="data_inicio" name="data_inicio" value="">
          <input type="hidden" id="data_fim"    name="data_fim"    value="">
          <input type="hidden" id="autogerar_milestones" name="autogerar_milestones" value="1">

          <!-- Objetivo + Status -->
          <div class="grid-2">
            <div>
              <label for="id_objetivo"><i class="fa-regular fa-circle-question"></i> Objetivo associado <span class="helper">(obrigatório)</span></label>
              <select id="id_objetivo" name="id_objetivo" required>
                <option value="">Selecione...</option>
                <?php foreach ($objetivos as $o): ?>
                  <option
                    value="<?= (int)$o['id_objetivo'] ?>"
                    data-status="<?= htmlspecialchars($o['status_aprovacao'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    <?= $prefIdObjetivo === (int)$o['id_objetivo'] ? 'selected' : '' ?>
                  >
                    <?= htmlspecialchars($o['descricao'] ?? ('Objetivo #'.(int)$o['id_objetivo']), ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="status_objetivo"><i class="fa-solid fa-clipboard-check"></i> Status do objetivo</label>
              <input type="text" id="status_objetivo" readonly placeholder="—">
            </div>
          </div>

          <!-- Descrição -->
          <div style="margin-top:12px;">
            <label for="descricao_kr"><i class="fa-regular fa-pen-to-square"></i> Descrição do Key Result <span class="helper">(obrigatório)</span></label>
            <textarea id="descricao_kr" name="descricao" required></textarea>
          </div>

          <!-- Baseline, Meta, Unidade -->
          <div class="grid-3" style="margin-top:12px;">
            <div>
              <label for="baseline"><i class="fa-solid fa-gauge"></i> Baseline <span class="helper">(obrigatório)</span></label>
              <input type="number" step="0.01" id="baseline" name="baseline" required>
            </div>
            <div>
              <label for="meta"><i class="fa-solid fa-bullseye"></i> Meta <span class="helper">(obrigatório)</span></label>
              <input type="number" step="0.01" id="meta" name="meta" required>
            </div>
            <div>
              <label for="unidade_medida"><i class="fa-solid fa-ruler"></i> Unidade de medida</label>
              <select id="unidade_medida" name="unidade_medida">
                <option value="">Selecione...</option>
                <optgroup label="Financeiro">
                  <option value="R$">Real (R$)</option><option value="US$">Dólar (US$)</option>
                  <option value="€">Euro (€)</option><option value="£">Libra (£)</option>
                  <option value="R$/unid">Custo por unidade (R$/unid)</option>
                </optgroup>
                <optgroup label="Percentuais e Taxas">
                  <option value="%">Percentual (%)</option><option value="pp">Ponto percentual (pp)</option>
                  <option value="taxa_conversao">% Conversão</option><option value="taxa_churn">% Churn</option>
                </optgroup>
                <optgroup label="Operacional / Quantitativo">
                  <option value="unid">Unidade (unid)</option><option value="itens">Itens</option><option value="pcs">Peças</option>
                  <option value="ord">Ordens</option><option value="proc">Processos</option>
                  <option value="contratos">Contratos</option><option value="pessoas">Pessoas</option>
                  <option value="tickets">Tickets</option><option value="visitas">Visitas</option>
                </optgroup>
                <optgroup label="Tempo">
                  <option value="h">Hora (h)</option><option value="d">Dia (d)</option><option value="sem">Semana (sem)</option>
                  <option value="mês">Mês</option><option value="tri">Trimestre (tri)</option><option value="a">Ano (a)</option>
                </optgroup>
                <optgroup label="Índices e Pontuações">
                  <option value="pts">Pontos (pts)</option><option value="nps">NPS</option><option value="rating">Rating</option>
                </optgroup>
                <optgroup label="Dimensão / Volume">
                  <option value="km">Quilômetro (km)</option><option value="m">Metro (m)</option>
                  <option value="L">Litro (L)</option><option value="m3">Metro cúbico (m³)</option>
                </optgroup>
                <optgroup label="Massa">
                  <option value="kg">Quilograma (kg)</option><option value="g">Grama (g)</option>
                </optgroup>
              </select>
            </div>
          </div>

          <!-- Direção, Natureza, Tipo -->
          <div class="grid-3" style="margin-top:12px;">
            <div>
              <label for="direcao_metrica"><i class="fa-solid fa-arrow-up-wide-short"></i> Direção da métrica</label>
              <select id="direcao_metrica" name="direcao_metrica">
                <option value="">Selecione...</option>
                <option value="MAIOR_MELHOR">Maior Melhor</option>
                <option value="MENOR_MELHOR">Menor Melhor</option>
                <option value="INTERVALO_IDEAL">Intervalo Ideal</option>
              </select>
            </div>
            <div>
              <label for="natureza_kr"><i class="fa-solid fa-shapes"></i> Natureza do KR</label>
              <select id="natureza_kr" name="natureza_kr">
                <option value="">Selecione...</option>
                <?php foreach($naturezas as $n): ?>
                  <option value="<?= htmlspecialchars($n['id_natureza'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($n['descricao_exibicao'], ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div id="natureza_help" class="note"></div>
            </div>
            <div>
              <label for="tipo_kr"><i class="fa-regular fa-square-check"></i> Tipo de KR</label>
              <select id="tipo_kr" name="tipo_kr">
                <option value="">Selecione...</option>
                <?php foreach($tiposKr as $t): ?>
                  <option value="<?= htmlspecialchars($t['id_tipo'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t['descricao_exibicao'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Ciclo + Frequência -->
          <div class="grid-3" style="margin-top:12px;">
            <div>
              <label for="ciclo_tipo"><i class="fa-regular fa-calendar-days"></i> Ciclo <span class="helper">(obrigatório)</span></label>
              <select id="ciclo_tipo" name="ciclo_tipo" required>
                <?php foreach ($ciclos as $c): ?>
                  <option value="<?= htmlspecialchars(strtolower($c['nome_ciclo']), ENT_QUOTES, 'UTF-8') ?>"
                    <?= strtolower($c['nome_ciclo'])==='trimestral' ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['descricao'], ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div id="ciclo_detalhe_wrapper">
              <div id="ciclo_detalhe_anual" class="detalhe" style="display:none; margin-top:6px;">
                <select id="ciclo_anual_ano" name="ciclo_anual_ano"></select>
              </div>
              <div id="ciclo_detalhe_semestral" class="detalhe" style="display:none; margin-top:6px;">
                <select id="ciclo_semestral" name="ciclo_semestral"></select>
              </div>
              <div id="ciclo_detalhe_trimestral" class="detalhe" style="margin-top:6px;">
                <select id="ciclo_trimestral" name="ciclo_trimestral"></select>
              </div>
              <div id="ciclo_detalhe_bimestral" class="detalhe" style="display:none; margin-top:6px;">
                <select id="ciclo_bimestral" name="ciclo_bimestral"></select>
              </div>
              <div id="ciclo_detalhe_mensal" class="detalhe" style="display:none; margin-top:6px;" >
                <div class="split">
                  <select id="ciclo_mensal_mes" name="ciclo_mensal_mes"></select>
                  <select id="ciclo_mensal_ano" name="ciclo_mensal_ano"></select>
                </div>
              </div>
              <div id="ciclo_detalhe_personalizado" class="detalhe" style="display:none; margin-top:6px;">
                <div class="split">
                  <input type="month" id="ciclo_pers_inicio" name="ciclo_pers_inicio">
                  <input type="month" id="ciclo_pers_fim" name="ciclo_pers_fim">
                </div>
              </div>
            </div>
            <div>
              <label for="frequencia_apontamento"><i class="fa-solid fa-clock-rotate-left"></i> Frequência de apontamento <span class="helper">(obrigatório)</span></label>
              <select id="frequencia_apontamento" name="tipo_frequencia_milestone" required>
                <option value="">Selecione...</option>
                <?php foreach($freqs as $f): ?>
                  <option value="<?= htmlspecialchars($f['id_frequencia'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($f['descricao_exibicao'], ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Margem, Status, Responsável -->
          <div class="grid-3" style="margin-top:12px%;">
            <div>
              <label for="margem_confianca"><i class="fa-regular fa-percent"></i> Margem de confiança (%)</label>
              <input type="number" step="0.01" id="margem_confianca" name="margem_confianca" placeholder="%">
            </div>
            <div>
              <label for="status_kr"><i class="fa-solid fa-list-check"></i> Status do KR <span class="helper">(obrigatório)</span></label>
              <select id="status_kr" name="status" required>
                <option value="">Selecione...</option>
                <?php foreach($statusKr as $s): ?>
                  <option value="<?= htmlspecialchars($s['id_status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($s['descricao_exibicao'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
              <div class="note">Se não escolher, será aplicado “Não Iniciado”.</div>
            </div>
            <div>
              <label for="responsavel_kr"><i class="fa-regular fa-user"></i> Responsável pelo KR</label>
              <select id="responsavel_kr" name="responsavel">
                <option value="">Selecione...</option>
                <?php foreach($users as $u): ?>
                  <option value="<?= (int)$u['id_user'] ?>">
                    <?= htmlspecialchars(trim(($u['primeiro_nome'] ?? '').' '.($u['ultimo_nome'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Observações -->
          <div style="margin-top:12px;">
            <label for="observacoes"><i class="fa-regular fa-note-sticky"></i> Observações</label>
            <textarea id="observacoes" name="observacoes" rows="4"></textarea>
          </div>

          <!-- Prévia Milestones -->
          <div class="ms-preview" id="msPreview" style="display:none;">
            <header><i class="fa-solid fa-flask"></i> Prévia de milestones (datas de referência e valor esperado)</header>
            <div class="muted" style="padding:8px 12px;">A prévia usa a mesma lógica do backend. Valores inteiros para unidades discretas (ex.: unid, itens, ord…).</div>
            <div style="max-height:280px; overflow:auto;">
              <table class="ms-table" id="msTable">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Data ref.</th>
                    <th class="right">Esperado</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>

          <div class="save-row">
            <button type="submit" class="btn btn-primary"><i class="fa-regular fa-floppy-disk"></i> Salvar Key Result</button>
          </div>
        </form>
      </section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <!-- Overlays IA -->
  <div id="loadingOverlay" class="overlay" aria-hidden="true">
    <div class="ai-card" role="dialog" aria-live="polite">
      <div class="ai-header">
        <div class="ai-avatar">IA</div>
        <div>
          <div class="ai-title">Analisando seu Key Result…</div>
          <div class="ai-subtle">Calculando a nota e a justificativa.</div>
        </div>
      </div>
      <div class="ai-bubble" style="display:flex;align-items:center;gap:10px;">
        <i class="fa-solid fa-spinner fa-spin"></i>
        <span>Aguarde só um instante…</span>
      </div>
    </div>
  </div>

  <div id="evaluationOverlay" class="overlay" aria-hidden="true">
    <div class="ai-card" role="dialog" aria-modal="true">
      <div class="ai-header">
        <div class="ai-avatar">IA</div>
        <div>
          <div class="ai-title">Avaliação do Key Result</div>
          <div class="ai-subtle">Veja a nota e o porquê.</div>
        </div>
      </div>
      <div class="ai-bubble">
        <div class="score-row">
          <div class="score-pill" id="scoreValue">—</div>
          <span class="quality-badge" id="qualityBadge">avaliando…</span>
        </div>
        <div class="ai-subtle" id="justificationText">—</div>
      </div>
      <div class="ai-actions">
        <button id="editKR" class="btn-ghost"><i class="fa-regular fa-pen-to-square"></i> Editar</button>
        <button id="confirmSave" class="btn btn-primary"><i class="fa-regular fa-floppy-disk"></i> Salvar KR</button>
      </div>
    </div>
  </div>

  <div id="saveMessageOverlay" class="overlay" aria-hidden="true">
    <div class="ai-card" role="alertdialog" aria-modal="true">
      <div class="ai-header">
        <div class="ai-avatar">IA</div>
        <div>
          <div class="ai-title">Tudo certo! ✅</div>
          <div class="ai-subtle">Seu Key Result foi salvo.</div>
        </div>
      </div>
      <div class="ai-bubble">
        <div id="saveAiMessage" class="ai-subtle" style="font-size:1rem; opacity:.9">
          Key Result salvo com sucesso. Vou submetê-lo à aprovação e você receberá uma notificação assim que houver feedback.
        </div>
      </div>
      <div class="ai-actions">
        <button id="closeSaveMsg" class="btn btn-primary">Fechar</button>
      </div>
    </div>
  </div>

  <script>
  // ========= Utilidades =========
  const $  = (s, r=document)=>r.querySelector(s);
  const $$ = (s, r=document)=>Array.from(r.querySelectorAll(s));
  function show(el){ el?.classList.add('show'); el?.setAttribute('aria-hidden','false'); }
  function hide(el){ el?.classList.remove('show'); el?.setAttribute('aria-hidden','true'); }

  // ========= Ajuste com chat lateral =========
  const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
  const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
  function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
  function isOpen(el){ const st=getComputedStyle(el); const vis=st.display!=='none'&&st.visibility!=='hidden'; const w=el.offsetWidth; return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show'); }
  function updateChatWidth(){ const el=findChatEl(); const w=(el && isOpen(el))?el.offsetWidth:0; document.documentElement.style.setProperty('--chat-w',(w||0)+'px'); }
  function setupChatObservers(){ const chat=findChatEl(); if(!chat) return; const mo=new MutationObserver(()=>updateChatWidth()); mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']}); window.addEventListener('resize',updateChatWidth); TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,200)))); updateChatWidth(); }

  // ========= IA helpers =========
  function scoreToQuality(score){
    if(score<=2) return {cls:'q-pessimo', label:'Péssimo'};
    if(score<=4) return {cls:'q-ruim', label:'Ruim'};
    if(score<=6) return {cls:'q-moderado', label:'Moderado'};
    if(score<=8) return {cls:'q-bom', label:'Bom'};
    return {cls:'q-otimo', label:'Ótimo'};
  }

  // ========= Helpers de datas =========
  function pad2(n){ return String(n).padStart(2,'0'); }
  function toISODate(d){ return d ? `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}` : ''; }
  function lastDayOfMonth(year, monthIndex0){ return new Date(year, monthIndex0+1, 0).getDate(); }

  function computePeriodFromCycle(){
    const tipo = ($('#ciclo_tipo')?.value || 'trimestral').toLowerCase();
    const nowY = new Date().getFullYear();
    let start, end;

    if (tipo === 'anual') {
      const y = parseInt($('#ciclo_anual_ano')?.value || nowY, 10);
      start = new Date(y, 0, 1);
      end   = new Date(y, 11, lastDayOfMonth(y,11));
    }
    else if (tipo === 'semestral') {
      const v = $('#ciclo_semestral')?.value || '';
      const m = v.match(/^S([12])\/(\d{4})$/);
      if (m) {
        const s = m[1] === '1' ? 0 : 6;
        const y = parseInt(m[2],10);
        start = new Date(y, s, 1);
        const endMonth = s === 0 ? 5 : 11;
        end   = new Date(y, endMonth, lastDayOfMonth(y,endMonth));
      }
    }
    else if (tipo === 'trimestral') {
      const v = $('#ciclo_trimestral')?.value || '';
      const m = v.match(/^Q([1-4])\/(\d{4})$/);
      if (m) {
        const q = parseInt(m[1],10);
        const y = parseInt(m[2],10);
        const startMonth = (q-1)*3;
        const endMonth   = startMonth+2;
        start = new Date(y, startMonth, 1);
        end   = new Date(y, endMonth, lastDayOfMonth(y,endMonth));
      }
    }
    else if (tipo === 'bimestral') {
      const v = $('#ciclo_bimestral')?.value || '';
      const m = v.match(/^(\d{2})-(\d{2})-(\d{4})$/);
      if (m) {
        const m1 = parseInt(m[1],10)-1, m2 = parseInt(m[2],10)-1, y = parseInt(m[3],10);
        start = new Date(y, m1, 1);
        end   = new Date(y, m2, lastDayOfMonth(y,m2));
      }
    }
    else if (tipo === 'mensal') {
      const mm = parseInt($('#ciclo_mensal_mes')?.value || (new Date().getMonth()+1), 10)-1;
      const yy = parseInt($('#ciclo_mensal_ano')?.value || nowY, 10);
      start = new Date(yy, mm, 1);
      end   = new Date(yy, mm, lastDayOfMonth(yy,mm));
    }
    else if (tipo === 'personalizado') {
      const ini = $('#ciclo_pers_inicio')?.value || '';
      const fim = $('#ciclo_pers_fim')?.value || '';
      if (/^\d{4}-\d{2}$/.test(ini)) {
        const [y1,m1] = ini.split('-').map(n=>parseInt(n,10));
        start = new Date(y1, m1-1, 1);
      }
      if (/^\d{4}-\d{2}$/.test(fim)) {
        const [y2,m2] = fim.split('-').map(n=>parseInt(n,10));
        end = new Date(y2, m2-1, lastDayOfMonth(y2, m2-1));
      }
    }

    if (!start || !end) {
      const d = new Date(), m = d.getMonth()+1, y = d.getFullYear();
      const q = m<=3?1:m<=6?2:m<=9?3:4;
      const sm = (q-1)*3, em = sm+2;
      start = new Date(y, sm, 1);
      end   = new Date(y, em, lastDayOfMonth(y,em));
    }
    return { startISO: toISODate(start), endISO: toISODate(end) };
  }

  // === Série de datas (espelha backend) ===
  function endOfMonth(d){ return new Date(d.getFullYear(), d.getMonth()+1, 0); }
  function endOfMonthOffsetFromStart(d, stepMonths){
    const first = new Date(d.getFullYear(), d.getMonth(), 1);
    const targetFirst = new Date(first.getFullYear(), first.getMonth() + (stepMonths-1), 1);
    return endOfMonth(targetFirst);
  }
  function endOfMonthAdvance(d, stepMonths){
    const first = new Date(d.getFullYear(), d.getMonth(), 1);
    const targetFirst = new Date(first.getFullYear(), first.getMonth() + stepMonths, 1);
    return endOfMonth(targetFirst);
  }
  function gerarSerieDatas(startISO, endISO, freq){
    const out = [];
    if(!startISO || !endISO) return out;
    const start = new Date(startISO);
    const end   = new Date(endISO);
    const f = (freq||'').toLowerCase();

    const pushUnique = (d)=>{
      const iso = toISODate(d);
      if(out.length===0 || out[out.length-1]!==iso){ out.push(iso); }
    };

    if (f==='semanal' || f==='quinzenal'){
      const stepDays = (f==='semanal')?7:15;
      let d = new Date(start);
      d.setDate(d.getDate()+stepDays);
      while (d < end) {
        pushUnique(d);
        d.setDate(d.getDate()+stepDays);
      }
      pushUnique(end);
    } else {
      const stepMonths = ({mensal:1,bimestral:2,trimestral:3,semestral:6,anual:12}[f] || 1);
      const firstEnd = endOfMonthOffsetFromStart(start, stepMonths);
      if (firstEnd > end){
        pushUnique(end);
      } else {
        pushUnique(firstEnd);
        let d = endOfMonthAdvance(firstEnd, stepMonths);
        while (d < end) { pushUnique(d); d = endOfMonthAdvance(d, stepMonths); }
        pushUnique(end);
      }
    }
    if (out.length===0) out.push(toISODate(new Date(endISO)));
    return out;
  }

  function unidadeRequerInteiro(u){
    u = String(u||'').toLowerCase().trim();
    const ints = ['unid','itens','pcs','ord','proc','contratos','processos','pessoas','casos','tickets','visitas'];
    return ints.includes(u);
  }

  function calcularEsperados(datas, baseline, meta, naturezaSlug, direcao, unidade){
    const N = datas.length;
    const out = [];
    const isInt = unidadeRequerInteiro(unidade);
    const round = (v)=> isInt ? Math.round(v) : Math.round(v*100)/100;

    const acum = (naturezaSlug==='acumulativa' || naturezaSlug==='acumulativo');
    const bin  = (naturezaSlug==='binario' || naturezaSlug==='binária' || naturezaSlug==='binaria');
    const maiorMelhor = String(direcao||'').toUpperCase() !== 'MENOR_MELHOR';

    for (let i=1; i<=N; i++){
      let esp = 0;
      if (bin){
        esp = (i===N) ? 1 : 0;
      }
      else if (acum){
        if (maiorMelhor){
          esp = baseline + (meta - baseline) * (i/N);
        } else {
          esp = baseline - (baseline - meta) * (i/N);
        }
      }
      else {
        esp = (i===N) ? meta : 0;
      }
      out.push(round(esp));
    }
    return out;
  }

  function estimateMilestones(startISO, endISO, freq){
    return gerarSerieDatas(startISO, endISO, freq).length;
  }

  function updateBadges(){
    const pb = $('#periodBadge'), pt = $('#periodText');
    const mb = $('#milestoneBadge'), mt = $('#milestoneText');
    const { startISO, endISO } = computePeriodFromCycle();
    const freq = $('#frequencia_apontamento')?.value || '';
    $('#data_inicio').value = startISO;
    $('#data_fim').value    = endISO;

    if (pt){ pt.textContent = `Período: ${startISO} → ${endISO}`; pb.style.display = 'inline-flex'; }
    if (mt && freq){
      const qtd = estimateMilestones(startISO, endISO, (freq||'').toLowerCase());
      mt.textContent = `Estimativa de milestones: ${qtd}`;
      mb.style.display = 'inline-flex';
    } else if (mb){ mb.style.display = 'none'; }
  }

  function ensurePeriodFields(){
    updateBadges();
    if ($('#ciclo_tipo')?.value) $('#ciclo_tipo').classList.add('has-value');
    if ($('#frequencia_apontamento')?.value) $('#frequencia_apontamento').classList.add('has-value');
  }

  const NAT_HELP = {
    'acumulativo': 'Acumulativo (monotônico): progride de forma cumulativa — só sobe ou só desce (ex.: faturamento acumulado, quitação de dívidas).',
    'acumulativa': 'Acumulativo (monotônico): progride de forma cumulativa — só sobe ou só desce (ex.: faturamento acumulado, quitação de dívidas).',
    'pontual': 'Pontual (flutuante): indicador que pode subir ou descer entre medições (ex.: taxa de conversão, NPS).',
    'binario': 'Binário (feito/não feito): realização discreta — ou foi atingido, ou não (ex.: lançar o app, fechar o contrato X).'
  };

  function applyNaturezaBehavior(){
    const sel = $('#natureza_kr');
    const val = (sel?.value || '').toLowerCase();
    const help = $('#natureza_help');
    if (help){ help.textContent = NAT_HELP[val] || ''; }

    const base = $('#baseline'), meta = $('#meta');
    if (val === 'binario'){
      if (base){ if(base.value==='') base.value='0'; base.readOnly = true; base.classList.add('has-value'); }
      if (meta){ if(meta.value==='') meta.value='1'; meta.readOnly = true; meta.classList.add('has-value'); }
    } else {
      if (base){ base.readOnly=false; }
      if (meta){ meta.readOnly=false; }
    }
    renderMilestonesPreview();
  }

  function ensureDefaultStatus(){
    const sel = $('#status_kr');
    if (!sel || sel.value) return;
    const optNI = Array.from(sel.options).find(o => (o.textContent||'').toLowerCase().includes('não iniciado'));
    if (optNI){ sel.value = optNI.value; sel.classList.add('has-value'); return; }
    const firstValid = Array.from(sel.options).find(o => (o.value||'')!=='');
    if (firstValid){ sel.value = firstValid.value; sel.classList.add('has-value'); }
  }

  function renderMilestonesPreview(){
    const freq = ($('#frequencia_apontamento')?.value || '').toLowerCase();
    const { startISO, endISO } = computePeriodFromCycle();
    const datas = gerarSerieDatas(startISO, endISO, freq);

    const base = parseFloat($('#baseline')?.value||'');
    const meta = parseFloat($('#meta')?.value||'');
    const naturezaRaw = ($('#natureza_kr')?.value||'').toLowerCase();
    const naturezaSlug = naturezaRaw==='acumulativo' ? 'acumulativa' : naturezaRaw;
    const direcao = $('#direcao_metrica')?.value || '';
    const unidade = $('#unidade_medida')?.value || '';

    const tbody = $('#msTable tbody');
    const wrapper = $('#msPreview');

    if (!freq || !datas.length || isNaN(base) || isNaN(meta)) {
      if (wrapper) wrapper.style.display='none';
      return;
    }

    const esperados = calcularEsperados(datas, base, meta, naturezaSlug, direcao, unidade);

    tbody.innerHTML = '';
    datas.forEach((d, i)=>{
      const tr = document.createElement('tr');
      const td1 = document.createElement('td'); td1.textContent = String(i+1);
      const td2 = document.createElement('td'); td2.textContent = d;
      const td3 = document.createElement('td'); td3.className='right'; td3.textContent = String(esperados[i]);
      tr.append(td1,td2,td3);
      tbody.appendChild(tr);
    });

    wrapper.style.display = 'block';
    updateBadges();
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    // Chat lateral
    (function setupChatObservers(){
      const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
      const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
      function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
      function isOpen(el){ const st=getComputedStyle(el); const vis=st.display!=='none'&&st.visibility!=='hidden'; const w=el.offsetWidth; return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show'); }
      function updateChatWidth(){ const el=findChatEl(); const w=(el && isOpen(el))?el.offsetWidth:0; document.documentElement.style.setProperty('--chat-w',(w||0)+'px'); }
      const chat=findChatEl(); if(chat){ const mo=new MutationObserver(()=>updateChatWidth()); mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']}); window.addEventListener('resize',updateChatWidth); TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,200)))); updateChatWidth(); }
    })();

    const form = $('#krForm');
    const loadOv = $('#loadingOverlay');
    const evalOv = $('#evaluationOverlay');
    const saveOv = $('#saveMessageOverlay');

    // Objetivo -> status + badge
    const selObj = $('#id_objetivo');
    const statusField = $('#status_objetivo');
    const badge = $('#selectedObjBadge');
    const badgeText = $('#selectedObjText');

    function updateStatusObjetivo(){
      const opt = selObj?.selectedOptions?.[0];
      const status = (opt?.dataset?.status || '—').toUpperCase();
      statusField.value = status;
      const name = (opt && opt.value) ? (opt.textContent || '').trim() : '';
      if (name) {
        selObj.classList.add('has-value');
        if (badge && badgeText){ badgeText.textContent = name; badge.style.display = 'inline-flex'; }
      } else {
        selObj.classList.remove('has-value');
        if (badge) badge.style.display = 'none';
      }
    }
    if (selObj) { selObj.addEventListener('change', updateStatusObjetivo); if (selObj.value) updateStatusObjetivo(); }

    // Ciclos
    const tipoCiclo = $('#ciclo_tipo');
    const boxes = {
      anual: $('#ciclo_detalhe_anual'),
      semestral: $('#ciclo_detalhe_semestral'),
      trimestral: $('#ciclo_detalhe_trimestral'),
      bimestral: $('#ciclo_detalhe_bimestral'),
      mensal: $('#ciclo_detalhe_mensal'),
      personalizado: $('#ciclo_detalhe_personalizado'),
    };

    function toggleDetail(){
      const v = (tipoCiclo?.value || 'trimestral').toLowerCase();
      Object.entries(boxes).forEach(([k,el])=>{ if(!el) return; el.style.display = (k===v) ? '' : 'none'; });
      ensurePeriodFields();
      renderMilestonesPreview();
    }

    (function populateCycleDetails(){
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
        if(!sTri.value){
          const m=new Date().getMonth()+1; const q=m<=3?'Q1':m<=6?'Q2':m<=9?'Q3':'Q4';
          const opt=[...sTri.options].find(o=>o.value.startsWith(q+'/')); if(opt) sTri.value=opt.value;
        }
      }

      const sBim=$('#ciclo_bimestral');
      if (sBim){
        for(let y=anoAtual; y<=anoAtual+5; y++){
          for(let i=0;i<12;i+=2){
            const d1=new Date(y,i), d2=new Date(y,i+1);
            const m1=d1.toLocaleString('pt-BR',{month:'short'}); const m2=d2.toLocaleString('pt-BR',{month:'short'});
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

      toggleDetail();
      if (tipoCiclo && !tipoCiclo.value) { tipoCiclo.value='trimestral'; toggleDetail(); }
      tipoCiclo?.addEventListener('change', toggleDetail);

      ['#ciclo_anual_ano','#ciclo_semestral','#ciclo_trimestral','#ciclo_bimestral',
       '#ciclo_mensal_mes','#ciclo_mensal_ano','#ciclo_pers_inicio','#ciclo_pers_fim']
       .forEach(sel => { const el=$(sel); el && el.addEventListener('change', ()=>{ ensurePeriodFields(); renderMilestonesPreview(); }); });

      $('#frequencia_apontamento')?.addEventListener('change', e=>{
        if(e.target.value) e.target.classList.add('has-value'); else e.target.classList.remove('has-value');
        updateBadges();
        renderMilestonesPreview();
      });

      ensurePeriodFields();
    })();

    // Natureza (ajuda + binário)
    $('#natureza_kr')?.addEventListener('change', applyNaturezaBehavior);
    applyNaturezaBehavior();

    // Status default
    ensureDefaultStatus();

    // Campos que disparam re-render da prévia
    ['#baseline','#meta','#unidade_medida','#direcao_metrica']
      .forEach(sel=>{ const el=$(sel); el && el.addEventListener('input', renderMilestonesPreview); });

    function setLoading(on){ on ? show(loadOv) : hide(loadOv); }

    async function avaliarKR(){
      ensurePeriodFields();
      const fd = new FormData($('#krForm'));
      fd.append('evaluate','1');
      setLoading(true);
      try{
        const res = await fetch($('#krForm').action, { method:'POST', body:fd });
        const data = await res.json().catch(()=> ({}));
        setLoading(false);

        if(res.status===422 && Array.isArray(data.errors)){
          const lista = data.errors.map(e=>`• ${e.message}`).join('\n');
          alert(`Por favor, corrija os campos obrigatórios:\n\n${lista}`);
          return;
        }
        if(!res.ok || typeof data.score==='undefined' || typeof data.justification==='undefined'){
          alert(data.error || 'Falha na avaliação IA.');
          return;
        }
        $('#score_ia').value = data.score;
        $('#justificativa_ia').value = data.justification;

        $('#scoreValue').textContent = data.score;
        $('#justificationText').textContent = data.justification;
        const q=scoreToQuality(Number(data.score));
        $('#qualityBadge').textContent=q.label;
        $('#qualityBadge').className='quality-badge '+q.cls;

        show($('#evaluationOverlay'));
      }catch(err){
        setLoading(false);
        alert(err?.message || 'Falha na avaliação IA. Tente novamente.');
      }
    }

    async function salvarKR(){
      ensurePeriodFields();
      ensureDefaultStatus();
      const fd = new FormData($('#krForm'));
      fd.delete('evaluate');
      setLoading(true);
      try{
        const res = await fetch($('#krForm').action, { method:'POST', body:fd });
        const data = await res.json().catch(()=> ({}));
        setLoading(false);
        if(res.ok && data?.success){
          const el = $('#saveAiMessage');
          if(el){
            const idKR = data.id_kr ? `<strong>${data.id_kr}</strong>` : 'Seu Key Result';
            el.innerHTML = `${idKR} foi salvo com sucesso.<br>Vou submetê-lo à aprovação e te aviso quando houver feedback.`;
          }
          show($('#saveMessageOverlay'));
        } else {
          throw new Error(data?.error || 'Erro ao salvar Key Result.');
        }
      }catch(err){
        setLoading(false);
        alert(err?.message || 'Erro de rede ao salvar.');
      }
    }

    $('#krForm')?.addEventListener('submit', (e)=>{ e.preventDefault(); avaliarKR(); });
    $('#confirmSave')?.addEventListener('click', ()=>{ hide($('#evaluationOverlay')); salvarKR(); });
    $('#editKR')?.addEventListener('click', ()=> hide($('#evaluationOverlay')));
    $('#closeSaveMsg')?.addEventListener('click', ()=> window.location.reload());

    // Prévia inicial
    renderMilestonesPreview();
  });
  </script>
</body>
</html>
