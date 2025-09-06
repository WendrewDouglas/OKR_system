<?php
// views/usuarios.php — Gerenciar Usuários (lista + permissões RBAC)
// Requer backend em /auth/usuarios_api.php com actions:
// options, list, delete, get_permissions, save_permissions, capabilities

declare(strict_types=1);
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (!isset($_SESSION['user_id'])) { header('Location: /OKR_system/views/login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gerenciar Usuários — OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    :root{
      --bg-soft:#171b21; --card: var(--bg1, #222222); --muted:#a6adbb; --text:#eaeef6;
      --gold:var(--bg2, #F1C40F); --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20); --btn:#0e131a;
      --accent:#0c4a6e;
    }
    body{ background:#fff !important; color:#111; }
    :root{ --chat-w:0px; }
    .content{ background:transparent; }
    main.users{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; }

    .crumbs{ color:#333; font-size:.9rem; display:flex; align-items:center; gap:6px; }
    .crumbs a{ color:var(--accent); text-decoration:none; }
    .crumbs .sep{ opacity:.5; margin:0 2px; }
    .crumbs i{ opacity:.8; }

    .head-card{
      background:linear-gradient(180deg, var(--card), #0d1117);
      border:1px solid var(--border); border-radius:16px; padding:16px;
      box-shadow:var(--shadow); color:#e5e7eb; position:relative; overflow:hidden;
    }
    .head-title{ margin:0; font-size:1.35rem; font-weight:900; display:flex; align-items:center; gap:8px; }
    .head-title i{ color:var(--gold); }

    .head-top{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .head-meta{ margin-top:10px; display:flex; gap:10px; flex-wrap:wrap; }
    .pill{ display:inline-flex; align-items:center; gap:8px; background:#0e131a; border:1px solid var(--border); color: var(--muted); padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
    .pill i{ font-size:.9rem; opacity:.9; }

    .btn-gold{
      background:var(--gold); color:#111; border:1px solid rgba(246,195,67,.9);
      padding:10px 16px; border-radius:12px; font-weight:900; white-space:nowrap;
      box-shadow:0 6px 20px rgba(246,195,67,.22);
    }
    .btn-gold:hover{ filter:brightness(.96); transform:translateY(-1px); box-shadow:0 10px 28px rgba(246,195,67,.28); }

    .toolbar{ display:block; }
    .filters{
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:14px; padding:14px; box-shadow:var(--shadow); color:#e5e7eb;
    }
    .filters-grid{
      display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap:12px; align-items:end;
    }
    .fg label{ display:block; margin:0 0 6px; font-size:.85rem; color:#cbd5e1; }
    .fg select, .fg input[type="search"]{
      width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none;
    }
    .fg.actions{ justify-self:end; align-self:end; }
    .span-2{ grid-column: span 2; }
    @media (max-width: 900px){ .span-2{ grid-column: span 1; } }

    .btn{ border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:8px 12px; border-radius:10px; font-weight:800; cursor:pointer; }
    .btn:hover{ transform:translateY(-1px); transition:.15s; }
    .btn-primary{ background:#1f2937; }
    .btn-danger{ border-color:#7f1d1d; background:rgba(127,29,29,.28); }

    .list{ display:grid; grid-template-columns:1fr; gap:10px; }
    .card{
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:16px; padding:12px; box-shadow:var(--shadow); color:#e5e7eb;
      display:grid; grid-template-columns: auto 1fr auto; gap:12px; align-items:center;
    }
    .avatar{ width:44px; height:44px; border-radius:50%; object-fit:cover; background:#111827; display:grid; place-items:center; font-weight:900; }
    .info{ display:grid; gap:4px; }
    .name{ font-weight:900; letter-spacing:.2px; }
    .meta{ display:flex; gap:8px; flex-wrap:wrap; }
    .badge{ font-size:.78rem; border:1px solid var(--border); padding:4px 8px; border-radius:999px; color:#c9d4e5; }
    .role{ font-size:.72rem; padding:3px 6px; border-radius:999px; border:1px dashed #334155; color:#cbd5e1; }

    /* ===== Acesso (resumo agrupado) ===== */
    .access{ display:grid; gap:10px; margin-top:8px; }
    .access-row{ display:grid; gap:6px; font-size:.82rem; color:#cbd5e1; }
    .access-row .label{ color:#fff; font-weight:900; }
    .chips{ display:flex; flex-wrap:wrap; gap:8px; align-items:flex-start; }

    /* Chip de grupo */
    .group-chip{
      display:inline-flex; align-items:center; gap:8px;
      font-size:.75rem; font-weight:900; letter-spacing:.2px;
      padding:6px 10px; border-radius:999px; border:1px solid rgba(255,255,255,.12);
      background:#0c1118; color:#e6edf3; position:relative;
    }
    .group-chip .count{ padding:2px 7px; border-radius:999px; border:1px solid rgba(255,255,255,.18); background:rgba(255,255,255,.06); font-size:.72rem; }
    .group-chip .caret{ opacity:.8; font-size:.8rem; }
    .group-chip:focus{ outline:2px solid rgba(255,255,255,.25); }

    /* Cores por recurso */
    .chip-aprovacao { background:rgba(20,184,166,.14); border-color:rgba(45,212,191,.35); color:#99f6e4; }
    .chip-auditoria { background:rgba(100,116,139,.18); border-color:rgba(148,163,184,.35); color:#e2e8f0; }
    .chip-custo     { background:rgba(244,63,94,.14);  border-color:rgba(251,113,133,.35); color:#fecdd3; }
    .chip-iniciativa{ background:rgba(245,158,11,.16); border-color:rgba(251,191,36,.38); color:#fde68a; }
    .chip-kr        { background:rgba(34,197,94,.14);  border-color:rgba(74,222,128,.35); color:#bbf7d0; }
    .chip-milestone { background:rgba(139,92,246,.16); border-color:rgba(167,139,250,.38); color:#ddd6fe; }
    .chip-objetivo  { background:rgba(59,130,246,.14); border-color:rgba(96,165,250,.35); color:#bfdbfe; }
    .chip-relatorio { background:rgba(99,102,241,.16); border-color:rgba(129,140,248,.38); color:#c7d2fe; }
    .chip-usuario   { background:rgba(217,70,239,.16); border-color:rgba(232,121,249,.38); color:#f5d0fe; }

    /* Popover do grupo */
    .group-pop{ position:absolute; top:calc(100% + 8px); left:0; min-width:220px; background:#0b1020; color:#e6e9f2;
      border:1px solid #223047; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,.35); padding:8px; display:none; z-index:10; }
    .group-chip:hover .group-pop, .group-chip:focus-within .group-pop{ display:block; }
    .scope-list{ display:grid; gap:6px; }
    .scope-item{ display:flex; align-items:center; justify-content:space-between; gap:8px;
      background:#0c1118; border:1px solid #1f2635; color:#e6e9f2; border-radius:8px; padding:6px 8px; cursor:pointer; }
    .scope-item.on{ border-color:#2563eb; background:rgba(37,99,235,.14); }
    .scope-item .name{ font-weight:800; }
    .scope-item .icon{ opacity:.9; }

    .chip-add{ display:inline-flex; align-items:center; justify-content:center;
      gap:6px; padding:6px 10px; border-radius:999px; border:1px dashed #334155; background:transparent; color:#cbd5e1;
      cursor:pointer; font-weight:900; }
    .chip-add:hover{ background:#0b1118; }

    .right{ display:flex; gap:8px; }
    .empty{ padding:16px; border:1px dashed #334155; border-radius:12px; color:#cbd5e1; text-align:center; background:#0b1118; }

    /* ===== Overlay/Modal ===== */
    .overlay{ position:fixed; inset:0; display:none; place-items:center; background:rgba(0,0,0,.55); z-index:3000; }
    .overlay.show{ display:grid; }
    .perm-card{
      width:min(980px,95vw); background:#0b1020; color:#e6e9f2;
      border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.35); padding:18px; position:relative; overflow:hidden;
      border:1px solid #223047;
    }
    .perm-header{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
    .tabs{ display:flex; gap:6px; }
    .tab{ background:#0c1118; border:1px solid #1f2635; color:#e5e7eb; padding:8px 12px; border-radius:999px; cursor:pointer; font-weight:800; }
    .tab.active{ background:#1f2937; }
    .perm-body{ margin-top:10px; display:none; }
    .perm-body.show{ display:block; }
    .grid-cap{ display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:8px; }
    .cap-group{ border:1px solid #1f2635; border-radius:12px; padding:10px; background:#0c1118; }
    .cap-title{ font-weight:900; color:#e5e7eb; margin-bottom:8px; display:flex; align-items:center; gap:6px; }
    .cap-item{ display:flex; align-items:center; gap:8px; margin:4px 0; font-size:.9rem; }
    .cap-item select{ background:#0b1118; border:1px solid #223047; color:#e6e9f2; border-radius:8px; padding:6px 8px; }
    .perm-actions{ display:flex; justify-content:flex-end; gap:8px; margin-top:10px; }

    /* Toast */
    .toast-wrap{ position:fixed; right:16px; bottom:16px; display:grid; gap:8px; z-index:4000; }
    .toast{ background:#0c1118; color:#e6e9f2; border:1px solid #223047; border-radius:10px; padding:10px 12px; box-shadow:var(--shadow); font-size:.9rem; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="users">
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <span><i class="fa-solid fa-users-gear"></i> Gerenciar Usuários</span>
      </div>

      <section class="head-card">
        <div class="head-top">
          <h1 class="head-title"><i class="fa-solid fa-users-gear"></i> Gerenciar Usuários</h1>
        </div>
        <div class="head-meta">
          <span class="pill" id="pillUsers"><i class="fa-regular fa-address-card"></i> Usuários: —</span>
          <span class="pill" id="pillOrg"><i class="fa-regular fa-building"></i> Organização: #—</span>
          <a class="btn-gold" href="/OKR_system/views/usuario_form.php"><i class="fa-solid fa-user-plus"></i> Novo usuário</a>
        </div>
      </section>

      <section class="toolbar">
        <div class="filters">
          <div class="filters-grid">
            <div class="fg">
              <label>Organização</label>
              <select id="fCompany"></select>
            </div>
            <div class="fg">
              <label>Papel</label>
              <select id="fRole"><option value="all">Todos</option></select>
            </div>
            <div class="fg span-2">
              <label>Pesquisar</label>
              <input id="fBusca" type="search" placeholder="Pesquisar por nome/e-mail/telefone…">
            </div>
            <div class="fg actions">
              <label>&nbsp;</label>
              <button class="btn btn-ghost" id="btnRefresh"><i class="fa-solid fa-rotate"></i> Atualizar</button>
            </div>
          </div>
        </div>
      </section>

      <section id="list" class="list"></section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <!-- Modal Excluir -->
  <div id="modalDel" class="overlay" aria-hidden="true">
    <div class="perm-card" role="dialog" aria-modal="true">
      <div class="perm-header"><div class="cap-title"><i class="fa-regular fa-trash-can"></i> Excluir usuário</div></div>
      <div style="background:#111833;border:1px solid rgba(255,255,255,.06);border-radius:14px;padding:14px;margin:8px 0 14px;">
        <div id="delMsg">Tem certeza que deseja excluir?</div>
      </div>
      <div class="perm-actions">
        <button class="btn" id="delCancel">Cancelar</button>
        <button class="btn btn-danger" id="delConfirm">Excluir</button>
      </div>
    </div>
  </div>

  <!-- Modal Permissões -->
  <div id="permModal" class="overlay" aria-hidden="true">
    <div class="perm-card" role="dialog" aria-modal="true">
      <div class="perm-header">
        <div class="cap-title"><i class="fa-solid fa-shield-halved"></i> Permissões — <span id="permUserName">Usuário</span></div>
        <div class="tabs">
          <button class="tab active" data-tab="resumo">Resumo</button>
          <button class="tab" data-tab="roles">Papéis</button>
          <button class="tab" data-tab="overrides">Overrides</button>
        </div>
      </div>

      <div id="tab-resumo" class="perm-body show">
        <div class="small" style="color:#a6adbb;margin-bottom:6px;">
          Passe o mouse em um grupo para expandir e clique no escopo para ativar/desativar. Clique em <em>Salvar</em> para aplicar.
        </div>
        <div class="access">
          <div class="access-row">
            <span class="label">Leitura</span>
            <div id="sumR" class="chips">—</div>
          </div>
          <div class="access-row">
            <span class="label">Edição</span>
            <div id="sumW" class="chips">—</div>
          </div>
        </div>
      </div>

      <div id="tab-roles" class="perm-body">
        <div id="rolesBoxModal" class="grid-cap" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));"></div>
      </div>

      <div id="tab-overrides" class="perm-body">
        <div id="capsBoxModal" class="grid-cap"></div>
        <div class="small" style="margin-top:6px;color:#a6adbb;">Overrides aplicam exceções por capacidade (ALLOW/DENY). “Inherit” remove a exceção e mantém o herdado dos papéis.</div>
      </div>

      <div class="perm-actions">
        <button class="btn" id="permClose">Fechar</button>
        <button class="btn btn-primary" id="permSave"><i class="fa-regular fa-floppy-disk"></i> Salvar</button>
      </div>
    </div>
  </div>

  <!-- Toasts -->
  <div id="toastWrap" class="toast-wrap" aria-live="polite" aria-atomic="true"></div>

<script>
const API  = '/OKR_system/auth/usuarios_api.php';
const CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8") ?>';

const $  = (s,r=document)=>r.querySelector(s);
const $$ = (s,r=document)=>Array.from(r.querySelectorAll(s));

let IS_MASTER=false, MY_COMPANY=null;
// >>> NOVO CONTROLE DE PERMISSÃO DO PRÓPRIO USUÁRIO <<<
let MY_ID=null, CAN_DELETE=false, ME_ROLES=[];

let OPTIONS = { companies:[], roles:[], capabilities:[] };

function toast(msg){
  const div = document.createElement('div');
  div.className = 'toast';
  div.textContent = msg;
  $('#toastWrap').appendChild(div);
  setTimeout(()=>{ div.style.opacity='0'; div.style.transform='translateY(4px)'; }, 2200);
  setTimeout(()=> div.remove(), 3000);
}

/* ===== Rotulagem amigável (somente front) ===== */
const LABELS = {
  resources: {
    aprovacao:'Aprovação',
    auditoria:'Auditoria',
    custo:'Custos',
    iniciativa:'Iniciativas',
    kr:'Resultados-chave',
    milestone:'Marcos',
    objetivo:'Objetivos',
    relatorio:'Relatórios',
    usuario:'Usuários'
  },
  scopes: { OWN:'Meu', TEAM:'Time', UNIT:'Unidade', ORG:'Org' },
  actions: { R:'Leitura', W:'Edição' }
};
const SCOPE_ORDER = ['OWN','TEAM','UNIT','ORG'];
const CHIP_CLASS = {
  aprovacao:'chip-aprovacao',
  auditoria:'chip-auditoria',
  custo:'chip-custo',
  iniciativa:'chip-iniciativa',
  kr:'chip-kr',
  milestone:'chip-milestone',
  objetivo:'chip-objetivo',
  relatorio:'chip-relatorio',
  usuario:'chip-usuario'
};

/* ===== Índices de capacidades ===== */
let CAP_BY_KEY = new Map();           // "R:objetivo@ORG" -> capability obj
let CAPS_INDEX = { R:{}, W:{} };      // R[resource] = ['OWN','TEAM'...]

function indexCapabilities(){
  CAP_BY_KEY = new Map();
  CAPS_INDEX = { R:{}, W:{} };
  (OPTIONS.capabilities||[]).forEach(c=>{
    const action = String(c.action).toUpperCase();
    const resource = String(c.resource).toLowerCase();
    const scope = String(c.scope).toUpperCase();
    const key = `${action}:${resource}@${scope}`;
    CAP_BY_KEY.set(key, c);
    (CAPS_INDEX[action][resource] ||= []);
    if (!CAPS_INDEX[action][resource].includes(scope)) CAPS_INDEX[action][resource].push(scope);
  });
  ['R','W'].forEach(a=>{
    Object.keys(CAPS_INDEX[a]).forEach(res=>{
      CAPS_INDEX[a][res].sort((x,y)=> SCOPE_ORDER.indexOf(x)-SCOPE_ORDER.indexOf(y));
    });
  });
}

/* ===== Estado do modal de permissões ===== */
let PERM_USER_ID = null;
let PERM_OVERRIDES = new Map(); // capability_id -> 'ALLOW'|'DENY'
let PERM_STATE = { R:new Set(), W:new Set() }; // chaves visíveis (ex.: "R:objetivo@ORG")

function resetPermState(){ PERM_OVERRIDES = new Map(); PERM_STATE = { R:new Set(), W:new Set() }; }
function parseCapList(str){
  if (!str || typeof str !== 'string') return [];
  return str.split(',').map(s=>s.trim()).filter(Boolean).map(key=>{
    let action=''; let resource=''; let scope='';
    const parts = key.split(':');
    if (parts.length >= 2) {
      action = (parts[0]||'').trim().toUpperCase();
      const rest = parts.slice(1).join(':');
      const at = rest.split('@');
      resource = (at[0]||'').trim().toLowerCase();
      scope = (at[1]||'').trim().toUpperCase();
    }
    return { key, action, resource, scope };
  }).filter(c=> c.action && c.resource && c.scope);
}

/* ===== UI helpers ===== */
function groupFromSet(actSet){
  const g = {};
  Array.from(actSet).forEach(k=>{
    const [action, rest] = k.split(':');
    const [resource, scope] = rest.split('@');
    (g[resource] ||= new Set()).add(scope);
  });
  return g;
}
function niceRes(resource){ return LABELS.resources[resource] || resource; }

/* ===== Render resumo agrupado ===== */
function renderSummaryGrouped(){
  const build = (act, mountSel) => {
    const container = $(mountSel);
    const g = groupFromSet(PERM_STATE[act]);
    const resources = Object.keys(g).sort((a,b)=> niceRes(a).localeCompare(niceRes(b),'pt-BR'));

    let html = '';
    resources.forEach(resource=>{
      const count = g[resource].size;
      const cls = CHIP_CLASS[resource] || '';
      const scopesAvail = CAPS_INDEX[act][resource] || [];
      let pop = `<div class="group-pop" role="menu" aria-label="${niceRes(resource)} — ${LABELS.actions[act]}"><div class="scope-list">`;
      scopesAvail.forEach(scope=>{
        const on = g[resource].has(scope);
        const scLabel = LABELS.scopes[scope] || scope;
        const icon = on ? 'fa-solid fa-toggle-on' : 'fa-solid fa-toggle-off';
        pop += `<button class="scope-item ${on?'on':''}" data-action="${act}" data-resource="${resource}" data-scope="${scope}">
                  <span class="name">${scLabel}</span>
                  <i class="icon ${icon}"></i>
                </button>`;
      });
      pop += `</div></div>`;

      html += `<span class="group-chip ${cls}" tabindex="0" title="${LABELS.actions[act]} — ${niceRes(resource)}">
                 <span class="label">${niceRes(resource)}</span>
                 <span class="count">${count}</span>
                 <i class="caret fa-solid fa-caret-down"></i>
                 ${pop}
               </span>`;
    });

    const plus = `<button class="chip-add" data-add="${act}" title="Adicionar ${LABELS.actions[act]}"><i class="fa-solid fa-plus"></i> Adicionar</button>`;
    container.innerHTML = (html || '') + plus;
  };
  build('R', '#sumR');
  build('W', '#sumW');
}

/* ===== Eventos (delegação robusta) ===== */
document.addEventListener('click', (ev)=>{
  const scopeBtn = ev.target.closest('.scope-item');
  if (scopeBtn){
    const action = scopeBtn.dataset.action;
    const resource = scopeBtn.dataset.resource;
    const scope = scopeBtn.dataset.scope;
    toggleScope(action, resource, scope);
    return;
  }

  const addBtn = ev.target.closest('.chip-add');
  if (addBtn){
    switchTab('overrides');
    toast('Use a aba “Overrides” para conceder novas permissões específicas (Allow).');
    return;
  }
});

/* ===== Alternar escopo ===== */
function findCapability(action, resource, scope){
  return CAP_BY_KEY.get(`${action}:${resource}@${scope}`);
}
function toggleScope(action, resource, scope){
  const key = `${action}:${resource}@${scope}`;
  const cap = findCapability(action, resource, scope);
  if (!cap){ toast('Capacidade não encontrada.'); return; }

  const set = PERM_STATE[action];
  if (set.has(key)){
    set.delete(key);
    PERM_OVERRIDES.set(String(cap.capability_id), 'DENY');
    toast(`Removido: ${LABELS.actions[action]} • ${niceRes(resource)} • ${LABELS.scopes[scope]}`);
  } else {
    set.add(key);
    PERM_OVERRIDES.set(String(cap.capability_id), 'ALLOW');
    toast(`Adicionado: ${LABELS.actions[action]} • ${niceRes(resource)} • ${LABELS.scopes[scope]}`);
  }
  renderSummaryGrouped();
}

/* ===== Abas, overrides e roles ===== */
function show(el){ el?.classList.add('show'); el?.setAttribute('aria-hidden','false'); }
function hide(el){ el?.classList.remove('show'); el?.setAttribute('aria-hidden','true'); }
function switchTab(tab){
  $$('.tab').forEach(b=> b.classList.toggle('active', b.dataset.tab===tab));
  $$('.perm-body').forEach(b=> b.classList.toggle('show', b.id==='tab-'+tab));
  if (tab==='resumo') renderSummaryGrouped();
}

function groupByResource(caps){
  const g={}; (caps||[]).forEach(c=>{ (g[c.resource] ||= []).push(c); });
  Object.values(g).forEach(arr=> arr.sort((a,b)=> (a.action+a.scope).localeCompare(b.action+b.scope)));
  return g;
}
function renderOverrides(container, overrides){
  container.innerHTML = '';
  const ov = new Map((overrides||[]).map(o=> [String(o.capability_id), o.effect]));
  const grouped = groupByResource(OPTIONS.capabilities);

  Object.keys(grouped).sort().forEach(resource=>{
    const items = grouped[resource];
    const box = document.createElement('div');
    box.className='cap-group';
    const resLabel = LABELS.resources[resource] || resource;
    box.innerHTML = `<div class="cap-title"><i class="fa-solid fa-cube"></i> ${resLabel}</div>`;
    items.forEach(c=>{
      const key = String(c.capability_id);
      const sel = ov.get(key) || 'INHERIT';
      const label = `${c.action} @ ${c.scope}`;
      box.insertAdjacentHTML('beforeend', `
        <div class="cap-item">
          <span style="min-width:120px;display:inline-block;">${label}</span>
          <select name="ov_${key}" data-cap="${key}">
            <option value="INHERIT" ${sel==='INHERIT'?'selected':''}>Inherit</option>
            <option value="ALLOW"   ${sel==='ALLOW'  ?'selected':''}>Allow</option>
            <option value="DENY"    ${sel==='DENY'   ?'selected':''}>Deny</option>
          </select>
        </div>
      `);
    });
    container.appendChild(box);
  });
}
function renderRoles(container, selected){
  container.innerHTML = '';
  const set = new Set((selected||[]).map(v=> String(v)));
  OPTIONS.roles.forEach(r=>{
    const rid = r.id || r.role_id || r.key || r.role_key;
    const label = r.descricao || r.role_name || r.key || r.role_key || rid;
    const id = 'role_'+rid;
    container.insertAdjacentHTML('beforeend',
      `<label class="cap-item" for="${id}">
         <input type="checkbox" id="${id}" name="roles[]" value="${rid}" ${set.has(String(rid))?'checked':''}>
         <span>${label}</span>
       </label>`);
  });
}

/* ===== Abrir Permissões ===== */
async function ensureCapabilities(){
  if (OPTIONS.capabilities?.length) return;
  const r = await fetch(API+'?action=capabilities',{cache:'no-store'});
  const j = await r.json();
  OPTIONS.capabilities = j.capabilities || [];
}
async function openPerm(id_user, name){
  PERM_USER_ID = id_user;
  $('#permUserName').textContent = name;
  show($('#permModal'));
  switchTab('resumo');

  await ensureCapabilities();
  indexCapabilities();

  const r = await fetch(API+`?action=get_permissions&id=${id_user}`, {cache:'no-store'});
  const j = await r.json();

  resetPermState();
  const sum = j.summary || {};
  parseCapList(sum.consulta_R || sum.consulta || '').forEach(c => PERM_STATE.R.add(c.key));
  parseCapList(sum.edicao_W   || sum.edicao   || '').forEach(c => PERM_STATE.W.add(c.key));
  (j.overrides||[]).forEach(o => PERM_OVERRIDES.set(String(o.capability_id), o.effect));

  renderSummaryGrouped();
  renderRoles($('#rolesBoxModal'), j.roles || []);
  renderOverrides($('#capsBoxModal'), j.overrides || []);
}

/* ===== Coleta & Save ===== */
function collectRolesFrom(){ return $$('#rolesBoxModal input[type="checkbox"]:checked').map(i=> i.value); }
function collectOverridesFrom(){
  const out = new Map();
  $$('#capsBoxModal select').forEach(sel=>{
    const cap = String(sel.dataset.cap);
    const val = String(sel.value||'INHERIT').toUpperCase();
    if (val==='ALLOW' || val==='DENY') out.set(cap, val);
  });
  PERM_OVERRIDES.forEach((val, capId)=> out.set(String(capId), val));
  return Array.from(out, ([capability_id, effect]) => ({capability_id, effect}));
}
async function savePerm(){
  if (!PERM_USER_ID) return;
  const roles = collectRolesFrom();
  const overrides = collectOverridesFrom();

  const fd = new FormData();
  fd.append('action','save_permissions');
  fd.append('csrf_token', CSRF);
  fd.append('id_user', String(PERM_USER_ID));
  roles.forEach(v => fd.append('roles[]', v));
  overrides.forEach(o => fd.append(`overrides[${o.capability_id}]`, o.effect));

  const r = await fetch(API, { method:'POST', body: fd });
  const j = await r.json();
  if (!r.ok || !j.success) { alert(j.error || 'Falha ao salvar permissões'); return; }

  hide($('#permModal'));
  await loadList();
  toast('Permissões salvas com sucesso.');
}

/* ===== Lista (cards) ===== */
function roleChip(r){ return `<span class="role">${r}</span>`; }
function buildAccessHTML(access){
  const rawR = (access?.consulta_R || access?.consulta || '').trim();
  const rawW = (access?.edicao_W   || access?.edicao   || '').trim();
  const toGroups = str => {
    const g = {};
    parseCapList(str).forEach(c=> (g[c.resource] ||= new Set()).add(c.scope));
    return Object.keys(g).sort((a,b)=> niceRes(a).localeCompare(niceRes(b),'pt-BR'))
      .map(res=>{
        const cls=CHIP_CLASS[res]||'';
        return `<span class="group-chip ${cls}" title="${niceRes(res)}"><span class="label">${niceRes(res)}</span><span class="count">${g[res].size}</span></span>`;
      }).join('') || '—';
  };
  return `<div class="access">
    <div class="access-row"><span class="label">Leitura</span><div class="chips">${toGroups(rawR)}</div></div>
    <div class="access-row"><span class="label">Edição</span><div class="chips">${toGroups(rawW)}</div></div>
  </div>`;
}
function userCard(u){
  const name = `${u.primeiro_nome||''} ${u.ultimo_nome||''}`.trim() || ('#'+u.id_user);
  const imgPNG = `/OKR_system/assets/img/avatars/${u.id_user}.png`;
  const imgJPG = `/OKR_system/assets/img/avatars/${u.id_user}.jpg`;
  const imgJPEG= `/OKR_system/assets/img/avatars/${u.id_user}.jpeg`;
  const avatar = `<img class="avatar" src="${imgPNG}" onerror="this.onerror=null; this.src='${imgJPG}'; this.onerror=function(){this.src='${imgJPEG}';};" alt="">`;
  const roles  = (u.roles||[]).length ? u.roles.map(roleChip).join(' ') : '<span class="role">sem papéis</span>';
  const access = u.access || { consulta_R: u.consulta_R, edicao_W: u.edicao_W };

  // >>> LÓGICA DE EXIBIÇÃO DO BOTÃO EXCLUIR <<<
  const rolesLower = (ME_ROLES||[]).map(r => String(r).toLowerCase());
  const iAmMasterOrAdmin = !!IS_MASTER || rolesLower.includes('admin_master') || rolesLower.includes('user_admin');
  const canDeleteThis = iAmMasterOrAdmin && String(u.id_user) !== String(MY_ID);
  const delBtn = canDeleteThis
    ? `<button class="btn btn-danger" title="Excluir usuário"
          data-id="${u.id_user}"
          data-name="${name.replace(/"/g,'&quot;')}"
          onclick="askDelete(this)">
          <i class="fa-regular fa-trash-can"></i>
       </button>`
    : '';

  return `
    <article class="card">
      <div class="av">${avatar}</div>
      <div class="info">
        <div class="name">${name}</div>
        <div class="meta">
          <span class="badge"><i class="fa-regular fa-envelope"></i> ${u.email_corporativo||'—'}</span>
          <span class="badge"><i class="fa-regular fa-building"></i> ${u.company_name||'—'}</span>
          ${u.telefone ? `<span class="badge"><i class="fa-regular fa-phone"></i> ${u.telefone}</span>` : ''}
        </div>
        <div class="meta">${roles}</div>
        ${buildAccessHTML(access)}
      </div>
      <div class="right">
        <button class="btn" title="Permissões" onclick="openPerm(${u.id_user}, '${name.replace(/'/g,"&#39;")}')"><i class="fa-solid fa-shield-halved"></i></button>
        <a class="btn" href="/OKR_system/views/usuario_form.php?id=${u.id_user}" title="Editar cadastro"><i class="fa-regular fa-pen-to-square"></i></a>
        ${delBtn}
      </div>
    </article>
  `;
}

/* ===== Options + Lista ===== */
async function loadOptions(){
  const r = await fetch(API+'?action=options',{cache:'no-store'});
  const j = await r.json();

  IS_MASTER  = !!j.is_master;
  MY_COMPANY = j.my_company ?? null;

  OPTIONS.roles        = j.roles || [];
  OPTIONS.capabilities = j.capabilities || [];

  // >>> Dados do usuário logado (para controle do botão Excluir)
  MY_ID    = j.my_id ?? j.me_id ?? null;
  ME_ROLES = j.me_roles || j.my_roles || [];

  const sel = $('#fCompany');
  sel.innerHTML = '';
  if (IS_MASTER) sel.add(new Option('Todas','all'));
  (j.companies||[]).forEach(c=> sel.add(new Option(`${c.nome} (#${c.id_company})`, c.id_company)));
  if (IS_MASTER) sel.value = 'all'; else if (MY_COMPANY) sel.value = String(MY_COMPANY);
  $('#pillOrg').innerHTML = `<i class="fa-regular fa-building"></i> Organização: ${IS_MASTER ? 'Todas' : ('#'+(MY_COMPANY ?? '—'))}`;

  const rsel = $('#fRole');
  (OPTIONS.roles).forEach(r=>{
    const txt = r.descricao || r.role_name || r.key || r.role_key || r.id;
    const val = r.id || r.role_id || r.key || r.role_key;
    rsel.add(new Option(txt, val));
  });
}
async function loadList(){
  const params = new URLSearchParams();
  const q  = ($('#fBusca').value||'').trim();
  const co = $('#fCompany').value || (IS_MASTER ? 'all' : (MY_COMPANY||''));
  const ro = $('#fRole').value || 'all';
  params.set('action','list');
  params.set('include_access','1');
  if (q)  params.set('q', q);
  if (co) params.set('company', co);
  if (ro) params.set('role', ro);
  params.set('per_page','100');

  const r = await fetch(API+'?'+params.toString(), {cache:'no-store'});
  const j = await r.json();

  const list = $('#list');
  list.innerHTML = '';

  const arr = j.users || j.items || [];
  $('#pillUsers').innerHTML = `<i class="fa-regular fa-address-card"></i> Usuários: ${j.total ?? arr.length}`;

  if (!arr.length){
    list.innerHTML = `<div class="empty"><i class="fa-regular fa-folder-open"></i> Nenhum usuário encontrado com os filtros atuais.</div>`;
    return;
  }
  list.innerHTML = arr.map(userCard).join('');
}

/* ===== Excluir ===== */
let pendingDeleteId=null;
function askDelete(btn){
  pendingDeleteId = parseInt(btn.dataset.id,10);
  const nm = btn.dataset.name || ('#'+pendingDeleteId);
  show($('#modalDel'));
  $('#delMsg').textContent = `Tem certeza que deseja excluir ${nm}?`;
}
async function doDelete(){
  if (!pendingDeleteId) return;
  try{
    const fd = new FormData();
    fd.append('action','delete');
    fd.append('csrf_token', CSRF);
    fd.append('id_user', String(pendingDeleteId));
    const r = await fetch(API, { method:'POST', body:fd });
    const j = await r.json();
    if (!r.ok || !j.success) throw new Error(j.error || 'Falha ao excluir');
    pendingDeleteId = null;
    hide($('#modalDel'));
    await loadList();
    toast('Usuário excluído com sucesso.');
  }catch(e){ alert(e.message||'Erro de rede'); }
}

/* ===== Boot ===== */
document.addEventListener('DOMContentLoaded', async ()=>{
  await loadOptions();
  await loadList();

  $('#fCompany').addEventListener('change', loadList);
  $('#fRole').addEventListener('change', loadList);
  $('#fBusca').addEventListener('input', ()=>{ clearTimeout(window.__t); window.__t=setTimeout(loadList,300); });
  $('#btnRefresh').addEventListener('click', loadList);

  // excluir
  $('#delCancel').addEventListener('click', ()=>{ pendingDeleteId=null; hide($('#modalDel')); });
  $('#delConfirm').addEventListener('click', doDelete);

  // modal perms
  $('#permClose').addEventListener('click', ()=> hide($('#permModal')));
  $$('.tab').forEach(b=> b.addEventListener('click', ()=> switchTab(b.dataset.tab)));
  $('#permSave').addEventListener('click', savePerm);
});
</script>
</body>
</html>
