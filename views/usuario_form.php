<?php
// views/usuario_form.php — Cadastro/Edição + RBAC (Papéis, Overrides) + Avatar (Canvas/IA)
declare(strict_types=1);
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

session_start();
require_once __DIR__.'/../auth/config.php';
require_once __DIR__.'/../auth/functions.php';
require_once __DIR__.'/../auth/acl.php';

// Gate automático pela tabela dom_paginas.requires_cap
if (($_GET['mode'] ?? '') === 'edit') {
  require_cap('W:objetivo@ORG');
}
if (!isset($_SESSION['user_id'])) { header('Location: /OKR_system/views/login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $id? 'Editar usuário' : 'Novo usuário' ?> — OKR System</title>

<link rel="stylesheet" href="/OKR_system/assets/css/base.css">
<link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
<link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

<style>
:root{
  --card:#12161c; --border:#222733; --text:#eaeef6; --muted:#a6adbb; --shadow:0 10px 30px rgba(0,0,0,.20);
  --gold:#f6c343;
}
body{ background:#fff !important; color:#111; }
main.form{ padding:24px; display:grid; gap:16px; }

.head-card{
  background:linear-gradient(180deg, var(--card), #0d1117);
  border:1px solid var(--border); border-radius:16px; padding:16px; color:var(--text); box-shadow:var(--shadow);
  display:flex; align-items:center; justify-content:space-between; gap:12px;
}
.head-actions{ display:flex; gap:8px; flex-wrap:wrap; }

.form-card{
  background:linear-gradient(180deg, var(--card), #0e1319);
  border:1px solid var(--border); border-radius:16px; padding:16px; color:var(--text); box-shadow:var(--shadow);
}

.grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.grid-3{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
@media (max-width:900px){ .grid-2,.grid-3{ grid-template-columns:1fr; } }

label{ display:block; margin-bottom:6px; color:#cbd5e1; font-size:.9rem; }
input[type=text], input[type=email], input[type=tel], select, textarea{
  width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px;
}

.badge{ border:1px solid var(--border); border-radius:999px; padding:4px 8px; font-size:.8rem; color:#cbd5e1; }
.btn{ border:1px solid var(--border); background:#0b1118; color:#e5e7eb; padding:10px 12px; border-radius:12px; font-weight:800; cursor:pointer; }
.btn:hover{ transform:translateY(-1px); transition:.15s; }
.btn-primary{ background:#1f2937; }
.btn-gold{ background:var(--gold); color:#111; border-color:#ad8a00; }

.section-title{ font-size:1.05rem; margin:0 0 12px; letter-spacing:.2px; color:#e5e7eb; }
.flex{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.checkbox-list{ display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px; }
.card-inline{ display:flex; gap:12px; align-items:center; }
.avatar-preview{ width:80px; height:80px; border-radius:50%; object-fit:cover; border:1px solid #2a3342; background:#0b1118; }
.small{ color:#9aa4b2; font-size:.85rem; }
.helper{ color:#9aa4b2; font-size:.85rem; display:block; margin-top:6px; }

.overlay{ position:fixed; inset:0; display:none; place-items:center; background:rgba(0,0,0,.55); z-index:3000; }
.overlay.show{ display:grid; }
.modal{ width:min(980px,95vw); background:#0b1020; color:#e6e9f2; border-radius:18px; border:1px solid #223047; padding:16px; box-shadow:0 20px 60px rgba(0,0,0,.35); }
.modal h3{ margin:0 0 8px; }

.builder{ display:grid; grid-template-columns:360px 1fr; gap:12px; }
.canvas-wrap{ background:#0c1118; border:1px solid #1f2635; border-radius:12px; padding:12px; display:grid; place-items:center; }
.builder .ctrls{ background:#0c1118; border:1px solid #1f2635; border-radius:12px; padding:12px; }
.builder .ctrls .row{ display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-bottom:8px; }
.builder select, .builder input[type=color], .builder input[type=range]{ width:100%; background:#0b1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; }

/* RBAC overrides */
.grid-cap{ display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:8px; }
.cap-group{ border:1px solid #1f2635; border-radius:12px; padding:10px; background:#0c1118; }
.cap-title{ font-weight:900; color:#e5e7eb; margin-bottom:8px; display:flex; align-items:center; gap:6px; }
.cap-item{ display:flex; align-items:center; gap:8px; margin:4px 0; font-size:.9rem; }
.cap-item select{ background:#0b1118; border:1px solid #223047; color:#e6e9f2; border-radius:8px; padding:6px 8px; }

.access{ display:grid; gap:4px; margin-top:6px; }
.access-row{ font-size:.88rem; color:#cbd5e1; }
.access-row strong{ color:#fff; }
</style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php'; ?>
<div class="content">
<?php include __DIR__.'/partials/header.php'; ?>

<main class="form">
  <section class="head-card">
    <h1 style="margin:0;font-size:1.2rem;display:flex;gap:8px;align-items:center;">
      <i class="fa-solid fa-user-gear"></i> <?= $id? 'Editar usuário' : 'Novo usuário' ?>
    </h1>
    <div class="head-actions">
      <a class="btn btn-gold" href="/OKR_system/views/usuarios.php"><i class="fa-solid fa-users"></i> Gerenciar Usuários</a>
    </div>
  </section>

  <section class="form-card">
    <h2 class="section-title"><i class="fa-regular fa-id-card"></i> Dados do usuário</h2>

    <form id="userForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="id_user" id="id_user" value="<?= (int)$id ?>">

      <div class="grid-3">
        <div>
          <label>Primeiro nome *</label>
          <input type="text" name="primeiro_nome" id="primeiro_nome" required>
        </div>
        <div>
          <label>Último nome</label>
          <input type="text" name="ultimo_nome" id="ultimo_nome">
        </div>
        <div>
          <label>E-mail corporativo *</label>
          <input type="email" name="email_corporativo" id="email_corporativo" required>
        </div>
      </div>

      <div class="grid-3" style="margin-top:12px;">
        <div>
          <label>Telefone</label>
          <input type="tel" name="telefone" id="telefone" placeholder="+55...">
        </div>

        <div>
          <label for="id_company">Organização</label>
          <select id="id_company" name="id_company"></select>
          <small id="orgHint" class="helper"></small>
        </div>

        <div class="card-inline">
          <img id="avatarPrev" class="avatar-preview" alt="avatar" src="/OKR_system/assets/img/user-avatar.jpeg">
          <div class="flex">
            <label for="avatarFile" class="btn">Upload avatar</label>
            <input id="avatarFile" type="file" accept="image/*" style="display:none">
            <button type="button" class="btn" id="btnBuildAvatar"><i class="fa-solid fa-wand-magic-sparkles"></i> Canvas</button>
            <button type="button" class="btn btn-gold" id="btnAIAvatar"><i class="fa-solid fa-robot"></i> Avatar IA</button>
          </div>
        </div>
      </div>

      <hr style="border-color:#1f2635; margin:16px 0;">

      <h2 class="section-title"><i class="fa-solid fa-shield-halved"></i> Permissões (RBAC)</h2>
      <div class="grid-2">
        <div>
          <label>Papéis (roles)</label>
          <div id="rolesBox" class="checkbox-list"></div>
          <div class="small">Papéis concedem acessos amplos (ex.: <em>admin_master</em>).</div>
        </div>
        <div>
          <label>Overrides (opcionais)</label>
          <div id="capsBox" class="grid-cap"></div>
          <div class="small">Use **Overrides** para exceções por capacidade (ALLOW/DENY). “Inherit” remove a exceção e mantém o herdado do papel.</div>
        </div>
      </div>

      <div style="margin-top:10px;">
        <div class="section-title"><i class="fa-regular fa-eye"></i> Resumo efetivo</div>
        <div class="access">
          <div class="access-row"><strong>Consulta (R):</strong> <span id="sumR">—</span></div>
          <div class="access-row"><strong>Edição (W):</strong> <span id="sumW">—</span></div>
        </div>
        <div class="small">O resumo reflete o que está no banco. Após salvar, recarregue para ver mudanças.</div>
      </div>

      <div style="margin-top:14px; display:flex; gap:8px; justify-content:flex-end;">
        <a href="/OKR_system/views/usuarios.php" class="btn">Cancelar</a>
        <button class="btn btn-primary" type="submit"><i class="fa-regular fa-floppy-disk"></i> Salvar</button>
      </div>
    </form>
  </section>
</main>
</div>

<!-- Modal Avatar (Canvas) -->
<div id="avatarModal" class="overlay" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true">
    <h3><i class="fa-regular fa-face-smile"></i> Gerador de Avatar (Canvas)</h3>
    <div class="builder">
      <div class="canvas-wrap"><canvas id="avatarCanvas" width="320" height="320"></canvas></div>
      <div class="ctrls">
        <div class="row"><label>Cor da pele</label><input type="color" id="skinColor" value="#F0C7A8"></div>
        <div class="row"><label>Tipo de cabelo</label><select id="hairType"></select></div>
        <div class="row"><label>Cor do cabelo</label><input type="color" id="hairColor" value="#2E2E2E"></div>
        <div class="row"><label>Olhos</label><select id="eyesType"></select></div>
        <div class="row"><label>Tam. dos olhos</label><input type="range" id="eyesScale" min="0.8" max="1.3" step="0.05" value="1"></div>
        <div class="row"><label>Nariz</label><select id="noseType"></select></div>
        <div class="row"><label>Boca</label><select id="mouthType"></select></div>
        <div class="row"><label>Formato do rosto</label><select id="faceType"></select></div>
        <div class="row"><label>Óculos</label><select id="glassesType"></select></div>
        <div class="row"><label>Chapéu</label><select id="hatType"></select></div>
        <div class="row"><label>Roupa</label><select id="clothesType"></select></div>
        <div class="flex" style="justify-content:flex-end; margin-top:10px;">
          <button class="btn" id="btnCloseAvatar">Fechar</button>
          <button class="btn btn-primary" id="btnSaveAvatar"><i class="fa-regular fa-floppy-disk"></i> Salvar PNG</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Avatar via IA (Realista) -->
<div id="aiModal" class="overlay" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true">
    <h3><i class="fa-solid fa-robot"></i> Avatar via IA (Realista)</h3>

    <div class="ai-body">
      <div class="grid-3">
        <div><label>Gênero *</label>
          <select id="aiGenero"><option value="masculino">Masculino</option><option value="feminino">Feminino</option><option value="neutro">Outro / Neutro</option></select>
        </div>
        <div><label>Cor da pele</label><input type="text" id="aiPele" placeholder="clara / morena / negra / parda"></div>
        <div><label>Formato do rosto</label>
          <select id="aiRosto"><option value="">Indiferente</option><option>oval</option><option>redondo</option><option>quadrado</option><option>triangular</option><option>coração</option></select>
        </div>
      </div>

      <div class="grid-3">
        <div><label>Tamanho do cabelo</label>
          <select id="aiCabeloTam"><option value="">Indiferente</option><option>careca</option><option>raspado</option><option>curto</option><option>médio</option><option>longo</option></select>
        </div>
        <div><label>Estilo do cabelo</label>
          <select id="aiCabeloEst"><option value="">Indiferente</option><option>liso</option><option>ondulado</option><option>cacheado</option><option>crespo</option><option>coque</option><option>rabo de cavalo</option></select>
        </div>
        <div><label>Cor do cabelo</label><input type="text" id="aiCabeloCor" placeholder="castanho escuro, loiro..."></div>
      </div>

      <div class="grid-3">
        <div><label>Pelos faciais</label>
          <select id="aiBarba"><option value="">Nenhum</option><option>barba por fazer</option><option>barba curta</option><option>barba cheia</option><option>bigode</option></select>
        </div>
        <div><label>Pintas / sardas / sinais</label>
          <select id="aiSinais"><option value="">Nenhum</option><option>sardas</option><option>pintas</option><option>sinais visíveis</option></select>
        </div>
        <div><label>Expressão</label>
          <select id="aiExpressao"><option>neutra</option><option>sorridente</option><option>séria</option></select>
        </div>
      </div>

      <div class="grid-3">
        <div><label>Óculos?</label>
          <select id="aiOculos"><option value="nao">Não</option><option value="sim">Sim</option></select>
        </div>
        <div><label>Estilo/Cor da armação</label><input type="text" id="aiOculosEst" placeholder="armação fina preta, redonda"></div>
        <div><label>Cor da lente</label><input type="text" id="aiOculosLente" placeholder="transparente, fumê"></div>
      </div>

      <div class="grid-3">
        <div><label>Acessório de cabeça</label>
          <select id="aiHeadAcc"><option value="">Nenhum</option><option>chapéu</option><option>boné</option><option>laço</option><option>tiara</option></select>
        </div>
        <div><label>Cor/tamanho do acessório</label><input type="text" id="aiHeadAccDet" placeholder="boné azul marinho"></div>
        <div><label>Vestuário</label>
          <select id="aiRoupa"><option>camiseta</option><option>camisa social</option><option>blusa com alças</option><option>terno e gravata</option><option>terno com gravata borboleta</option></select>
        </div>
      </div>

      <div class="grid-3">
        <div><label>Cor da roupa</label><input type="text" id="aiRoupaCor" placeholder="preta, azul marinho, branca"></div>
        <div><label>Observações extras</label><input type="text" id="aiExtras" placeholder="(opcional)"></div>
        <div><label>Tamanho</label>
          <select id="aiSize"><option value="1024x1024">1024x1024</option><option value="1024x1536">1024x1536</option><option value="1536x1024">1536x1024</option><option value="auto">auto</option></select>
        </div>
      </div>

      <div class="small" id="aiHint" style="display:none;"></div>

      <div class="flex" style="justify-content:flex-end;">
        <button class="btn" id="aiClose" type="button">Fechar</button>
        <button class="btn" id="aiPreviewBtn" type="button"><i class="fa-regular fa-eye"></i> Visualizar</button>
        <button class="btn btn-gold" id="aiSaveBtn" type="button"><i class="fa-regular fa-floppy-disk"></i> Salvar no cadastro</button>
      </div>

      <div style="margin-top:10px; display:flex; gap:12px; align-items:center;">
        <img id="aiPreviewImg" src="" alt="Preview do avatar" style="display:none; width:160px; height:160px; border-radius:50%; border:1px solid #223047; object-fit:cover;">
        <span class="small">O preview não altera seu avatar até você salvar.</span>
      </div>
    </div>
  </div>
</div>

<script>
const API_USERS = '/OKR_system/auth/usuarios_api.php';
const API_AVATAR_AI = '/OKR_system/api/avatar_ai.php';
const CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8") ?>';
const USER_ID = <?= (int)$id ?>;

const $  = (s, r=document)=>r.querySelector(s);
const $$ = (s, r=document)=>Array.from(r.querySelectorAll(s));
function show(el){ el?.classList.add('show'); el?.setAttribute('aria-hidden','false'); }
function hide(el){ el?.classList.remove('show'); el?.setAttribute('aria-hidden','true'); }

let OPTIONS = { companies:[], roles:[], capabilities:[] };
let IS_MASTER=false, MY_COMPANY=null;

/* ===== Options ===== */
async function loadOptions(){
  const r = await fetch(API_USERS + '?action=options', {cache:'no-store'});
  const j = await r.json();

  IS_MASTER  = !!j.is_master;
  MY_COMPANY = j.my_company ?? null;
  OPTIONS.roles        = j.roles || [];
  OPTIONS.capabilities = j.capabilities || [];

  const sel  = $('#id_company'), hint = $('#orgHint');
  sel.innerHTML = '';

  const comps = j.companies || [];
  if (!comps.length) {
    sel.add(new Option('Nenhuma organização disponível', ''));
    sel.disabled = true;
    if (hint) hint.textContent = 'Cadastre uma organização em Configurações > Editar Organização.';
  } else {
    comps.forEach(c => sel.add(new Option(c.nome || ('Empresa #'+c.id_company), c.id_company)));
    if (!j.is_master) {
      sel.value = String(j.my_company ?? '');
      sel.disabled = true;
      if (hint) hint.textContent = 'Você só pode atribuir usuários à sua organização.';
    } else {
      if (comps.length === 1) sel.value = String(comps[0].id_company);
      if (hint) hint.textContent = 'Você é master: pode atribuir qualquer organização.';
    }
  }

  renderRoles($('#rolesBox'), []);
  renderOverrides($('#capsBox'), []);
}

/* ===== Render roles & overrides ===== */
function renderRoles(container, selected){
  container.innerHTML='';
  const set = new Set((selected||[]).map(v=> String(v)));
  OPTIONS.roles.forEach(r=>{
    const rid = r.id || r.role_id || r.key || r.role_key;
    const label = r.descricao || r.role_name || r.key || r.role_key || rid;
    const id = 'role_'+rid;
    container.insertAdjacentHTML('beforeend',
      `<label style="display:flex;align-items:center;gap:6px" for="${id}">
         <input type="checkbox" id="${id}" name="roles[]" value="${rid}" ${set.has(String(rid))?'checked':''}>
         <span>${label}</span>
       </label>`);
  });
}

function groupByResource(caps){
  const g={}; (caps||[]).forEach(c=>{ (g[c.resource] ||= []).push(c); });
  Object.values(g).forEach(arr=> arr.sort((a,b)=> (a.action+a.scope).localeCompare(b.action+b.scope)));
  return g;
}
function renderOverrides(container, overrides){
  container.innerHTML='';
  const ov = new Map((overrides||[]).map(o=> [String(o.capability_id), o.effect])); // ALLOW/DENY
  const grouped = groupByResource(OPTIONS.capabilities);

  Object.keys(grouped).sort().forEach(resource=>{
    const items = grouped[resource];
    const box = document.createElement('div');
    box.className='cap-group';
    box.innerHTML = `<div class="cap-title"><i class="fa-solid fa-cube"></i> ${resource}</div>`;
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
        </div>`);
    });
    container.appendChild(box);
  });
}

/* ===== Load User ===== */
async function loadUser(){
  if (!USER_ID) return;
  const r = await fetch(API_USERS + `?action=get&id=${USER_ID}`, {cache:'no-store'});
  const j = await r.json();
  const u = j.user;
  if (!u) { alert('Usuário não encontrado'); return; }

  $('#primeiro_nome').value     = u.primeiro_nome || '';
  $('#ultimo_nome').value       = u.ultimo_nome || '';
  $('#email_corporativo').value = u.email_corporativo || '';
  $('#telefone').value          = u.telefone || '';
  $('#id_company').value        = String(u.id_company ?? '');

  if (j.avatar) $('#avatarPrev').src = j.avatar;

  // Permissões (se o endpoint já retornar)
  if (j.roles_all)  OPTIONS.roles = j.roles_all;
  if (j.caps_all)   OPTIONS.capabilities = j.caps_all;

  renderRoles($('#rolesBox'), j.roles || []);
  renderOverrides($('#capsBox'), j.overrides || []);

  // Resumo
  const sum = j.summary || {};
  $('#sumR').textContent = (sum.consulta_R || sum.consulta || '—') || '—';
  $('#sumW').textContent = (sum.edicao_W   || sum.edicao   || '—') || '—';
}

/* ===== Save User (dados + roles + overrides) ===== */
$('#userForm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const fd = new FormData(e.currentTarget);
  fd.append('action','save');

  // roles marcados
  $$('#rolesBox input[type="checkbox"]:checked').forEach(chk=>{
    fd.append('roles[]', chk.value);
  });

  // overrides selecionados != INHERIT
  $$('#capsBox select').forEach(sel=>{
    if (sel.value && sel.value!=='INHERIT'){
      fd.append(`overrides[${sel.dataset.cap}]`, sel.value);
    }
  });

  const r = await fetch(API_USERS, { method:'POST', body:fd });
  const j = await r.json();
  if (j?.success){
    window.location.href='/OKR_system/views/usuarios.php';
  } else {
    alert(j?.error || 'Falha ao salvar');
  }
});

/* ===== Upload Avatar ===== */
$('#avatarFile').addEventListener('change', async (ev)=>{
  const f = ev.target.files[0];
  if (!f) return;
  if (!USER_ID){ alert('Salve o usuário antes de enviar avatar.'); return; }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'upload_avatar');
  fd.append('id_user', String(USER_ID));
  fd.append('avatar', f);

  const r = await fetch(API_USERS, { method:'POST', body:fd });
  const j = await r.json();
  if (j?.success){
    $('#avatarPrev').src = j.path + '?t=' + Date.now();
  } else {
    alert(j?.error || 'Falha no upload');
  }
});

/* ===== Canvas Avatar ===== */
const avatarModal = $('#avatarModal');
const cv = $('#avatarCanvas'); const ctx = cv.getContext('2d');
let parts = {};
function pathFromSVG(d){ return new Path2D(d); }
function drawCanvas(){
  ctx.clearRect(0,0,cv.width,cv.height);
  ctx.save();
  const faceType = $('#faceType')?.value || ''; const skin = $('#skinColor')?.value || '#F0C7A8';
  if (parts.face?.[faceType]){ ctx.fillStyle=skin; ctx.fill(pathFromSVG(parts.face[faceType])); }
  const eType = $('#eyesType')?.value || ''; const esc = parseFloat($('#eyesScale')?.value||'1');
  if (parts.eyes?.[eType]){ ctx.save(); ctx.translate(160,160); ctx.scale(esc,esc); ctx.translate(-160,-160);
    ctx.fillStyle="#111"; ctx.fill(pathFromSVG(parts.eyes[eType])); ctx.restore(); }
  const nType=$('#noseType')?.value||''; if (parts.nose?.[nType]){ ctx.fillStyle="#6b7280"; ctx.fill(pathFromSVG(parts.nose[nType])); }
  const mType=$('#mouthType')?.value||''; if (parts.mouth?.[mType]){ ctx.fillStyle="#b91c1c"; ctx.fill(pathFromSVG(parts.mouth[mType])); }
  const hType=$('#hairType')?.value||''; const hColor=$('#hairColor')?.value||'#2E2E2E';
  if (parts.hair?.[hType]){ ctx.fillStyle=hColor; ctx.fill(pathFromSVG(parts.hair[hType])); }
  const gType=$('#glassesType')?.value||''; if (parts.glasses?.[gType]){ ctx.strokeStyle="#111827"; ctx.lineWidth=3; ctx.stroke(pathFromSVG(parts.glasses[gType])); }
  const hat=$('#hatType')?.value||''; if (parts.hat?.[hat]){ ctx.fillStyle="#1f2937"; ctx.fill(pathFromSVG(parts.hat[hat])); }
  const cType=$('#clothesType')?.value||''; if (parts.clothes?.[cType]){ ctx.fillStyle="#1f2937"; ctx.fill(pathFromSVG(parts.clothes[cType])); }
  ctx.restore();
}
async function loadParts(){
  try{
    const mod = await import('/OKR_system/assets/js/avatar_parts.js');
    parts = mod.AVATAR_PARTS || {};
    const fill = (id, keys)=>{ const s=document.getElementById(id); if(!s) return; s.innerHTML=''; keys.forEach(k=> s.add(new Option(k,k))); };
    fill('hairType', Object.keys(parts.hair||{}));
    fill('eyesType', Object.keys(parts.eyes||{}));
    fill('noseType', Object.keys(parts.nose||{}));
    fill('mouthType', Object.keys(parts.mouth||{}));
    fill('faceType', Object.keys(parts.face||{}));
    fill('glassesType', Object.keys(parts.glasses||{}));
    fill('hatType', Object.keys(parts.hat||{}));
    fill('clothesType', Object.keys(parts.clothes||{}));
    drawCanvas();
  }catch(e){}
}
['skinColor','hairType','hairColor','eyesType','eyesScale','noseType','mouthType','faceType','glassesType','hatType','clothesType']
  .forEach(id=> document.addEventListener('input', (ev)=>{ if (ev.target?.id===id) drawCanvas(); }));

$('#btnBuildAvatar').addEventListener('click', ()=>{
  if (!USER_ID){ alert('Salve o usuário antes de gerar avatar.'); return; }
  show(avatarModal); drawCanvas();
});
$('#btnCloseAvatar').addEventListener('click', ()=> hide(avatarModal));
$('#btnSaveAvatar').addEventListener('click', async ()=>{
  if (!USER_ID){ alert('Salve o usuário antes de gerar avatar.'); return; }
  const selCompany = $('#id_company');
  if (!selCompany.disabled && (!selCompany.value || selCompany.value === '0')) { alert('Selecione uma organização antes de salvar.'); return; }
  const data=cv.toDataURL('image/png');
  const fd=new FormData();
  fd.append('csrf_token',CSRF);
  fd.append('action','save_avatar_canvas');
  fd.append('id_user', String(USER_ID));
  fd.append('data_url', data);
  const r=await fetch(API_USERS,{method:'POST', body:fd});
  const j=await r.json();
  if (j?.success){ $('#avatarPrev').src = j.path + '?t=' + Date.now(); hide(avatarModal); }
  else alert(j?.error||'Falha ao salvar avatar');
});

/* ===== IA Avatar ===== */
const aiModal   = document.getElementById('aiModal');
const aiHintEl  = document.getElementById('aiHint');
const aiImg     = document.getElementById('aiPreviewImg');
let   lastPreviewToken = '';

function showAIModal(){
  if (!USER_ID){ alert('Salve o usuário antes de gerar avatar.'); return; }
  lastPreviewToken=''; aiImg.src=''; aiImg.style.display='none'; aiHintEl.style.display='none';
  aiModal.classList.add('show'); aiModal.setAttribute('aria-hidden','false');
}
function hideAIModal(){ aiModal.classList.remove('show'); aiModal.setAttribute('aria-hidden','true'); }

function buildPrompt(){
  const gen = document.getElementById('aiGenero').value;
  const pele = (document.getElementById('aiPele').value||'').trim();
  const rosto = document.getElementById('aiRosto').value;
  const cabTam = document.getElementById('aiCabeloTam').value;
  const cabEst = document.getElementById('aiCabeloEst').value;
  const cabCor = (document.getElementById('aiCabeloCor').value||'').trim();
  const barba  = document.getElementById('aiBarba').value;
  const sinais = document.getElementById('aiSinais').value;
  const expr   = document.getElementById('aiExpressao').value;
  const oculos = document.getElementById('aiOculos').value;
  const ocEst  = (document.getElementById('aiOculosEst').value||'').trim();
  const ocLen  = (document.getElementById('aiOculosLente').value||'').trim();
  const headAcc = document.getElementById('aiHeadAcc').value;
  const headDet = (document.getElementById('aiHeadAccDet').value||'').trim();
  const roupa   = document.getElementById('aiRoupa').value;
  const roupaCor= (document.getElementById('aiRoupaCor').value||'').trim();
  const extras  = (document.getElementById('aiExtras').value||'').trim();
  const generoDesc = gen === 'masculino' ? 'homem' : gen === 'feminino' ? 'mulher' : 'pessoa de gênero neutro';
  const parts = [];
  parts.push(`${generoDesc} em retrato realista, busto (peito para cima)`);
  if (pele)   parts.push(`pele ${pele}`);
  if (rosto)  parts.push(`formato de rosto ${rosto}`);
  if (cabTam) parts.push(`cabelo ${cabTam}`);
  if (cabEst) parts.push(`estilo de cabelo ${cabEst}`);
  if (cabCor) parts.push(`cor do cabelo ${cabCor}`);
  if (barba)  parts.push(barba);
  if (sinais) parts.push(sinais);
  parts.push(`expressão ${expr}`);
  if (oculos === 'sim') { let o = 'óculos'; if (ocEst) o += ` de ${ocEst}`; if (ocLen) o += `, lentes ${ocLen}`; parts.push(o); }
  if (headAcc) { let h = headAcc; if (headDet) h += ` ${headDet}`; parts.push(h); }
  let r = roupa; if (roupaCor) r += ` ${roupaCor}`; parts.push(r);
  if (extras) parts.push(extras);
  return parts.join(', ');
}

async function aiPreview(){
  const selCompany = document.getElementById('id_company');
  if (!selCompany.disabled && (!selCompany.value || selCompany.value === '0')) { alert('Selecione uma organização.'); return; }
  const size = document.getElementById('aiSize').value;
  const prompt = buildPrompt();

  aiHintEl.style.display='block';
  aiHintEl.textContent = 'Gerando preview (OpenAI)...';

  try {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('id_user', String(USER_ID));
    fd.append('prompt', prompt);
    fd.append('size', size);
    fd.append('mode', 'preview');

    const r = await fetch(API_AVATAR_AI, { method:'POST', body: fd });
    const j = await r.json();
    if (!r.ok || !j.success) throw new Error(j.error || 'Falha ao gerar preview');

    lastPreviewToken = j.preview_token || '';
    aiImg.src = j.path + '?t=' + Date.now();
    aiImg.style.display = 'inline-block';
    aiHintEl.textContent = `Preview gerado • Provider: ${j.provider||'-'} • Model: ${j.model||'-'}. Se estiver ok, clique em "Salvar no cadastro".`;

  } catch (e){
    aiHintEl.textContent = 'Erro: ' + (e.message || 'Falha inesperada');
  }
}

async function aiCommit(){
  if (!lastPreviewToken) { alert('Gere um preview antes de salvar.'); return; }
  aiHintEl.style.display='block';
  aiHintEl.textContent = 'Salvando avatar...';

  try {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('id_user', String(USER_ID));
    fd.append('mode', 'commit');
    fd.append('preview_token', lastPreviewToken);

    const r = await fetch(API_AVATAR_AI, { method:'POST', body: fd });
    const j = await r.json();
    if (!r.ok || !j.success) throw new Error(j.error || 'Falha ao salvar avatar');

    document.getElementById('avatarPrev').src = j.path + '?t=' + Date.now();
    aiHintEl.textContent = 'Avatar salvo com sucesso!';
    hideAIModal();
  } catch (e){
    aiHintEl.textContent = 'Erro: ' + (e.message || 'Falha inesperada ao salvar');
  }
}
document.getElementById('btnAIAvatar').addEventListener('click', showAIModal);
document.getElementById('aiClose').addEventListener('click', hideAIModal);
document.getElementById('aiPreviewBtn').addEventListener('click', aiPreview);
document.getElementById('aiSaveBtn').addEventListener('click', aiCommit);

/* ===== Boot ===== */
async function loadSummary(){
  if (!USER_ID) return;
  const r = await fetch(API_USERS+`?action=get_access&id=${USER_ID}`,{cache:'no-store'});
  const j = await r.json();
  const sum = j.summary || {};
  document.getElementById('sumR').textContent = (sum.consulta_R || sum.consulta || '—') || '—';
  document.getElementById('sumW').textContent = (sum.edicao_W   || sum.edicao   || '—') || '—';
}

(async function init(){
  await loadOptions();
  await loadUser();
  await loadSummary();
  await loadParts();
})();
</script>
</body>
</html>
