<?php
// views/config_style.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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

// Carrega organizações p/ combo
$companies = [];
try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );
  $companies = $pdo->query("SELECT id_company, organizacao FROM company ORDER BY created_at DESC")->fetchAll();
} catch (PDOException $e) {
  // mantém vazio
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Configurações da Organização – OKR System</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- CSS globais -->
  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

  <style>
    :root{
      --bg-soft:#171b21;
      --card:#12161c;
      --muted:#a6adbb;
      --text:#eaeef6;
      --gold:#f6c343;
      --green:#22c55e;
      --blue:#60a5fa;
      --red:#ef4444;
      --border:#222733;
      --shadow:0 10px 30px rgba(0,0,0,.20);
    }
    .main-wrapper{ padding:2rem 2rem 2rem 1.5rem; }
    @media (max-width: 991px){ .main-wrapper{ padding:1rem; } }

    .grid-2{ display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    @media (max-width: 1000px){ .grid-2{ grid-template-columns: 1fr; } }

    .card-soft{
      background: linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border);
      border-radius:16px;
      padding:16px;
      box-shadow: var(--shadow);
      color: var(--text);
      position:relative;
      overflow:hidden;
    }
    .card-title{
      display:flex; align-items:center; gap:10px;
      font-weight:800; letter-spacing:.2px; margin-bottom:10px;
    }
    .card-title i{ color: var(--gold); }
    .muted{ color: var(--muted); font-size:.92rem; }

    .form-row{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
    .form-row-1{ display:grid; grid-template-columns: 1fr; gap:12px; }
    @media (max-width: 700px){ .form-row{ grid-template-columns: 1fr; } }

    .form-group{ display:flex; flex-direction:column; gap:6px; }
    .form-group label{ font-size:.9rem; color:var(--muted); }
    .form-control{
      background:#0b0f14; border:1px solid var(--border);
      border-radius:10px; color:var(--text); padding:10px 12px;
      outline:none;
    }
    .form-control::placeholder{ color:#7a8394; }
    .form-control:focus{ border-color:#304054; }

    .actions{ display:flex; gap:10px; margin-top:12px; }
    .btn-modern{
      display:inline-flex; align-items:center; gap:8px;
      padding:10px 14px; border-radius:12px; font-weight:700; font-size:.9rem;
      border:1px solid var(--border); cursor:pointer; transition: .2s ease;
      background:#0e131a; color: var(--text);
    }
    .btn-modern:hover{ border-color:#304054; }
    .btn-primary-modern{
      background: linear-gradient(90deg, var(--gold), var(--green));
      color:#1a1a1a; border-color:rgba(255,255,255,.15);
    }
    .btn-primary-modern i{ color:#1a1a1a; }

    .status-line{
      margin-top:10px; padding:10px; border-radius:12px; border:1px solid var(--border);
      background:#0e131a; color:var(--muted); display:none;
    }
    .status-line.show{ display:block; }

    .overlay{
      position:absolute; inset:0; display:none;
      background:rgba(0,0,0,.45); backdrop-filter: blur(1px);
      align-items:center; justify-content:center; z-index:5;
    }
    .overlay.show{ display:flex; }
    .spinner{
      display:flex; align-items:center; gap:10px; color:#fff; font-weight:700;
      background: rgba(0,0,0,.35); padding:10px 14px; border-radius:12px;
      border:1px solid rgba(255,255,255,.15);
    }

    .hide{ display:none !important; }

    /* Swatches de cor */
    .swatches{ display:flex; flex-wrap:wrap; gap:8px; }
    .sw{ width:32px; height:24px; border-radius:6px; border:1px solid var(--border); cursor:pointer; }
    .sw.active{ outline:2px solid #fff; outline-offset:2px; }

    /* Preview do tema */
    .preview{
      margin-top:10px; border:1px solid var(--border); border-radius:12px; overflow:hidden;
    }
    .preview-top{ padding:14px; font-weight:800; }
    .preview-bottom{ padding:14px; }
    .logo-preview{ width:120px; height:40px; object-fit:contain; display:block; background:#0b0f14; border:1px dashed var(--border); border-radius:8px; }

    /* Read-only grid do card antigo */
    .readonly-grid{ display:grid; grid-template-columns: repeat(2, 1fr); gap:10px; }
    @media (max-width: 900px){ .readonly-grid{ grid-template-columns: 1fr; } }
    .ro-item{ background:#0e131a; border:1px solid var(--border); border-radius:12px; padding:10px; }
    .ro-label{ color:var(--muted); font-size:.8rem; display:block; margin-bottom:4px; }
    .ro-value{ color:var(--text); font-weight:800; font-size:.95rem; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main id="main-content" class="main-wrapper">
      <h1 style="font-size:1.15rem"><i class="fa-solid fa-building me-2"></i>Configurações da Organização</h1>

      <div class="grid-2">
        <!-- ==================== CARD 1: PERSONALIZAÇÃO (NOVO) ==================== -->
        <section class="card-soft" id="card-style">
          <div class="card-title"><i class="fa-solid fa-palette"></i>Personalização</div>
          <p class="muted" style="margin-top:-6px;margin-bottom:10px">
            Selecione uma organização já cadastrada para definir <strong>cores</strong>, enviar <strong>logo</strong> e (em breve) associar um <strong>OKR Master</strong>.
          </p>

          <form id="styleForm" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-row">
              <div class="form-group">
                <label for="id_company">Organização</label>
                <select class="form-control" id="id_company" name="id_company" required>
                  <option value="">— selecione —</option>
                  <?php foreach ($companies as $c): ?>
                    <option value="<?= (int)$c['id_company'] ?>"><?= htmlspecialchars($c['organizacao']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>&nbsp;</label>
                <a href="#card-form" class="btn-modern" style="justify-content:center; text-decoration:none;">
                  <i class="fa-solid fa-circle-plus"></i>Cadastrar nova organização
                </a>
              </div>
            </div>

            <div id="styleControls" class="<?= empty($companies) ? 'hide' : '' ?>">
              <div class="form-row">
                <div class="form-group">
                  <label>Background 1 (escuro)</label>
                  <div class="swatches" data-target="bg1_hex">
                    <?php
                      $dark = ['#12161c','#0d1117','#171b21','#101418','#1f2937','#111827'];
                      foreach ($dark as $d) echo '<div class="sw" data-val="'.$d.'" style="background:'.$d.'"></div>';
                    ?>
                  </div>
                  <div style="margin-top:8px">
                    <input type="color" class="form-control" style="height:38px;padding:2px" id="bg1_picker" value="#12161c">
                    <input type="hidden" name="bg1_hex" id="bg1_hex" value="#12161c">
                  </div>
                </div>
                <div class="form-group">
                  <label>Background 2 (claro)</label>
                  <div class="swatches" data-target="bg2_hex">
                    <?php
                      $light = ['#eaeef6','#f5f7fb','#f3f4f6','#e5e7eb','#ffffff','#f8fafc'];
                      foreach ($light as $l) echo '<div class="sw" data-val="'.$l.'" style="background:'.$l.'"></div>';
                    ?>
                  </div>
                  <div style="margin-top:8px">
                    <input type="color" class="form-control" style="height:38px;padding:2px" id="bg2_picker" value="#eaeef6">
                    <input type="hidden" name="bg2_hex" id="bg2_hex" value="#eaeef6">
                  </div>
                </div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label for="logo_file">Logo (PNG/JPG/SVG — máx. 1.5MB)</label>
                  <input class="form-control" type="file" id="logo_file" name="logo_file" accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml">
                  <div style="margin-top:8px">
                    <img id="logoPreview" class="logo-preview" alt="Logo preview">
                  </div>
                </div>
                <div class="form-group">
                  <label for="okr_master">OKR Master (em breve)</label>
                  <select class="form-control" id="okr_master" name="okr_master" disabled>
                    <option value="">— inativo por enquanto —</option>
                  </select>
                </div>
              </div>

              <div class="preview" id="themePreview" aria-label="Pré-visualização do tema">
                <div class="preview-top" id="pvTop">Título/Top bar</div>
                <div class="preview-bottom" id="pvBottom">
                  <div style="display:flex;align-items:center;gap:10px;">
                    <div style="width:12px;height:12px;border-radius:999px;background:#60a5fa;"></div>
                    <span>Texto de exemplo sobre fundo claro</span>
                  </div>
                </div>
              </div>

              <div class="actions">
                <button type="submit" class="btn-modern btn-primary-modern">
                  <i class="fa-solid fa-floppy-disk"></i> Salvar estilo
                </button>
              </div>
              <div id="statusStyle" class="status-line"></div>
              <div class="overlay" id="overlayStyle">
                <div class="spinner"><i class="fa-solid fa-rotate fa-spin"></i><span>Salvando estilo…</span></div>
              </div>
            </div>

            <?php if (empty($companies)): ?>
              <div class="status-line show" style="margin-top:12px">
                Nenhuma organização encontrada. Cadastre uma ao lado para habilitar a personalização.
              </div>
            <?php endif; ?>
          </form>
        </section>

        <!-- ==================== CARD 2: CADASTRO (EXISTENTE) ==================== -->
        <section class="card-soft" id="card-form">
          <div class="card-title"><i class="fa-solid fa-pen-to-square"></i>Cadastro</div>
          <p class="muted" style="margin-top:-6px;margin-bottom:10px">
            Preencha o <strong>Nome Fantasia</strong> (obrigatório). O <strong>CNPJ</strong> é opcional; se informado,
            validaremos na base da Receita e exibiremos os dados oficiais em seguida.
          </p>

          <form id="orgForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-row">
              <div class="form-group">
                <label for="organizacao">Organização (Nome Fantasia) *</label>
                <input class="form-control" type="text" id="organizacao" name="organizacao"
                       placeholder="Ex.: Colormaq" required>
              </div>
              <div class="form-group">
                <label for="cnpj">CNPJ (opcional)</label>
                <input class="form-control" type="text" id="cnpj" name="cnpj" maxlength="18"
                       placeholder="00.000.000/0000-00">
              </div>
            </div>

            <div class="actions">
              <button type="submit" class="btn-modern btn-primary-modern">
                <i class="fa-solid fa-floppy-disk"></i> Cadastrar organização
              </button>
              <button type="reset" class="btn-modern">
                <i class="fa-solid fa-eraser"></i> Limpar
              </button>
            </div>

            <div id="status" class="status-line"></div>
            <div class="overlay" id="overlay">
              <div class="spinner" id="spinner"><i class="fa-solid fa-rotate fa-spin"></i><span>Validando CNPJ…</span></div>
            </div>
          </form>

          <section class="card-soft hide" id="card-ro" style="margin-top:16px">
            <div class="card-title" style="margin-top:-6px"><i class="fa-solid fa-circle-info"></i>Dados oficiais (Receita Federal)</div>
            <div class="readonly-grid" id="roGrid"></div>
          </section>
        </section>
      </div>

      <!-- Chat (inalterado) -->
      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // ===== Helpers gerais =====
    const statusStyle = document.getElementById('statusStyle');
    const overlayStyle = document.getElementById('overlayStyle');
    const showStatus = (el, msg, type='info') => {
      el.classList.add('show');
      el.style.borderColor = (type==='error') ? 'rgba(239,68,68,.35)' :
                              (type==='success') ? 'rgba(34,197,94,.35)' : 'var(--border)';
      el.style.color = (type==='error') ? '#fecaca' :
                       (type==='success') ? '#dcfce7' : 'var(--muted)';
      el.style.background = (type==='error') ? 'rgba(239,68,68,.12)' :
                            (type==='success') ? 'rgba(34,197,94,.12)' : '#0e131a';
      el.innerHTML = msg;
    };
    const hideStatus = (el) => { el.classList.remove('show'); el.innerHTML=''; };

    // ====== PERSONALIZAÇÃO ======
    const formStyle   = document.getElementById('styleForm');
    const idCompanyEl = document.getElementById('id_company');
    const styleControls = document.getElementById('styleControls');

    const bg1Hex = document.getElementById('bg1_hex');
    const bg2Hex = document.getElementById('bg2_hex');
    const bg1Picker = document.getElementById('bg1_picker');
    const bg2Picker = document.getElementById('bg2_picker');

    const pvTop = document.getElementById('pvTop');
    const pvBottom = document.getElementById('pvBottom');
    const logoFile = document.getElementById('logo_file');
    const logoPreview = document.getElementById('logoPreview');

    function updatePreview(){
      pvTop.style.background = bg1Hex.value || '#12161c';
      pvTop.style.color = '#eaeef6';
      pvBottom.style.background = bg2Hex.value || '#eaeef6';
      pvBottom.style.color = '#111827';
    }
    function bindSwatches(){
      document.querySelectorAll('.swatches').forEach(w=>{
        w.addEventListener('click', (ev)=>{
          const sw = ev.target.closest('.sw'); if(!sw) return;
          w.querySelectorAll('.sw').forEach(s=>s.classList.remove('active'));
          sw.classList.add('active');
          const targetId = w.getAttribute('data-target');
          const val = sw.getAttribute('data-val');
          document.getElementById(targetId).value = val;
          if (targetId==='bg1_hex') bg1Picker.value = val;
          if (targetId==='bg2_hex') bg2Picker.value = val;
          updatePreview();
        });
      });
    }
    bg1Picker.addEventListener('input', () => { bg1Hex.value = bg1Picker.value; clearActive('bg1_hex'); updatePreview(); });
    bg2Picker.addEventListener('input', () => { bg2Hex.value = bg2Picker.value; clearActive('bg2_hex'); updatePreview(); });
    function clearActive(targetId){
      document.querySelectorAll(`.swatches[data-target="${targetId}"] .sw`).forEach(s=>s.classList.remove('active'));
    }

    // Logo preview
    logoFile.addEventListener('change', ()=>{
      const file = logoFile.files[0];
      if (!file) { logoPreview.src=''; return; }
      const reader = new FileReader();
      reader.onload = e => { logoPreview.src = e.target.result; };
      reader.readAsDataURL(file);
    });

    // Carrega estilo quando selecionar empresa
    idCompanyEl.addEventListener('change', async ()=>{
      hideStatus(statusStyle);
      if (!idCompanyEl.value) return;
      try {
        const r = await fetch(`/OKR_system/auth/get_company_style.php?id_company=${encodeURIComponent(idCompanyEl.value)}`);
        const data = await r.json();
        if (!data.success) throw new Error(data.error||'Erro ao buscar estilo.');

        const rec = data.record;
        if (rec) {
          bg1Hex.value = rec.bg1_hex || '#12161c';
          bg2Hex.value = rec.bg2_hex || '#eaeef6';
          bg1Picker.value = bg1Hex.value;
          bg2Picker.value = bg2Hex.value;
          if (rec.logo_base64) logoPreview.src = rec.logo_base64; else logoPreview.removeAttribute('src');
          updatePreview();
          showStatus(statusStyle, 'Estilo carregado para esta organização.', 'success');
        } else {
          bg1Hex.value = '#12161c'; bg2Hex.value = '#eaeef6';
          bg1Picker.value = '#12161c'; bg2Picker.value = '#eaeef6';
          logoPreview.removeAttribute('src');
          updatePreview();
          showStatus(statusStyle, 'Nenhum estilo salvo ainda para esta organização.', 'info');
        }
      } catch (e) {
        showStatus(statusStyle, '<strong>Erro:</strong> '+ (e.message || e), 'error');
      }
    });

    formStyle.addEventListener('submit', async (ev)=>{
      ev.preventDefault();
      hideStatus(statusStyle);

      if (!idCompanyEl.value) {
        showStatus(statusStyle, '<strong>Erro:</strong> selecione uma organização.', 'error');
        return;
      }
      overlayStyle.classList.add('show');
      try {
        const fd = new FormData(formStyle);
        const resp = await fetch('/OKR_system/auth/salvar_company_style.php', {
          method: 'POST',
          body: fd,
          headers: { 'Accept':'application/json' }
        });
        const data = await resp.json();
        if (!resp.ok || !data.success) throw new Error(data.error || 'Falha ao salvar estilo.');
        showStatus(statusStyle, 'Estilo salvo com sucesso!', 'success');
      } catch (e) {
        showStatus(statusStyle, '<strong>Erro:</strong> ' + (e.message || e), 'error');
      } finally {
        overlayStyle.classList.remove('show');
      }
    });

    // ====== CADASTRO (já existente) ======
    const form = document.getElementById('orgForm');
    const cnpjInput = document.getElementById('cnpj');
    const statusBox = document.getElementById('status');
    const overlay = document.getElementById('overlay');
    const spinner = document.getElementById('spinner');
    const roCard = document.getElementById('card-ro');
    const roGrid = document.getElementById('roGrid');

    const maskCNPJ = (v) => {
      const d = v.replace(/\D+/g,'').slice(0,14);
      let r = '';
      if (d.length <= 2) r = d;
      else if (d.length <= 5) r = d.replace(/(\d{2})(\d+)/, '$1.$2');
      else if (d.length <= 8) r = d.replace(/(\d{2})(\d{3})(\d+)/, '$1.$2.$3');
      else if (d.length <= 12) r = d.replace(/(\d{2})(\d{3})(\d{3})(\d+)/, '$1.$2.$3/$4');
      else r = d.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})/, '$1.$2.$3/$4-$5');
      return r;
    };
    cnpjInput.addEventListener('input', (e)=>{ e.target.value = maskCNPJ(e.target.value); });

    function showStatusOrg(msg, type='info') {
      statusBox.classList.add('show');
      statusBox.style.borderColor = (type==='error') ? 'rgba(239,68,68,.35)' :
                                    (type==='success') ? 'rgba(34,197,94,.35)' : 'var(--border)';
      statusBox.style.color = (type==='error') ? '#fecaca' :
                              (type==='success') ? '#dcfce7' : 'var(--muted)';
      statusBox.style.background = (type==='error') ? 'rgba(239,68,68,.12)' :
                                   (type==='success') ? 'rgba(34,197,94,.12)' : '#0e131a';
      statusBox.innerHTML = msg;
    }
    function hideStatusOrg(){ statusBox.classList.remove('show'); statusBox.innerHTML=''; }

    function renderReadOnly(rec) {
      const fields = [
        ['Organização (Fantasia)', rec.organizacao || '—'],
        ['CNPJ', rec.cnpj ? rec.cnpj : '—'],
        ['NOME EMPRESARIAL', rec.razao_social || '—'],
        ['NAT. JURÍDICA (cód.)', rec.natureza_juridica_code || '—'],
        ['NAT. JURÍDICA (descr.)', rec.natureza_juridica_desc || '—'],
        ['LOGRADOURO', rec.logradouro || '—'],
        ['NÚMERO', rec.numero || '—'],
        ['COMPLEMENTO', rec.complemento || '—'],
        ['CEP', rec.cep || '—'],
        ['BAIRRO/DISTRITO', rec.bairro || '—'],
        ['MUNICÍPIO', rec.municipio || '—'],
        ['UF', rec.uf || '—'],
        ['ENDEREÇO ELETRÔNICO', rec.email || '—'],
        ['TELEFONE', rec.telefone || '—'],
        ['SITUAÇÃO CADASTRAL', rec.situacao_cadastral || '—'],
        ['DATA SITUAÇÃO CADASTRAL', rec.data_situacao_cadastral || '—'],
      ];
      roGrid.innerHTML = fields.map(([label, val]) => `
        <div class="ro-item">
          <span class="ro-label">${label}</span>
          <div class="ro-value">${val}</div>
        </div>
      `).join('');
      roCard.classList.remove('hide');

      // Atualiza combo de organizações (se estava vazio)
      const opt = document.createElement('option');
      opt.value = rec.id_company; opt.textContent = rec.organizacao;
      if (![...idCompanyEl.options].some(o=>o.value==opt.value)) {
        idCompanyEl.appendChild(opt);
        styleControls.classList.remove('hide');
      }
    }

    form.addEventListener('submit', async (e)=>{
      e.preventDefault();
      hideStatusOrg();

      const fd = new FormData(form);
      const org = (fd.get('organizacao')||'').trim();
      const cnpj = (fd.get('cnpj')||'').replace(/\D+/g,'');

      if (!org) { showStatusOrg('<strong>Erro:</strong> Informe o Nome Fantasia.', 'error'); return; }
      overlay.classList.add('show');
      if (!cnpj) spinner.querySelector('span').textContent = 'Salvando…'; else spinner.querySelector('span').textContent = 'Validando CNPJ…';

      try {
        const resp = await fetch('/OKR_system/auth/salvar_company.php', {
          method:'POST',
          body: fd,
          headers:{ 'Accept':'application/json' }
        });
        const data = await resp.json();
        if (!resp.ok || !data.success) throw new Error(data.error || 'Falha ao salvar.');
        if (data.fez_consulta) {
          showStatusOrg('Organização salva com sucesso. Dados oficiais do CNPJ carregados abaixo.', 'success');
          renderReadOnly(data.record || {});
        } else {
          showStatusOrg('Organização salva com sucesso (CNPJ não informado).', 'success');
          roCard.classList.add('hide');
          // adiciona no combo se ainda não tiver
          const opt = document.createElement('option');
          opt.value = data.record?.id_company; opt.textContent = data.record?.organizacao || org;
          if (opt.value && ![...idCompanyEl.options].some(o=>o.value==opt.value)) {
            idCompanyEl.appendChild(opt);
            styleControls.classList.remove('hide');
          }
        }
      } catch (err) {
        showStatusOrg('<strong>Erro:</strong> ' + (err.message || err), 'error');
      } finally {
        overlay.classList.remove('show');
      }
    });

    // Inicialização
    bindSwatches();
    updatePreview();
  </script>
</body>
</html>
