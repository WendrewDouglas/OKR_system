<?php
// views/usuarios.php — Gerenciar Usuários (lista, filtros e ações)
// Requer: auth/usuarios_api.php (actions: options, list, delete)

declare(strict_types=1);
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php'); exit;
}

// CSRF
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
      box-shadow:var(--shadow); color:var(--text); position:relative; overflow:hidden;
    }
    .head-title{ margin:0; font-size:1.35rem; font-weight:900; letter-spacing:.2px; display:flex; align-items:center; gap:8px; }
    .head-title i{ color:var(--gold); }

    .head-top{
      display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
    }
    .head-meta{ margin-top:10px; display:flex; gap:10px; flex-wrap:wrap; }
    .pill{ display:inline-flex; align-items:center; gap:8px; background:#0e131a; border:1px solid var(--border); color: var(--muted); padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
    .pill i{ font-size:.9rem; opacity:.9; }

    /* Botão gold no header */
    .btn-gold{
      background:var(--gold); color:#111; border:1px solid rgba(246,195,67,.9);
      padding:10px 16px; border-radius:12px; font-weight:900; white-space:nowrap;
      box-shadow:0 6px 20px rgba(246,195,67,.22);
    }
    .btn-gold:hover{ filter:brightness(.96); transform:translateY(-1px); box-shadow:0 10px 28px rgba(246,195,67,.28); }

    /* ===== Toolbar (filtros) ===== */
    .toolbar{ display:block; }
    .filters{
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:14px; padding:14px; box-shadow:var(--shadow); color:var(--text);
    }
    .filters-grid{
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap:12px;
      align-items:end;
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
    .btn-ghost{ background:transparent; border:1px dashed #334155; color:#cbd5e1; }

    .list{ display:grid; grid-template-columns:1fr; gap:10px; }
    .card{
      background:linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:16px; padding:12px; box-shadow:var(--shadow); color:var(--text);
      display:grid; grid-template-columns: auto 1fr auto; gap:12px; align-items:center;
    }
    .avatar{ width:44px; height:44px; border-radius:50%; object-fit:cover; background:#111827; display:grid; place-items:center; font-weight:900; }
    .info{ display:grid; gap:4px; }
    .name{ font-weight:900; letter-spacing:.2px; }
    .meta{ display:flex; gap:8px; flex-wrap:wrap; }
    .badge{ font-size:.78rem; border:1px solid var(--border); padding:4px 8px; border-radius:999px; color:#c9d4e5; }
    .role{ font-size:.72rem; padding:3px 6px; border-radius:999px; border:1px dashed #334155; color:#cbd5e1; }

    .right{ display:flex; gap:8px; }

    .empty{
      padding:16px; border:1px dashed #334155; border-radius:12px; color:#cbd5e1; text-align:center; background:#0b1118;
    }

    /* modal excluir */
    .overlay{ position:fixed; inset:0; display:none; place-items:center; background:rgba(0,0,0,.55); z-index:3000; }
    .overlay.show{ display:grid; }
    .ai-card{
      width:min(560px,94vw); background:#0b1020; color:#e6e9f2;
      border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.35); padding:18px; position:relative; overflow:hidden;
      border:1px solid #223047;
    }
    .ai-header{ display:flex; align-items:center; gap:12px; margin-bottom:10px; }
    .ai-avatar{ width:44px; height:44px; border-radius:50%; display:grid; place-items:center; color:#fff; font-weight:800;
      background:conic-gradient(from 180deg at 50% 50%, #3b82f6, #06b6d4, #8b5cf6, #3b82f6); box-shadow:0 6px 18px rgba(59,130,246,.35); }
    .ai-title{ font-size:1rem; opacity:.9; font-weight:900; }
    .ai-bubble{ background:#111833; border:1px solid rgba(255,255,255,.06); border-radius:14px; padding:14px; margin:8px 0 14px; }
    .ai-actions{ display:flex; gap:8px; justify-content:flex-end; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="users">
      <!-- Breadcrumb -->
      <div class="crumbs">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <span><i class="fa-solid fa-users-gear"></i> Gerenciar Usuários</span>
      </div>

      <!-- Cabeçalho (com botão à direita) -->
      <section class="head-card">
        <div class="head-top">
          <h1 class="head-title"><i class="fa-solid fa-users-gear"></i> Gerenciar Usuários</h1>
          <a class="btn-gold" href="/OKR_system/views/usuario_form.php">
            <i class="fa-solid fa-user-plus"></i> Novo usuário
          </a>
        </div>
        <div class="head-meta">
          <span class="pill" id="pillUsers"><i class="fa-regular fa-address-card"></i> Usuários: —</span>
          <span class="pill" id="pillOrg"><i class="fa-regular fa-building"></i> Organização: #—</span>
        </div>
      </section>

      <!-- Filtros -->
      <section class="toolbar">
        <div class="filters">
          <div class="filters-grid">
            <div class="fg">
              <label>Organização</label>
              <select id="fCompany"></select>
            </div>

            <div class="fg">
              <label>Papel</label>
              <select id="fRole">
                <option value="all">Todos</option>
              </select>
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

      <!-- Lista -->
      <section id="list" class="list"></section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <!-- Modal excluir -->
  <div id="modalDel" class="overlay" aria-hidden="true">
    <div class="ai-card" role="dialog" aria-modal="true">
      <div class="ai-header">
        <div class="ai-avatar">OK</div>
        <div><div class="ai-title">Excluir usuário</div></div>
      </div>
      <div class="ai-bubble">
        <div id="delMsg">Tem certeza que deseja excluir?</div>
      </div>
      <div class="ai-actions">
        <button class="btn" id="delCancel">Cancelar</button>
        <button class="btn btn-danger" id="delConfirm">Excluir</button>
      </div>
    </div>
  </div>

<script>
const API  = '/OKR_system/auth/usuarios_api.php';
const CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8") ?>';

const $  = (s,r=document)=>r.querySelector(s);
const $$ = (s,r=document)=>Array.from(r.querySelectorAll(s));

let IS_MASTER = false;
let MY_COMPANY = null;

let pendingDeleteId = null;

function roleChip(r){ return `<span class="role">${r}</span>`; }

function userCard(u){
  const name = `${u.primeiro_nome} ${u.ultimo_nome||''}`.trim();
  const imgPath = `/OKR_system/assets/img/avatars/${u.id_user}.png`;
  const imgJpg  = `/OKR_system/assets/img/avatars/${u.id_user}.jpg`;
  const imgJpeg = `/OKR_system/assets/img/avatars/${u.id_user}.jpeg`;
  const avatar = `<img class="avatar" src="${imgPath}" onerror="this.onerror=null; this.src='${imgJpg}'; this.onerror=function(){this.onerror=null; this.src='${imgJpeg}';};" alt="">`;
  const roles = (u.roles||[]).length ? u.roles.map(roleChip).join(' ') : '<span class="role">sem papéis</span>';
  return `
    <article class="card">
      <div class="av">${avatar}</div>
      <div class="info">
        <div class="name">${name}</div>
        <div class="meta">
          <span class="badge"><i class="fa-regular fa-envelope"></i> ${u.email_corporativo||'—'}</span>
          <span class="badge"><i class="fa-regular fa-building"></i> ${u.company_name||'—'}</span>
          ${u.telefone ? `<span class="badge"><i class="fa-regular fa-phone"></i> ${u.telefone}</span>`:''}
        </div>
        <div class="meta">${roles}</div>
      </div>
      <div class="right">
        <a class="btn" href="/OKR_system/views/usuario_form.php?id=${u.id_user}"><i class="fa-regular fa-pen-to-square"></i></a>
        ${u.can_delete ? `<button class="btn btn-danger" data-id="${u.id_user}" data-name="${name}" onclick="askDelete(this)"><i class="fa-regular fa-trash-can"></i></button>` : ''}
      </div>
    </article>
  `;
}

async function loadOptions(){
  const r = await fetch(API+'?action=options',{cache:'no-store'});
  const j = await r.json();
  IS_MASTER  = !!j.is_master;
  MY_COMPANY = j.my_company ?? null;

  const sel = $('#fCompany');
  sel.innerHTML = '';
  if (IS_MASTER) sel.add(new Option('Todas','all'));
  (j.companies||[]).forEach(c=> sel.add(new Option(`${c.nome} (#${c.id_company})`, c.id_company)));

  if (IS_MASTER) sel.value = 'all';
  else if (MY_COMPANY) sel.value = String(MY_COMPANY);

  const rsel = $('#fRole');
  (j.roles||[]).forEach(r=> rsel.add(new Option(r.descricao, r.id)));

  $('#pillOrg').innerHTML = `<i class="fa-regular fa-building"></i> Organização: ${IS_MASTER ? 'Todas' : ('#'+(MY_COMPANY ?? '—'))}`;
}

async function loadList(){
  const params = new URLSearchParams();
  const q  = ($('#fBusca').value||'').trim();
  const co = $('#fCompany').value || (IS_MASTER ? 'all' : (MY_COMPANY||''));
  const ro = $('#fRole').value || 'all';
  params.set('action','list');
  if (q) params.set('q', q);
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

function askDelete(btn){
  pendingDeleteId = parseInt(btn.dataset.id,10);
  const nm = btn.dataset.name || ('#'+pendingDeleteId);
  show($('#modalDel'));
  document.getElementById('delMsg').textContent = `Tem certeza que deseja excluir ${nm}?`;
}
async function doDelete(){
  if (!pendingDeleteId) return;
  try{
    const fd = new FormData();
    fd.append('action','delete');
    fd.append('csrf_token', '<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8") ?>');
    fd.append('id_user', String(pendingDeleteId));
    const r = await fetch(API, { method:'POST', body:fd });
    const j = await r.json();
    if (!r.ok || !j.success) throw new Error(j.error || 'Falha ao excluir');
    pendingDeleteId = null;
    hide($('#modalDel'));
    await loadList();
  }catch(e){ alert(e.message||'Erro de rede'); }
}

// utils modal
function show(el){ el?.classList.add('show'); el?.setAttribute('aria-hidden','false'); }
function hide(el){ el?.classList.remove('show'); el?.setAttribute('aria-hidden','true'); }

// boot
document.addEventListener('DOMContentLoaded', async ()=>{
  await loadOptions();
  await loadList();

  document.getElementById('fCompany').addEventListener('change', loadList);
  document.getElementById('fRole').addEventListener('change', loadList);
  document.getElementById('fBusca').addEventListener('input', ()=>{
    clearTimeout(window.__t);
    window.__t = setTimeout(loadList, 300);
  });
  document.getElementById('btnRefresh').addEventListener('click', loadList);

  // modal
  document.getElementById('delCancel').addEventListener('click', ()=>{ pendingDeleteId=null; hide($('#modalDel')); });
  document.getElementById('delConfirm').addEventListener('click', doDelete);
});
</script>
</body>
</html>
