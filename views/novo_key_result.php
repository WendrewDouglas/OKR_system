<?php
// views/novo_key_result.php

// DEV ONLY: exibe erros na tela (remova em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

// Redireciona se não estiver logado
if (!isset($_SESSION['user_id'])) {
    header('Location: /OKR_system/views/login.php');
    exit;
}

// Conexão PDO
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die("Erro ao conectar: " . $e->getMessage());
}

// Gera token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch domínio
$objetivosStmt = $pdo->prepare("SELECT id_objetivo, descricao, status_aprovacao FROM objetivos ORDER BY dt_prazo ASC");
$objetivosStmt->execute();
$objetivos = $objetivosStmt->fetchAll();

$tiposKrStmt = $pdo->query("SELECT id_tipo, descricao_exibicao FROM dom_tipo_kr ORDER BY descricao_exibicao");
$tiposKr     = $tiposKrStmt->fetchAll();

$natStmt     = $pdo->query("SELECT id_natureza, descricao_exibicao FROM dom_natureza_kr ORDER BY descricao_exibicao");
$naturezasKr = $natStmt->fetchAll();

$usersStmt = $pdo->query("SELECT id_user, primeiro_nome, ultimo_nome FROM usuarios ORDER BY primeiro_nome");
$users     = $usersStmt->fetchAll();

$ciclosStmt = $pdo->query("SELECT id_ciclo, nome_ciclo, descricao FROM dom_ciclos ORDER BY id_ciclo");
$ciclos = $ciclosStmt->fetchAll();

$freqStmt    = $pdo->query("SELECT id_frequencia, descricao_exibicao FROM dom_tipo_frequencia_milestone ORDER BY descricao_exibicao");
$frequencias = $freqStmt->fetchAll();

$statusStmt = $pdo->query("SELECT id_status, descricao_exibicao FROM dom_status_kr ORDER BY descricao_exibicao");
$statusKr   = $statusStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cadastrar Novo Key Result – OKR System</title>
  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css" crossorigin="anonymous"/>
  <style>
    .d-none { display: none !important; }
    .main-wrapper { display: flex; gap: 2%; margin: 2rem; align-items: flex-start; }
    .form-container { flex: 2; background: #fff; padding: 1rem; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    h1 { display:flex; align-items:center; font-size:1.75rem; margin-bottom:1.5rem; }
    .info-inline { margin-right:6px; color:#6c757d; cursor:pointer; }
    .form-group { margin-bottom:1rem; }
    label { display:flex; align-items:center; font-weight:500; margin-bottom:0.25rem; }
    input.form-control, select.form-control, textarea.form-control { width:100%; padding:0.5rem; border:1px solid #ccc; border-radius:4px; }
    .form-two-col { display:grid; grid-template-columns:2fr 1fr; gap:1rem; }
    .form-three-col { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; }
    @media(max-width:800px) { .form-three-col { grid-template-columns:1fr; } }
    @media(max-width:600px) { .form-two-col, .form-three-col { grid-template-columns:1fr; } }
    .btn-save { display:block; margin:2rem auto 0; width:160px; padding:0.5rem; }
    #id_objetivo, #id_objetivo option, #status_objetivo { text-transform: uppercase; }
    .kr-details-box { background: #f8f9fa; border:1px solid #e9ecef; border-radius:6px; padding:1rem; margin-top:1.5rem; }
    .kr-details-box h2 { font-size:1.25rem; margin-bottom:1rem; }
    .ciclo-row { display:flex; align-items:flex-end; gap:1rem; flex-wrap:nowrap; }
    .ciclo-row > .col { flex:1; }

    /* ===== IA Card / Bubble (novo visual) ===== */
    .overlay { position:fixed; top:0; left:0; width:100%; height:100%;
      background:rgba(2,6,23,0.68); display:flex; align-items:center; justify-content:center; z-index:2000; }
    .overlay.d-none { display:none !important; }

    .ai-card {
      width: min(720px, 92vw);
      background: #0b1020;
      color: #e6e9f2;
      border-radius: 18px;
      box-shadow: 0 20px 60px rgba(0,0,0,.35);
      padding: 18px 18px 16px;
      position: relative;
      overflow: hidden;
    }
    .ai-card::after {
      content: "";
      position: absolute; inset: 0;
      background: radial-gradient(1000px 300px at 10% -20%, rgba(64,140,255,.18), transparent 60%),
                  radial-gradient(700px 220px at 100% 0%, rgba(0,196,204,.12), transparent 60%);
      pointer-events: none;
    }
    .ai-header { display:flex; align-items:center; gap:12px; margin-bottom:10px; }
    .ai-avatar {
      width: 44px; height: 44px; flex:0 0 44px;
      border-radius: 50%;
      background: conic-gradient(from 180deg at 50% 50%, #3b82f6, #06b6d4, #8b5cf6, #3b82f6);
      display:grid; place-items:center;
      box-shadow: 0 6px 18px rgba(59,130,246,.35);
      color:#fff; font-weight:700; letter-spacing:.5px;
    }
    .ai-title { font-size:.95rem; line-height:1.1; opacity:.9; }
    .ai-subtle { font-size:.85rem; opacity:.65; }
    .ai-bubble {
      background:#111833; border:1px solid rgba(255,255,255,.06);
      border-radius:14px; padding:16px; margin:8px 0 14px;
    }
    .score-row { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:6px; }
    .score-pill {
      font-variation-settings:"wght" 700; font-weight:700;
      font-size:2.25rem; line-height:1;
      padding:6px 14px; border-radius:12px;
      background: linear-gradient(135deg, rgba(59,130,246,.16), rgba(2,132,199,.12));
      border:1px solid rgba(255,255,255,.08);
    }
    .quality-badge {
      padding:4px 10px; border-radius:999px; font-size:.8rem;
      border:1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.06);
      text-transform:capitalize;
    }
    .quality-badge.q-pessimo { background: rgba(239,68,68,.15); border-color: rgba(239,68,68,.25); }
    .quality-badge.q-ruim    { background: rgba(245,158,11,.15); border-color: rgba(245,158,11,.25); }
    .quality-badge.q-moderado{ background: rgba(14,165,233,.15); border-color: rgba(14,165,233,.25); }
    .quality-badge.q-bom     { background: rgba(34,197,94,.15); border-color: rgba(34,197,94,.25); }
    .quality-badge.q-otimo   { background: rgba(168,85,247,.16); border-color: rgba(168,85,247,.25); }

    .ai-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:6px; }
    .ai-actions .btn-lg { border-radius:12px; padding:.7rem 1rem; }
    .btn-ghost { background:transparent; border:1px solid rgba(255,255,255,.16); color:#d1d5db; }
    .btn-ghost:hover { background: rgba(255,255,255,.06); color:#fff; }

    .ai-success { display:flex; align-items:flex-start; gap:12px; }
    .ai-success .ai-bubble { margin-top:0; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../views/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/../views/partials/header.php'; ?>
    <main id="main-content" class="main-wrapper">
      <div class="form-container">
        <h1><i class="fas fa-bullseye info-inline"></i>Cadastrar Novo Key Result</h1>
        <form id="krForm" action="/OKR_system/auth/salvar_kr.php" method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
          <input type="hidden" id="score_ia" name="score_ia" value="">
          <input type="hidden" id="justificativa_ia" name="justificativa_ia" value="">

          <!-- Objetivo associado + status -->
          <div class="form-two-col">
            <div class="form-group">
              <label for="id_objetivo">
                <i class="fas fa-info-circle info-inline" data-bs-toggle="tooltip" title="Selecione o objetivo ao qual este Key Result está vinculado."></i>
                Objetivo Associado<span class="text-danger">*</span>
              </label>
              <select id="id_objetivo" name="id_objetivo" class="form-control" required>
                <option value="">Selecione...</option>
                <?php foreach ($objetivos as $o): ?>
                  <option value="<?=htmlspecialchars($o['id_objetivo'])?>" data-status="<?=htmlspecialchars($o['status_aprovacao'])?>">
                    <?=htmlspecialchars($o['descricao'])?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="status_objetivo">Status do Objetivo</label>
              <input type="text" id="status_objetivo" class="form-control" readonly>
            </div>
          </div>

          <!-- Detalhes do Key Result -->
          <div class="kr-details-box">
            <h2>Detalhes do Key Result</h2>

            <!-- Descrição -->
            <div class="form-group">
              <label for="descricao_kr">Descrição do Key Result<span class="text-danger">*</span></label>
              <textarea id="descricao_kr" name="descricao" class="form-control" rows="3" required></textarea>
            </div>

            <!-- Baseline, Meta e Unidade -->
            <div class="form-three-col">
              <div class="form-group">
                <label for="baseline">Baseline<span class="text-danger">*</span></label>
                <input type="number" step="0.01" id="baseline" name="baseline" class="form-control" required>
              </div>
              <div class="form-group">
                <label for="meta">Meta<span class="text-danger">*</span></label>
                <input type="number" step="0.01" id="meta" name="meta" class="form-control" required>
              </div>
              <div class="form-group">
                <label for="unidade_medida">Unidade de Medida</label>
                <select id="unidade_medida" name="unidade_medida" class="form-control">
                  <option value="">Selecione...</option>
                  <optgroup label="Financeiro">
                    <option value="R$">Real (R$)</option>
                    <option value="US$">Dólar (US$)</option>
                    <option value="€">Euro (€)</option>
                    <option value="£">Libra (£)</option>
                    <option value="R$/unid">Custo por unidade (R$/unid)</option>
                  </optgroup>
                  <optgroup label="Percentuais e Taxas">
                    <option value="%">Percentual (%)</option>
                    <option value="pp">Ponto percentual (pp)</option>
                    <option value="taxa_conversao">% Conversão</option>
                    <option value="taxa_churn">% Churn</option>
                  </optgroup>
                  <optgroup label="Operacional / Quantitativo">
                    <option value="unid">Unidade (unid)</option>
                    <option value="itens">Itens</option>
                    <option value="pcs">Peças</option>
                    <option value="ord">Ordens</option>
                    <option value="proc">Processos</option>
                  </optgroup>
                  <optgroup label="Tempo">
                    <option value="h">Hora (h)</option>
                    <option value="d">Dia (d)</option>
                    <option value="sem">Semana (sem)</option>
                    <option value="mês">Mês</option>
                    <option value="tri">Trimestre (tri)</option>
                    <option value="a">Ano (a)</option>
                  </optgroup>
                  <optgroup label="Índices e Pontuações">
                    <option value="pts">Pontos (pts)</option>
                    <option value="nps">NPS</option>
                    <option value="rating">Rating</option>
                  </optgroup>
                  <optgroup label="Dimensão / Volume">
                    <option value="km">Quilômetro (km)</option>
                    <option value="m">Metro (m)</option>
                    <option value="L">Litro (L)</option>
                    <option value="m3">Metro cúbico (m³)</option>
                  </optgroup>
                  <optgroup label="Massa">
                    <option value="kg">Quilograma (kg)</option>
                    <option value="g">Grama (g)</option>
                  </optgroup>
                </select>
              </div>
            </div>

            <!-- Direção, Natureza e Tipo -->
            <div class="form-three-col">
              <div class="form-group">
                <label for="direcao_metrica">Direção da Métrica</label>
                <select id="direcao_metrica" name="direcao_metrica" class="form-control">
                  <option value="">Selecione...</option>
                  <option value="MAIOR_MELHOR">Maior Melhor</option>
                  <option value="MENOR_MELHOR">Menor Melhor</option>
                  <option value="INTERVALO_IDEAL">Intervalo Ideal</option>
                </select>
              </div>
              <div class="form-group">
                <label for="natureza_kr">Natureza do KR</label>
                <select id="natureza_kr" name="natureza_kr" class="form-control">
                  <option value="">Selecione...</option>
                  <?php foreach($naturezasKr as $n): ?>
                  <option value="<?=htmlspecialchars($n['id_natureza'])?>">
                    <?=htmlspecialchars($n['descricao_exibicao'])?>
                  </option>
                  <?php endforeach;?>
                </select>
              </div>
              <div class="form-group">
                <label for="tipo_kr">Tipo de KR</label>
                <select id="tipo_kr" name="tipo_kr" class="form-control">
                  <option value="">Selecione...</option>
                  <?php foreach($tiposKr as $t): ?>
                  <option value="<?=htmlspecialchars($t['id_tipo'])?>">
                    <?=htmlspecialchars($t['descricao_exibicao'])?>
                  </option>
                  <?php endforeach;?>
                </select>
              </div>
            </div>

            <!-- Ciclo, Detalhe e Frequência -->
            <div class="form-group ciclo-row">
              <div class="col">
                <label for="ciclo_tipo">Ciclo<span class="text-danger">*</span></label>
                <select id="ciclo_tipo" name="ciclo_tipo" class="form-control" required>
                  <?php foreach ($ciclos as $c): ?>
                  <option value="<?=htmlspecialchars(strtolower($c['nome_ciclo']))?>" <?=strtolower($c['nome_ciclo'])==='trimestral'?'selected':''?>>
                    <?=htmlspecialchars($c['descricao'])?>
                  </option>
                  <?php endforeach;?>
                </select>
              </div>
              <div id="ciclo_detalhe_wrapper" class="col">
                <div id="ciclo_detalhe_anual" class="detalhe d-none">
                  <select id="ciclo_anual_ano" name="ciclo_anual_ano" class="form-control"></select>
                </div>
                <div id="ciclo_detalhe_semestral" class="detalhe d-none">
                  <select id="ciclo_semestral" name="ciclo_semestral" class="form-control"></select>
                </div>
                <div id="ciclo_detalhe_trimestral" class="detalhe">
                  <select id="ciclo_trimestral" name="ciclo_trimestral" class="form-control"></select>
                </div>
                <div id="ciclo_detalhe_bimestral" class="detalhe d-none">
                  <select id="ciclo_bimestral" name="ciclo_bimestral" class="form-control"></select>
                </div>
                <div id="ciclo_detalhe_mensal" class="detalhe d-none d-flex">
                  <select id="ciclo_mensal_mes" name="ciclo_mensal_mes" class="form-control"></select>
                  <select id="ciclo_mensal_ano" name="ciclo_mensal_ano" class="form-control"></select>
                </div>
                <div id="ciclo_detalhe_personalizado" class="detalhe d-none d-flex">
                  <input type="month" id="ciclo_pers_inicio" name="ciclo_pers_inicio" class="form-control">
                  <input type="month" id="ciclo_pers_fim" name="ciclo_pers_fim" class="form-control">
                </div>
              </div>
              <div class="col">
                <label for="frequencia_apontamento">Frequência de Apontamento<span class="text-danger">*</span></label>
                <select id="frequencia_apontamento" name="tipo_frequencia_milestone" class="form-control" required>
                  <option value="">Selecione...</option>
                  <?php foreach($frequencias as $f): ?>
                  <option value="<?=htmlspecialchars($f['id_frequencia'])?>">
                    <?=htmlspecialchars($f['descricao_exibicao'])?>
                  </option>
                  <?php endforeach;?>
                </select>
              </div>
            </div>

            <!-- Margem, Status e Responsável -->
            <div class="form-three-col">
              <div class="form-group">
                <label for="margem_confianca">Margem de Confiança (%)</label>
                <input type="number" step="0.01" id="margem_confianca" name="margem_confianca" class="form-control" placeholder="%">
              </div>
              <div class="form-group">
                <label for="status_kr">Status do KR</label>
                <select id="status_kr" name="status" class="form-control">
                  <option value="">Selecione...</option>
                  <?php foreach($statusKr as $s): ?>
                  <option value="<?=htmlspecialchars($s['id_status'])?>">
                    <?=htmlspecialchars($s['descricao_exibicao'])?>
                  </option>
                  <?php endforeach;?>
                </select>
              </div>
              <div class="form-group">
                <label for="responsavel_kr">Responsável pelo KR</label>
                <select id="responsavel_kr" name="responsavel" class="form-control">
                  <option value="">Selecione...</option>
                  <?php foreach($users as $u): ?>
                  <option value="<?=htmlspecialchars($u['id_user'])?>">
                    <?=htmlspecialchars($u['primeiro_nome'].' '.$u['ultimo_nome'])?>
                  </option>
                  <?php endforeach;?>
                </select>
              </div>
            </div>

            <!-- Observações -->
            <div class="form-group">
              <label for="observacoes">Observações</label>
              <textarea id="observacoes" name="observacoes" class="form-control" rows="4"></textarea>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-save">Salvar Key Result</button>
        </form>
      </div>

      <?php include __DIR__ . '/../views/partials/chat.php'; ?>
    </main>
  </div>

  <!-- Overlays (NOVO VISUAL IA) -->
  <div id="loadingOverlay" class="overlay d-none">
    <div class="ai-card" role="dialog" aria-live="polite">
      <div class="ai-header">
        <div class="ai-avatar">IA</div>
        <div>
          <div class="ai-title">Analisando seu Key Result…</div>
          <div class="ai-subtle">Estou calculando a nota e a justificativa.</div>
        </div>
      </div>
      <div class="ai-bubble" style="display:flex;align-items:center;gap:10px;">
        <i class="fas fa-spinner fa-spin"></i>
        <span>Aguarde só um instante…</span>
      </div>
    </div>
  </div>

  <div id="evaluationOverlay" class="overlay d-none">
    <div class="ai-card" role="dialog" aria-modal="true">
      <div class="ai-header">
        <div class="ai-avatar">IA</div>
        <div>
          <div class="ai-title">Avaliação do Key Result</div>
          <div class="ai-subtle">Veja minha nota e o porquê.</div>
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
        <button id="editKR" class="btn btn-ghost btn-lg"><i class="fa-regular fa-pen-to-square me-1"></i>Editar</button>
        <button id="confirmSave" class="btn btn-primary btn-lg"><i class="fa-regular fa-floppy-disk me-1"></i>Salvar KR</button>
      </div>
    </div>
  </div>

  <div id="saveMessageOverlay" class="overlay d-none">
    <div class="ai-card" role="alertdialog" aria-modal="true">
      <div class="ai-header">
        <div class="ai-avatar">IA</div>
        <div>
          <div class="ai-title">Tudo certo! ✅</div>
          <div class="ai-subtle">Seu Key Result foi salvo.</div>
        </div>
      </div>

      <div class="ai-bubble ai-success">
        <div style="flex:1">
          <div id="saveAiMessage" class="ai-subtle" style="font-size:1rem; opacity:.9">
            Key Result salvo com sucesso. Vou submetê-lo à aprovação e você receberá uma notificação assim que houver feedback do aprovador.
          </div>
        </div>
      </div>

      <div class="ai-actions">
        <button id="closeSaveMsg" class="btn btn-primary btn-lg">Fechar</button>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* =========================
   novo_key_result.js – fluxo avaliação IA (0–10) -> confirmar -> salvar
   Com visual estilo "IA" e mensagens amigáveis
   ========================= */

(function () {
  "use strict";

  // Silencia um warning chato de extensões
  window.addEventListener("unhandledrejection", (e) => {
    if (e?.reason?.message?.includes("A listener indicated an asynchronous response")) {
      e.preventDefault();
    }
  });

  document.addEventListener("DOMContentLoaded", () => {
    // ------------- UTIL -------------
    const $ = (sel, root = document) => root.querySelector(sel);
    const $all = (sel, root = document) => Array.from(root.querySelectorAll(sel));
    const show = (el) => el && el.classList.remove("d-none");
    const hide = (el) => el && el.classList.add("d-none");

    // ------------- TOOLTIP -------------
    try { $all("[data-bs-toggle='tooltip']").forEach((el) => new bootstrap.Tooltip(el)); } catch (_) {}

    // ------------- CAMPOS / ELEMENTOS -------------
    const form = $("#krForm");
    const loadOv = $("#loadingOverlay");
    const evalOv = $("#evaluationOverlay");
    const saveOv = $("#saveMessageOverlay");
    const scoreVal = $("#scoreValue");
    const justText = $("#justificationText");
    const qualityBadge = $("#qualityBadge");
    const btnConfirmSave = $("#confirmSave");
    const btnEditKR = $("#editKR");
    const btnCloseSaveMsg = $("#closeSaveMsg");

    const selObj = $("#id_objetivo");
    const statusField = $("#status_objetivo");

    const tipoCiclo = $("#ciclo_tipo");
    const containers = {
      anual: $("#ciclo_detalhe_anual"),
      semestral: $("#ciclo_detalhe_semestral"),
      trimestral: $("#ciclo_detalhe_trimestral"),
      bimestral: $("#ciclo_detalhe_bimestral"),
      mensal: $("#ciclo_detalhe_mensal"),
      personalizado: $("#ciclo_detalhe_personalizado"),
    };

    // ------------- Função: nota -> qualidade -------------
    function scoreToQuality(score) {
      if (score <= 2) return { id: 'péssimo', cls: 'q-pessimo', label: 'Péssimo' };
      if (score <= 4) return { id: 'ruim',    cls: 'q-ruim',    label: 'Ruim' };
      if (score <= 6) return { id: 'moderado',cls: 'q-moderado',label: 'Moderado' };
      if (score <= 8) return { id: 'bom',     cls: 'q-bom',     label: 'Bom' };
      return { id: 'ótimo',   cls: 'q-otimo',  label: 'Ótimo' };
    }

    // ------------- STATUS DO OBJETIVO -------------
    function updateStatusObjetivo() {
      const opt = selObj?.selectedOptions?.[0];
      statusField.value = (opt?.dataset?.status || "").toUpperCase();
    }
    if (selObj && statusField) {
      selObj.addEventListener("change", updateStatusObjetivo);
      if (selObj.value) updateStatusObjetivo();
    }

    // ------------- CICLO: MOSTRAR / OCULTAR DETALHE -------------
    function toggleDetail() {
      const selected = tipoCiclo?.value || "trimestral";
      Object.keys(containers).forEach((key) => {
        const box = containers[key];
        if (!box) return;
        if (key === selected) show(box);
        else hide(box);
      });
    }
    if (tipoCiclo) tipoCiclo.addEventListener("change", toggleDetail);

    // ------------- POPULAR DETALHES DE CICLO -------------
    (function populateCycleDetails() {
      const anoAtual = new Date().getFullYear();

      // ANUAL
      if (containers.anual) {
        const sel = containers.anual.querySelector("select");
        for (let y = anoAtual; y <= anoAtual + 5; y++) {
          sel.add(new Option(String(y), String(y)));
        }
      }

      // SEMESTRAL
      if (containers.semestral) {
        const sel = containers.semestral.querySelector("select");
        for (let y = anoAtual; y <= anoAtual + 5; y++) {
          sel.add(new Option(`1º Sem/${y}`, `S1/${y}`));
          sel.add(new Option(`2º Sem/${y}`, `S2/${y}`));
        }
      }

      // TRIMESTRAL
      if (containers.trimestral) {
        const sel = containers.trimestral.querySelector("select");
        ["Q1", "Q2", "Q3", "Q4"].forEach((q) => {
          for (let y = anoAtual; y <= anoAtual + 5; y++) {
            sel.add(new Option(`${q}/${y}`, `${q}/${y}`));
          }
        });
        if (!sel.value) {
          const mes = new Date().getMonth() + 1;
          const q = mes <= 3 ? "Q1" : mes <= 6 ? "Q2" : mes <= 9 ? "Q3" : "Q4";
          const opt = Array.from(sel.options).find((o) => o.value.startsWith(q + "/"));
          if (opt) sel.value = opt.value;
        }
      }

      // BIMESTRAL
      if (containers.bimestral) {
        const sel = containers.bimestral.querySelector("select");
        for (let y = anoAtual; y <= anoAtual + 5; y++) {
          for (let i = 0; i < 12; i += 2) {
            const d1 = new Date(y, i);
            const d2 = new Date(y, i + 1);
            const m1s = d1.toLocaleString("pt-BR", { month: "short" });
            const m2s = d2.toLocaleString("pt-BR", { month: "short" });
            const label = `${m1s.charAt(0).toUpperCase()+m1s.slice(1)}–${m2s.charAt(0).toUpperCase()+m2s.slice(1)}/${y}`;
            const value = `${String(d1.getMonth()+1).padStart(2,"0")}-${String(d2.getMonth()+1).padStart(2,"0")}-${y}`;
            sel.add(new Option(label, value));
          }
        }
      }

      // MENSAL
      if (containers.mensal) {
        const selMes = containers.mensal.querySelector("#ciclo_mensal_mes");
        const selAno = containers.mensal.querySelector("#ciclo_mensal_ano");
        const meses = ["Janeiro","Fevereiro","Março","Abril","Maio","Junho","Julho","Agosto","Setembro","Outubro","Novembro","Dezembro"];
        meses.forEach((m, i) => selMes.add(new Option(m, String(i + 1).padStart(2, "0"))));
        for (let y = anoAtual; y <= anoAtual + 5; y++) selAno.add(new Option(String(y), String(y)));
        if (!selMes.value) selMes.value = String(new Date().getMonth() + 1).padStart(2, "0");
        if (!selAno.value) selAno.value = String(anoAtual);
      }

      toggleDetail();
      if (tipoCiclo && !tipoCiclo.value) { tipoCiclo.value = "trimestral"; toggleDetail(); }
    })();

    // ------------- SUBMIT (Avaliar -> Confirmar -> Salvar) -------------
    function setOverlayLoading(on) {
      if (on) show(loadOv); else hide(loadOv);
    }

    async function avaliarKR() {
      const fd = new FormData(form);
      fd.append("evaluate", "1");
      setOverlayLoading(true);
      try {
        const res = await fetch(form.action, { method: "POST", body: fd });
        const data = await res.json().catch(() => ({}));
        setOverlayLoading(false);

        if (res.status === 422 && Array.isArray(data.errors)) {
          const lista = data.errors.map(e => `• ${e.message}`).join("\n");
          alert(`Por favor, corrija os campos obrigatórios:\n\n${lista}`);
          return;
        }
        if (!res.ok || typeof data.score === "undefined" || typeof data.justification === "undefined") {
          alert(data.error || "Falha na avaliação IA.");
          return;
        }

        // Preenche card IA
        scoreVal.textContent = data.score;
        justText.textContent = data.justification;
        const q = scoreToQuality(Number(data.score));
        qualityBadge.textContent = q.label;
        qualityBadge.className = 'quality-badge ' + q.cls;

        // Preenche campos ocultos para o backend
        $("#score_ia").value = data.score;
        $("#justificativa_ia").value = data.justification;

        show(evalOv);
      } catch (err) {
        setOverlayLoading(false);
        alert(err?.message || "Falha na avaliação IA. Tente novamente.");
      }
    }

    async function salvarKR() {
      const fd = new FormData(form);
      fd.delete("evaluate");
      setOverlayLoading(true);
      try {
        const res = await fetch(form.action, { method: "POST", body: fd });
        const data = await res.json().catch(() => ({}));
        setOverlayLoading(false);
        if (res.ok && data?.success) {
          const el = document.getElementById('saveAiMessage');
          if (el) {
            const idKR = data.id_kr ? `<strong>${data.id_kr}</strong>` : 'Seu Key Result';
            el.innerHTML = `${idKR} foi salvo com sucesso.<br>Vou submetê-lo à aprovação e te aviso assim que houver um feedback do aprovador.`;
          }
          show(saveOv);
        } else {
          throw new Error(data?.error || "Erro ao salvar Key Result.");
        }
      } catch (err) {
        setOverlayLoading(false);
        alert(err?.message || "Erro de rede ao salvar.");
      }
    }

    // Intercepta submit do formulário para primeiro avaliar
    if (form) {
      form.addEventListener("submit", (e) => {
        e.preventDefault();
        avaliarKR();
      });
    }

    // Botões do overlay de avaliação
    if (btnConfirmSave) {
      btnConfirmSave.addEventListener("click", () => {
        hide(evalOv);
        salvarKR();
      });
    }
    if (btnEditKR) {
      btnEditKR.addEventListener("click", () => hide(evalOv));
    }

    // Overlay de sucesso
    if (btnCloseSaveMsg) {
      btnCloseSaveMsg.addEventListener("click", () => {
        window.location.reload();
      });
    }
  });
})();
</script>

</body>
</html>
