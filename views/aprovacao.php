<?php
// views/aprovacao.php — Central de Aprovações (Objetivos, KRs, Orçamentos)
// - Fila "Para aprovar", "Reprovados" (com reenvio), "Minhas submissões"
// - Aprovar/Reprovar com observações (obrigatório para reprovar)
// - Reenvio com justificativa (volta para 'pendente')
// - Filtros por módulo, status, pesquisa
// - Mantém estilo dark com cartões arredondados e breadcrumbs

declare(strict_types=1);
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
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

// Dados básicos do usuário (nome para registrar em 'aprovador' nos objetos que têm campo textual)
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
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    :root{
      --bg-soft:#171b21; --card:#12161c; --muted:#a6adbb; --text:#eaeef6;
      --gold:#f6c343; --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20); --btn:#0e131a;
      --accent:#0c4a6e;
      --chip:#0e131a;
      --ok:#16a34a; --no:#dc2626; --warn:#f59e0b;
    }
    body{ background:#fff !important; color:#111; }
    :root{ --chat-w:0px; }
    .content{ background:transparent; }
    main.approval{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; }

    .crumbs{ color:#333; font-size:.9rem; display:flex; align-items:center; gap:6px; }
    .crumbs a{ color:var(--accent); text-decoration:none; }
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
    .pill{ display:inline-flex; align-items:center; gap:8px; background:var(--chip); border:1px solid var(--border); color: var(--muted); padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
    .pill i{ font-size:.9rem; opacity:.9; }
    .pill.good{ color:#c7f9cc; border-color:#14532d; background:rgba(20,83,45,.35); }
    .pill.warn{ color:#fff7ed; border-color:#854d0e; background:rgba(133,77,14,.32); }
    .pill.bad { color:#fee2e2; border-color:#7f1d1d; background:rgba(127,29,29,.28); }

    .toolbar{
      display:grid; grid-template-columns: 1fr auto; gap:12px;
    }
    .filters{
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:14px; padding:12px; box-shadow:var(--shadow); color:var(--text);
      display:flex; flex-wrap:wrap; gap:8px; align-items:center;
    }
    .filters select, .filters input[type="search"]{
      background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none;
    }
    .tabs{
      display:flex; gap:8px; flex-wrap:wrap;
    }
    .tab-btn{
      border:1px solid var(--border); background:#0b1118; color:#e5e7eb; padding:10px 12px; border-radius:12px; font-weight:800; cursor:pointer;
    }
    .tab-btn.active{ outline:2px solid rgba(246,195,67,.18); border-color:var(--gold); color:var(--gold); }

    .list{
      display:grid; grid-template-columns:1fr; gap:10px;
    }
    .card{
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:16px; padding:12px; box-shadow:var(--shadow); color:var(--text);
      display:grid; grid-template-columns: 1fr auto; gap:10px;
    }
    .left{ display:grid; gap:8px; }
    .title{
      display:flex; align-items:center; gap:10px; font-weight:900; letter-spacing:.2px;
    }
    .title .mod{ font-size:.78rem; padding:4px 8px; border-radius:8px; border:1px solid var(--border); background:#0b1118; color:#cbd5e1; font-weight:800; text-transform:uppercase; }
    .title .mod.obj{ border-color:#2a3342; }
    .title .mod.kr{ border-color:#334155; }
    .title .mod.orc{ border-color:#3f3f46; }
    .desc{
      color:#cdd6e0; font-size:.93rem;
      display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
    }
    .meta{ display:flex; gap:8px; flex-wrap:wrap; }
    .badge{ font-size:.78rem; border:1px solid var(--border); padding:4px 8px; border-radius:999px; color:#c9d4e5; }
    .badge.red{ color:#fecaca; border-color:#7f1d1d; background:rgba(127,29,29,.28); }
    .badge.green{ color:#dcfce7; border-color:#14532d; background:rgba(20,83,45,.35); }
    .badge.yellow{ color:#fef3c7; border-color:#78350f; background:rgba(120,53,15,.35); }
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

    .empty{
      padding:16px; border:1px dashed #334155; border-radius:12px; color:#cbd5e1; text-align:center; background:#0b1118;
    }

    /* modal */
    .overlay{ position:fixed; inset:0; display:none; place-items:center; background:rgba(0,0,0,.55); z-index:3000; }
    .overlay.show{ display:grid; }
    .ai-card{
      width:min(820px,94vw); background:#0b1020; color:#e6e9f2;
      border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.35); padding:18px; position:relative; overflow:hidden;
      border:1px solid #223047;
    }
    .ai-header{ display:flex; align-items:center; gap:12px; margin-bottom:10px; }
    .ai-avatar{ width:44px; height:44px; border-radius:50%; display:grid; place-items:center; color:#fff; font-weight:800;
      background:conic-gradient(from 180deg at 50% 50%, #3b82f6, #06b6d4, #8b5cf6, #3b82f6); box-shadow:0 6px 18px rgba(59,130,246,.35); }
    .ai-title{ font-size:1rem; opacity:.9; font-weight:900; }
    .ai-bubble{ background:#111833; border:1px solid rgba(255,255,255,.06); border-radius:14px; padding:14px; margin:8px 0 14px; }
    .ai-actions{ display:flex; gap:8px; justify-content:flex-end; }
    .ai-actions .btn{ padding:10px 14px; border-radius:12px; }

    .modal-textarea{
      width:100%; min-height:100px; resize:vertical;
      background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px;
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="approval">
      <!-- Breadcrumb -->
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <span><i class="fa-solid fa-badge-check"></i> Aprovações</span>
      </div>

      <!-- Cabeçalho -->
      <section class="head-card">
        <h1 class="head-title"><i class="fa-solid fa-clipboard-check"></i> Central de Aprovações</h1>
        <div class="head-meta" id="headPills">
          <span class="pill warn" id="pillPendente"><i class="fa-solid fa-hourglass-half"></i> Pendentes: —</span>
          <span class="pill bad" id="pillReprovado"><i class="fa-solid fa-xmark"></i> Reprovados: —</span>
          <span class="pill good" id="pillAprovado"><i class="fa-solid fa-check"></i> Aprovados (últimos 30d): —</span>
        </div>
      </section>

      <!-- Toolbar -->
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

      <!-- Lista -->
      <section class="list" id="list"></section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <!-- Modal: Aprovar/Reprovar/Reenviar -->
  <div id="modalAction" class="overlay" aria-hidden="true">
    <div class="ai-card" role="dialog" aria-modal="true">
      <div class="ai-header">
        <div class="ai-avatar">OK</div>
        <div>
          <div class="ai-title" id="modalTitle">Ação</div>
          <div style="opacity:.7;font-size:.9rem" id="modalSub">—</div>
        </div>
      </div>
      <div class="ai-bubble">
        <div id="modalMsg" style="margin-bottom:8px;">Adicione observações (obrigatório para reprovar/reenviar):</div>
        <textarea id="modalObs" class="modal-textarea" placeholder="Escreva sua justificativa/observações…"></textarea>
      </div>
      <div class="ai-actions">
        <button class="btn" id="modalCancel">Cancelar</button>
        <button class="btn btn-primary" id="modalConfirm">Confirmar</button>
      </div>
    </div>
  </div>

  <script>
  // ========= Helpers =========
  const $  = (s, r=document)=>r.querySelector(s);
  const $$ = (s, r=document)=>Array.from(r.querySelectorAll(s));
  function show(el){ el?.classList.add('show'); el?.setAttribute('aria-hidden','false'); }
  function hide(el){ el?.classList.remove('show'); el?.setAttribute('aria-hidden','true'); }
  function clampText(el, lines=2){ el.style.display='-webkit-box'; el.style.webkitLineClamp=lines; el.style.webkitBoxOrient='vertical'; el.style.overflow='hidden'; }

  // ========= Chat lateral (mantém margem) =========
  const CHAT_SELECTORS=['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
  const TOGGLE_SELECTORS=['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];
  function findChatEl(){ for(const s of CHAT_SELECTORS){ const el=document.querySelector(s); if(el) return el; } return null; }
  function isOpen(el){ const st=getComputedStyle(el); const vis=st.display!=='none'&&st.visibility!=='hidden'; const w=el.offsetWidth; return (vis&&w>0)||el.classList.contains('open')||el.classList.contains('show'); }
  function updateChatWidth(){ const el=findChatEl(); const w=(el && isOpen(el))?el.offsetWidth:0; document.documentElement.style.setProperty('--chat-w',(w||0)+'px'); }
  function setupChatObservers(){ const chat=findChatEl(); if(!chat) return; const mo=new MutationObserver(()=>updateChatWidth()); mo.observe(chat,{attributes:true,attributeFilter:['style','class','aria-expanded']}); window.addEventListener('resize',updateChatWidth); TOGGLE_SELECTORS.forEach(s=>document.querySelectorAll(s).forEach(btn=>btn.addEventListener('click',()=>setTimeout(updateChatWidth,200)))); updateChatWidth(); }

  // ========= Estado =========
  const API = '/OKR_system/auth/aprovacao_api.php';
  const CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8") ?>';
  const MEU_NOME = '<?= htmlspecialchars($meuNome, ENT_QUOTES, "UTF-8") ?>';
  const MEU_ID   = '<?= htmlspecialchars($meuId, ENT_QUOTES, "UTF-8") ?>';

  let DATA = { stats:{}, rows:[] };
  let CURRENT_TAB = 'para_aprovar';

  // ========= Render =========
  function badgeStatus(s){
    const v = (s||'').toString().toLowerCase();
    if (v==='aprovado') return `<span class="badge green"><i class="fa-solid fa-check"></i> Aprovado</span>`;
    if (v==='reprovado') return `<span class="badge red"><i class="fa-solid fa-xmark"></i> Reprovado</span>`;
    return `<span class="badge yellow"><i class="fa-solid fa-hourglass-half"></i> Pendente</span>`;
  }
  function modChip(m){
    if (m==='objetivo') return `<span class="mod obj">OBJ</span>`;
    if (m==='kr')       return `<span class="mod kr">KR</span>`;
    return `<span class="mod orc">ORÇ</span>`;
  }
  function currencyBR(v){
    if (v===null || v===undefined || isNaN(v)) return '—';
    return new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v));
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

    return `
      <article class="card" data-key="${row.module}|${row.id}">
        <div class="left">
          <div class="title">
            ${modChip(row.module)}
            <span>${title}</span>
            ${sub}
          </div>
          <div class="desc">${row.descricao ? row.descricao : (row.resumo||'—')}</div>
          <div class="meta">
            ${badgeStatus(row.status_aprovacao)}
            <span class="badge"><i class="fa-regular fa-user"></i> Autor: ${row.usuario_criador_nome || '—'}</span>
            ${row.dt_criacao ? `<span class="badge"><i class="fa-regular fa-calendar"></i> Criado: ${row.dt_criacao}</span>`:''}
            ${row.dt_aprovacao ? `<span class="badge"><i class="fa-regular fa-clock"></i> Últ. decisão: ${row.dt_aprovacao}</span>`:''}
          </div>

          <div class="details">
            <dl>
              ${row.module==='kr' && row.objetivo_desc ? `<dt>Objetivo</dt><dd>${row.objetivo_desc}</dd>`:''}
              ${row.module==='orcamento' ? `<dt>Iniciativa</dt><dd>${row.id_iniciativa||'—'}</dd>`:''}
              ${row.comentarios_aprovacao ? `<dt>Comentários</dt><dd>${row.comentarios_aprovacao}</dd>`:''}
              ${row.justificativa ? `<dt>Justificativa</dt><dd>${row.justificativa}</dd>`:''}
            </dl>
          </div>
        </div>
        <div class="right">
          <button class="btn btn-ghost btn-toggle"><i class="fa-regular fa-eye"></i></button>
          ${canApprove ? `
            <button class="btn btn-approve btn-acao" data-action="approve"><i class="fa-solid fa-check"></i></button>
            <button class="btn btn-reject  btn-acao" data-action="reject"><i class="fa-solid fa-xmark"></i></button>
          `:''}
          ${canResend ? `
            <button class="btn btn-resend btn-acao" data-action="resubmit"><i class="fa-solid fa-paper-plane"></i></button>
          `:''}
        </div>
      </article>
    `;
  }

  function render(){
    // head pills
    $('#pillPendente').innerHTML = `<i class="fa-solid fa-hourglass-half"></i> Pendentes: ${DATA?.stats?.pendentes ?? '—'}`;
    $('#pillReprovado').innerHTML = `<i class="fa-solid fa-xmark"></i> Reprovados: ${DATA?.stats?.reprovados ?? '—'}`;
    $('#pillAprovado').innerHTML = `<i class="fa-solid fa-check"></i> Aprovados (últimos 30d): ${DATA?.stats?.aprovados30 ?? '—'}`;

    // filtros
    const mod = $('#fModulo').value;
    const sts = $('#fStatus').value;
    const scope = $('#fEscopo').value;
    const q = ($('#fBusca').value||'').trim().toLowerCase();

    const list = $('#list'); list.innerHTML = '';

    let rows = DATA.rows.filter(r => r.scope===CURRENT_TAB);

    if (mod!=='all') rows = rows.filter(r => r.module===mod);
    if (sts!=='all') rows = rows.filter(r => String(r.status_aprovacao).toLowerCase()===sts);

    if (scope && scope!==CURRENT_TAB) {
      rows = rows.filter(r => r.scope===scope);
    }

    if (q){
      rows = rows.filter(r=>{
        const blob = [
          r.module, r.id, r.descricao, r.resumo, r.usuario_criador_nome, r.objetivo_desc, r.objetivo_id, r.id_iniciativa
        ].filter(Boolean).join(' ').toLowerCase();
        return blob.includes(q);
      });
    }

    if (!rows.length){
      list.innerHTML = `<div class="empty"><i class="fa-regular fa-folder-open"></i> Nada por aqui com os filtros atuais.</div>`;
      return;
    }

    list.innerHTML = rows.map(card).join('');

    // bindings
    $$('.desc', list).forEach(el=>clampText(el,2));
    $$('.btn-toggle', list).forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const details = btn.closest('.card').querySelector('.details');
        details.classList.toggle('show');
        btn.innerHTML = details.classList.contains('show') ? `<i class="fa-regular fa-eye-slash"></i>` : `<i class="fa-regular fa-eye"></i>`;
      });
    });
    $$('.btn-acao', list).forEach(btn=>{
      btn.addEventListener('click', ()=> openModal(btn));
    });
  }

  // ========= Modal ações =========
  let pendingAction = null; // {action,module,id,title}
  function openModal(btn){
    const card = btn.closest('.card');
    const [module,id] = card.dataset.key.split('|');
    const action = btn.dataset.action;

    const title = card.querySelector('.title span')?.textContent || `${module} ${id}`;

    pendingAction = { action, module, id, title };
    $('#modalTitle').textContent =
      action==='approve' ? 'Aprovar item' :
      action==='reject'  ? 'Reprovar item' :
      'Reenviar item para aprovação';
    $('#modalSub').textContent = `${module.toUpperCase()} • ${title}`;
    $('#modalObs').value = '';
    $('#modalMsg').textContent =
      action==='approve'
        ? 'Você pode adicionar observações à aprovação (opcional).'
        : 'Adicione sua justificativa (obrigatório).';
    show($('#modalAction'));
  }
  $('#modalCancel').addEventListener('click', ()=>{ hide($('#modalAction')); pendingAction=null; });
  $('#modalConfirm').addEventListener('click', async ()=>{
    if (!pendingAction) return;
    const obs = $('#modalObs').value.trim();
    if ((pendingAction.action==='reject' || pendingAction.action==='resubmit') && !obs){
      alert('Por favor, informe a justificativa.');
      return;
    }

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

      hide($('#modalAction'));
      await loadData(); // recarrega lista e contadores
    }catch(err){
      alert(err?.message || 'Erro de rede');
    }
  });

  // ========= Tabs / Filtros =========
  $$('.tab-btn').forEach(b=>{
    b.addEventListener('click', ()=>{
      $$('.tab-btn').forEach(x=>x.classList.remove('active'));
      b.classList.add('active');
      const tab = b.dataset.tab;
      CURRENT_TAB = tab;
      // sincroniza select
      $('#fEscopo').value = tab;
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

  // ========= Data loader =========
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

  // ========= Boot =========
  document.addEventListener('DOMContentLoaded', ()=>{
    setupChatObservers();
    loadData();
  });
  </script>
</body>
</html>
