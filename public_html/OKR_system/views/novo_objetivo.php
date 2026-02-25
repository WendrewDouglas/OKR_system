<?php
// views/novo_objetivo.php
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
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
//require_cap('W:objetivo@ORG');

if (empty($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
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

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Descobre empresa do logado
$userId = (int)$_SESSION['user_id'];
$st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user=:u LIMIT 1");
$st->execute([':u'=>$userId]);
$companyId = (int)($st->fetchColumn() ?: 0);

// Listas
$users = [];
if ($companyId) {
  $st = $pdo->prepare("SELECT id_user, primeiro_nome, ultimo_nome FROM usuarios WHERE id_company=:c ORDER BY primeiro_nome");
  $st->execute([':c'=>$companyId]);
  $users = $st->fetchAll();
}
$pilares = $pdo->query("SELECT id_pilar, descricao_exibicao FROM dom_pilar_bsc ORDER BY ordem_pilar")->fetchAll();
$tipos   = $pdo->query("SELECT id_tipo,  descricao_exibicao FROM dom_tipo_objetivo ORDER BY descricao_exibicao")->fetchAll();
$ciclos  = $pdo->query("SELECT id_ciclo, nome_ciclo, descricao FROM dom_ciclos ORDER BY id_ciclo")->fetchAll();

// Tema (uma vez)
if (!defined('PB_THEME_LINK_EMITTED')) {
  define('PB_THEME_LINK_EMITTED', true);
  echo '<link rel="stylesheet" href="/OKR_system/assets/company_theme.php">';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Novo Objetivo – OKR System</title>

  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/components.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    body{ background:#fff !important; color:#111; }
    :root{ --chat-w:0px; }
    .content{ background:transparent; }
    main.nobj{ padding:24px; display:grid; grid-template-columns:1fr; gap:16px; margin-right:var(--chat-w); transition:margin-right .25s ease; }

    .grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .grid-3{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
    @media (max-width:900px){ .grid-2,.grid-3{ grid-template-columns:1fr; } }

    label{ display:block; margin-bottom:6px; color:#cbd5e1; font-size:.9rem; }
    input[type="text"], textarea, select{
      width:100%; background:#0c1118; color:#e5e7eb; border:1px solid #1f2635; border-radius:10px; padding:10px 10px; outline:none;
    }
    textarea{ resize:vertical; min-height:90px; }

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

    .grid-2.align-center { align-items: center; }
    #ciclo_detalhe_wrapper > label { display:block; margin-bottom:6px; color:#cbd5e1; font-size:.9rem; }

  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main class="nobj">
      <?php
        $breadcrumbs = [
          ['label' => 'Dashboard', 'icon' => 'fa-solid fa-house', 'href' => '/OKR_system/dashboard'],
          ['label' => 'Meus OKRs', 'icon' => 'fa-solid fa-bullseye', 'href' => '/OKR_system/meus_okrs'],
          ['label' => 'Novo Objetivo', 'icon' => 'fa-solid fa-circle-plus'],
        ];
        include __DIR__ . '/partials/breadcrumbs.php';
      ?>

      <section class="head-card">
        <h1 class="head-title"><i class="fa-solid fa-bullseye"></i>Novo Objetivo</h1>
        <div class="head-meta">
          <span class="pill"><i class="fa-solid fa-circle-info"></i>Defina o objetivo, ciclo e responsável(eis), e salve para submeter à aprovação.</span>
          <span id="periodBadge" class="pill" style="display:none;">
            <i class="fa-regular fa-calendar"></i>
            <span id="periodText"></span>
          </span>
          <span id="ownersBadge" class="pill pill-gold" style="display:none;">
            <i class="fa-regular fa-user"></i>
            <span id="ownersText"></span>
          </span>
        </div>
      </section>

      <section class="form-card">
        <h2><i class="fa-regular fa-rectangle-list"></i> Dados do Objetivo</h2>

        <form id="objectiveForm" action="/OKR_system/auth/salvar_objetivo.php" method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" id="qualidade" name="qualidade" value="">
          <input type="hidden" id="justificativa_ia" name="justificativa_ia" value="">

          <!-- Passo 1: Essenciais (aberto por padrão) -->
          <fieldset class="pd-fieldset open" id="pdStep1">
            <div class="pd-legend" onclick="document.getElementById('pdStep1').classList.toggle('open')">
              <i class="fa-solid fa-1" aria-hidden="true"></i>
              Essenciais — Nome, Tipo e Pilar
              <i class="fa-solid fa-chevron-down pd-chev" aria-hidden="true"></i>
            </div>
            <div class="pd-body">
              <div>
                <label for="nome_objetivo"><i class="fa-regular fa-pen-to-square"></i> Nome do Objetivo <span class="helper">(obrigatório)</span></label>
                <input type="text" id="nome_objetivo" name="nome_objetivo" required>
              </div>

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
            </div>
          </fieldset>

          <!-- Passo 2: Detalhes (abre automaticamente quando Passo 1 está preenchido) -->
          <fieldset class="pd-fieldset" id="pdStep2" style="margin-top:12px;">
            <div class="pd-legend" onclick="document.getElementById('pdStep2').classList.toggle('open')">
              <i class="fa-solid fa-2" aria-hidden="true"></i>
              Detalhes — Ciclo, Responsáveis e Observações
              <i class="fa-solid fa-chevron-down pd-chev" aria-hidden="true"></i>
            </div>
            <div class="pd-body">
              <div class="grid-2 align-center">
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

              <div style="margin-top:12px;">
                <label><i class="fa-regular fa-user"></i> Responsável(es) <span class="helper">(obrigatório)</span></label>
                <div class="multi-select-container">
                  <div class="chips-input" id="responsavel_container">
                    <input type="text" id="responsavel_input" class="chips-input-field" placeholder="Clique para selecionar...">
                  </div>
                  <div class="dropdown-list d-none" id="responsavel_list">
                    <ul>
                      <?php foreach($users as $u): ?>
                        <li data-id="<?= (int)$u['id_user'] ?>"><?= htmlspecialchars($u['primeiro_nome'].' '.$u['ultimo_nome']) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
                <input type="hidden" id="responsavel" name="responsavel">
                <small id="responsavel_warning" class="warning-text d-none">
                  ⚠️ Prefira um único responsável para evitar ambiguidades e garantir foco.
                </small>
              </div>

              <div style="margin-top:12px;">
                <label for="observacoes"><i class="fa-regular fa-note-sticky"></i> Observações</label>
                <textarea id="observacoes" name="observacoes" rows="4"></textarea>
              </div>
            </div>
          </fieldset>

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
  // Silencia erros irrelevantes de extensões
  window.addEventListener('unhandledrejection', (event) => {
    const msg = event?.reason?.message || '';
    if (msg.includes('A listener indicated an asynchronous response')) event.preventDefault();
  });

  const $  = (s, r=document)=>r.querySelector(s);
  const $$ = (s, r=document)=>Array.from(r.querySelectorAll(s));
  const show = el => { el?.classList.add('show'); el?.setAttribute('aria-hidden','false'); };
  const hide = el => { el?.classList.remove('show'); el?.setAttribute('aria-hidden','true'); };

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
    const ids = ($('#responsavel')?.value || '').split(',').filter(Boolean);
    const ob = $('#ownersBadge'), ot = $('#ownersText');
    if (ids.length>0 && ob && ot){ ot.textContent = `Responsáveis: ${ids.length}`; ob.style.display='inline-flex'; }
    else if (ob){ ob.style.display='none'; }
  }

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
          const m1=d1.toLocaleString('pt-BR',{month:'short'});
          const m2=d2.toLocaleString('pt-BR',{month:'short'});
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

  // Progressive Disclosure: auto-open Step 2 when Step 1 is filled
  function checkStep1Complete(){
    const nome  = ($('#nome_objetivo')?.value || '').trim();
    const tipo  = ($('#tipo_objetivo')?.value || '').trim();
    const pilar = ($('#pilar_bsc')?.value || '').trim();
    const step2 = document.getElementById('pdStep2');
    if (nome && tipo && pilar && step2 && !step2.classList.contains('open')) {
      step2.classList.add('open');
    }
  }

  // Fluxo IA
  document.addEventListener('DOMContentLoaded', () => {
    populateCycles();
    toggleCycleDetail();

    // Watch Step 1 fields for progressive disclosure
    ['#nome_objetivo','#tipo_objetivo','#pilar_bsc'].forEach(sel => {
      const el = $(sel);
      if (el) {
        el.addEventListener('input', checkStep1Complete);
        el.addEventListener('change', checkStep1Complete);
      }
    });

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
      if (score <= 2) return { id:'péssimo',  cls:'q-pessimo',  label:'Péssimo'  };
      if (score <= 4) return { id:'ruim',     cls:'q-ruim',     label:'Ruim'     };
      if (score <= 6) return { id:'moderado', cls:'q-moderado', label:'Moderado' };
      if (score <= 8) return { id:'bom',      cls:'q-bom',      label:'Bom'      };
      return            { id:'ótimo',    cls:'q-otimo',    label:'Ótimo'    };
    }

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const fd = new FormData(form);
      fd.append('evaluate','1');
      setLoading(true);

      try {
        const res  = await fetch(form.action, {
          method:'POST', body:fd,
          headers:{ 'Accept':'application/json' }
        });
        const data = await res.json();

        setLoading(false);

        if (data.error || data.message) {
          alert(data.error || data.message);
          return;
        }

        if (typeof data?.score !== 'number' || typeof data?.justification !== 'string') {
          throw new Error('Resposta IA inválida');
        }

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
            const res2 = await fetch(form.action, {
              method:'POST', body:fd2,
              headers:{ 'Accept':'application/json' }
            });
            const ret  = await res2.json();
            setLoading(false);

            if (ret.error || ret.message) {
              alert(ret.error || ret.message);
              return;
            }

            if (ret?.success) {
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

    updateBadges();
  });
  </script>
</body>
</html>
