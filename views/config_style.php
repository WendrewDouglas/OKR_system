<?php
// views/config_style.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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

if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ============ INJETAR O TEMA (uma vez por página) ============ */
if (!defined('PB_THEME_LINK_EMITTED')) {
  define('PB_THEME_LINK_EMITTED', true);
  // Se quiser forçar recarregar em testes, acrescente ?nocache=1
  echo '<link rel="stylesheet" href="/OKR_system/assets/company_theme.php">';
}

// Carrega SOMENTE a empresa do usuário logado
$userCompany = null;
$hasCompany = false;
$hasValidCNPJ = false;

function only_digits_local($s){ return preg_replace('/\D+/', '', (string)$s); }
function validaCNPJlocal($cnpj) {
  $cnpj = only_digits_local($cnpj);
  if (strlen($cnpj) !== 14) return false;
  if (preg_match('/^(\d)\1{13}$/', $cnpj)) return false;
  $b = array_map('intval', str_split($cnpj));
  $p1=[5,4,3,2,9,8,7,6,5,4,3,2]; $p2=[6,5,4,3,2,9,8,7,6,5,4,3,2];
  $s=0; for($i=0;$i<12;$i++) $s += $b[$i]*$p1[$i]; $d1 = ($s%11<2)?0:11-$s%11;
  if ($b[12] !== $d1) return false;
  $s=0; for($i=0;$i<13;$i++) $s += $b[$i]*$p2[$i]; $d2 = ($s%11<2)?0:11-$s%11;
  return $b[13] === $d2;
}

try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );

  $st = $pdo->prepare("
    SELECT c.*
    FROM usuarios u
    LEFT JOIN company c ON c.id_company = u.id_company
    WHERE u.id_user = :uid
    LIMIT 1
  ");
  $st->execute([':uid' => $_SESSION['user_id']]);
  $userCompany = $st->fetch();

  if ($userCompany && !empty($userCompany['id_company'])) {
    $hasCompany = true;
    $cnpjDigits = only_digits_local($userCompany['cnpj'] ?? '');
    $hasValidCNPJ = $cnpjDigits ? validaCNPJlocal($cnpjDigits) : false;
  }
} catch (PDOException $e) {
  // silencioso
}

$companyId   = (int)($userCompany['id_company'] ?? 0);
$companyName = $userCompany['organizacao'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Personalizar Estilo – OKR System</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Seus CSS -->
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
    .main-wrapper{ padding:2rem 2rem 2rem 1.5rem; }
    @media (max-width: 991px){ .main-wrapper{ padding:1rem; } }

    .card-soft{
      background: linear-gradient(180deg, var(--card), #0e1319);
      border:1px solid var(--border); border-radius:16px; padding:16px;
      box-shadow: var(--shadow); color: var(--text); position:relative; overflow:hidden;
    }
    .card-title{ display:flex; align-items:center; gap:10px; font-weight:800; letter-spacing:.2px; margin-bottom:10px; }
    .card-title i{ color: var(--gold); }
    .muted{ color: var(--muted); font-size:.92rem; }

    .form-row{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
    @media (max-width: 700px){ .form-row{ grid-template-columns: 1fr; } }
    .form-group{ display:flex; flex-direction:column; gap:6px; }
    .form-group label{ font-size:.9rem; color:var(--muted); }
    .form-control{
      background:#0b0f14; border:1px solid var(--border); border-radius:10px;
      color:var(--text); padding:10px 12px; outline:none;
    }
    .form-control:focus{ border-color:#304054; }

    .actions{ display:flex; gap:10px; margin-top:12px; flex-wrap:wrap; }
    .btn-modern{
      display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:12px;
      font-weight:700; font-size:.9rem; border:1px solid var(--border); cursor:pointer; transition:.2s ease;
      background:#0e131a; color: var(--text);
    }
    .btn-modern:hover{ border-color:#304054; }
    .btn-primary-modern{ background: linear-gradient(90deg, var(--gold), var(--green)); color:#1a1a1a; border-color:rgba(255,255,255,.15); }
    .btn-primary-modern i{ color:#1a1a1a; }
    .btn-danger-modern{ background: linear-gradient(90deg, #f87171, #ef4444); color:#1a1a1a; border-color:rgba(255,255,255,.15); }
    .btn-danger-modern i{ color:#1a1a1a; }

    .status-line{
      margin-top:10px; padding:10px; border-radius:12px; border:1px solid var(--border);
      background:#0e131a; color:var(--muted);
      display:none; /* base: escondido */
    }
    .status-line.is-open{ display:block; }        /* <<< classe própria, sem conflito */
    .status-line.warn{
      border-color: rgba(246,195,67,.35);
      background: rgba(246,195,67,.10);
      color: #fef9c3;
    }

    .overlay{ position:absolute; inset:0; display:none; background:rgba(0,0,0,.45); backdrop-filter: blur(1px); align-items:center; justify-content:center; z-index:5; }
    .overlay.show{ display:flex; }
    .spinner{ display:flex; align-items:center; gap:10px; color:#fff; font-weight:700; background: rgba(0,0,0,.35); padding:10px 14px; border-radius:12px; border:1px solid rgba(255,255,255,.15); }

    .hide{ display:none !important; }

    .swatches{ display:flex; flex-wrap:wrap; gap:8px; }
    .sw{ width:32px; height:24px; border-radius:6px; border:1px solid var(--border); cursor:pointer; }
    .sw.active{ outline:2px solid #fff; outline-offset:2px; }

    /* Preview */
    .preview{ margin-top:10px; border:1px solid var(--border); border-radius:12px; overflow:hidden; }
    .preview-top{ padding:14px; font-weight:800; }
    .preview-bottom{ padding:14px; background:#ffffff !important; color:#222222 !important; }

    .logo-preview{ width:120px; height:40px; object-fit:contain; display:block; background:#0b0f14; border:1px dashed var(--border); border-radius:8px; }

    /* Fieldset sem borda */
    #fsStyle{ border: 0; padding: 0; margin: 0; min-inline-size: 0; }
    #fsStyle > legend{ display:none; }

    .btn-close{ filter: invert(1) grayscale(100%) brightness(200%); opacity:.85; }

    /* ===========================================================
       OPÇÃO B — FALLBACK SEM BOOTSTRAP CSS
       Garante que o modal e o backdrop fiquem escondidos por padrão
       e só apareçam quando a classe .show for aplicada via JS.
       =========================================================== */

    /* ===== Modal moderno (dark + gold) ===== */
  .modal-modern .modal-dialog{ max-width:520px; }
  .modal-modern .modal-content{
    position:relative;
    background: radial-gradient(1200px 300px at 120% -10%, rgba(246,195,67,.10), transparent 60%),
                linear-gradient(180deg, var(--card), #0e1319);
    border:1px solid rgba(255,255,255,.12);
    border-radius:18px;
    box-shadow: 0 26px 80px rgba(0,0,0,.65);
    color: var(--text);
    animation: modal-pop .18s ease-out;
    overflow:hidden;
  }
  @keyframes modal-pop {
    from{ transform:scale(.96); opacity:.6 }
    to  { transform:scale(1);   opacity:1 }
  }

  /* glow decorativo */
  .modal-modern .glow{
    position:absolute; inset:-30% -10% auto auto;
    width:340px; height:340px; pointer-events:none; opacity:.18;
    background: radial-gradient(closest-side, var(--gold), transparent 65%);
    filter: blur(40px);
  }

  /* header compacto com "hero" */
  .modal-modern .modal-body{ padding:20px 18px 14px; }
  .modal-modern .modal-head{
    display:flex; align-items:center; gap:12px; margin-bottom:10px;
  }
  .modal-modern .hero{
    width:64px; height:64px; border-radius:14px;
    display:flex; align-items:center; justify-content:center;
    background: linear-gradient(135deg, rgba(246,195,67,.20), rgba(239,68,68,.18));
    border:1px solid rgba(246,195,67,.35);
    box-shadow: 0 10px 30px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.06);
  }
  .modal-modern .hero i{ font-size:26px; color: var(--gold); }

  .modal-modern .title{
    font-weight:800; letter-spacing:.2px; font-size:1.08rem; line-height:1.15;
  }
  .modal-modern .subtitle{
    color: var(--muted); font-size:.95rem; margin-top:4px;
  }

  /* linha de “padrões” com swatches */
  .modal-modern .defaults{
    display:flex; align-items:center; gap:10px;
    margin:8px 0 2px;
  }
  .modal-modern .swatch{
    width:28px; height:20px; border-radius:6px; border:1px solid var(--border);
    background: var(--c);
  }

  /* bullets */
  .modal-modern .bullets{
    margin:6px 0 0; padding-left:18px; color:var(--muted); font-size:.92rem;
  }

  /* footer com botões */
  .modal-modern .modal-footer{
    border:0; padding:12px 18px 18px; display:flex; gap:10px; justify-content:flex-end;
  }
  .modal-modern .btn-ghost{
    background: transparent; color: var(--text); border:1px solid var(--border);
    border-radius:12px; padding:10px 14px; font-weight:700; display:inline-flex; gap:8px; align-items:center;
  }
  .modal-modern .btn-ghost:hover{ border-color:#304054; }
  .modal-modern .btn-danger-strong{
    background: linear-gradient(90deg, #f87171, #ef4444); color:#1a1a1a; 
    border:1px solid rgba(255,255,255,.15); border-radius:12px;
    padding:10px 14px; font-weight:800; display:inline-flex; gap:8px; align-items:center;
  }
  .modal-modern .btn-close{
    position:absolute; top:10px; right:10px;
    filter: invert(1) grayscale(100%) brightness(200%); opacity:.85;
  }


    .modal { 
      display: none;                 /* oculto por padrão */
      position: fixed !important;
      top: 0 !important; right: 0 !important; bottom: 0 !important; left: 0 !important;
      z-index: 20010 !important;
    }
    .modal.show { 
      display: block;                /* visível somente quando .show */
    }
    .modal-backdrop { 
      display: none;                 /* oculto por padrão */
    }
    .modal-backdrop.show {
      display: block;                /* visível somente quando .show */
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.45) !important;
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      z-index: 20000 !important;
    }

    /* Estética do conteúdo do modal (mantida) */
    .modal-dialog{ margin: 0 auto !important; max-width: 520px; }
    .modal-dialog-centered{
      display: flex !important;
      align-items: center !important;
      min-height: calc(100vh - 1rem * 2) !important; /* aproximação do var(--bs-modal-margin) */
    }
    .modal-content{
      background: linear-gradient(180deg, #12161c, #0e1319);
      border:1px solid rgba(255,255,255,.12);
      border-radius:16px;
      box-shadow: 0 18px 60px rgba(0,0,0,.55);
      color: #eaeef6;
    }
    .modal-header, .modal-footer{ border-color: rgba(255,255,255,.12); }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main id="main-content" class="main-wrapper">
      <h1 style="font-size:1.15rem"><i class="fa-solid fa-palette me-2"></i>Personalizar Estilo</h1>

      <section class="card-soft" id="card-style">
        <div class="card-title"><i class="fa-solid fa-sliders"></i>Definições de Estilo</div>
        <p class="muted" style="margin-top:-6px;margin-bottom:10px">
          A personalização de cores e logo é aplicada <strong>somente</strong> à sua organização vinculada.
        </p>

        <?php if (!$hasCompany): ?>
          <div class="status-line is-open warn" style="margin-bottom:10px">
            Seu usuário ainda não está vinculado a uma organização. <a href="/OKR_system/views/organizacao.php" style="color:#fff;text-decoration:underline">Cadastre/edite sua organização</a> para habilitar a personalização.
          </div>
        <?php endif; ?>

        <?php if ($hasCompany && !$hasValidCNPJ): ?>
          <div class="status-line is-open warn" id="cnpjGuard" style="margin-bottom:10px">
            Para <strong>personalizar o estilo</strong>, é necessário que sua organização possua um <strong>CNPJ válido</strong>.
            Vá em <a href="/OKR_system/views/organizacao.php" style="color:#fff;text-decoration:underline">Organização</a>, informe e valide o CNPJ. Depois retorne aqui.
          </div>
        <?php endif; ?>

        <form id="styleForm" autocomplete="off" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="id_company" id="id_company" value="<?= (int)$companyId ?>">

          <div class="form-row">
            <div class="form-group" style="grid-column: 1 / -1;">
              <label>Organização</label>
              <input class="form-control" type="text" value="<?= htmlspecialchars($companyName ?: '—', ENT_QUOTES, 'UTF-8') ?>" readonly>
              <small class="muted">A personalização é restrita à sua organização. Para alterar dados da empresa, acesse <a href="/OKR_system/views/organizacao.php" style="color:#eaeef6;text-decoration:underline">Organização</a>.</small>
            </div>
          </div>

          <!-- Fieldset desativado quando não houver CNPJ válido -->
          <fieldset id="fsStyle" <?= ($hasCompany && $hasValidCNPJ) ? '' : 'disabled' ?>>
            <div class="form-row">
              <div class="form-group">
                <label>Background 1 (escuro)</label>
                <div class="swatches" data-target="bg1_hex">
                  <?php
                    $dark = ['#222222','#0D3B66','#0F3D2E','#5C1A1B','#2B0A3D','#3D220F'];
                    foreach ($dark as $d) echo '<div class="sw" data-val="'.$d.'" style="background:'.$d.'"></div>';
                  ?>
                </div>
                <div style="margin-top:8px">
                  <input type="color" class="form-control" style="height:38px;padding:2px" id="bg1_picker" value="#222222">
                  <input type="hidden" name="bg1_hex" id="bg1_hex" value="#222222">
                </div>
              </div>
              <div class="form-group">
                <label>Background 2 (claro)</label>
                <div class="swatches" data-target="bg2_hex">
                  <?php
                    $light = ['#f1c40f','#ff9f1c','#ec4899','#3b82f6','#10b981','#00e5ff','#ffffff'];
                    foreach ($light as $l) echo '<div class="sw" data-val="'.$l.'" style="background:'.$l.'"></div>';
                  ?>
                </div>
                <div style="margin-top:8px">
                  <input type="color" class="form-control" style="height:38px;padding:2px" id="bg2_picker" value="#f1c40f">
                  <input type="hidden" name="bg2_hex" id="bg2_hex" value="#f1c40f">
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

            <!-- Preview: top bar (texto=bg2, fundo=bg1); conteúdo fixo branco com texto/dot #222 -->
            <div class="preview" id="themePreview" aria-label="Pré-visualização do tema">
              <div class="preview-top" id="pvTop">Título/Top bar</div>
              <div class="preview-bottom" id="pvBottom">
                <div style="display:flex;align-items:center;gap:10px;">
                  <div id="pvDot" style="width:12px;height:12px;border-radius:999px;background:#222222;"></div>
                  <span>Texto de exemplo sobre fundo claro</span>
                </div>
              </div>
            </div>

            <div class="actions">
              <button type="submit" class="btn-modern btn-primary-modern" id="btnSaveStyle">
                <i class="fa-solid fa-floppy-disk"></i> Salvar estilo
              </button>

              <!-- Reset para padrão -->
              <button type="button" class="btn-modern btn-danger-modern" id="btnResetStyle">
                <i class="fa-solid fa-rotate-left"></i> Resetar para padrão
              </button>
            </div>
            <small class="muted">O reset aplica as cores e a logo padrão.</small>
          </fieldset>

          <div id="statusStyle" class="status-line"></div>
          <div class="overlay" id="overlayStyle">
            <div class="spinner"><i class="fa-solid fa-rotate fa-spin"></i><span>Salvando estilo…</span></div>
          </div>
        </form>
      </section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <!-- Modal de confirmação de reset (visual moderno) -->
  <div class="modal fade modal-modern" id="confirmResetModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <span class="glow" aria-hidden="true"></span>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>

        <div class="modal-body">
          <div class="modal-head">
            <div class="hero"><i class="fa-solid fa-rotate-left"></i></div>
            <div>
              <div class="title">Resetar para o padrão?</div>
              <div class="subtitle">
                Esta ação aplica as <strong>cores padrão</strong> e a <strong>logo padrão</strong> da sua organização.
              </div>
            </div>
          </div>

          <div class="defaults">
            <span class="muted">Cores padrão:</span>
            <span class="swatch" style="--c:#222222" title="#222222"></span>
            <span class="swatch" style="--c:#f1c40f" title="#f1c40f"></span>
          </div>

          <ul class="bullets">
            <li>Atualiza sidebar, header e páginas com a paleta padrão.</li>
            <li>Substitui a logo personalizada, se houver.</li>
          </ul>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-ghost btn-modern" data-bs-dismiss="modal">
            <i class="fa-solid fa-xmark"></i> Cancelar
          </button>
          <button type="button" class="btn-danger-strong btn-modern" id="btnConfirmReset">
            <i class="fa-solid fa-rotate-left"></i> Resetar agora
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Apenas JS do Bootstrap (sem CSS). O fallback de exibição está no bloco <style> -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Flags do PHP
    const HAS_COMPANY = <?= $hasCompany ? 'true' : 'false' ?>;
    const HAS_VALID_CNPJ = <?= $hasValidCNPJ ? 'true' : 'false' ?>;
    const COMPANY_ID = <?= (int)$companyId ?>;

    // Status helpers
    const statusStyle = document.getElementById('statusStyle');
    const overlayStyle = document.getElementById('overlayStyle');

    const showStatus = (el, msg, type='info') => {
      el.classList.add('is-open');
      el.classList.toggle('warn', type === 'warn');
      el.style.borderColor = (type==='error') ? 'rgba(239,68,68,.35)' :
                              (type==='success') ? 'rgba(34,197,94,.35)' :
                              (type==='warn') ? 'rgba(246,195,67,.35)' : 'var(--border)';
      el.style.color = (type==='error') ? '#fecaca' :
                       (type==='success') ? '#dcfce7' :
                       (type==='warn') ? '#fef9c3' : 'var(--muted)';
      el.style.background = (type==='error') ? 'rgba(239,68,68,.12)' :
                            (type==='success') ? 'rgba(34,197,94,.12)' :
                            (type==='warn') ? 'rgba(246,195,67,.10)' : '#0e131a';
      el.innerHTML = msg;
    };
    const hideStatus = (el) => { el.classList.remove('is-open'); el.classList.remove('warn'); el.innerHTML=''; };

    const AUTOHIDE_MS = 3500;
    const flashStatus = (el, msg, type='info', timeout=AUTOHIDE_MS) => {
      showStatus(el, msg, type);
      if (timeout) setTimeout(() => { hideStatus(el); }, timeout);
    };

    // Personalização
    const formStyle   = document.getElementById('styleForm');
    const fsStyle     = document.getElementById('fsStyle');
    const bg1Hex = document.getElementById('bg1_hex'); // escuro
    const bg2Hex = document.getElementById('bg2_hex'); // claro
    const bg1Picker = document.getElementById('bg1_picker');
    const bg2Picker = document.getElementById('bg2_picker');
    const pvTop = document.getElementById('pvTop');
    const pvBottom = document.getElementById('pvBottom');
    const pvDot = document.getElementById('pvDot');
    const logoFile = document.getElementById('logo_file');
    const logoPreview = document.getElementById('logoPreview');

    // Preview
    function updatePreview(){
      pvTop.style.background = bg1Hex.value || '#222222';
      pvTop.style.color = bg2Hex.value || '#f1c40f';

      pvBottom.style.background = '#ffffff';
      pvBottom.style.color = '#222222';
      if (pvDot) pvDot.style.background = '#222222';
    }
    function bindSwatches(){
      document.querySelectorAll('.swatches').forEach(w=>{
        w.addEventListener('click', (ev)=>{
          const sw = ev.target.closest('.sw'); if(!sw || fsStyle.disabled) return;
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
    function clearActive(targetId){
      document.querySelectorAll(`.swatches[data-target="${targetId}"] .sw`).forEach(s=>s.classList.remove('active'));
    }
    bg1Picker.addEventListener('input', () => { if(fsStyle.disabled) return; bg1Hex.value = bg1Picker.value; clearActive('bg1_hex'); updatePreview(); });
    bg2Picker.addEventListener('input', () => { if (fsStyle.disabled) return; bg2Hex.value = bg2Picker.value; clearActive('bg2_hex'); updatePreview(); });

    // Logo preview
    logoFile.addEventListener('change', ()=>{
      const file = logoFile.files[0];
      if (!file) { logoPreview.removeAttribute('src'); return; }
      const reader = new FileReader();
      reader.onload = e => { logoPreview.src = e.target.result; };
      reader.readAsDataURL(file);
    });

    // Carrega estilo existente automaticamente (sem mensagem automática)
    async function loadExistingStyle(){
      if (!HAS_COMPANY) return;
      try {
        hideStatus(statusStyle);
        const r = await fetch(`/OKR_system/auth/get_company_style.php?id_company=${encodeURIComponent(COMPANY_ID)}`);
        const data = await r.json();
        if (!data.success) throw new Error(data.error||'Erro ao buscar estilo.');
        const rec = data.record;
        if (rec) {
          bg1Hex.value = rec.bg1_hex || '#222222';
          bg2Hex.value = rec.bg2_hex || '#f1c40f';
          bg1Picker.value = bg1Hex.value;
          bg2Picker.value = bg2Hex.value;
          if (rec.logo_base64) logoPreview.src = rec.logo_base64; else logoPreview.removeAttribute('src');
        } else {
          // padrões silenciosos
          bg1Hex.value = '#222222'; bg2Hex.value = '#f1c40f';
          bg1Picker.value = '#222222'; bg2Picker.value = '#f1c40f';
          logoPreview.removeAttribute('src');
        }
        updatePreview();
      } catch (e) {
        showStatus(statusStyle, '<strong>Erro:</strong> '+ (e.message || e), 'error');
      }
    }

    formStyle.addEventListener('submit', async (ev)=>{
      ev.preventDefault();
      hideStatus(statusStyle);

      if (!HAS_COMPANY) {
        showStatus(statusStyle, '<strong>Erro:</strong> seu usuário não está vinculado a uma organização.', 'error');
        return;
      }
      if (!HAS_VALID_CNPJ) {
        showStatus(statusStyle, '<strong>Erro:</strong> para salvar o estilo é obrigatório ter um CNPJ válido na organização.', 'error');
        return;
      }

      overlayStyle.classList.add('show');
      try {
        const fd = new FormData(formStyle);
        const resp = await fetch('/OKR_system/auth/salvar_company_style.php', {
          method: 'POST', body: fd, headers: { 'Accept':'application/json' }
        });
        const data = await resp.json();
        if (!resp.ok || !data.success) throw new Error(data.error || 'Falha ao salvar estilo.');
        flashStatus(statusStyle, 'Estilo salvo com sucesso!', 'success');
        await hardReload({ bg1: fd.get('bg1_hex'), bg2: fd.get('bg2_hex') });
      } catch (e) {
        showStatus(statusStyle, '<strong>Erro:</strong> ' + (e.message || e), 'error');
      } finally {
        overlayStyle.classList.remove('show');
      }
    });

    // ====== Reset para padrão ======
    const btnReset     = document.getElementById('btnResetStyle');
    const btnConfirm   = document.getElementById('btnConfirmReset');
    let resetModal     = null;

    window.addEventListener('DOMContentLoaded', () => {
      const modalEl = document.getElementById('confirmResetModal');
      if (modalEl) {
        resetModal = bootstrap.Modal.getOrCreateInstance(modalEl, {
          backdrop: 'static',
          keyboard: false,
          focus: true
        });
        // Garantia extra: nunca nascer "show"
        modalEl.classList.remove('show');
        document.querySelectorAll('.modal-backdrop').forEach(b=>b.remove());
      }
    });

    btnReset?.addEventListener('click', () => {
      hideStatus(statusStyle);
      if (!HAS_COMPANY) { showStatus(statusStyle, 'Seu usuário não está vinculado a uma organização.', 'error'); return; }
      if (!HAS_VALID_CNPJ) { showStatus(statusStyle, 'É obrigatório ter um CNPJ válido para personalizar/resetar o estilo.', 'error'); return; }
      resetModal?.show();
    });

    btnConfirm?.addEventListener('click', async () => {
      resetModal?.hide();
      const spinnerTextEl = overlayStyle.querySelector('.spinner span');
      const oldText = spinnerTextEl ? spinnerTextEl.textContent : '';
      if (spinnerTextEl) spinnerTextEl.textContent = 'Aplicando padrão…';
      overlayStyle.classList.add('show');

      try {
        const fd = new FormData();
        fd.append('csrf_token', '<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8") ?>');
        fd.append('id_company', COMPANY_ID);

        const resp = await fetch('/OKR_system/auth/reset_company_style.php', {
          method: 'POST',
          body: fd,
          headers: { 'Accept': 'application/json' }
        });
        const data = await resp.json();
        if (!resp.ok || !data.success) throw new Error(data.error || 'Falha ao resetar estilo.');

        const rec = data.record || {};
        bg1Hex.value = rec.bg1_hex || '#222222';
        bg2Hex.value = rec.bg2_hex || '#f1c40f';
        bg1Picker.value = bg1Hex.value;
        bg2Picker.value = bg2Hex.value;
        if (rec.logo_base64) logoPreview.src = rec.logo_base64; else logoPreview.removeAttribute('src');
        clearActive('bg1_hex'); clearActive('bg2_hex');
        updatePreview();

        flashStatus(statusStyle, 'Estilo restaurado para o padrão com sucesso.', 'success');
        await hardReload({ bg1: '#222222', bg2: '#f1c40f' });
      } catch (e) {
        showStatus(statusStyle, '<strong>Erro:</strong> ' + (e.message || e), 'error');
      } finally {
        overlayStyle.classList.remove('show');
        if (spinnerTextEl) spinnerTextEl.textContent = oldText || 'Salvando estilo…';
      }
    });

    // Init
    bindSwatches();
    updatePreview();
    loadExistingStyle();

    if (!HAS_VALID_CNPJ) {
      document.getElementById('fsStyle').disabled = true;
    }

    async function hardReload(expected){ 
  // 1) aguarda o backend refletir (poll em get_company_style)
  const sleep = ms => new Promise(r=>setTimeout(r, ms));
  if (expected) {
    for (let i=0; i<8; i++) { // até ~2.4s
      try {
        const r = await fetch(`/OKR_system/auth/get_company_style.php?id_company=${encodeURIComponent(COMPANY_ID)}&t=${Date.now()}`, { cache: 'no-store' });
        const d = await r.json();
        const rec = d?.record || {};
        if (d?.success && rec.bg1_hex?.toLowerCase() === expected.bg1.toLowerCase()
                      && rec.bg2_hex?.toLowerCase() === expected.bg2.toLowerCase()) {
          break;
        }
      } catch(_) {}
      await sleep(300);
    }
  }
  // 2) navega com cache-buster para forçar HTML/CSS/partials atualizados
  const url = new URL(window.location.href);
  url.searchParams.set('r', String(Date.now()));
  window.location.replace(url.toString());
}

  </script>
</body>
</html>
