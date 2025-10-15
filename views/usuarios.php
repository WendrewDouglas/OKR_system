<?php
// views/usuarios.php — Gerenciar Usuários (lista + permissões RBAC + Departamento/Função)
// Backend esperado em /auth/usuarios_api.php com as actions:
// options, list, get_user, save_user, delete, capabilities, get_permissions, save_permissions
// (NOVAS LEITURAS): departamentos (por company), niveis_cargo (fixo)

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/acl.php';

/* ===================== LOGGING (PHP) ===================== */
// Arquivo de log em views/error_log (cria se não existir)
$ERROR_LOG_FILE = __DIR__ . '/error_log';
if (!file_exists($ERROR_LOG_FILE)) {
  @touch($ERROR_LOG_FILE);
  @chmod($ERROR_LOG_FILE, 0664);
}

// Direciona erros do PHP para o arquivo
ini_set('log_errors', '1');
ini_set('error_log', $ERROR_LOG_FILE);

// (Opcional) mostrar erros apenas em DEV. Em produção, deixe 0.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Helpers de log
function app_log(string $message, array $context = []): void {
  global $ERROR_LOG_FILE;
  $uid = $_SESSION['user_id'] ?? 'guest';
  $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
  $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
  $line = sprintf(
    "[%s] uid=%s ip=%s ua=%s msg=%s ctx=%s%s",
    date('c'), $uid, $ip, str_replace(["\r","\n"], ' ', $ua),
    str_replace(["\r","\n"], ' ', $message),
    json_encode($context, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    PHP_EOL
  );
  @file_put_contents($ERROR_LOG_FILE, $line, FILE_APPEND);
}

// Trata exceptions e errors não capturados
set_exception_handler(function(Throwable $e){
  app_log('UNCAUGHT_EXCEPTION', [
    'type' => get_class($e),
    'message' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
    'trace' => explode("\n", $e->getTraceAsString()),
  ]);
  http_response_code(500);
  exit('Erro interno.');
});
set_error_handler(function($severity, $message, $file, $line){
  // Converte erros em log sem interromper a execução
  app_log('PHP_ERROR', compact('severity','message','file','line'));
  return false; // deixa o PHP lidar conforme configuração
});

// Endpoint simples para LOG de front-end (via fetch/beacon)
if (isset($_POST['__frontlog'])) {
  header('Content-Type: application/json; charset=utf-8');
  $msg  = substr((string)($_POST['message'] ?? ''), 0, 4000);
  $meta = (string)($_POST['meta'] ?? '');
  app_log('FRONTEND_LOG', [
    'message' => $msg,
    'meta'    => $meta,
    'path'    => $_SERVER['REQUEST_URI'] ?? ''
  ]);
  echo json_encode(['ok' => true]);
  exit;
}

/* ===================== GATE & AUTH ===================== */
// Gate automático pela dom_paginas.requires_cap (defina M:user@ORG para esta rota).
gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');

// Requer sessão autenticada
if (empty($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php'); exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
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
      --radius: 14px;
    }
    body{ background:#fff !important; color:#111; }
    :root{ --chat-w:0px; }
    .content{ background:transparent; }
    main.users{
      padding:24px; display:grid; grid-template-columns:1fr; gap:16px;
      margin-right:var(--chat-w); transition:margin-right .25s ease;
    }

    .crumbs{ color:#333; font-size:.9rem; display:flex; align-items:center; gap:6px; }
    .crumbs a{ color:#0c4a6e; text-decoration:none; }
    .crumbs .sep{ opacity:.5; margin:0 2px; }
    .crumbs i{ opacity:.8; }

    .head-card{
      background:linear-gradient(180deg, var(--card), #0d1117);
      border:1px solid var(--border); border-radius:16px; padding:16px;
      box-shadow:var(--shadow); color:#e5e7eb; position:relative; overflow:hidden;
      border:1px solid #223047;
    }
    .head-title{ margin:0; font-size:1.35rem; font-weight:900; display:flex; align-items:center; gap:8px; }
    .head-title i{ color:var(--gold); }

    .head-top{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .head-meta{ margin-top:10px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .pill{ display:inline-flex; align-items:center; gap:8px; background:#0e131a; border:1px solid var(--border); color: var(--muted); padding:6px 10px; border-radius:999px; font-size:.82rem; font-weight:700; }
    .pill i{ font-size:.9rem; opacity:.9; }

    .btn { border:1px solid var(--border); background:var(--btn); color:#e5e7eb; padding:10px 14px; border-radius:12px; font-weight:800; cursor:pointer; }
    .btn:hover{ transform:translateY(-1px); transition:.15s; }
    .btn-ghost{ background:#0c1118; }
    .btn-primary{ background:#1f2937; }
    .btn-danger{ border-color:#7f1d1d; background:rgba(127,29,29,.28); }
    .btn-gold{
      background:var(--gold); color:#111; border:1px solid rgba(246,195,67,.9);
      padding:10px 16px; border-radius:12px; font-weight:900; white-space:nowrap;
      box-shadow:0 6px 20px rgba(246,195,67,.22);
    }
    .btn-gold:hover{ filter:brightness(.96); transform:translateY(-1px); box-shadow:0 10px 28px rgba(246,195,67,.28); }

    .toolbar{ position:sticky; top:64px; z-index:10; }
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
    .fg.actions{ display:flex; gap:8px; justify-content:flex-end; align-items:end; }
    .span-2{ grid-column: span 2; }
    @media (max-width: 900px){ .span-2{ grid-column: span 1; } }

    .list{ display:grid; grid-template-columns:1fr; gap:10px; min-height:90px; }
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
    .role{ font-size:.78rem; padding:6px 10px; border-radius:999px; border:1px dashed #334155; color:#e6edf3; background:#0c1118; font-weight:800; }
    .role.role-level{ border-style:solid; }
    .right{ display:flex; gap:8px; }

    .empty{ padding:16px; border:1px dashed #334155; border-radius:12px; color:#cbd5e1; text-align:center; background:#0b1118; }
    .skeleton{
      position:relative; overflow:hidden; border-radius:var(--radius);
      min-height:68px; background:#0c1118; border:1px solid #1f2635;
    }
    .skeleton::after{
      content:""; position:absolute; inset:0;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,.06), transparent);
      transform: translateX(-100%); animation: sh 1.1s infinite;
    }
    @keyframes sh{ to { transform: translateX(100%); } }

    /* ===== Overlay/Modal base ===== */
    .overlay{ position:fixed; inset:0; display:none; place-items:center; background:rgba(0,0,0,.55); z-index:3000; }
    .overlay.show{ display:grid; }
    .modal-card{
      width:min(980px,95vw); background:#0b1020; color:#e6e9f2;
      border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.35); padding:18px; position:relative; overflow:hidden;
      border:1px solid #223047;
    }
    .modal-header{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
    .tabs{ display:flex; gap:6px; }
    .tab{ background:#0c1118; border:1px solid #1f2635; color:#e5e7eb; padding:8px 12px; border-radius:999px; cursor:pointer; font-weight:800; }
    .tab.active{ background:#1f2937; }
    .modal-body{ margin-top:10px; }
    .grid-cap{ display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:8px; }
    .cap-group{ border:1px solid #1f2635; border-radius:12px; padding:10px; background:#0c1118; }
    .cap-title{ font-weight:900; color:#e5e7eb; margin-bottom:8px; display:flex; align-items:center; gap:6px; }
    .cap-item{ display:flex; align-items:center; gap:8px; margin:4px 0; font-size:.9rem; }
    .cap-item select{ background:#0b1118; border:1px solid #223047; color:#e6e9f2; border-radius:8px; padding:6px 8px; }
    .modal-actions{ display:flex; justify-content:flex-end; gap:8px; margin-top:10px; }

    /* ===== Form user ===== */
    .uf-grid{ display:grid; gap:10px; background:#111833; border:1px solid rgba(255,255,255,.06); border-radius:14px; padding:14px; margin:8px 0 14px; }
    .row-2{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    @media (max-width: 720px){ .row-2{ grid-template-columns:1fr; } }
    .uf-grid label{ display:block; margin:0 0 6px; font-size:.85rem; color:#cbd5e1; }
    .uf-grid input, .uf-grid select{
      width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none;
    }
    .hint{ color:#a6adbb; font-size:.85rem; }
    .error{ color:#fecaca; font-size:.85rem; margin-top:4px; }
    .req::after{ content:" *"; color:#F59E0B; font-weight:900; }

    /* ====== Matriz ====== */
    .mx-card{ width:min(1100px,96vw); max-height:92vh; }
    .mx-toolbar{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .mx-toolbar input{ background:#0b1326; border:1px solid #223047; color:#e6e9f2; border-radius:10px; padding:8px 10px; min-width:220px; }
    .matrix-wrap{ overflow:auto; border:1px solid #223047; border-radius:12px; background:#0c1118; }
    table.matrix{ width:100%; border-collapse:separate; border-spacing:0; }
    .matrix thead th{
      position:sticky; top:0; z-index:2; background:#0b1326; color:#e6e9f2; font-weight:800; font-size:.9rem;
      border-bottom:1px solid #223047; padding:10px; text-align:left;
    }
    .matrix thead th.role-col{ text-align:center; min-width:130px; }
    .matrix tbody td, .matrix tbody th{
      border-bottom:1px solid #1f2635; padding:10px; font-size:.9rem; color:#e6e9f2;
    }
    .matrix tbody th{ position:sticky; left:0; z-index:1; background:#0c1118; font-weight:800; max-width:320px; }
    .page-path{ color:#a6adbb; font-size:.8rem; display:block; margin-top:2px; }
    .role-head{ display:flex; align-items:center; justify-content:center; gap:6px; }
    .role-head .tag{ padding:4px 8px; border-radius:999px; border:1px solid #2a3350; background:#0b1326; font-size:.78rem; }
    .cell{ display:flex; align-items:center; justify-content:center; gap:10px; min-width:84px; }
    .ico{ opacity:.35; }
    .hasR .ico.eye{ color:#60a5fa; opacity:1; }
    .hasW .ico.pen{ color:#22c55e; opacity:1; }
    .none{ opacity:.25; font-size:.8rem; }
    .hl{ outline:2px solid #2563eb; outline-offset:-2px; border-radius:8px; }
    .sticky-left{ position:sticky; left:0; background:#0b1326; z-index:3; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="users">
      <nav class="crumbs" aria-label="breadcrumb">
        <i class="fa-solid fa-route"></i>
        <a href="/OKR_system/dashboard" aria-label="Voltar ao dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
        <span class="sep">/</span>
        <span aria-current="page"><i class="fa-solid fa-users-gear"></i> Gerenciar Usuários</span>
      </nav>

      <section class="head-card">
        <div class="head-top">
          <h1 class="head-title"><i class="fa-solid fa-users-gear"></i> Gerenciar Usuários</h1>
        </div>
        <div class="head-meta">
          <span class="pill" id="pillUsers"><i class="fa-regular fa-address-card"></i> Usuários: —</span>
          <span class="pill" id="pillOrg"><i class="fa-regular fa-building"></i> Organização: #—</span>
          <button class="btn-gold" id="btnNewUser" onclick="openUserForm(null)" style="margin-left:auto"><i class="fa-solid fa-user-plus"></i> Novo usuário</button>
        </div>
      </section>

      <section class="toolbar" aria-label="Ferramentas">
        <div class="filters">
          <div class="filters-grid">
            <div class="fg">
              <label for="fCompany">Organização</label>
              <select id="fCompany" aria-label="Filtrar por organização"></select>
            </div>
            <div class="fg">
              <label for="fRole">Papel</label>
              <select id="fRole" aria-label="Filtrar por papel">
                <option value="all">Todos</option>
              </select>
            </div>
            <div class="fg span-2">
              <label for="fBusca">Pesquisar</label>
              <input id="fBusca" type="search" placeholder="Pesquisar por nome/e-mail/telefone…" autocomplete="off" aria-label="Pesquisar usuários">
            </div>
            <div class="fg actions">
              <button class="btn btn-ghost" id="btnClear" title="Limpar filtros"><i class="fa-solid fa-eraser"></i> Limpar</button>
              <button class="btn btn-ghost" id="btnRefresh" title="Atualizar lista"><i class="fa-solid fa-rotate"></i> Atualizar</button>
            </div>
          </div>
        </div>
      </section>

      <section id="list" class="list" aria-live="polite">
        <div class="skeleton"></div>
        <div class="skeleton"></div>
        <div class="skeleton"></div>
      </section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <!-- Modal Excluir -->
  <div id="modalDel" class="overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="delTitle">
      <div class="modal-header"><h3 id="delTitle" class="cap-title"><i class="fa-regular fa-trash-can"></i> Excluir usuário</h3></div>
      <div class="modal-body uf-grid">
        <div id="delMsg">Tem certeza que deseja excluir?</div>
      </div>
      <div class="modal-actions">
        <button class="btn" id="delCancel">Cancelar</button>
        <button class="btn btn-danger" id="delConfirm">Excluir</button>
      </div>
    </div>
  </div>

  <!-- Modal Permissões -->
  <div id="permModal" class="overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="permTitle">
      <div class="modal-header">
        <h3 id="permTitle" class="cap-title"><i class="fa-solid fa-shield-halved"></i> Permissões — <span id="permUserName">Usuário</span></h3>
        <div class="tabs" role="tablist" aria-label="Abas de permissões">
          <button class="tab active" data-tab="resumo" role="tab" aria-selected="true">Resumo</button>
          <button class="tab" data-tab="roles" role="tab">Papéis</button>
          <button class="tab" data-tab="overrides" role="tab">Overrides</button>
        </div>
      </div>

      <div id="tab-resumo" class="modal-body show" role="tabpanel">
        <div class="hint" style="margin-bottom:6px;">
          Resumo do acesso herdado + overrides. Use as abas para ajustar papéis e exceções.
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

      <div id="tab-roles" class="modal-body" role="tabpanel" aria-hidden="true">
        <div id="rolesBoxModal" class="grid-cap" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));"></div>
      </div>

      <div id="tab-overrides" class="modal-body" role="tabpanel" aria-hidden="true">
        <div id="capsBoxModal" class="grid-cap"></div>
        <div class="hint" style="margin-top:6px;">Overrides aplicam exceções por capacidade (ALLOW/DENY). “Inherit” remove a exceção e mantém o herdado dos papéis.</div>
      </div>

      <div class="modal-actions">
        <button class="btn" id="permClose">Fechar</button>
        <button class="btn btn-primary" id="permSave"><i class="fa-regular fa-floppy-disk"></i> Salvar</button>
      </div>
    </div>
  </div>

  <!-- Modal Criar/Editar Usuário -->
  <div id="userFormModal" class="overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="ufTitle">
      <div class="modal-header">
        <h3 id="ufTitle" class="cap-title"><i class="fa-solid fa-user-pen"></i> Novo usuário</h3>
      </div>
      <form id="userForm" class="modal-body" onsubmit="return false;">
        <div class="uf-grid">
          <div class="row-2">
            <div>
              <label for="ufPrimeiro" class="req">Nome</label>
              <input type="text" id="ufPrimeiro" required placeholder="Primeiro nome" autocomplete="off">
              <div class="error" id="errPrimeiro" hidden>Informe o primeiro nome.</div>
            </div>
            <div>
              <label for="ufUltimo">Sobrenome</label>
              <input type="text" id="ufUltimo" placeholder="Sobrenome" autocomplete="off">
            </div>
          </div>

          <div class="row-2">
            <div>
              <label for="ufEmail" class="req">E-mail corporativo</label>
              <input type="email" id="ufEmail" required placeholder="email@empresa.com" autocomplete="off" inputmode="email">
              <div class="error" id="errEmail" hidden>E-mail inválido ou já cadastrado.</div>
            </div>
            <div>
              <label for="ufFone">Telefone</label>
              <input type="text" id="ufFone" placeholder="(xx) xxxxx-xxxx" autocomplete="off">
            </div>
          </div>

          <div class="row-2">
            <div>
              <label for="ufCompany" class="req">Organização</label>
              <select id="ufCompany" required></select>
              <div class="hint">Somente <b>admin_master</b> pode trocar a organização.</div>
            </div>
            <div>
              <label for="ufRoleSelect">Papel (RBAC)</label>
              <select id="ufRoleSelect"></select>
              <div class="hint">Cada usuário possui apenas um papel.</div>
            </div>
          </div>

          <div class="row-2">
            <div>
              <label for="ufDepartamento" class="req">Departamento</label>
              <select id="ufDepartamento" required>
                <option value="">Selecione…</option>
              </select>
              <div class="error" id="errDepartamento" hidden>Selecione o departamento.</div>
            </div>
            <div>
              <label for="ufFuncao" class="req">Função (cargo)</label>
              <select id="ufFuncao" required>
                <option value="">Selecione…</option>
              </select>
              <div class="error" id="errFuncao" hidden>Selecione a função.</div>
              <div class="hint">Ex.: Auxiliar, Assistente, Analista, Especialista, Coordenador, Gerente, Diretor…</div>
            </div>
          </div>
        </div>
      </form>
      <div class="modal-actions">
        <button class="btn" id="ufCancel">Cancelar</button>
        <button class="btn btn-primary" id="ufSave"><i class="fa-regular fa-floppy-disk"></i> Salvar</button>
      </div>
    </div>
  </div>

  <!-- Modal Matriz Perfis × Telas (Somente visualização) -->
  <div id="matrixModal" class="overlay" aria-hidden="true">
    <div class="modal-card mx-card" role="dialog" aria-modal="true" aria-labelledby="mxTitle">
      <div class="modal-header">
        <h3 id="mxTitle"><i class="fa-solid fa-table"></i> Perfis × Telas</h3>
        <div class="mx-toolbar">
          <input id="mxSearch" type="search" placeholder="Filtrar telas…" aria-label="Filtrar telas">
          <span class="hint">Somente visualização</span>
        </div>
      </div>
      <div class="matrix-wrap" id="matrixWrap" aria-live="polite"></div>
      <div class="modal-actions">
        <button class="btn" id="mxClose">Fechar</button>
      </div>
    </div>
  </div>

  <!-- Toasts -->
  <div id="toastWrap" class="toast-wrap" aria-live="polite" aria-atomic="true"></div>

<script>
/* ====================== CONFIG & HELPERS ====================== */
const API  = '/OKR_system/auth/usuarios_api.php';
const CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8") ?>';

const $  = (s,r=document)=>r.querySelector(s);
const $$ = (s,r=document)=>Array.from(r.querySelectorAll(s));

function toast(msg){
  const div = document.createElement('div');
  div.className = 'toast';
  div.textContent = msg;
  $('#toastWrap').appendChild(div);
  setTimeout(()=>{ div.style.opacity='0'; div.style.transform='translateY(4px)'; }, 2200);
  setTimeout(()=> div.remove(), 3000);
}
function show(el){ el?.classList.add('show'); el?.setAttribute('aria-hidden','false'); }
function hide(el){ el?.classList.remove('show'); el?.setAttribute('aria-hidden','true'); }

/* ===== Frontend Logger ===== */
const FRONTLOG_URL = window.location.href; // POST para o próprio arquivo PHP
function logClient(message, meta={}){
  try{
    const payload = new FormData();
    payload.append('__frontlog','1');
    payload.append('message', String(message||''));
    payload.append('meta', JSON.stringify(meta));
    if (navigator.sendBeacon){
      return navigator.sendBeacon(FRONTLOG_URL, payload);
    }
    return fetch(FRONTLOG_URL, { method:'POST', body: payload, credentials:'same-origin' });
  }catch(e){ /* silencioso */ }
}
// captura global
window.addEventListener('error', (ev)=> {
  logClient('window.error', { message: ev.message, filename: ev.filename, lineno: ev.lineno, colno: ev.colno });
});
window.addEventListener('unhandledrejection', (ev)=> {
  logClient('unhandledrejection', { reason: (ev.reason?.message || String(ev.reason)), stack: ev.reason?.stack || null });
});

/* ====== fetch flexível ====== */
async function apiFetchFlexible(action, params={}, prefer='GET'){
  const toQS = (o)=> new URLSearchParams(o).toString();

  async function tryGET(){
    const url = API + '?' + toQS({action, ...params});
    const r = await fetch(url, {cache:'no-store'});
    let j = {};
    try { j = await r.json(); } catch(_){}
    return {ok:r.ok, j, url, method:'GET'};
  }
  async function tryPOST(){
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', CSRF);
    for (const [k,v] of Object.entries(params)){
      if (Array.isArray(v)) v.forEach(val=> fd.append(k, val));
      else if (v !== undefined && v !== null) fd.append(k, v);
    }
    const r = await fetch(API, { method:'POST', body:fd, cache:'no-store' });
    let j = {};
    try { j = await r.json(); } catch(_){}
    return {ok:r.ok, j, url:API, method:'POST'};
  }

  const first = prefer === 'POST' ? tryPOST : tryGET;
  const second= prefer === 'POST' ? tryGET : tryPOST;

  let {ok, j, url, method} = await first();
  if (ok && j && j.success !== false) return j;

  const errText = (j && (j.error||j.message||'')).toString();
  const needFallback = /ação inválida|acao invalida|invalid/i.test(errText) || !ok;
  if (needFallback){
    ({ok, j, url, method} = await second());
    if (ok && j && j.success !== false) return j;
  }

  const finalErr = (j && (j.error||j.message)) || 'Falha na requisição';
  logClient('apiFetchFlexible.fail', { action, params, prefer, finalErr, last:{ok,url,method,body:j} });
  throw new Error(finalErr);
}

/* ====================== STATE ====================== */
let IS_MASTER=false, MY_COMPANY=null, MY_ID=null, ME_ROLES=[];
let OPTIONS = { companies:[], roles:[], capabilities:[], niveis_cargo:[], departamentosByCompany:new Map() };
let ROLE_ID_TO_KEY = new Map();
let listAbort = null;

/* ===== CACHES id→nome (Departamento & Nível/Cargo) ===== */
const DEP_NAME = new Map();     // chave: `${companyId}|${depId}` -> nome do departamento
const NIVEL_NAME = new Map();   // chave: `${nivelId}` -> nome do nível/cargo
function putDepName(cid, id, name){
  if (cid != null && id != null && name) DEP_NAME.set(String(cid)+'|'+String(id), String(name));
}
function getDepNameFromCache(cid, id){
  if (cid == null || id == null) return undefined;
  return DEP_NAME.get(String(cid)+'|'+String(id));
}
function putNivelName(id, name){
  if (id != null && name) NIVEL_NAME.set(String(id), String(name));
}
function getNivelName(id){
  if (id == null) return undefined;
  return NIVEL_NAME.get(String(id));
}

/* ===== Prioridade de papéis ===== */
const ROLE_PRIORITY = {
  'admin_master': 600,
  'gestor_master': 500,
  'gestor_user': 400,
  'user_admin': 300,
  'user_colab': 200,
  'user_guest': 100
};
function highestRoleKeyFromKeys(keys){
  const arr = (keys||[]).map(k=>String(k));
  if (!arr.length) return null;
  return arr.sort((a,b)=>{
    const pa = ROLE_PRIORITY[a] ?? 0;
    const pb = ROLE_PRIORITY[b] ?? 0;
    if (pa!==pb) return pb-pa;
    return a.localeCompare(b);
  })[0];
}

/* ===== Rotulagem amigável ===== */
const LABELS = {
  resources: {
    aprovacao:'Aprovação', auditoria:'Auditoria', custo:'Custos', iniciativa:'Iniciativas',
    kr:'Resultados-chave', milestone:'Marcos', objetivo:'Objetivos', relatorio:'Relatórios', usuario:'Usuários'
  },
  scopes: { OWN:'Meu', TEAM:'Time', UNIT:'Unidade', ORG:'Org' },
  actions: { R:'Leitura', W:'Edição' }
};
const CHIP_CLASS = {
  aprovacao:'chip-aprovacao', auditoria:'chip-auditoria', custo:'chip-custo', iniciativa:'chip-iniciativa',
  kr:'chip-kr', milestone:'chip-milestone', objetivo:'chip-objetivo', relatorio:'chip-relatorio', usuario:'chip-usuario'
};
const SCOPE_ORDER = ['OWN','TEAM','UNIT','ORG'];

/* Índices de capacidades */
let CAP_BY_KEY = new Map();
let CAPS_INDEX = { R:{}, W:{} };
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

/* ====================== PERMISSIONS MODAL ====================== */
let PERM_USER_ID = null;
let PERM_OVERRIDES = new Map(); // capability_id -> 'ALLOW'|'DENY'
let PERM_STATE = { R:new Set(), W:new Set() };

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

function renderSummaryGrouped(){
  const build = (act, mountSel) => {
    const container = $(mountSel);
    const g = groupFromSet(PERM_STATE[act]);
    const resources = Object.keys(g).sort((a,b)=> niceRes(a).localeCompare(niceRes(b),'pt-BR'));
    let html = '';
    resources.forEach(resource=>{
      const count = g[resource].size;
      const cls = CHIP_CLASS[resource] || '';
      html += `<span class="group-chip ${cls}" title="${LABELS.actions[act]} — ${niceRes(resource)}">
                 <span class="label">${niceRes(resource)}</span>
                 <span class="count">${count}</span>
               </span>`;
    });
    container.innerHTML = html || '—';
  };
  build('R', '#sumR'); build('W', '#sumW');
}

document.addEventListener('keydown', (ev)=>{
  if (ev.key === 'Escape'){
    hide($('#permModal')); hide($('#modalDel')); hide($('#userFormModal')); hide($('#matrixModal'));
  }
});

function switchTab(tab){
  $$('.tab').forEach(b=> b.classList.toggle('active', b.dataset.tab===tab));
  $$('.modal-body[role="tabpanel"]').forEach(b=>{
    const showp = b.id === 'tab-'+tab;
    b.classList.toggle('show', showp);
    b.setAttribute('aria-hidden', showp ? 'false':'true');
  });
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
function renderRoles(container, selectedIds){
  container.innerHTML = '';
  const selectedId = Array.isArray(selectedIds) && selectedIds.length ? String(selectedIds[0]) : String(selectedIds||'');
  const name = 'roles_single';
  OPTIONS.roles.forEach(r=>{
    const rid  = String(r.role_id);
    const key  = r.role_key;
    const text = r.role_name || key;
    const desc = r.role_desc || '';
    const isAdminMaster = key === 'admin_master';
    if (isAdminMaster && !IS_MASTER) return;
    const id = 'role_radio_'+rid;
    container.insertAdjacentHTML('beforeend',
      `<label class="cap-item" for="${id}" style="border:1px solid #1f2635;border-radius:10px;padding:8px;">
         <input type="radio" id="${id}" name="${name}" value="${rid}" ${selectedId===rid?'checked':''}>
         <span><b>${text}</b><br><small class="hint">${desc}</small></span>
       </label>`);
  });
}
async function ensureCapabilities(){
  if (OPTIONS.capabilities?.length) return;
  const r = await fetch(API+'?action=capabilities',{cache:'no-store'});
  const j = await r.json();
  OPTIONS.capabilities = j.capabilities || j.data?.capabilities || [];
}
async function openPerm(id_user, name){
  try{
    PERM_USER_ID = id_user;
    $('#permUserName').textContent = name;
    show($('#permModal'));
    switchTab('resumo');

    await ensureCapabilities();
    indexCapabilities();

    const r = await fetch(API+`?action=get_permissions&id=${id_user}`, {cache:'no-store'});
    const j = await r.json();

    resetPermState();
    const sum = j.summary || j.data?.summary || {};
    parseCapList(sum.consulta_R || sum.consulta || '').forEach(c => PERM_STATE.R.add(c.key));
    parseCapList(sum.edicao_W   || sum.edicao   || '').forEach(c => PERM_STATE.W.add(c.key));
    (j.overrides || j.data?.overrides || []).forEach(o => PERM_OVERRIDES.set(String(o.capability_id), o.effect));

    renderSummaryGrouped();
    renderRoles($('#rolesBoxModal'), j.roles || j.data?.roles || []);
    renderOverrides($('#capsBoxModal'), j.overrides || j.data?.overrides || []);
  }catch(e){
    logClient('openPerm.fail', { id_user, error: String(e?.message||e) });
    alert('Falha ao abrir permissões.');
  }
}
function collectRolesFrom(){
  const sel = $('#rolesBoxModal input[type="radio"]:checked');
  return sel ? [sel.value] : [];
}
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
  if (!r.ok || !j.success) {
    logClient('savePerm.fail', { j });
    alert(j.error || 'Falha ao salvar permissões'); return;
  }
  hide($('#permModal'));
  await loadList();
  toast('Permissões salvas com sucesso.');
}

/* ====================== LISTA (cards com Departamento e Função) ====================== */
function roleChip(roleKey){
  const safe = String(roleKey||'').replace(/'/g,"&#39;").replace(/"/g,'&quot;');
  return `<span class="role role-level" title="Ver matriz de acessos de ${safe}" onclick="openRoleMatrixByName('${safe}')">${safe||'sem papel'}</span>`;
}

/* Helpers para extrair nomes flexíveis */
function _extractNameFromUnknown(v){
  if (v == null) return '';
  if (typeof v === 'string') return v;
  if (typeof v === 'object'){
    return (
      v.nome ?? v.name ?? v.label ?? v.text ?? v.titulo ?? v.title ??
      v.department_name ?? v.departamento_nome ??
      v.nivel_nome ?? v.funcao_nome ?? v.cargo_nome ?? ''
    );
  }
  return String(v||'');
}

/* Getters dos chips com fallback para caches id→nome */
function getDepName(u){
  // 1) nome direto
  const direct = _extractNameFromUnknown(
    u.departamento_nome ?? u.department_name ?? u.departamento ?? u.dep ?? u.dep_nome
  );
  if (direct) return direct;
  // 2) cache por empresa+departamento
  const cid = u.id_company ?? u.company_id ?? u.org_id;
  const did = u.id_departamento ?? u.departamento_id ?? u.department_id ?? u.dep_id ?? u.id_dep;
  const cached = getDepNameFromCache(cid, did);
  return cached || '—';
}
function getFuncName(u){
  // 1) nome direto
  const direct = _extractNameFromUnknown(
    u.funcao_nome ?? u.nivel_cargo_nome ?? u.cargo_nome ?? u.funcao ?? u.nivel ?? u.role_funcao
  );
  if (direct) return direct;
  // 2) cache por id do nível/cargo
  const nid = u.id_nivel_cargo ?? u.id_funcao ?? u.funcao_id ?? u.cargo_id ?? u.nivel_id;
  const cached = getNivelName(nid);
  return cached || '—';
}

/* === NORMALIZAÇÃO DE USUÁRIO (corrige campos não carregando) === */
function normalizeUser(raw){
  if (!raw || typeof raw !== 'object') return {};
  const u = {...raw};

  // IDs
  u.id_user = u.id_user ?? u.user_id ?? u.id ?? u.idusuario ?? u.userid ?? null;

  // Empresa
  u.id_company   = u.id_company ?? u.company_id ?? u.empresa_id ?? u.org_id ?? null;
  u.company_name = u.company_name ?? u.empresa_nome ?? u.company ?? u.organizacao_nome ?? u.org_name ?? null;

  // Nome / Sobrenome
  u.primeiro_nome = u.primeiro_nome ?? u.nome ?? u.first_name ?? u.nome_primeiro ?? '';
  u.ultimo_nome   = u.ultimo_nome   ?? u.sobrenome ?? u.last_name ?? u.nome_ultimo ?? '';

  // Contatos
  u.email_corporativo = u.email_corporativo ?? u.email ?? u.corporate_email ?? '';
  u.telefone          = u.telefone ?? u.phone ?? u.celular ?? u.fone ?? '';

  // Departamento / Função
  u.id_departamento  = u.id_departamento ?? u.departamento_id ?? u.department_id ?? u.dep_id ?? u.id_dep ?? null;
  u.departamento_nome= u.departamento_nome ?? u.department_name ?? u.dep_nome ?? _extractNameFromUnknown(u.departamento) ?? null;

  u.id_nivel_cargo   = u.id_nivel_cargo ?? u.id_funcao ?? u.funcao_id ?? u.cargo_id ?? u.nivel_id ?? null;
  u.funcao_nome      = u.funcao_nome ?? u.nivel_cargo_nome ?? u.cargo_nome ?? _extractNameFromUnknown(u.funcao || u.cargo || u.nivel) ?? u.funcao ?? null;

  // Papéis
  let rolesKeys = [];
  if (Array.isArray(u.roles) && u.roles.length) {
    const tmp = u.roles.map(v => String(v));
    rolesKeys = tmp.every(v => ROLE_ID_TO_KEY.has(v)) ? tmp.map(id => ROLE_ID_TO_KEY.get(id) || id) : tmp;
  } else if (Array.isArray(u.role_ids) && u.role_ids.length) {
    rolesKeys = u.role_ids.map(v => ROLE_ID_TO_KEY.get(String(v)) || String(v));
  } else if (u.role_key) {
    rolesKeys = [String(u.role_key)];
  } else if (u.role_id) {
    rolesKeys = [ROLE_ID_TO_KEY.get(String(u.role_id)) || String(u.role_id)];
  }
  u.roles = rolesKeys.filter(Boolean);

  return u;
}

function userCard(_u){
  const u = normalizeUser(_u);

  const safeId = (u.id_user ?? '').toString();
  const name = `${u.primeiro_nome||''} ${u.ultimo_nome||''}`.trim() || (safeId ? ('#'+safeId) : '(sem id)');
  // preferir o caminho enviado pelo backend (igual ao header)
  const primaryAvatar = (u.avatar && String(u.avatar).trim())
    ? u.avatar
    : `/OKR_system/assets/img/avatars/${safeId||'0'}.png`;

  const avatar = `
    <img class="avatar" src="${primaryAvatar}" loading="lazy"
      alt="Avatar de ${name}"
      onerror="
        this.onerror=null;
        const id='${safeId||'0'}';
        // cascata compatível com a lógica antiga, mas só se o primary falhar
        if (!this.dataset.fb) { this.dataset.fb='png'; this.src='/OKR_system/assets/img/avatars/'+id+'.png'; }
        else if (this.dataset.fb==='png') { this.dataset.fb='jpg'; this.src='/OKR_system/assets/img/avatars/'+id+'.jpg'; }
        else if (this.dataset.fb==='jpg') { this.dataset.fb='jpeg'; this.src='/OKR_system/assets/img/avatars/'+id+'.jpeg'; }
      ">
  `;

  const mainRoleKey = highestRoleKeyFromKeys(u.roles||[]);
  const roleHtml = roleChip(mainRoleKey);

  const dep = getDepName(u);
  const func = getFuncName(u);

  const rolesLower = (ME_ROLES||[]).map(r => String(r).toLowerCase());
  const iAmMasterOrAdmin = !!IS_MASTER || rolesLower.includes('admin_master') || rolesLower.includes('user_admin');
  const canDeleteThis = iAmMasterOrAdmin && safeId && String(safeId) !== String(MY_ID ?? '');

  return `
    <article class="card">
      <div class="av">${avatar}</div>
      <div class="info">
        <div class="name">${name}</div>
        <div class="meta">
          <span class="badge"><i class="fa-regular fa-envelope"></i> ${u.email_corporativo||'—'}</span>
          <span class="badge"><i class="fa-regular fa-building"></i> ${u.company_name||'—'}</span>
          ${u.telefone ? `<span class="badge"><i class="fa-brands fa-whatsapp"></i> ${u.telefone}</span>` : ''}
        </div>
        <div class="meta">
          <span class="badge" title="Departamento"><i class="fa-solid fa-sitemap"></i> ${dep}</span>
          <span class="badge" title="Função (cargo)"><i class="fa-solid fa-id-badge"></i> ${func}</span>
          ${roleHtml}
        </div>
      </div>
      <div class="right">
        <button class="btn" title="Permissões" onclick="openPerm(${safeId||0}, '${name.replace(/'/g,"&#39;")}')"><i class="fa-solid fa-shield-halved"></i></button>
        <button class="btn" title="Editar cadastro" onclick="openUserForm(${safeId||0})"><i class="fa-regular fa-pen-to-square"></i></button>
        ${canDeleteThis ? `
          <button class="btn btn-danger" title="Excluir usuário"
            data-id="${safeId}"
            data-name="${name.replace(/"/g,'&quot;')}"
            onclick="askDelete(this)">
            <i class="fa-regular fa-trash-can"></i>
          </button>` : ''}
      </div>
    </article>
  `;
}

/* ====================== OPTIONS & LIST LOADERS ====================== */
function buildRoleMaps(){
  ROLE_ID_TO_KEY = new Map();
  (OPTIONS.roles||[]).forEach(r=>{
    const rid = String(r.role_id);
    const rkey = r.role_key || r.role_name || rid;
    ROLE_ID_TO_KEY.set(rid, rkey);
  });
}

async function loadOptions(){
  const r = await fetch(API+'?action=options',{cache:'no-store'});
  const j = await r.json();

  IS_MASTER  = !!(j.is_master ?? j.data?.is_master);
  MY_COMPANY = (j.my_company ?? j.data?.my_company) ?? null;
  MY_ID      = (j.my_id ?? j.me_id ?? j.data?.my_id ?? j.data?.me_id) ?? null;
  ME_ROLES   = j.my_roles || j.me_roles || j.data?.my_roles || j.data?.me_roles || [];

  OPTIONS.roles        = j.roles || j.data?.roles || [];
  OPTIONS.capabilities = j.capabilities || j.data?.capabilities || [];
  OPTIONS.companies    = j.companies || j.data?.companies || [];
  OPTIONS.niveis_cargo = j.niveis_cargo || j.data?.niveis_cargo || [];

  buildRoleMaps();

  const sel = $('#fCompany');
  sel.innerHTML = '';
  if (IS_MASTER) sel.add(new Option('Todas','all'));
  (OPTIONS.companies||[]).forEach(c=> sel.add(new Option(`${c.nome ?? c.name ?? ('#'+c.id_company)} (#${c.id_company})`, c.id_company)));
  if (IS_MASTER) sel.value = 'all'; else if (MY_COMPANY) sel.value = String(MY_COMPANY);
  $('#pillOrg').innerHTML = `<i class="fa-regular fa-building"></i> Organização: ${IS_MASTER ? 'Todas' : ('#'+(MY_COMPANY ?? '—'))}`;

  const rsel = $('#fRole');
  rsel.innerHTML = '<option value="all">Todos</option>';
  (OPTIONS.roles||[]).forEach(r=>{
    const txt = r.role_name || r.role_key;
    const val = r.role_id;
    rsel.add(new Option(txt, val));
  });

  // popular o select do formulário (papel único)
  const roleSelect = $('#ufRoleSelect');
  if (roleSelect){
    roleSelect.innerHTML = '<option value="">Selecione…</option>';
    (OPTIONS.roles||[]).forEach(r=>{
      const isAdminMaster = r.role_key === 'admin_master';
      if (isAdminMaster && !IS_MASTER) return;
      roleSelect.add(new Option(r.role_name || r.role_key, r.role_id));
    });
  }
}

/* ====== Departamentos / Níveis (resiliente + log) ====== */
async function loadDepartamentos(companyId){
  const cid = String(companyId ?? '');
  if (!cid) return [];
  if (OPTIONS.departamentosByCompany.has(cid)) {
    // garantir cache preenchido a partir do que já temos
    (OPTIONS.departamentosByCompany.get(cid) || []).forEach(d=>{
      const id = d.id ?? d.id_departamento ?? d.departamento_id ?? d.department_id ?? d.id_dep ?? d.dep_id ?? d.value;
      const name = d.label ?? d.nome ?? d.name ?? d.text ?? d.department_name ?? d.departamento_nome;
      if (id != null && name) putDepName(cid, id, name);
    });
    return OPTIONS.departamentosByCompany.get(cid);
  }

  const tryList = [
    { action: 'departamentos', params: { company: cid, id_company: cid } },
    { action: 'departamentos_by_company', params: { company: cid, company_id: cid, id_company: cid } },
    { action: 'departamentos', params: { company_id: cid } },
  ];

  for (const t of tryList){
    try{
      const j = await apiFetchFlexible(t.action, t.params, 'GET');
      const arr = j.items || j.departamentos || j.options || j.data?.items || j.data?.departamentos || j.data?.options || [];
      if (Array.isArray(arr) && arr.length){
        OPTIONS.departamentosByCompany.set(cid, arr);
        // preencher cache de departamentos para esta empresa
        arr.forEach(d=>{
          const id = d.id ?? d.id_departamento ?? d.departamento_id ?? d.department_id ?? d.id_dep ?? d.dep_id ?? d.value;
          const name = d.label ?? d.nome ?? d.name ?? d.text ?? d.department_name ?? d.departamento_nome;
          if (id != null && name) putDepName(cid, id, name);
        });
        return arr;
      }
    }catch(e){
      logClient('loadDepartamentos.try.fail', { tryAction: t.action, params: t.params, error: String(e?.message||e) });
    }
  }

  // fallback: tenta vir de options
  const fromOptions = (Array.isArray(OPTIONS.departamentos) ? OPTIONS.departamentos : [])
    .filter(d => String(d.id_company ?? d.company_id ?? d.company) === cid);
  if (fromOptions.length){
    OPTIONS.departamentosByCompany.set(cid, fromOptions);
    fromOptions.forEach(d=>{
      const id = d.id ?? d.id_departamento ?? d.departamento_id ?? d.department_id ?? d.id_dep ?? d.dep_id ?? d.value;
      const name = d.label ?? d.nome ?? d.name ?? d.text ?? d.department_name ?? d.departamento_nome;
      if (id != null && name) putDepName(cid, id, name);
    });
    return fromOptions;
  }

  // nada encontrado
  logClient('loadDepartamentos.empty', { cid });
  OPTIONS.departamentosByCompany.set(cid, []);
  return [];
}
async function loadNiveisCargo(){
  if (OPTIONS.niveis_cargo && OPTIONS.niveis_cargo.length){
    // garantir o cache populado
    (OPTIONS.niveis_cargo||[]).forEach(n=>{
      const id = n.id ?? n.id_nivel ?? n.nivel_id ?? n.id_funcao ?? n.funcao_id ?? n.id_cargo ?? n.cargo_id ?? n.value;
      const name = n.label ?? n.nome ?? n.name ?? n.text ?? n.nivel_nome ?? n.funcao_nome ?? n.cargo_nome;
      if (id != null && name) putNivelName(id, name);
    });
    return OPTIONS.niveis_cargo;
  }

  const tryList = [
    { action:'niveis_cargo', params:{} },
    { action:'cargos_niveis', params:{} },
    { action:'niveis', params:{} },
  ];

  for (const t of tryList){
    try{
      const j = await apiFetchFlexible(t.action, t.params, 'GET');
      const arr = j.items || j.niveis || j.options || j.data?.items || j.data?.niveis || j.data?.options || [];
      if (Array.isArray(arr) && arr.length){
        OPTIONS.niveis_cargo = arr;
        // preencher cache de níveis/cargos
        arr.forEach(n=>{
          const id = n.id ?? n.id_nivel ?? n.nivel_id ?? n.id_funcao ?? n.funcao_id ?? n.id_cargo ?? n.cargo_id ?? n.value;
          const name = n.label ?? n.nome ?? n.name ?? n.text ?? n.nivel_nome ?? n.funcao_nome ?? n.cargo_nome;
          if (id != null && name) putNivelName(id, name);
        });
        return OPTIONS.niveis_cargo;
      }
    }catch(e){
      logClient('loadNiveisCargo.try.fail', { tryAction: t.action, error: String(e?.message||e) });
    }
  }

  logClient('loadNiveisCargo.empty', {});
  OPTIONS.niveis_cargo = [];
  return OPTIONS.niveis_cargo;
}

/* ===== helper p/ mapear id->nome (quando útil localmente) ===== */
function normalizeListToMap(list){
  const m = new Map();
  (list||[]).forEach(it=>{
    const id =
      it?.id ?? it?.value ??
      it?.id_departamento ?? it?.departamento_id ?? it?.department_id ?? it?.id_dep ?? it?.dep_id ??
      it?.id_nivel ?? it?.nivel_id ??
      it?.id_funcao ?? it?.funcao_id ??
      it?.id_cargo ?? it?.cargo_id ??
      it?.key;
    if (id == null || id === '') return;

    const name =
      it?.nome ?? it?.name ?? it?.label ?? it?.text ?? it?.descricao ?? it?.description ??
      it?.titulo ?? it?.title ??
      it?.department_name ?? it?.departamento_nome ??
      it?.nivel_nome ?? it?.funcao_nome ?? it?.cargo_nome ??
      (`#${id}`);

    m.set(String(id), String(name));
  });
  return m;
}

async function loadList(){
  const list = $('#list');
  list.innerHTML = `<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>`;

  if (listAbort) listAbort.abort();
  listAbort = new AbortController();

  const params = new URLSearchParams();
  const q  = ($('#fBusca').value||'').trim();
  const co = $('#fCompany').value || (IS_MASTER ? 'all' : (MY_COMPANY||''));
  const ro = $('#fRole').value || 'all';
  params.set('action','list');
  if (q)  params.set('q', q);
  if (co) params.set('company', co);
  if (ro) params.set('role', ro);
  params.set('per_page','100');

  try{
    const r = await fetch(API+'?'+params.toString(), {cache:'no-store', signal:listAbort.signal});
    const j = await r.json();

    // === payloads compatíveis
    let arr = j.users || j.usuarios || j.items || j.data?.users || j.data?.usuarios || j.data?.items || [];
    if (!Array.isArray(arr)) {
      logClient('loadList.shape.warn', { gotKeys:Object.keys(j||{}), type: typeof arr });
      arr = [];
    }

    $('#pillUsers').innerHTML = `<i class="fa-regular fa-address-card"></i> Usuários: ${j.total ?? j.data?.total ?? arr.length}`;

    // ======== ENRIQUECIMENTO (Dep/Fun) + preenchimento de caches ========
    try{
      const nvs = await loadNiveisCargo();              // preenche NIVEL_NAME
      const nivelMap = normalizeListToMap(nvs);

      const companyIds = [...new Set(arr.map(u => (u.id_company ?? u.company_id ?? u.org_id)).filter(v => v !== null && v !== undefined))];
      const depMaps = new Map();
      await Promise.all(companyIds.map(async cid=>{
        const deps = await loadDepartamentos(cid);      // preenche DEP_NAME por empresa
        depMaps.set(String(cid), normalizeListToMap(deps));
      }));

      arr.forEach(u=>{
        const id_company = u.id_company ?? u.company_id ?? u.org_id ?? null;
        const id_dep     = u.id_departamento ?? u.departamento_id ?? u.department_id ?? u.dep_id ?? u.id_dep ?? null;
        const id_nivel   = u.id_nivel_cargo ?? u.id_funcao ?? u.funcao_id ?? u.cargo_id ?? u.nivel_id ?? null;

        // usa objetos aninhados se existirem
        if ((!u.departamento_nome || u.departamento_nome === '') && typeof u.departamento === 'object'){
          u.departamento_nome = _extractNameFromUnknown(u.departamento);
        }
        if ((!u.funcao_nome || u.funcao_nome === '') && (typeof u.funcao === 'object' || typeof u.cargo === 'object' || typeof u.nivel === 'object')){
          u.funcao_nome = _extractNameFromUnknown(u.funcao || u.cargo || u.nivel);
        }

        // tenta via mapas locais
        if ((!u.departamento_nome || u.departamento_nome === '') && id_dep != null){
          const m = depMaps.get(String(id_company));
          if (m) u.departamento_nome = m.get(String(id_dep)) || u.departamento_nome || null;
        }
        if ((!u.funcao_nome || u.funcao_nome === '') && id_nivel != null){
          u.funcao_nome = nivelMap.get(String(id_nivel)) || u.funcao_nome || null;
        }

        // se ainda vazio, os getters usarão os CACHES globais já preenchidos pelas funções de carga
        if ((u.departamento_nome == null || u.departamento_nome === '') && id_dep != null){
          logClient('dep.name.missing', { id_company, id_dep, user: u.id_user ?? u.user_id ?? u.id ?? null });
        }
        if ((u.funcao_nome == null || u.funcao_nome === '') && id_nivel != null){
          logClient('func.name.missing', { id_company, id_nivel, user: u.id_user ?? u.user_id ?? u.id ?? null });
        }
      });
    }catch(enrichErr){
      logClient('loadList.enrich.fail', { error: String(enrichErr?.message||enrichErr) });
    }
    // ==========================================

    if (!arr.length){
      list.innerHTML = `<div class="empty"><i class="fa-regular fa-folder-open"></i> Nenhum usuário encontrado com os filtros atuais.</div>`;
      return;
    }
    const html = arr.map(normalizeUser).map(userCard).join('');
    list.innerHTML = html;
  } catch(e){
    if (e.name === 'AbortError') return;
    logClient('loadList.fail', { error: String(e?.message||e) });
    list.innerHTML = `<div class="empty"><i class="fa-solid fa-triangle-exclamation"></i> Falha ao carregar a lista.</div>`;
  }
}

/* ====================== DELETE ====================== */
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
  }catch(e){
    logClient('doDelete.fail', { id: pendingDeleteId, error: String(e?.message||e) });
    alert(e.message||'Erro de rede');
  }
}

/* ====================== USER FORM (NEW/EDIT) ====================== */
let UF_ID = null; // null => novo
async function populateDepsAndNiveis(companyId, selectedDep='', selectedNivel=''){
  // Departamentos
  const depSel = $('#ufDepartamento');
  depSel.innerHTML = `<option value="">Carregando…</option>`;
  let deps = [];
  try { deps = companyId ? await loadDepartamentos(companyId) : []; } catch(e) { deps = []; logClient('populateDeps.fail', {companyId, error:String(e?.message||e)}); }
  depSel.innerHTML = '<option value="">Selecione…</option>';
  deps.forEach(d => {
    const id = d.id ?? d.id_departamento ?? d.departamento_id ?? d.department_id ?? d.id_dep ?? d.dep_id ?? d.value;
    const label = d.label ?? d.nome ?? d.name ?? d.text ?? d.department_name ?? d.departamento_nome ?? `#${id}`;
    const opt = new Option(label, id);
    if (String(selectedDep) === String(id)) opt.selected = true;
    depSel.add(opt);
    // garantir cache
    if (companyId != null) putDepName(companyId, id, label);
  });

  // Funções (níveis de cargo)
  const funSel = $('#ufFuncao');
  funSel.innerHTML = `<option value="">Carregando…</option>`;
  let nvs = [];
  try { nvs = await loadNiveisCargo(); } catch(e) { nvs = []; logClient('populateNiveis.fail', { error:String(e?.message||e) }); }
  funSel.innerHTML = '<option value="">Selecione…</option>';
  nvs.sort((a,b)=> (a.ordem??0) - (b.ordem??0)).forEach(n => {
    const id = n.id ?? n.id_nivel ?? n.nivel_id ?? n.id_funcao ?? n.funcao_id ?? n.id_cargo ?? n.cargo_id ?? n.value;
    const label = n.label ?? n.nome ?? n.name ?? n.text ?? n.nivel_nome ?? n.funcao_nome ?? n.cargo_nome ?? `#${id}`;
    const opt = new Option(label, id);
    if (String(selectedNivel) === String(id)) opt.selected = true;
    funSel.add(opt);
    // garantir cache
    putNivelName(id, label);
  });
}
function clearUF(){
  UF_ID = null;
  $('#ufTitle').textContent = 'Novo usuário';
  $('#ufPrimeiro').value=''; $('#ufUltimo').value='';
  $('#ufEmail').value=''; $('#ufFone').value='';
  $('#errPrimeiro').hidden = true; $('#errEmail').hidden = true;
  $('#errDepartamento').hidden = true; $('#errFuncao').hidden = true;

  const sel = $('#ufCompany'); sel.innerHTML='';
  (OPTIONS.companies||[]).forEach(c=> sel.add(new Option(`${c.nome ?? c.name ?? ('#'+c.id_company)} (#${c.id_company})`, c.id_company)));
  if (!IS_MASTER && MY_COMPANY) {
    sel.value = String(MY_COMPANY);
    sel.disabled = true;
  } else {
    sel.disabled = false;
    if (!sel.value && OPTIONS.companies.length) {
      sel.value = String(OPTIONS.companies[0].id_company); // fallback
    }
  }

  $('#ufRoleSelect').value = '';

  $('#ufDepartamento').innerHTML = '<option value="">Selecione…</option>';
  $('#ufFuncao').innerHTML = '<option value="">Selecione…</option>';
}
async function openUserForm(id=null){
  // UI first
  show($('#userFormModal'));
  $('#ufSave').disabled = true;

  try{
    try { await loadOptions(); } catch(e){ logClient('openUserForm.loadOptions.fail', { error:String(e?.message||e) }); }
    clearUF();

    const cid = $('#ufCompany').value || MY_COMPANY || (OPTIONS.companies[0]?.id_company ?? '');
    try { await populateDepsAndNiveis(cid); }
    catch(e){ logClient('openUserForm.populate.fail', { cid, error: String(e?.message||e) }); toast('Não foi possível carregar Departamentos/Funções.'); }

    if (id){
      UF_ID = id;
      $('#ufTitle').textContent = 'Editar usuário';
      try{
        const j = await apiFetchFlexible('get_user', { id: String(id) }, 'GET');
        const rawUser = j.user || j.data?.user || j.data || j;
        const u = normalizeUser(rawUser || {});
        // garante avatar também vindo do get_user (top-level)
        if (!u.avatar && j.avatar) u.avatar = j.avatar;
        $('#ufPrimeiro').value = u.primeiro_nome || '';
        $('#ufUltimo').value   = u.ultimo_nome   || '';
        $('#ufEmail').value    = u.email_corporativo || '';
        $('#ufFone').value     = u.telefone || '';

        const userCompany = u.id_company || cid;
        if (IS_MASTER) $('#ufCompany').value = String(userCompany);

        const selDep  = u.id_departamento || '';
        const selFunc = u.id_nivel_cargo  || '';
        try { await populateDepsAndNiveis(userCompany, selDep, selFunc); } catch(e){ logClient('openUserForm.populate2.fail', { userCompany, error:String(e?.message||e) }); }

        const rid = Array.isArray(u.roles) && u.roles.length
          ? (Array.from(ROLE_ID_TO_KEY.entries()).find(([,key]) => key === u.roles[0])?.[0] ?? '')
          : (j.roles && j.roles.length ? String(j.roles[0]) : '');
        if (rid) $('#ufRoleSelect').value = rid;
      }catch(e){
        logClient('openUserForm.get_user.fail', { id, error:String(e?.message||e) });
        toast('Falha ao carregar os dados do usuário.');
      }
    } else {
      $('#ufTitle').textContent = 'Novo usuário';
    }
  } finally {
    $('#ufSave').disabled = false;
  }
}
function validateUF(){
  let ok = true;
  const nome = $('#ufPrimeiro').value.trim();
  const email= $('#ufEmail').value.trim();
  $('#errPrimeiro').hidden = !!nome;
  if (!nome) ok=false;
  const ev = (/^[^\s@]+@[^\s@]+\.[^\s@]+$/).test(email);
  $('#errEmail').hidden = ev;
  if (!ev) ok=false;

  const dep = $('#ufDepartamento').value;
  const fun = $('#ufFuncao').value;
  $('#errDepartamento').hidden = !!dep;
  $('#errFuncao').hidden = !!fun;
  if (!dep || !fun) ok=false;

  return ok;
}
async function saveUser(){
  if (!validateUF()) { toast('Corrija os erros do formulário.'); return; }

  const fd = new FormData();
  fd.append('action','save_user');
  fd.append('csrf_token', CSRF);
  if (UF_ID) fd.append('id_user', String(UF_ID));
  fd.append('primeiro_nome', $('#ufPrimeiro').value.trim());
  fd.append('ultimo_nome',   $('#ufUltimo').value.trim());
  fd.append('email',         $('#ufEmail').value.trim());
  fd.append('telefone',      $('#ufFone').value.trim());
  fd.append('id_company',    $('#ufCompany').value);

  const rid = $('#ufRoleSelect').value;
  if (rid) fd.append('roles[]', rid);

  // departamento e função
  fd.append('id_departamento', $('#ufDepartamento').value);
  fd.append('id_nivel_cargo',  $('#ufFuncao').value);

  $('#ufSave').disabled = true; $('#ufSave').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvando…';
  try{
    const r = await fetch(API, { method:'POST', body: fd });
    const j = await r.json();
    if (!r.ok || !j.success){
      if ((j.error||'').toLowerCase().includes('e-mail')) {
        $('#errEmail').textContent = j.error;
        $('#errEmail').hidden = false;
      }
      logClient('saveUser.fail', { response: j });
      throw new Error(j.error || 'Falha ao salvar');
    }
    hide($('#userFormModal'));
    toast('Usuário salvo com sucesso.');
    await loadList();
  }catch(e){
    logClient('saveUser.catch', { error: String(e?.message||e) });
    alert(e.message||'Erro ao salvar');
  } finally {
    $('#ufSave').disabled = false; $('#ufSave').innerHTML = '<i class="fa-regular fa-floppy-disk"></i> Salvar';
  }
}

/* ====================== MATRIZ Perfis × Telas ====================== */
let MATRIX = null; // {pages, roles, role_caps}
let MX_FILTER = '';
function splitCaps(str){ return (str?String(str):'').split(/[|,]/).map(s=>s.trim()).filter(Boolean); }

async function ensureMatrix(){
  if (MATRIX) return;
  const j = await apiFetchFlexible('roles_matrix', {}, 'GET');
  MATRIX = { pages:j.pages||j.data?.pages||[], roles:j.roles||j.data?.roles||[], role_caps:j.role_caps||j.data?.role_caps||{} };
}
function renderMatrix(highlightRoleId=null){
  if (!MATRIX) return;

  const roles = [...MATRIX.roles];
  if (highlightRoleId){
    roles.sort((a,b)=> String(a.role_id)===String(highlightRoleId)? -1 : (String(b.role_id)===String(highlightRoleId)? 1 : 0));
  }

  const term = (MX_FILTER||'').toLowerCase();
  const pages = MATRIX.pages.filter(p=>{
    if (!term) return true;
    const hay = `${p.titulo||''} ${p.path||''}`.toLowerCase();
    return hay.includes(term);
  });

  const wrap = $('#matrixWrap');
  if (!pages.length){
    wrap.innerHTML = `<div class="empty" style="margin:8px;"><i class="fa-regular fa-folder-open"></i> Nenhuma tela encontrada com o filtro atual.</div>`;
    return;
  }

  let html = `<table class="matrix" role="table" aria-label="Matriz de acessos por perfil">
    <thead><tr>
      <th class="sticky-left" scope="col">Tela</th>
      ${roles.map(r=>`<th class="role-col" scope="col"><div class="role-head"><span class="tag ${String(r.role_id)===String(highlightRoleId)?'hl':''}">${r.role_name||r.role_key}</span></div></th>`).join('')}
    </tr></thead>
    <tbody>`;

  pages.forEach(p=>{
    const needR = splitCaps(p.cap_read);
    const needW = splitCaps(p.cap_write);
    html += `<tr>
      <th scope="row" class="sticky-left">
        ${p.titulo||'(sem título)'}
        ${p.path ? `<span class="page-path">${p.path}</span>`:''}
      </th>
      ${roles.map(r=>{
        const caps = new Set(MATRIX.role_caps?.[String(r.role_id)] || []);
        const hasR = needR.length ? needR.some(c=> caps.has(c)) : false;
        const hasW = needW.length ? needW.some(c=> caps.has(c)) : false;
        const cls = `${hasR?'hasR':''} ${hasW?'hasW':''}`;
        const title = hasR && hasW ? 'Leitura + Edição' : (hasR ? 'Leitura' : (hasW ? 'Edição' : 'Sem acesso'));
        return `<td class="${cls}">
          <div class="cell ${String(r.role_id)===String(highlightRoleId)?'hl':''}" title="${title}">
            ${hasR?'<i class="ico eye fa-regular fa-eye" aria-label="Leitura"></i>':''}
            ${hasW?'<i class="ico pen fa-regular fa-pen-to-square" aria-label="Edição"></i>':''}
            ${(!hasR && !hasW)?'<span class="none">—</span>':''}
          </div>
        </td>`;
      }).join('')}
    </tr>`;
  });

  html += `</tbody></table>`;
  wrap.innerHTML = html;
}
async function openRoleMatrixByName(roleNameOrKey){
  try{
    await ensureMatrix();
    const q = String(roleNameOrKey||'').toLowerCase();
    const role = MATRIX.roles.find(r=> (r.role_name||'').toLowerCase()===q || (r.role_key||'').toLowerCase()===q);
    renderMatrix(role?.role_id || null);
    show($('#matrixModal'));
  }catch(e){
    logClient('openRoleMatrix.fail', { roleNameOrKey, error: String(e?.message||e) });
    alert('Falha ao abrir a matriz');
  }
}

/* ====================== BOOTSTRAP ====================== */
document.addEventListener('DOMContentLoaded', async ()=>{
  // Registra eventos ANTES dos awaits
  $('#btnNewUser')?.addEventListener('click', ()=> openUserForm(null));
  $('#ufCancel')?.addEventListener('click', ()=> hide($('#userFormModal')));
  $('#ufSave')?.addEventListener('click', saveUser);

  // filtros
  $('#fCompany')?.addEventListener('change', loadList);
  $('#fRole')?.addEventListener('change',   loadList);

  let t=null;
  $('#fBusca')?.addEventListener('input', ()=>{
    clearTimeout(t);
    t=setTimeout(loadList, 320);
  });
  $('#btnRefresh')?.addEventListener('click', loadList);
  $('#btnClear')?.addEventListener('click', ()=>{
    if (IS_MASTER) $('#fCompany').value = 'all'; else if (MY_COMPANY) $('#fCompany').value = String(MY_COMPANY);
    $('#fRole').value = 'all';
    $('#fBusca').value = '';
    loadList();
  });

  // excluir
  $('#delCancel')?.addEventListener('click', ()=>{ pendingDeleteId=null; hide($('#modalDel')); });
  $('#delConfirm')?.addEventListener('click', doDelete);

  // modal perms
  $('#permClose')?.addEventListener('click', () => hide($('#permModal')));
  $$('.tab').forEach(b=> b.addEventListener('click', ()=> switchTab(b.dataset.tab)));
  $('#permSave')?.addEventListener('click', savePerm);

  // matriz
  $('#mxClose')?.addEventListener('click', ()=> hide($('#matrixModal')));
  $('#mxSearch')?.addEventListener('input', (e)=>{ MX_FILTER = e.target.value||''; renderMatrix(); });

  // quando trocar a empresa no formulário, recarregar departamentos
  $('#ufCompany')?.addEventListener('change', async (e)=>{
    const cid = e.target.value;
    try { await populateDepsAndNiveis(cid, '', $('#ufFuncao').value||''); }
    catch(err){ logClient('onCompanyChange.populate.fail', { cid, error:String(err?.message||err) }); }
  });

  // Carregamentos iniciais
  try { await loadOptions(); } catch(e){ logClient('bootstrap.loadOptions.fail', { error: String(e?.message||e) }); toast('Não foi possível carregar opções.'); }
  try { await loadList();    } catch(e){ logClient('bootstrap.loadList.fail', { error: String(e?.message||e) }); toast('Não foi possível carregar a lista.'); }
});

// Exposição global
window.openUserForm = openUserForm;
window.askDelete = askDelete;
window.openPerm = openPerm;
window.openRoleMatrixByName = openRoleMatrixByName;
</script>
</body>
</html>
