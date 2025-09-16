<?php
// views/notificacoes.php
declare(strict_types=1);
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
if (!isset($_SESSION['user_id'])) { header('Location: /OKR_system/views/login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Notificações — OKR System</title>
  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>
  <style>
    :root{
      --bg-soft:#171b21; --card: var(--bg1, #222222); --muted:#a6adbb; --text:#eaeef6;
      --gold:var(--bg2, #F1C40F); --green:#22c55e; --blue:#60a5fa; --red:#ef4444;
      --border:#222733; --shadow:0 10px 30px rgba(0,0,0,.20);
    }
    body{ background:#fff !important; color:#111; }
    main.noti{ padding:24px; display:grid; gap:16px; }
    .head-card{ background:linear-gradient(180deg, var(--card), #0d1117); border:1px solid var(--border); border-radius:16px; padding:16px; color:var(--text); box-shadow:var(--shadow); }
    .list{ display:grid; gap:10px; }
    .card{ background:linear-gradient(180deg, var(--card), #0e1319); border:1px solid var(--border); border-radius:14px; padding:12px; color:var(--text); display:grid; grid-template-columns:1fr auto; gap:8px; }
    .title{ font-weight:900; letter-spacing:.2px; display:flex; align-items:center; gap:8px; }
    .msg{ color:#cbd5e1; }
    .meta{ color:#9aa4b2; font-size:.85rem; }
    .badge{ font-size:.78rem; border:1px solid var(--border); padding:2px 8px; border-radius:999px; }
    .actions{ display:flex; align-items:center; gap:6px; }
    .btn{ border:1px solid var(--border); background:var(--gold); color:#222222; padding:8px 10px; border-radius:10px; font-weight:800; cursor:pointer; }
    .btn:hover{ transform:translateY(-1px); transition:.15s; }
    .empty{ border:1px dashed #334155; color:#cbd5e1; padding:16px; border-radius:12px; text-align:center; background:#0b1118; }
    .pill{ display:inline-flex; gap:6px; align-items:center; border:1px solid #334155; padding:6px 10px; border-radius:999px; }
  </style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<div class="content">
<?php include __DIR__ . '/partials/header.php'; ?>
<main class="noti">
  <div class="head-card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
      <h1 style="margin:0;font-size:1.2rem;"><i class="fa-regular fa-bell"></i> Minhas notificações</h1>
      <div class="actions">
        <button id="btnAllRead" class="btn"><i class="fa-regular fa-envelope-open"></i> Marcar todas como lidas</button>
      </div>
    </div>
    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
      <span class="pill"><i class="fa-regular fa-circle-dot"></i> <span id="counter">—</span> não lidas</span>
    </div>
  </div>

  <section id="list" class="list"></section>
</main>
</div>

<script>
const API = '/OKR_system/auth/notificacoes_api.php';
const CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8") ?>';

function card(n){
  return `
    <article class="card" data-id="${n.id_notificacao}">
      <div>
        <div class="title">
          <i class="fa-regular ${n.lida?'fa-envelope-open':'fa-envelope'}"></i>
          <span>${n.titulo}</span>
          ${n.lida?'<span class="badge">lida</span>':'<span class="badge">não lida</span>'}
        </div>
        <div class="msg">${n.mensagem}</div>
        <div class="meta"><i class="fa-regular fa-clock"></i> ${n.dt_criado_fmt}</div>
      </div>
      <div class="actions">
        ${n.lida?'':'<button class="btn btnRead">Marcar como lida</button>'}
      </div>
    </article>
  `;
}

async function loadAll(){
  const [cnt, lst] = await Promise.all([
    fetch(API+'?action=count').then(r=>r.json()),
    fetch(API+'?action=list').then(r=>r.json())
  ]);
  document.getElementById('counter').textContent = cnt?.count ?? 0;
  const list = document.getElementById('list');
  if (!lst?.items?.length){
    list.innerHTML = `<div class="empty"><i class="fa-regular fa-folder-open"></i> Sem notificações.</div>`;
    return;
  }
  list.innerHTML = lst.items.map(card).join('');
  list.querySelectorAll('.btnRead').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = btn.closest('.card').dataset.id;
      const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('action','mark_read'); fd.append('id', id);
      const res = await fetch(API, {method:'POST', body: fd});
      const data = await res.json().catch(()=> ({}));
      if (data?.success) loadAll();
    });
  });
}
document.getElementById('btnAllRead').addEventListener('click', async ()=>{
  const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('action','mark_read'); fd.append('id','all');
  const res = await fetch(API, {method:'POST', body: fd});
  const data = await res.json().catch(()=> ({}));
  if (data?.success) loadAll();
});
document.addEventListener('DOMContentLoaded', loadAll);
</script>
</body>
</html>
