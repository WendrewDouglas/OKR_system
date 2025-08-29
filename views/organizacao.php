<?php
// views/organizacao.php
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

// Carrega a empresa vinculada ao usuário logado
$userCompany = null;
$userCompanyId = 0;

try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );

  $st = $pdo->prepare("
    SELECT u.id_company,
           c.*
    FROM usuarios u
    LEFT JOIN company c ON c.id_company = u.id_company
    WHERE u.id_user = :uid
    LIMIT 1
  ");
  $st->execute([':uid' => $_SESSION['user_id']]);
  $row = $st->fetch();
  if ($row) {
    $userCompanyId = (int)($row['id_company'] ?? 0);
    // Se não houver registro em company (id_company null) criamos um placeholder de campos vazios
    $userCompany = $row['id_company'] ? $row : [
      'id_company' => null,
      'organizacao' => '',
      'cnpj' => null,
      'razao_social' => null,
      'natureza_juridica_code' => null,
      'natureza_juridica_desc' => null,
      'logradouro' => null,
      'numero' => null,
      'complemento' => null,
      'cep' => null,
      'bairro' => null,
      'municipio' => null,
      'uf' => null,
      'email' => null,
      'telefone' => null,
      'situacao_cadastral' => null,
      'data_situacao_cadastral' => null,
      'missao' => null,
      'visao' => null
    ];
  }
} catch (PDOException $e) {
  // em caso de erro, segue com valores padrão
}

$hasCompany = $userCompanyId > 0;
$hasCNPJ = $hasCompany && !empty($userCompany['cnpj']);

// Helper para escapar fácil
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Organização – OKR System</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

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

    .actions{ display:flex; gap:10px; margin-top:12px; }
    .btn-modern{
      display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:12px;
      font-weight:700; font-size:.9rem; border:1px solid var(--border); cursor:pointer; transition:.2s ease;
      background:#0e131a; color: var(--text);
    }
    .btn-modern:hover{ border-color:#304054; }
    .btn-primary-modern{ background: linear-gradient(90deg, var(--gold), var(--green)); color:#1a1a1a; border-color:rgba(255,255,255,.15); }
    .btn-primary-modern i{ color:#1a1a1a; }

    .status-line{
      margin-top:10px; padding:10px; border-radius:12px; border:1px solid var(--border);
      background:#0e131a; color:var(--muted); display:none;
    }
    .status-line.show{ display:block; }

    .overlay{ position:absolute; inset:0; display:none; background:rgba(0,0,0,.45); backdrop-filter: blur(1px); align-items:center; justify-content:center; z-index:5; }
    .overlay.show{ display:flex; }
    .spinner{ display:flex; align-items:center; gap:10px; color:#fff; font-weight:700; background: rgba(0,0,0,.35); padding:10px 14px; border-radius:12px; border:1px solid rgba(255,255,255,.15); }

    .readonly-grid{ display:grid; grid-template-columns: repeat(2, 1fr); gap:10px; }
    @media (max-width: 900px){ .readonly-grid{ grid-template-columns: 1fr; } }
    .ro-item{ background:#0e131a; border:1px solid var(--border); border-radius:12px; padding:10px; }
    .ro-label{ color:var(--muted); font-size:.8rem; display:block; margin-bottom:4px; }
    .ro-value{ color:var(--text); font-weight:800; font-size:.95rem; }

    .hide{ display:none !important; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main id="main-content" class="main-wrapper">
      <h1 style="font-size:1.15rem"><i class="fa-solid fa-building me-2"></i>Cadastro de Organização</h1>

      <!-- ===== CARD 1: Cadastro/Atualização da Organização (apenas da empresa do usuário) ===== -->
      <section class="card-soft" id="card-form">
        <div class="card-title"><i class="fa-solid fa-pen-to-square"></i><?= $hasCompany ? 'Editar organização' : 'Novo cadastro' ?></div>
        <p class="muted" style="margin-top:-6px;margin-bottom:10px">
          O <strong>Nome Fantasia</strong> é obrigatório. O <strong>CNPJ</strong> é opcional; se informado (ou substituído),
          validaremos na base da Receita e atualizaremos os dados oficiais.
        </p>

        <?php if (!$hasCompany): ?>
          <div class="status-line show" style="margin-bottom:10px">
            Seu usuário ainda não está vinculado a uma organização válida. Contate o administrador ou utilize o cadastro padrão (id_company=1).
          </div>
        <?php endif; ?>

        <form id="orgForm" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <?php if ($hasCompany): ?>
            <input type="hidden" name="id_company" value="<?= (int)$userCompanyId ?>">
          <?php endif; ?>

          <div class="form-row">
            <div class="form-group">
              <label for="organizacao">Organização (Nome Fantasia) *</label>
              <input class="form-control" type="text" id="organizacao" name="organizacao"
                     placeholder="Nome da Empresa" required
                     value="<?= h($userCompany['organizacao'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="cnpj"><?= $hasCNPJ ? 'CNPJ (substituir)' : 'CNPJ (opcional)' ?></label>
              <input class="form-control" type="text" id="cnpj" name="cnpj" maxlength="18"
                     placeholder="00.000.000/0000-00"
                     value="<?= h($userCompany['cnpj'] ?? '') ?>">
            </div>
          </div>

          <div class="actions">
            <button type="submit" class="btn-modern btn-primary-modern">
              <i class="fa-solid fa-floppy-disk"></i> Salvar organização
            </button>
            <button type="reset" class="btn-modern">
              <i class="fa-solid fa-eraser"></i> Limpar
            </button>
            <a href="/OKR_system/views/config_style.php" class="btn-modern" title="Ir para Personalização">
              <i class="fa-solid fa-palette"></i> Personalizar estilo
            </a>
          </div>

          <div id="status" class="status-line"></div>
          <div class="overlay" id="overlay">
            <div class="spinner" id="spinner"><i class="fa-solid fa-rotate fa-spin"></i><span><?= $hasCNPJ ? 'Validando/Atualizando…' : 'Validando CNPJ…' ?></span></div>
          </div>
        </form>

        <!-- Dados oficiais (somente leitura) -->
        <section class="card-soft <?= $hasCNPJ ? '' : 'hide' ?>" id="card-ro" style="margin-top:16px">
          <div class="card-title" style="margin-top:-6px"><i class="fa-solid fa-circle-info"></i>Dados oficiais (Receita Federal)</div>
          <div class="readonly-grid" id="roGrid">
            <?php if ($hasCNPJ):
              $fields = [
                ['Organização (Fantasia)', $userCompany['organizacao'] ?? '—'],
                ['CNPJ', $userCompany['cnpj'] ?? '—'],
                ['NOME EMPRESARIAL', $userCompany['razao_social'] ?? '—'],
                ['NAT. JURÍDICA (cód.)', $userCompany['natureza_juridica_code'] ?? '—'],
                ['NAT. JURÍDICA (descr.)', $userCompany['natureza_juridica_desc'] ?? '—'],
                ['LOGRADOURO', $userCompany['logradouro'] ?? '—'],
                ['NÚMERO', $userCompany['numero'] ?? '—'],
                ['COMPLEMENTO', $userCompany['complemento'] ?? '—'],
                ['CEP', $userCompany['cep'] ?? '—'],
                ['BAIRRO/DISTRITO', $userCompany['bairro'] ?? '—'],
                ['MUNICÍPIO', $userCompany['municipio'] ?? '—'],
                ['UF', $userCompany['uf'] ?? '—'],
                ['ENDEREÇO ELETRÔNICO', $userCompany['email'] ?? '—'],
                ['TELEFONE', $userCompany['telefone'] ?? '—'],
                ['SITUAÇÃO CADASTRAL', $userCompany['situacao_cadastral'] ?? '—'],
                ['DATA SITUAÇÃO CADASTRAL', $userCompany['data_situacao_cadastral'] ?? '—'],
              ];
              foreach ($fields as [$label,$val]): ?>
                <div class="ro-item">
                  <span class="ro-label"><?= h($label) ?></span>
                  <div class="ro-value"><?= h($val) ?></div>
                </div>
              <?php endforeach; endif; ?>
          </div>
        </section>
      </section>

      <!-- ===== CARD 2: Missão & Visão (sempre e somente da empresa do usuário) ===== -->
      <section class="card-soft" id="card-missao-visao" style="margin-top:16px">
        <div class="card-title"><i class="fa-solid fa-bullseye"></i>Missão & Visão</div>
        <p class="muted" style="margin-top:-6px;margin-bottom:10px">
          Defina a <strong>Missão</strong> e a <strong>Visão</strong> da sua organização.
        </p>

        <form id="mvForm" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="id_company" value="<?= (int)$userCompanyId ?>">

          <?php if (!$hasCompany): ?>
            <div class="status-line show" style="margin-bottom:10px">
              Vincule seu usuário a uma organização antes de editar Missão & Visão.
            </div>
          <?php endif; ?>

          <div class="form-row">
            <div class="form-group" style="grid-column: 1 / -1;">
              <label for="missao">Missão</label>
              <textarea class="form-control" id="missao" name="missao" rows="3"
                        placeholder="Descreva a missão da organização" <?= $hasCompany ? '' : 'disabled' ?>><?= h($userCompany['missao'] ?? '') ?></textarea>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group" style="grid-column: 1 / -1;">
              <label for="visao">Visão</label>
              <textarea class="form-control" id="visao" name="visao" rows="3"
                        placeholder="Descreva a visão da organização" <?= $hasCompany ? '' : 'disabled' ?>><?= h($userCompany['visao'] ?? '') ?></textarea>
            </div>
          </div>

          <div class="actions">
            <button type="submit" id="btnSaveMV" class="btn-modern btn-primary-modern" <?= $hasCompany ? '' : 'disabled' ?>>
              <i class="fa-solid fa-floppy-disk"></i> Salvar Missão & Visão
            </button>
          </div>
          <div id="statusMV" class="status-line"></div>
          <div class="overlay" id="overlayMV">
            <div class="spinner"><i class="fa-solid fa-rotate fa-spin"></i><span>Salvando…</span></div>
          </div>
        </form>
      </section>

      <?php include __DIR__ . '/partials/chat.php'; ?>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // ====== Cadastro/Atualização da Organização ======
    const form = document.getElementById('orgForm');
    const cnpjInput = document.getElementById('cnpj');
    const statusBox = document.getElementById('status');
    const overlay = document.getElementById('overlay');
    const spinner = document.getElementById('spinner');
    const roCard = document.getElementById('card-ro');
    const roGrid = document.getElementById('roGrid');

    // máscara CNPJ
    const maskCNPJ = (v) => {
      const d = (v||'').replace(/\D+/g,'').slice(0,14);
      let r = '';
      if (d.length <= 2) r = d;
      else if (d.length <= 5) r = d.replace(/(\d{2})(\d+)/, '$1.$2');
      else if (d.length <= 8) r = d.replace(/(\d{2})(\d{3})(\d+)/, '$1.$2.$3');
      else if (d.length <= 12) r = d.replace(/(\d{2})(\d{3})(\d{3})(\d+)/, '$1.$2.$3/$4');
      else r = d.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})/, '$1.$2.$3/$4-$5');
      return r;
    };
    cnpjInput && cnpjInput.addEventListener('input', (e)=>{ e.target.value = maskCNPJ(e.target.value); });

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

    // Render dados oficiais após salvar/atualizar
    function renderReadOnly(rec) {
      const fields = [
        ['Organização (Fantasia)', rec.organizacao || '—'],
        ['CNPJ', rec.cnpj || '—'],
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
          <div class="ro-value">${val || '—'}</div>
        </div>
      `).join('');
      roCard.classList.remove('hide');
    }

    form.addEventListener('submit', async (e)=>{
      e.preventDefault();
      hideStatusOrg();

      const fd = new FormData(form);
      const org = (fd.get('organizacao')||'').trim();
      const cnpj = (fd.get('cnpj')||'').replace(/\D+/g,'');

      if (!org) { showStatusOrg('<strong>Erro:</strong> Informe o Nome Fantasia.', 'error'); return; }
      overlay.classList.add('show');
      spinner.querySelector('span').textContent = cnpj ? 'Validando/Salvando…' : 'Salvando…';

      try {
        // IMPORTANTE: este endpoint deve aceitar id_company para UPDATE
        const resp = await fetch('/OKR_system/auth/salvar_company.php', {
          method:'POST', body: fd, headers:{ 'Accept':'application/json' }
        });
        const data = await resp.json();
        if (!resp.ok || !data.success) throw new Error(data.error || 'Falha ao salvar.');
        if (data.record) renderReadOnly(data.record);
        showStatusOrg('Organização salva com sucesso.', 'success');
      } catch (err) {
        showStatusOrg('<strong>Erro:</strong> ' + (err.message || err), 'error');
      } finally {
        overlay.classList.remove('show');
      }
    });

    // ====== Missão & Visão (sem seleção, usa id_company do usuário) ======
    const mvForm = document.getElementById('mvForm');
    const missaoEl = document.getElementById('missao');
    const visaoEl  = document.getElementById('visao');
    const btnSaveMV = document.getElementById('btnSaveMV');
    const statusMV = document.getElementById('statusMV');
    const overlayMV = document.getElementById('overlayMV');

    const showStatusMV = (msg, type='info') => {
      statusMV.classList.add('show');
      statusMV.style.borderColor = (type==='error') ? 'rgba(239,68,68,.35)' :
                                   (type==='success') ? 'rgba(34,197,94,.35)' : 'var(--border)';
      statusMV.style.color = (type==='error') ? '#fecaca' :
                             (type==='success') ? '#dcfce7' : 'var(--muted)';
      statusMV.style.background = (type==='error') ? 'rgba(239,68,68,.12)' :
                                  (type==='success') ? 'rgba(34,197,94,.12)' : '#0e131a';
      statusMV.innerHTML = msg;
    };
    const hideStatusMV = () => { statusMV.classList.remove('show'); statusMV.innerHTML=''; };

    mvForm.addEventListener('submit', async (ev)=>{
      ev.preventDefault();
      hideStatusMV();

      overlayMV.classList.add('show');
      try {
        const fd = new FormData(mvForm);
        const resp = await fetch('/OKR_system/auth/salvar_missao_visao.php', {
          method:'POST', body: fd, headers:{ 'Accept':'application/json' }
        });
        const data = await resp.json();
        if (!resp.ok || !data.success) throw new Error(data.error || 'Falha ao salvar.');
        showStatusMV('Missão & Visão salvas com sucesso!', 'success');
      } catch (e) {
        showStatusMV('<strong>Erro:</strong> '+(e.message||e), 'error');
      } finally {
        overlayMV.classList.remove('show');
      }
    });
  </script>
</body>
</html>
