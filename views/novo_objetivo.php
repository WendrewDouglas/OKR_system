<?php
// views/novo_objetivo.php

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


/* ============ INJETAR O TEMA (uma vez por página) ============ */
if (!defined('PB_THEME_LINK_EMITTED')) {
  define('PB_THEME_LINK_EMITTED', true);
  // Se quiser forçar recarregar em testes, acrescente ?nocache=1
  echo '<link rel="stylesheet" href="/OKR_system/assets/company_theme.php">';
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

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Domínios / listas
$users   = $pdo->query("SELECT id_user, primeiro_nome, ultimo_nome FROM usuarios ORDER BY primeiro_nome")->fetchAll();
$pilares = $pdo->query("SELECT id_pilar, descricao_exibicao FROM dom_pilar_bsc ORDER BY ordem_pilar")->fetchAll();
$tipos   = $pdo->query("SELECT id_tipo, descricao_exibicao FROM dom_tipo_objetivo ORDER BY descricao_exibicao")->fetchAll();
$ciclos  = $pdo->query("SELECT id_ciclo, nome_ciclo, descricao FROM dom_ciclos ORDER BY id_ciclo")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Novo Objetivo – OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    body{ background:#fff !important; color:#111; }
    :root{ --chat-w:0px; }
    .content{ background:transparent; }
    main.nobj{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; }

    :root{
      --bg-soft:#171b21; --card: var(--bg1, #222222); --muted:#a6adbb; --text:#eaeef6;
      --gold:var(--bg2, #F1C40F); --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20); --btn:#0e131a;
    }

    /* Breadcrumb */
    .crumbs{ color:#333; font-size:.9rem; display:flex; align-items:center; gap:6px; }
    .crumbs a{ color:#0c4a6e; text-decoration:none; }
    .crumbs .sep{ opacity:.5; margin:0 2px; }
    .crumbs i{ opacity:.85; }

    /* Card do cabeçalho */
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
    .pill-gold{ border-color: var(--gold); color: var(--gold); background: rgba(246,195,67,.10); box-shadow: 0 0 0 1px rgba(246,195,67,.10), 0 6px 18px rgba(246,195,67,.10); }
    .pill-gold i{ color: var(--gold); }

    /* Card do formulário */
    .form-card{
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:16px; padding:16px;
      box-shadow:var(--shadow); color:var(--text);
    }
    .form-card h2{ font-size:1.05rem; margin:0 0 12px; letter-spacing:.2px; color:#e5e7eb; }

    .grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .grid-3{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
    @media (max-width:900px){ .grid-2,.grid-3{ grid-template-columns:1fr; } }

    label{ display:block; margin-bottom:6px; color:#cbd5e1; font-size:.9rem; }
    input[type="text"], textarea, select{
      width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none;
    }
    textarea{ resize:vertical; min-height:90px; }

    /* Multi-select chips (responsáveis) */
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
    .save-row{ display:flex; justify-content:center; margin-top:16px; }
    .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:10px 14px; border-radius:12px; font-weight:700; }
    .btn:hover{ border-color:#2a3342; transform:translateY(-1px); transition:.15s; }
    .btn-primary{ background:#1f2937; }

    /* Centraliza verticalmente os dois campos da linha do ciclo */
    .grid-2.align-center { align-items: center; }
    /* Evita deslocar quando centralizado */
    .grid-2.align-center #ciclo_detalhe_wrapper .detalhe { margin-top: 0 !important; }
    /* Estilo do label "Período" para ficar igual aos demais */
    #ciclo_detalhe_wrapper > label { display:block; margin-bottom:6px; color:#cbd5e1; font-size:.9rem; }

    /* Overlays IA (mesmo estilo do KR) */
    .overlay{ position:fixed; inset:0; display:none; place-items:center; background:rgba(0,0,0,.55); z-index:3000; }
    .overlay.show{ display:grid; }
    .ai-card{ width:min(920px,94vw); background:#0b1020; color:#e6e9f2; border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.35); padding:18px; position:relative; overflow:hidden; border:1px solid #223047; }
    .ai-card::after{ content:""; position:absolute; inset:0;
      background: radial-gradient(1000px 300px at 10% -20%, rgba(64,140,255,.18), transparent 60%),
                  radial-gradient(700px 220px at 100% 0%, rgba(0,196,204,.12), transparent 60%); pointer-events:none; }
    .ai-header{ display:flex; align-items:center; gap:12px; margin-bottom:10px; }
    .ai-avatar{ width:44px; height:44px; border-radius:50%; display:grid; place-items:center; color:#fff; font-weight:800;
      background:conic-gradient(from 180deg at 50% 50%, #3b82f6, #06b6d4, #8b5cf6, #3b82f6); box-shadow:0 6px 18px rgba(59,130,246,.35); }
    .ai-title{ font-size:.95rem; opacity:.9; }
    .ai-subtle{ font-size:.85rem; opacity:.7; }
    .ai-bubble{ background:#111833; border:1px solid rgba(255,255,255,.06); border-radius:14px; padding:16px; margin:8px 0 14px; }
    .score-row{ display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:6px; }
    .score-pill{ font-weight:900; font-size:2.25rem; padding:6px 14px; border-radius:12px; background:linear-gradient(135deg, rgba(59,130,246,.16), rgba(2,132,199,.12)); border:1px solid rgba(255,255,255,.08); }
    .quality-badge{ padding:4px 10px; border-radius:999px; font-size:.8rem; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.06); text-transform:capitalize; }
    .q-pessimo{ background:rgba(239,68,68,.15); border-color:rgba(239,68,68,.25); }
    .q-ruim{ background:rgba(245,158,11,.15); border-color:rgba(245,158,11,.25); }
    .q-moderado{ background:rgba(14,165,233,.15); border-color:rgba(14,165,233,.25); }
    .q-bom{ background:rgba(34,197,94,.15); border-color:rgba(34,197,94,.25); }
    .q-otimo{ background:rgba(168,85,247,.16); border-color:rgba(168,85,247,.25); }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="nobj">
      <!-- Breadcrumb -->
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <a href="/OKR_system/meus_okrs"><i class="fa-solid fa-bullseye"></i> Meus OKRs</a>
        <span class="sep">/</span>
        <span><i class="fa-solid fa-circle-plus"></i> Novo Objetivo</span>
      </div>

      <!-- Cabeçalho -->
      <section class="head-card">
        <h1 class="head-title"><i class="fa-solid fa-bullseye"></i>Novo Objetivo</h1>
        <div class="head-meta">
          <span class="pill"><i class="fa-solid fa-circle-info"></i>Defina o objetivo, ciclo e responsável(eis), e salve para submeter à aprovação.</span>

          <!-- Badge de período (mostra quando computado) -->
          <span id="periodBadge" class="pill" style="display:none;">
            <i class="fa-regular fa-calendar"></i>
            <span id="periodText"></span>
          </span>

          <!-- Badge de responsáveis (contador) -->
          <span id="ownersBadge" class="pill pill-gold" style="display:none;">
            <i class="fa-regular fa-user"></i>
            <span id="ownersText"></span>
          </span>
        </div>
      </section>

      <!-- Formulário -->
      <section class="form-card">
        <h2><i class="fa-regular fa-rectangle-list"></i> Dados do Objetivo</h2>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger"><ul>
            <?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
          </ul></div>
        <?php endif; ?>

        <form id="objectiveForm" action="/OKR_system/auth/salvar_objetivo.php" method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" id="qualidade" name="qualidade" value="">
          <input type="hidden" id="justificativa_ia" name="justificativa_ia" value="">

          <!-- Nome -->
          <div>
            <label for="nome_objetivo"><i class="fa-regular fa-pen-to-square"></i> Nome do Objetivo <span class="helper">(obrigatório)</span></label>
            <input type="text" id="nome_objetivo" name="nome_objetivo" required>
          </div>

          <!-- Tipo e Pilar -->
          <div class="grid-2" style="margin-top:12px;">
            <div>
              <label for="tipo_objetivo"><i class="fa-regular fa-square-check"></i> Tipo de Objetivo <span class="helper">(obrigatório)</span></label>
              <select id="tipo_objetivo" name="tipo_objetivo" required>
                <option value="">Selecione...</option>
                <?php foreach ($tipos as $t): ?>
                  <option value="<?= htmlspecialchars($t['id_tipo']) ?>"><?= htmlspecialchars($t['descricao_exibicao']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="pilar_bsc"><i class="fa-solid fa-layer-group"></i> Pilar BSC <span class="helper">(obrigatório)</span></label>
              <select id="pilar_bsc" name="pilar_bsc" required>
                <option value="">Selecione...</option>
                <?php foreach ($pilares as $p): ?>
                  <option value="<?= htmlspecialchars($p['id_pilar']) ?>"><?= htmlspecialchars($p['descricao_exibicao']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Ciclo + Período (detalhe) -->
          <div class="grid-2 align-center" style="margin-top:12px;">
            <div>
              <label for="ciclo_tipo"><i class="fa-regular fa-calendar-days"></i> Ciclo <span class="helper">(obrigatório)</span></label>
              <select id="ciclo_tipo" name="ciclo_tipo" required>
                <?php foreach ($ciclos as $c): ?>
                  <option value="<?= htmlspecialchars($c['nome_ciclo']) ?>" <?= $c['nome_ciclo']==='trimestral' ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['descricao']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div id="ciclo_detalhe_wrapper" role="group" aria-labelledby="lblPeriodo">
              <label id="lblPeriodo"><i class="fa-regular fa-calendar"></i> Período <span class="helper">(obrigatório)</span></label>

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

              <div id="ciclo_detalhe_personalizado" class="detalhe d-none">
                <div class="grid-2">
                  <input type="month" id="ciclo_pers_inicio" name="ciclo_pers_inicio">
                  <input type="month" id="ciclo_pers_fim"    name="ciclo_pers_fim">
                </div>
              </div>
            </div>
          </div>

          <!-- Responsáveis -->
          <div style="margin-top:12px;">
            <label><i class="fa-regular fa-user"></i> Responsável(es) <span class="helper">(obrigatório)</span></label>
            <div class="multi-select-container">
              <div class="chips-input" id="responsavel_container">
                <input type="text" id="responsavel_input" class="chips-input-field" placeholder="Clique para selecionar...">
              </div>
              <div class="dropdown-list d-none" id="responsavel_list">
                <ul>
                  <?php foreach($users as $u): ?>
                    <li data-id="<?= (int)$u['id_user'] ?>"><?= htmlspecialchars(($u['primeiro_nome'].' '.$u['ultimo_nome'])) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
            <input type="hidden" id="responsavel" name="responsavel">
            <small id="responsavel_warning" class="warning-text d-none">
              ⚠️ Prefira um único responsável para evitar ambiguidades e garantir foco.
            </small>
          </div>

          <!-- Observações -->
          <div style="margin-top:12px;">
            <label for="observacoes"><i class="fa-regular fa-note-sticky"></i> Observações</label>
            <textarea id="observacoes" name="observacoes" rows="4"></textarea>
          </div>

          <div class="save-row">
            <button type="submit" class="btn btn-primary"><i class="fa-regular fa-floppy-disk"></i> Salvar Objetivo</button>
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
          <div class="ai-title">Analisando seu objetivo…</div>
          <div class="ai-subtle">Calculando nota e justificativa.</div>
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
          <div class="ai-title">Avaliação do Objetivo</div>
          <div class="ai-subtle">Veja minha nota e o porquê.</div>
        </div>
      </div>

      <div class="ai-bubble">
        <div class="score-row">
          <div class="score-pill score-value">—</div>
          <span class="quality-badge" id="qualityBadge">avaliando…</span>
        </div>
        <div class="ai-subtle" id="evaluationResult">—</div>
      </div>

      <div class="ai-actions">
        <button id="editObjective" class="btn btn-ghost"><i class="fa-regular fa-pen-to-square"></i> Editar</button>
        <button id="saveObjective" class="btn btn-primary"><i class="fa-regular fa-floppy-disk"></i> Continuar e Salvar</button>
      </div>
    </div>
  </div>

  <div id="saveMessageOverlay" class="overlay" aria-hidden="true">
    <div class="ai-card" role="alertdialog" aria-modal="true">
      <div class="ai-header">
        <div class="ai-avatar">IA</div>
        <div>
          <div class="ai-title">Tudo certo! ✅</div>
          <div class="ai-subtle">Seu objetivo foi salvo.</div>
        </div>
      </div>

      <div class="ai-bubble">
        <div id="saveAiMessage" class="ai-subtle" style="font-size:1rem; opacity:.9">
          Objetivo salvo com sucesso. Vou submetê-lo à aprovação e você será notificado sobre o feedback do aprovador.
        </div>
      </div>

      <div class="ai-actions">
        <button id="closeSaveMessage" class="btn btn-primary">Fechar</button>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  // Silencia o erro da extensão Chrome (como no KR)
  window.addEventListener('unhandledrejection', function(event) {
    const msg = event?.reason?.message || '';
    if (msg.includes('A listener indicated an asynchronous response')) event.preventDefault();
  });

  // ========= Utils =========
  const $  = (s, r=document)=>r.querySelector(s);
  const $$ = (s, r=document)=>Array.from(r.querySelectorAll(s));
  function show(el){ el?.classList.add('show'); el?.setAttribute('aria-hidden','false'); }
  function hide(el){ el?.classList.remove('show'); el?.setAttribute('aria-hidden','true'); }

  // ========= Badges auxiliares =========
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
      const m = v.match(/^Q([1-4])\/(\d{4})$/);
      if (m) {
        const q = parseInt(m[1],10);
        const y = parseInt(m[2],10);
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
      // fallback: trimestre corrente
      const d=new Date(), m=d.getMonth()+1, y=d.getFullYear();
      const q = m<=3?1 : m<=6?2 : m<=9?3 : 4;
      const sm=(q-1)*3, em=sm+2;
      start=new Date(y,sm,1); end=new Date(y,em,lastDayOfMonth(y,em));
    }
    return { startISO: toISO(start), endISO: toISO(end) };
  }

  function updateBadges(){
    const {startISO, endISO} = computePeriodFromCycle();
    const pb = $('#periodBadge'), pt = $('#periodText');
    if (pt && pb){ pt.textContent = `Período: ${startISO} → ${endISO}`; pb.style.display='inline-flex'; }
    // Responsáveis
    const ids = ($('#responsavel')?.value || '').split(',').filter(Boolean);
    const ob = $('#ownersBadge'), ot = $('#ownersText');
    if (ids.length>0 && ob && ot){ ot.textContent = `Responsáveis: ${ids.length}`; ob.style.display='inline-flex'; }
    else if (ob){ ob.style.display='none'; }
  }

  // ========= População dinâmica de ciclos =========
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
  }

  function toggleCycleDetail(){
    const tipo = ($('#ciclo_tipo')?.value || 'trimestral').toLowerCase();
    const boxes = {
      anual: $('#ciclo_detalhe_anual'),
      semestral: $('#ciclo_detalhe_semestral'),
      trimestral: $('#ciclo_detalhe_trimestral'),
      bimestral: $('#ciclo_detalhe_bimestral'),
      mensal: $('#ciclo_detalhe_mensal'),
      personalizado: $('#ciclo_detalhe_personalizado'),
    };
    Object.entries(boxes).forEach(([k,el])=>{ if(!el) return; el.classList.toggle('d-none', k!==tipo); });
    updateBadges();
  }

  // ========= Multi-select Responsável(es) =========
  function setupOwners(){
    const inputResp   = $('#responsavel_input'),
          listCont    = $('#responsavel_list'),
          containerCh = $('#responsavel_container'),
          hiddenResp  = $('#responsavel'),
          warning     = $('#responsavel_warning');

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
        const text = li.textContent;
        const chip = document.createElement('span');
        chip.className = 'chip';
        const label = document.createElement('span'); label.textContent = text;
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
      hiddenResp.value = ids.join(',');
      warning.classList.toggle('d-none', ids.length <= 1);
      updateBadges();
    }
  }

  // ========= Chat lateral (acomoda largura, igual ao KR) =========
  const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
  const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
  function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
  function isOpen(el){ const st=getComputedStyle(el); const vis=st.display!=='none'&&st.visibility!=='hidden'; const w=el.offsetWidth; return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show'); }
  function updateChatWidth(){ const el=findChatEl(); const w=(el && isOpen(el))?el.offsetWidth:0; document.documentElement.style.setProperty('--chat-w',(w||0)+'px'); }
  function setupChatObservers(){ const chat=findChatEl(); if(!chat) return; const mo=new MutationObserver(()=>updateChatWidth()); mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']}); window.addEventListener('resize',updateChatWidth); TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,200)))); updateChatWidth(); }

  // ========= Fluxo IA =========
  document.addEventListener('DOMContentLoaded', () => {
    setupChatObservers();
    populateCycles();
    toggleCycleDetail();

    $('#ciclo_tipo')?.addEventListener('change', toggleCycleDetail);
    ['#ciclo_anual_ano','#ciclo_semestral','#ciclo_trimestral','#ciclo_bimestral','#ciclo_mensal_mes','#ciclo_mensal_ano','#ciclo_pers_inicio','#ciclo_pers_fim']
      .forEach(sel => { const el=$(sel); el && el.addEventListener('change', updateBadges); });

    setupOwners();

    const form      = $('#objectiveForm'),
          loading   = $('#loadingOverlay'),
          evalOvr   = $('#evaluationOverlay'),
          scoreBox  = document.querySelector('.score-value'),
          resultBox = $('#evaluationResult'),
          successO  = $('#saveMessageOverlay'),
          qualityBd = $('#qualityBadge');

    function setLoading(on){ on ? show(loading) : hide(loading); }

    function scoreToQuality(score){
      if (score <= 2) return { id:'péssimo', cls:'q-pessimo',  label:'Péssimo'  };
      if (score <= 4) return { id:'ruim',    cls:'q-ruim',     label:'Ruim'     };
      if (score <= 6) return { id:'moderado',cls:'q-moderado', label:'Moderado' };
      if (score <= 8) return { id:'bom',     cls:'q-bom',      label:'Bom'      };
      return            { id:'ótimo',   cls:'q-otimo',    label:'Ótimo'    };
    }

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const fd = new FormData(form);
      fd.append('evaluate','1');
      setLoading(true);

      try {
        const res  = await fetch(form.action, { method:'POST', body:fd });
        const data = await res.json();
        setLoading(false);

        if (data.score == null || data.justification == null) throw new Error('Resposta IA inválida');

        // Guarda justificativa
        $('#justificativa_ia').value = data.justification ?? '';

        // Preenche UI IA
        scoreBox.textContent = data.score;
        resultBox.textContent = data.justification;

        const q = scoreToQuality(Number(data.score));
        qualityBd.textContent = q.label;
        qualityBd.className = 'quality-badge ' + q.cls;
        $('#qualidade').value = q.id;

        show(evalOvr);

        $('#saveObjective').onclick = async () => {
          hide(evalOvr);
          setLoading(true);
          const fd2 = new FormData(form);
          fd2.delete('evaluate');
          try {
            const res2 = await fetch(form.action, { method:'POST', body:fd2 });
            const ret  = await res2.json();
            setLoading(false);
            if (ret.success) {
              const el = $('#saveAiMessage');
              if (el) {
                const objId = ret.id_objetivo ? `<strong>${ret.id_objetivo}</strong>` : 'Seu objetivo';
                el.innerHTML = `${objId} foi salvo com sucesso.<br>Vou submetê-lo à aprovação e te aviso assim que houver feedback do aprovador.`;
              }
              show(successO);
            } else {
              alert('Falha ao salvar o objetivo.');
            }
          } catch(err2) {
            console.error('Erro ao salvar:', err2);
            setLoading(false);
            alert('Erro de rede ao salvar objetivo.');
          }
        };

        $('#editObjective').onclick = () => hide(evalOvr);

      } catch(err) {
        console.error('Fluxo IA erro:', err);
        setLoading(false);
        alert('Erro ao avaliar o objetivo. Tente novamente.');
      }
    });

    $('#closeSaveMessage')?.addEventListener('click', () => {
      hide(successO);
      window.location.href = '/OKR_system/views/novo_objetivo.php';
    });

    // Badges iniciais
    updateBadges();
  });
  </script>
</body>
</html>
