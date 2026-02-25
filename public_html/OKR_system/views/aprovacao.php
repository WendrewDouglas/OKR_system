<?php
// views/aprovacao.php — Central de Aprovações com tipo de movimento e diff
declare(strict_types=1);
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__.'/../auth/acl.php';

// Gate automático pela tabela dom_paginas.requires_cap
gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');
if (($_GET['mode'] ?? '') === 'edit') {
  require_cap('W:objetivo@ORG');
}
// Regra de acesso a página de aprovação
//require_cap('R:aprovacao@ORG', ['id_orcamento' => (int)($_POST['id_orcamento'] ?? 0)]);

if (!isset($_SESSION['user_id'])) { header('Location: /OKR_system/views/login.php'); exit; }

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// Conexão
try{
  $pdo=new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
}catch(PDOException $e){ http_response_code(500); die("Erro ao conectar: ".$e->getMessage()); }

// Dados do usuário
$meuId = (string)$_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT primeiro_nome, COALESCE(ultimo_nome,'') AS ultimo_nome FROM usuarios WHERE id_user = ?");
$stmt->execute([$meuId]);
$u = $stmt->fetch() ?: ['primeiro_nome'=>'Usuário','ultimo_nome'=>''];
$meuNome = trim(($u['primeiro_nome'] ?? '').' '.($u['ultimo_nome'] ?? ''));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Aprovações — OKR System</title>

<link rel="stylesheet" href="/OKR_system/assets/css/base.css">
<link rel="stylesheet" href="/OKR_system/assets/css/components.css">
<link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
<link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

<style>
  /* Garantia de ocultação */
  [hidden]{ display:none !important; }
  .overlay{ position:fixed; inset:0; display:grid; place-items:center; background:rgba(0,0,0,.55); z-index:3000; }
  .overlay:not(.show){ display:none !important; }

  /* Card do modal no padrão do sistema */
  .ai-card{
    width:min(720px,94vw);
    background:linear-gradient(180deg, var(--card), #0b1020);
    color:var(--text);
    border:1px solid var(--border);
    border-radius:18px;
    box-shadow:var(--shadow);
    padding:0; /* agora as seções controlam o padding */
    overflow:hidden;
  }
  .ai-header{ display:flex; align-items:center; gap:12px; padding:16px; border-bottom:1px dashed #223047; }
  .ai-title{ font-weight:900; letter-spacing:.2px; }
  .ai-avatar{
    width:44px; height:44px; border-radius:12px;
    display:grid; place-items:center; font-weight:800;
    background:#0e131a; border:1px solid #1f2635; color:#e5e7eb;
  }
  .ai-bubble{ padding:16px; }
  .ai-actions{ display:flex; gap:8px; justify-content:flex-end; padding:12px 16px; border-top:1px dashed #223047; }

  /* Textarea padrão do sistema */
  .modal-textarea{
    width:100%; min-height:120px; resize:vertical;
    background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px; outline:none;
  }

  /* Variações por ação (cores do tema) */
  .ai-card.k-approve .ai-avatar{ background:rgba(20,83,45,.35); border-color:#14532d; color:#dcfce7; }
  .ai-card.k-reject  .ai-avatar{ background:rgba(127,29,29,.28); border-color:#7f1d1d; color:#fecaca; }
  .ai-card.k-resend  .ai-avatar{ background:rgba(120,53,15,.35); border-color:#78350f; color:#fde68a; }

  /* Botão confirmar herda sua paleta existente */
  .btn-approve{ border-color:#14532d; background:rgba(20,83,45,.35); }
  .btn-reject { border-color:#7f1d1d; background:rgba(127,29,29,.28); }
  .btn-resend { border-color:#78350f; background:rgba(120,53,15,.35); }

  /* Estados de loading */
  .btn[disabled]{ opacity:.7; cursor:not-allowed; }

  body{ background:#fff !important; color:#111; }
  :root{ --chat-w:0px; }
  .content{ background:transparent; }
  main.approval{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; }

  .pill.good{ color:#c7f9cc; border-color:#14532d; background:rgba(20,83,45,.35); }
  .pill.warn{ color:#fff7ed; border-color:#854d0e; background:rgba(133,77,14,.32); }
  .pill.bad { color:#fee2e2; border-color:#7f1d1d; background:rgba(127,29,29,.28); }

  .toolbar{ display:grid; grid-template-columns: 1fr auto; gap:12px; }
  .filters{ background:linear-gradient(180deg, var(--card), #0e1319); border:1px solid var(--border);
    border-radius:14px; padding:12px; box-shadow:var(--shadow); color:var(--text); display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
  .filters select, .filters input[type="search"]{ background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none; }
  .tabs{ display:flex; gap:8px; flex-wrap:wrap; }
  .tab-btn{ border:1px solid var(--border); background:#0b1118; color:#e5e7eb; padding:10px 12px; border-radius:12px; font-weight:800; cursor:pointer; }
  .tab-btn.active{ outline:2px solid rgba(246,195,67,.18); border-color:var(--gold); color:var(--gold); }

  .list{ display:grid; grid-template-columns:1fr; gap:10px; }
  .card{ background:linear-gradient(180deg, var(--card), #0e1319); border:1px solid var(--border); border-radius:16px; padding:12px;
    box-shadow:var(--shadow); color:var(--text); display:grid; grid-template-columns: 1fr auto; gap:10px; }
  .left{ display:grid; gap:8px; }
  .title{ display:flex; align-items:center; gap:10px; font-weight:900; letter-spacing:.2px; }
  .title .mod{ font-size:.78rem; padding:4px 8px; border-radius:8px; border:1px solid var(--border); background:#0b1118; color:#cbd5e1; font-weight:800; text-transform:uppercase; }
  .desc{ color:#cdd6e0; font-size:.93rem; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  .meta{ display:flex; gap:8px; flex-wrap:wrap; }
  .badge{ font-size:.78rem; border:1px solid var(--border); padding:4px 8px; border-radius:999px; color:#c9d4e5; }
  .badge.red{ color:#fecaca; border-color:#7f1d1d; background:rgba(127,29,29,.28); }
  .badge.green{ color:#dcfce7; border-color:#14532d; background:rgba(20,83,45,.35); }
  .badge.yellow{ color:#fef3c7; border-color:#78350f; background:rgba(120,53,15,.35); }
  .badge.mov{ color:#fde68a; border-color:#92400e; background:rgba(146,64,14,.35); } /* [MOV] */
  .badge.gold-outline{ color:var(--gold); border-color:var(--gold); background:rgba(246,195,67,.08); }

  .right{ display:flex; align-items:center; gap:8px; }
  .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:8px 12px; border-radius:10px; font-weight:800; cursor:pointer; }
  .btn:hover{ transform:translateY(-1px); transition:.15s; }
  .btn-ghost{ background:transparent; border:1px dashed #334155; color:#cbd5e1; }
  .btn-approve{ border-color:#14532d; background:rgba(20,83,45,.35); }
  .btn-reject{ border-color:#7f1d1d; background:rgba(127,29,29,.28); }
  .btn-resend{ border-color:#78350f; background:rgba(120,53,15,.35); }
  .btn-primary{ background:#1f2937; }

  .details{ display:none; grid-column:1 / -1; border-top:1px dashed #243041; padding-top:10px; margin-top:6px; }
  .details.show{ display:block; }
  .details dl{ display:grid; grid-template-columns: 160px 1fr; gap:8px 12px; }
  .details dt{ color:#9aa4b2; font-weight:700; }
  .details dd{ margin:0; color:#e5e7eb; }

  /* [MOV] tabela de mudanças */
  .changes{ margin-top:10px; }
  .changes h4{ margin:0 0 6px; font-size:.95rem; color:#eaeef6; }
  .changes table{ width:100%; border-collapse:collapse; }
  .changes th,.changes td{ border:1px solid #243041; padding:6px 8px; font-size:.9rem; }
  .changes th{ background:#0b1118; color:#cbd5e1; text-align:left; }
  .changes td small{ opacity:.85; }
  .just{ margin-top:6px; color:#cdd6e0; font-size:.92rem; }

  .bulk-check { display:flex; align-items:center; }
  .bulk-cb { width:18px; height:18px; accent-color:var(--gold); cursor:pointer; }
  .bulk-bar {
    position:fixed; bottom:24px; left:50%; transform:translateX(-50%);
    background:linear-gradient(180deg, var(--card), #0b1020);
    border:1px solid var(--gold); border-radius:14px; padding:10px 20px;
    display:none; align-items:center; gap:12px;
    box-shadow:0 10px 40px rgba(0,0,0,.4); z-index:2000; color:var(--text);
  }
  .bulk-bar.show { display:flex; }
  .bulk-bar .bulk-count { font-weight:800; color:var(--gold); }
</style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="approval">
      <?php
        $breadcrumbs = [
          ['label' => 'Dashboard', 'icon' => 'fa-solid fa-house', 'href' => '/OKR_system/dashboard'],
          ['label' => 'Aprovações', 'icon' => 'fa-solid fa-badge-check'],
        ];
        include __DIR__ . '/partials/breadcrumbs.php';
      ?>

      <section class="head-card">
        <h1 class="head-title"><i class="fa-solid fa-clipboard-check"></i> Central de Aprovações</h1>
        <div class="head-meta" id="headPills">
          <span class="pill warn" id="pillPendente"><i class="fa-solid fa-hourglass-half"></i> Pendentes: —</span>
          <span class="pill bad" id="pillReprovado"><i class="fa-solid fa-xmark"></i> Reprovados: —</span>
          <span class="pill good" id="pillAprovado"><i class="fa-solid fa-check"></i> Aprovados (últimos 30d): —</span>
        </div>
      </section>

      <section class="toolbar">
        <div class="filters">
          <label style="color:#cbd5e1;font-size:.85rem;">Módulo</label>
          <select id="fModulo">
            <option value="all">Todos</option>
            <option value="objetivo">Objetivos</option>
            <option value="kr">Key Results</option>
            <option value="orcamento">Orçamentos</option>
          </select>

          <label style="color:#cbd5e1;font-size:.85rem;margin-left:10px;">Status</label>
          <select id="fStatus">
            <option value="pendente">Pendentes</option>
            <option value="reprovado">Reprovados</option>
            <option value="aprovado">Aprovados</option>
            <option value="all">Todos</option>
          </select>

          <label style="color:#cbd5e1;font-size:.85rem;margin-left:10px;">Exibir</label>
          <select id="fEscopo">
            <option value="para_aprovar">Para aprovar</option>
            <option value="minhas">Minhas submissões</option>
            <option value="reprovados">Reprovados</option>
          </select>

          <input id="fBusca" type="search" placeholder="Pesquisar por texto/ID…" style="flex:1;min-width:200px;">
          <button class="btn btn-ghost" id="btnRefresh"><i class="fa-solid fa-rotate"></i> Atualizar</button>
        </div>
        <div class="tabs" role="tablist" aria-label="Âncoras rápidas">
          <button class="tab-btn active" data-tab="para_aprovar"><i class="fa-solid fa-inbox"></i> Para aprovar</button>
          <button class="tab-btn" data-tab="reprovados"><i class="fa-solid fa-ban"></i> Reprovados</button>
          <button class="tab-btn" data-tab="minhas"><i class="fa-regular fa-paper-plane"></i> Minhas submissões</button>
        </div>
      </section>

      <section class="list" id="list"></section>

      <?php include __DIR__ . '/partials/chat.php'; ?>

      <div class="bulk-bar" id="bulkBar">
        <span>Selecionados: <strong class="bulk-count" id="bulkCount">0</strong></span>
        <button class="btn btn-approve" id="bulkApprove"><i class="fa-solid fa-check"></i> Aprovar selecionados</button>
        <button class="btn btn-ghost" id="bulkCancel"><i class="fa-regular fa-circle-xmark"></i> Cancelar</button>
      </div>
    </main>
  </div>

<!-- Modal: Aprovar/Reprovar/Reenviar -->
<div id="modalAction" class="overlay" aria-hidden="true" hidden>
  <div class="ai-card" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-describedby="modalMsg">
    <div class="ai-header">
      <div class="ai-avatar" id="modalIcon" aria-hidden="true">
        <i class="fa-solid fa-check"></i>
      </div>
      <div>
        <div class="ai-title" id="modalTitle">Aprovar item</div>
        <div class="ai-sub" id="modalSub" style="opacity:.7;font-size:.9rem">—</div>
      </div>
    </div>

    <div class="ai-bubble">
      <div id="modalMsg" style="margin-bottom:8px;">Você pode adicionar observações à aprovação (opcional).</div>

      <textarea id="modalObs" class="modal-textarea" maxlength="400"
        placeholder="Escreva sua justificativa/observações…"></textarea>

      <div class="help" style="display:flex;gap:8px;align-items:center;margin-top:6px;">
        <span id="reqHint" class="req" style="display:none;">
          <i class="fa-solid fa-asterisk"></i> Justificativa obrigatória
        </span>
        <span style="margin-left:auto;opacity:.75;" id="charCount">0/400</span>
      </div>
    </div>

    <div class="ai-actions">
      <button class="btn" id="modalCancel">
        <i class="fa-regular fa-circle-xmark"></i> Cancelar
      </button>
      <button class="btn btn-primary" id="modalConfirm">
        <i class="fa-solid fa-check"></i> Confirmar
      </button>
    </div>
  </div>
</div>

<script>
const $  = (s, r=document)=>r.querySelector(s);
const $$ = (s, r=document)=>Array.from(r.querySelectorAll(s));
function show(el){
  if(!el) return;
  el.classList.add('show');
  el.removeAttribute('hidden');
  el.setAttribute('aria-hidden','false');
}
function hide(el){
  if(!el) return;
  el.classList.remove('show');
  el.setAttribute('aria-hidden','true');
  el.setAttribute('hidden','');
}
function clampText(el, lines=2){ el.style.display='-webkit-box'; el.style.webkitLineClamp=lines; el.style.webkitBoxOrient='vertical'; el.style.overflow='hidden'; }

// Estado
const API = '/OKR_system/auth/aprovacao_api.php';
const CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8") ?>';
const MEU_NOME = '<?= htmlspecialchars($meuNome, ENT_QUOTES, "UTF-8") ?>';
const MEU_ID   = '<?= htmlspecialchars($meuId, ENT_QUOTES, "UTF-8") ?>';

let DATA = { stats:{}, rows:[] };
let CURRENT_TAB = 'para_aprovar';

// Badges
function badgeStatus(s){
  const v = (s||'').toString().toLowerCase();
  if (v==='aprovado') return `<span class="badge green"><i class="fa-solid fa-check"></i> Aprovado</span>`;
  if (v==='reprovado') return `<span class="badge red"><i class="fa-solid fa-xmark"></i> Reprovado</span>`;
  return `<span class="badge yellow"><i class="fa-solid fa-hourglass-half"></i> Pendente</span>`;
}
/* [MOV] selo do movimento */
function movLabel(row){
  const t = (row.mov_tipo||'').toLowerCase();
  const m = row.module;
  if (!t) return '';
  const mapa = {
    objetivo: { novo:'Novo objetivo', alteracao:'Alteração de objetivo' },
    kr:       { novo:'Novo KR',        alteracao:'Alteração de KR' },
    orcamento:{ novo:'Novo orçamento', alteracao:'Alteração de orçamento' }
  };
  const txt = (mapa[m] && mapa[m][t]) ? mapa[m][t] : (t==='novo'?'Novo':'Alteração');
  return `<span class="badge mov"><i class="fa-solid fa-right-left"></i> ${txt}</span>`;
}

function modChip(m){
  if (m==='objetivo') return `<span class="mod">OBJ</span>`;
  if (m==='kr')       return `<span class="mod">KR</span>`;
  return `<span class="mod">ORÇ</span>`;
}
function currencyBR(v){
  if (v===null || v===undefined || isNaN(v)) return '—';
  return new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v));
}

/* [MOV] tabela HTML das diferenças */
function renderDiffs(row){
  if ((row.mov_tipo||'')!=='alteracao') return '';
  const diffs = Array.isArray(row.mov_diffs) ? row.mov_diffs : [];
  if (!diffs.length && !row.mov_just) return '';
  const trs = diffs.map(d=>`
    <tr>
      <td><code>${(d.campo||'').toString()}</code></td>
      <td>${(d.antes ?? '—')}</td>
      <td><strong>${(d.depois ?? '—')}</strong></td>
    </tr>
  `).join('');
  return `
    <div class="changes">
      <h4><i class="fa-solid fa-list-check"></i> Mudanças propostas</h4>
      ${diffs.length ? `
        <table>
          <thead><tr><th>Campo</th><th>Valor anterior</th><th>Valor atual</th></tr></thead>
          <tbody>${trs}</tbody>
        </table>` : `<div style="opacity:.7">Sem diferenças de campo informadas.</div>`}
      ${row.mov_just ? `<div class="just"><i class="fa-regular fa-comment-dots"></i> <strong>Justificativa:</strong> ${row.mov_just}</div>`:''}
    </div>
  `;
}

function card(row){
  const title = row.module==='orcamento'
    ? `Orçamento #${row.id} — ${currencyBR(row.valor||0)}`
    : (row.module==='kr' ? `KR ${row.id}` : `Objetivo #${row.id}`);

  const sub = row.module==='kr' && row.objetivo_id
    ? `<span class="badge gold-outline"><i class="fa-solid fa-bullseye"></i> Obj #${row.objetivo_id}</span>`
    : '';

  const canApprove = row.scope==='para_aprovar' && row.status_aprovacao==='pendente';
  const canResend  = row.scope!=='para_aprovar' && row.status_aprovacao==='reprovado' && String(row.usuario_criador_id||'')===MEU_ID;

  const checkbox = canApprove ? `<label class="bulk-check"><input type="checkbox" class="bulk-cb" data-module="${row.module}" data-id="${row.id}"></label>` : '';

  return `
    <article class="card" data-key="${row.module}|${row.id}">
      <div class="left">
        ${checkbox}
        <div class="title">
          ${modChip(row.module)}
          <span>${title}</span>
          ${sub}
        </div>
        <div class="desc">${row.descricao ? row.descricao : (row.resumo||'—')}</div>

        <div class="meta">
          ${badgeStatus(row.status_aprovacao)}
          ${movLabel(row)}
          <span class="badge"><i class="fa-regular fa-user"></i> Autor: ${row.usuario_criador_nome || '—'}</span>
          ${row.dt_criacao ? `<span class="badge"><i class="fa-regular fa-calendar"></i> Criado: ${row.dt_criacao}</span>`:''}
          ${row.dt_aprovacao ? `<span class="badge"><i class="fa-regular fa-clock"></i> Últ. decisão: ${row.dt_aprovacao}</span>`:''}
        </div>

        <div class="details">
          <dl>
            ${row.module==='kr' && row.objetivo_desc ? `<dt>Objetivo</dt><dd>${row.objetivo_desc}</dd>`:''}
            ${row.module==='orcamento' ? `<dt>Iniciativa</dt><dd>${row.id_iniciativa||'—'}</dd>`:''}
            ${row.comentarios_aprovacao ? `<dt>Comentários</dt><dd>${row.comentarios_aprovacao}</dd>`:''}
            ${row.justificativa ? `<dt>Justificativa (orçamento)</dt><dd>${row.justificativa}</dd>`:''}
          </dl>
          ${renderDiffs(row)}
        </div>
      </div>
      <div class="right">
        <button class="btn btn-ghost btn-toggle"><i class="fa-regular fa-eye"></i></button>
        ${canApprove ? `
          <button class="btn btn-approve btn-acao" data-action="approve"><i class="fa-solid fa-check"></i> <span class="btn-label">Aprovar</span></button>
          <button class="btn btn-reject  btn-acao" data-action="reject"><i class="fa-solid fa-xmark"></i> <span class="btn-label">Reprovar</span></button>
        `:''}
        ${canResend ? `<button class="btn btn-resend btn-acao" data-action="resubmit"><i class="fa-solid fa-paper-plane"></i> <span class="btn-label">Reenviar</span></button>`:''}
      </div>
    </article>
  `;
}

function render(){
  const sPend = Number.isFinite(DATA?.stats?.pendentes) ? parseInt(DATA.stats.pendentes,10) : '—';
  const sRep  = Number.isFinite(DATA?.stats?.reprovados) ? parseInt(DATA.stats.reprovados,10) : '—';
  const sApr  = Number.isFinite(DATA?.stats?.aprovados30) ? parseInt(DATA.stats.aprovados30,10) : '—';
  $('#pillPendente').innerHTML = `<i class="fa-solid fa-hourglass-half"></i> Pendentes: ${sPend}`;
  $('#pillReprovado').innerHTML = `<i class="fa-solid fa-xmark"></i> Reprovados: ${sRep}`;
  $('#pillAprovado').innerHTML = `<i class="fa-solid fa-check"></i> Aprovados (últimos 30d): ${sApr}`;

  const mod = $('#fModulo').value;
  const sts = $('#fStatus').value;
  const scope = $('#fEscopo').value;
  const q = ($('#fBusca').value||'').trim().toLowerCase();

  const list = $('#list'); list.innerHTML = '';
  let rows = DATA.rows.filter(r => r.scope===CURRENT_TAB);

  if (mod!=='all') rows = rows.filter(r => r.module===mod);
  if (sts!=='all') rows = rows.filter(r => String(r.status_aprovacao).toLowerCase()===sts);
  if (scope && scope!==CURRENT_TAB) rows = rows.filter(r => r.scope===scope);

  if (q){
    rows = rows.filter(r=>{
      const blob = [
        r.module, r.id, r.descricao, r.resumo, r.usuario_criador_nome, r.objetivo_desc, r.objetivo_id, r.id_iniciativa,
        (r.mov_tipo||''), (r.mov_just||'')
      ].filter(Boolean).join(' ').toLowerCase();
      return blob.includes(q);
    });
  }

  if (!rows.length){
    list.innerHTML = `<div class="empty"><i class="fa-regular fa-folder-open"></i> Nada por aqui com os filtros atuais.</div>`;
    return;
  }

  list.innerHTML = rows.map(card).join('');

  $$('.desc', list).forEach(el=>clampText(el,2));
  $$('.btn-toggle', list).forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const details = btn.closest('.card').querySelector('.details');
      details.classList.toggle('show');
      btn.innerHTML = details.classList.contains('show') ? `<i class="fa-regular fa-eye-slash"></i>` : `<i class="fa-regular fa-eye"></i>`;
    });
  });
  $$('.btn-acao', list).forEach(btn=> btn.addEventListener('click', ()=> openModal(btn)) );
}

  // ===== Modal ações =====
  let pendingAction = null;
  const modalEl    = $('#modalAction');
  const modalCard  = modalEl.querySelector('.ai-card');
  const iconEl     = $('#modalIcon i');
  const titleEl    = $('#modalTitle');
  const subEl      = $('#modalSub');
  const msgEl      = $('#modalMsg');
  const obsEl      = $('#modalObs');
  const reqHintEl  = $('#reqHint');
  const charEl     = $('#charCount');
  const btnCancel  = $('#modalCancel');
  const btnConfirm = $('#modalConfirm');

  function lockScroll(lock){ document.body.style.overflow = lock ? 'hidden' : ''; }
  function setConfirmStyle(kind){
    btnConfirm.className = 'btn ' + (kind==='approve' ? 'btn-approve' : kind==='reject' ? 'btn-reject' : 'btn-resend');
    btnConfirm.innerHTML =
      kind==='approve' ? '<i class="fa-solid fa-check"></i> Aprovar' :
      kind==='reject'  ? '<i class="fa-solid fa-xmark"></i> Reprovar' :
                        '<i class="fa-solid fa-paper-plane"></i> Reenviar';
  }
  function setHeaderIcon(kind){
    iconEl.className =
      kind==='approve' ? 'fa-solid fa-check' :
      kind==='reject'  ? 'fa-solid fa-xmark' :
                        'fa-solid fa-paper-plane';
    modalCard.classList.remove('k-approve','k-reject','k-resend');
    modalCard.classList.add(kind==='approve'?'k-approve':kind==='reject'?'k-reject':'k-resend');
  }
  function needJustification(){ return pendingAction && pendingAction.action !== 'approve'; }
  function validateJustification(){
    const need = needJustification();
    const ok = !need || obsEl.value.trim().length > 0;
    reqHintEl.style.display = ok ? 'none' : 'inline-flex';
    btnConfirm.disabled = !ok;
    charEl.textContent = `${obsEl.value.length}/400`;
  }
  function trapFocus(e){
    if (!modalEl.classList.contains('show')) return;
    if (e.key !== 'Tab') return;
    const focusables = modalEl.querySelectorAll('button, [href], input, textarea, select, [tabindex]:not([tabindex="-1"])');
    if (!focusables.length) return;
    const first = focusables[0], last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first){ last.focus(); e.preventDefault(); }
    else if (!e.shiftKey && document.activeElement === last){ first.focus(); e.preventDefault(); }
  }

  function openModal(btn){
    const card = btn.closest('.card');
    const [module, id] = card.dataset.key.split('|');
    const action = btn.dataset.action; // approve | reject | resubmit
    const title = card.querySelector('.title span')?.textContent || `${module} ${id}`;

    pendingAction = { action, module, id, title };
    titleEl.textContent =
      action==='approve' ? 'Aprovar item' :
      action==='reject'  ? 'Reprovar item' :
                          'Reenviar item para aprovação';

    subEl.textContent = `${module.toUpperCase()} • ${title}`;
    msgEl.textContent = action==='approve'
      ? 'Você pode adicionar observações à aprovação (opcional).'
      : 'Adicione sua justificativa (obrigatório).';

    obsEl.value = '';
    setHeaderIcon(action==='resubmit' ? 'resend' : action);
    setConfirmStyle(action==='resubmit' ? 'resend' : action);
    validateJustification();

    show(modalEl);
    lockScroll(true);
    setTimeout(()=> obsEl.focus(), 0);
  }

  function closeModal(){
    hide(modalEl);
    lockScroll(false);
    pendingAction = null;
  }

  btnCancel.addEventListener('click', closeModal);
  modalEl.addEventListener('click', (e)=>{ if (e.target === modalEl) closeModal(); });
  document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && modalEl.classList.contains('show')) closeModal(); });
  document.addEventListener('keydown', trapFocus);
  obsEl.addEventListener('input', validateJustification);

  // Submissão
  btnConfirm.addEventListener('click', async ()=>{
    if (!pendingAction) return;
    const need = needJustification();
    const obs = obsEl.value.trim();
    if (need && !obs){
      validateJustification();
      return;
    }

    // loading
    const prevHTML = btnConfirm.innerHTML;
    btnConfirm.disabled = true;
    btnCancel.disabled = true;
    btnConfirm.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Enviando…';

    try{
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('action', pendingAction.action);
      fd.append('module', pendingAction.module);
      fd.append('id', pendingAction.id);
      fd.append('comentarios', obs);

      const res = await fetch(API, { method:'POST', body:fd });
      const data = await res.json().catch(()=> ({}));
      if (!res.ok || !data.success) throw new Error(data.error || 'Falha na operação');

      closeModal();
      await loadData();
    }catch(err){
      alert(err?.message || 'Erro de rede');
    }finally{
      btnConfirm.innerHTML = prevHTML;
      btnConfirm.disabled = false;
      btnCancel.disabled = false;
    }
  });
// Filtros/Tabs
$$('.tab-btn').forEach(b=>{
  b.addEventListener('click', ()=>{
    $$('.tab-btn').forEach(x=>x.classList.remove('active'));
    b.classList.add('active');
    CURRENT_TAB = b.dataset.tab;
    $('#fEscopo').value = CURRENT_TAB;
    render();
  });
});
$('#fModulo').addEventListener('change', render);
$('#fStatus').addEventListener('change', render);
$('#fEscopo').addEventListener('change', e=>{
  CURRENT_TAB = e.target.value;
  $$('.tab-btn').forEach(x=>x.classList.toggle('active', x.dataset.tab===CURRENT_TAB));
  render();
});
$('#fBusca').addEventListener('input', render);
$('#btnRefresh').addEventListener('click', ()=>loadData());

// Data loader
async function loadData(){
  const qs = new URLSearchParams({ action:'summary' });
  try{
    const res = await fetch(`${API}?${qs.toString()}`, { headers:{ 'X-CSRF': CSRF }});
    const data = await res.json().catch(()=> ({}));
    if (!res.ok) throw new Error(data.error || 'Falha ao carregar');
    DATA = data;
    render();
  }catch(err){
    alert(err?.message || 'Erro ao carregar dados.');
  }
}

// Boot
document.addEventListener('DOMContentLoaded', ()=>{ loadData(); });

// Bulk approve
function updateBulkBar(){
  const checked = $$('.bulk-cb:checked');
  const bar = $('#bulkBar');
  const count = $('#bulkCount');
  if(checked.length > 0){
    bar.classList.add('show');
    count.textContent = checked.length;
  } else {
    bar.classList.remove('show');
  }
}

document.addEventListener('change', e=>{
  if(e.target.classList.contains('bulk-cb')) updateBulkBar();
});

$('#bulkCancel')?.addEventListener('click', ()=>{
  $$('.bulk-cb:checked').forEach(cb=>cb.checked=false);
  updateBulkBar();
});

$('#bulkApprove')?.addEventListener('click', async ()=>{
  const checked = $$('.bulk-cb:checked');
  if(!checked.length) return;

  const btn = $('#bulkApprove');
  const prevHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Aprovando...';

  let successCount = 0;
  for(const cb of checked){
    try{
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('action', 'approve');
      fd.append('module', cb.dataset.module);
      fd.append('id', cb.dataset.id);
      fd.append('comentarios', '');
      const res = await fetch(API, { method:'POST', body:fd });
      const data = await res.json().catch(()=>({}));
      if(res.ok && data.success) successCount++;
    }catch(e){}
  }

  btn.innerHTML = prevHTML;
  btn.disabled = false;
  $$('.bulk-cb:checked').forEach(cb=>cb.checked=false);
  updateBulkBar();

  if(successCount > 0) await loadData();
  if(successCount < checked.length){
    alert(`${successCount} de ${checked.length} itens aprovados. Alguns falharam.`);
  }
});
</script>
</body>
</html>
