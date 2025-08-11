<?php
// views/novo_objetivo.php

// DEV ONLY: exibe erros na tela (remova em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';

// Redireciona se não estiver logado
if (!isset($_SESSION['user_id'])) {
    header('Location: /OKR_system/views/login.php');
    exit;
}

// Conexão PDO
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die("Erro ao conectar: " . $e->getMessage());
}

// Gera token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Buscar usuários para campo Responsável(es)
$usersStmt = $pdo->query("SELECT id_user, primeiro_nome, ultimo_nome FROM usuarios ORDER BY primeiro_nome");
$users = $usersStmt->fetchAll();

// Buscar pilares BSC do domínio, respeitando ordem_pilar
$pilaresStmt = $pdo->query("SELECT id_pilar, descricao_exibicao FROM dom_pilar_bsc ORDER BY ordem_pilar");
$pilares = $pilaresStmt->fetchAll();

// Buscar tipos de objetivo do domínio
$tiposStmt = $pdo->query("SELECT id_tipo, descricao_exibicao FROM dom_tipo_objetivo ORDER BY descricao_exibicao");
$tipos = $tiposStmt->fetchAll();

// Buscar ciclos do domínio
$ciclosStmt = $pdo->query("
    SELECT id_ciclo, nome_ciclo, descricao 
    FROM dom_ciclos 
    ORDER BY id_ciclo
");
$ciclos = $ciclosStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cadastrar Objetivo – OKR System</title>
    <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
    <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
    <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css" crossorigin="anonymous"/>
    <style>
        /* (restante do CSS da página permanece) */
        .main-wrapper {
            display: flex;
            gap: 2%;
            margin: 2rem;
            align-items: flex-start;
        }
        .form-container {
            flex: 2;
            background: #fff;
            padding: 1rem;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        h1 { display:flex; align-items:center; font-size:1.75rem; margin-bottom:1.5rem; }
        .info-inline { margin-right:6px; color:#6c757d; cursor:pointer; }
        .form-group { margin-bottom:1rem; }
        label { display:flex; align-items:center; font-weight:500; margin-bottom:0.25rem; }
        input.form-control, select.form-control, textarea.form-control {
            width:100%; padding:0.5rem; border:1px solid #ccc; border-radius:4px;
        }
        .form-two-col { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        @media(max-width:600px) { .form-two-col { grid-template-columns:1fr; } }
        .btn-save { display:block; margin:2rem auto 0; width:140px; padding:0.5rem; }
        .multi-select-container { position:relative; }
        .chips-input { display:flex; flex-wrap:wrap; gap:4px; padding:4px; border:1px solid #ccc; border-radius:4px; }
        .chips-input-field { flex:1; border:none; outline:none; min-width:120px; }
        .chip { background:#e9ecef; border-radius:16px; padding:0 8px; display:flex; align-items:center; }
        .remove-chip { margin-left:4px; cursor:pointer; font-weight:bold; }
        .dropdown-list { position:absolute; top:calc(100% + 4px); left:0; width:100%; max-height:200px; overflow-y:auto; background:#fff; border:1px solid #ccc; border-radius:4px; z-index:1000; }
        .dropdown-list ul { list-style:none; margin:0; padding:0; }
        .dropdown-list li { padding:8px; cursor:pointer; }
        .dropdown-list li:hover { background:#f1f1f1; }
        .d-none { display:none; }
        .warning-text { color:#dc3545; font-size:0.875rem; margin-top:4px; }
        .ciclo-row { display:flex; align-items:flex-end; gap:1rem; flex-wrap:nowrap; }
        .ciclo-row > .col { flex:1; }
        .ciclo-row .detalhe.d-flex > .form-control { flex: 1; width: auto; }

        /* ========= NOVO: estilo da experiência "IA" (igual ao novo_key_result) ========= */
        .overlay { position:fixed; top:0; left:0; width:100%; height:100%;
          background:rgba(2,6,23,0.68); display:flex; align-items:center; justify-content:center; z-index:2000; }
        .overlay.d-none { display:none !important; }

        .ai-card {
          width: min(720px, 92vw);
          background: #0b1020;
          color: #e6e9f2;
          border-radius: 18px;
          box-shadow: 0 20px 60px rgba(0,0,0,.35);
          padding: 18px 18px 16px;
          position: relative;
          overflow: hidden;
        }
        .ai-card::after {
          content: "";
          position: absolute; inset: 0;
          background: radial-gradient(1000px 300px at 10% -20%, rgba(64,140,255,.18), transparent 60%),
                      radial-gradient(700px 220px at 100% 0%, rgba(0,196,204,.12), transparent 60%);
          pointer-events: none;
        }
        .ai-header { display:flex; align-items:center; gap:12px; margin-bottom:10px; }
        .ai-avatar {
          width: 44px; height: 44px; flex:0 0 44px;
          border-radius: 50%;
          background: conic-gradient(from 180deg at 50% 50%, #3b82f6, #06b6d4, #8b5cf6, #3b82f6);
          display:grid; place-items:center;
          box-shadow: 0 6px 18px rgba(59,130,246,.35);
          color:#fff; font-weight:700; letter-spacing:.5px;
        }
        .ai-title { font-size:.95rem; line-height:1.1; opacity:.9; }
        .ai-subtle { font-size:.85rem; opacity:.65; }
        .ai-bubble {
          background:#111833; border:1px solid rgba(255,255,255,.06);
          border-radius:14px; padding:16px; margin:8px 0 14px;
        }
        .score-row { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:6px; }
        .score-pill {
          font-weight:700; font-size:2.25rem; line-height:1;
          padding:6px 14px; border-radius:12px;
          background: linear-gradient(135deg, rgba(59,130,246,.16), rgba(2,132,199,.12));
          border:1px solid rgba(255,255,255,.08);
        }
        .quality-badge {
          padding:4px 10px; border-radius:999px; font-size:.8rem;
          border:1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.06);
          text-transform:capitalize;
        }
        .quality-badge.q-pessimo { background: rgba(239,68,68,.15); border-color: rgba(239,68,68,.25); }
        .quality-badge.q-ruim    { background: rgba(245,158,11,.15); border-color: rgba(245,158,11,.25); }
        .quality-badge.q-moderado{ background: rgba(14,165,233,.15); border-color: rgba(14,165,233,.25); }
        .quality-badge.q-bom     { background: rgba(34,197,94,.15); border-color: rgba(34,197,94,.25); }
        .quality-badge.q-otimo   { background: rgba(168,85,247,.16); border-color: rgba(168,85,247,.25); }

        .ai-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:6px; }
        .ai-actions .btn-lg { border-radius:12px; padding:.7rem 1rem; }
        .btn-ghost { background:transparent; border:1px solid rgba(255,255,255,.16); color:#d1d5db; }
        .btn-ghost:hover { background: rgba(255,255,255,.06); color:#fff; }

        .ai-success { display:flex; align-items:flex-start; gap:12px; }
        .ai-success .ai-bubble { margin-top:0; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/partials/sidebar.php'; ?>
    <div class="content">
        <?php include __DIR__ . '/../views/partials/header.php'; ?>
        <main id="main-content" class="main-wrapper">
            <!-- Formulário -->
            <div class="form-container">
                <h1><i class="fas fa-bullseye info-inline"></i>Cadastrar Novo Objetivo</h1>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger"><ul>
                        <?php foreach($errors as $e): ?>
                            <li><?=htmlspecialchars($e)?></li>
                        <?php endforeach; ?>
                    </ul></div>
                <?php endif; ?>

                <form id="objectiveForm" action="/OKR_system/auth/salvar_objetivo.php" method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">

                    <!-- Nome -->
                    <div class="form-group">
                        <label for="nome_objetivo">
                            <i class="fas fa-info-circle info-inline" data-bs-toggle="tooltip" title="Informe um título descritivo para o objetivo."></i>
                            Nome do Objetivo<span class="text-danger">*</span>
                        </label>
                        <input type="text" id="nome_objetivo" name="nome_objetivo" class="form-control" required>
                    </div>

                    <!-- Tipo e Pilar -->
                    <div class="form-two-col">
                        <div class="form-group">
                            <label for="tipo_objetivo">Tipo de Objetivo<span class="text-danger">*</span></label>
                            <select id="tipo_objetivo" name="tipo_objetivo" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($tipos as $t): ?>
                                    <option value="<?=htmlspecialchars($t['id_tipo'])?>"><?=htmlspecialchars($t['descricao_exibicao'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="pilar_bsc">Pilar BSC<span class="text-danger">*</span></label>
                            <select id="pilar_bsc" name="pilar_bsc" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($pilares as $p): ?>
                                    <option value="<?=htmlspecialchars($p['id_pilar'])?>"><?=htmlspecialchars($p['descricao_exibicao'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group ciclo-row">
                        <!-- Coluna Ciclo -->
                        <div class="col">
                            <label for="ciclo_tipo">Ciclo<span class="text-danger">*</span></label>
                            <select id="ciclo_tipo" name="ciclo_tipo" class="form-control" required>
                                <?php foreach ($ciclos as $c): ?>
                                <option 
                                    value="<?= htmlspecialchars($c['nome_ciclo']) ?>" 
                                    <?= $c['nome_ciclo'] === 'trimestral' ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($c['descricao']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Coluna Detalhe Ciclo -->
                        <div id="ciclo_detalhe_wrapper" class="col">
                            <div id="ciclo_detalhe_anual" class="detalhe d-none">
                            <select id="ciclo_anual_ano" name="ciclo_anual_ano" class="form-control"></select>
                            </div>
                            <div id="ciclo_detalhe_semestral" class="detalhe d-none">
                            <select id="ciclo_semestral" name="ciclo_semestral" class="form-control"></select>
                            </div>
                            <div id="ciclo_detalhe_trimestral" class="detalhe">
                            <select id="ciclo_trimestral" name="ciclo_trimestral" class="form-control"></select>
                            </div>
                            <div id="ciclo_detalhe_bimestral" class="detalhe d-none">
                            <select id="ciclo_bimestral" name="ciclo_bimestral" class="form-control"></select>
                            </div>
                            <div id="ciclo_detalhe_mensal" class="detalhe d-none d-flex">
                            <select id="ciclo_mensal_mes" name="ciclo_mensal_mes" class="form-control"></select>
                            <select id="ciclo_mensal_ano" name="ciclo_mensal_ano" class="form-control"></select>
                            </div>
                            <div id="ciclo_detalhe_personalizado" class="detalhe d-none d-flex">
                            <input type="month" id="ciclo_pers_inicio" name="ciclo_pers_inicio" class="form-control">
                            <input type="month" id="ciclo_pers_fim" name="ciclo_pers_fim" class="form-control">
                            </div>
                        </div>
                    </div>

                    <!-- Responsáveis -->
                    <div class="form-group">
                        <label>
                            <i class="fas fa-info-circle info-inline" data-bs-toggle="tooltip" title="Selecione o(s) responsável(eis) pelo objetivo."></i>
                            Responsável(es)<span class="text-danger">*</span>
                        </label>
                        <div class="multi-select-container">
                            <div class="chips-input" id="responsavel_container">
                                <input type="text" id="responsavel_input" class="form-control chips-input-field" placeholder="Clique para selecionar...">
                            </div>
                            <div class="dropdown-list d-none" id="responsavel_list">
                                <ul>
                                    <?php foreach($users as $u): ?>
                                        <li data-id="<?=$u['id_user']?>"><?=htmlspecialchars($u['primeiro_nome'].' '.$u['ultimo_nome'])?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <input type="hidden" id="responsavel" name="responsavel">
                        <small id="responsavel_warning" class="warning-text d-none">
                            ⚠️ Ao ter um único responsável por cada OKR, evitam-se ambiguidades e garante-se foco na execução e no acompanhamento dos resultados.
                        </small>
                    </div>

                    <!-- Observações -->
                    <div class="form-group">
                        <label for="observacoes">
                            <i class="fas fa-info-circle info-inline" data-bs-toggle="tooltip" title="Campo opcional para comentários adicionais."></i>
                            Observações
                        </label>
                        <textarea id="observacoes" name="observacoes" class="form-control" rows="4"></textarea>
                    </div>

                    <input type="hidden" id="qualidade" name="qualidade" value="">
                    <input type="hidden" id="justificativa_ia" name="justificativa_ia" value="">

                    <button type="submit" class="btn btn-primary btn-save">Salvar Objetivo</button>
                </form>
            </div>

            <!-- Chat -->
            <?php include __DIR__ . '/../views/partials/chat.php'; ?>

        </main>
    </div>

    <!-- ========= NOVO: Overlays no estilo IA ========= -->
    <div id="loadingOverlay" class="overlay d-none">
        <div class="ai-card" role="dialog" aria-live="polite">
          <div class="ai-header">
            <div class="ai-avatar">IA</div>
            <div>
              <div class="ai-title">Analisando seu objetivo…</div>
              <div class="ai-subtle">Calculando nota e justificativa.</div>
            </div>
          </div>
          <div class="ai-bubble" style="display:flex;align-items:center;gap:10px;">
            <i class="fas fa-spinner fa-spin"></i>
            <span>Aguarde só um instante…</span>
          </div>
        </div>
    </div>

    <div id="evaluationOverlay" class="overlay d-none">
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
          <button id="editObjective" class="btn btn-ghost btn-lg">
            <i class="fa-regular fa-pen-to-square me-1"></i>Editar
          </button>
          <button id="saveObjective" class="btn btn-primary btn-lg">
            <i class="fa-regular fa-floppy-disk me-1"></i>Continuar e Salvar
          </button>
        </div>
      </div>
    </div>

    <div id="saveMessageOverlay" class="overlay d-none">
      <div class="ai-card" role="alertdialog" aria-modal="true">
        <div class="ai-header">
          <div class="ai-avatar">IA</div>
          <div>
            <div class="ai-title">Tudo certo! ✅</div>
            <div class="ai-subtle">Seu objetivo foi salvo.</div>
          </div>
        </div>

        <div class="ai-bubble ai-success">
          <div id="saveAiMessage" class="ai-subtle" style="font-size:1rem; opacity:.9">
            Objetivo salvo com sucesso. Vou submetê-lo à aprovação e você receberá uma notificação assim que houver feedback do aprovador.
          </div>
        </div>

        <div class="ai-actions">
          <button id="closeSaveMessage" class="btn btn-primary btn-lg">Fechar</button>
        </div>
      </div>
    </div>
    <!-- ========= Fim dos Overlays ========= -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// 1) Silencia só o erro da extensão Chrome
window.addEventListener('unhandledrejection', function(event) {
  const msg = event.reason && event.reason.message;
  if (msg && msg.includes('A listener indicated an asynchronous response')) {
    event.preventDefault();
  }
});

document.addEventListener('DOMContentLoaded', () => {
  // ====== CICLO: mostrar/ocultar detalhe ======
  const tipo = document.getElementById('ciclo_tipo');
  const containers = {
    anual: document.getElementById('ciclo_detalhe_anual'),
    semestral: document.getElementById('ciclo_detalhe_semestral'),
    trimestral: document.getElementById('ciclo_detalhe_trimestral'),
    bimestral: document.getElementById('ciclo_detalhe_bimestral'),
    mensal: document.getElementById('ciclo_detalhe_mensal'),
    personalizado: document.getElementById('ciclo_detalhe_personalizado')
  };
  function toggleDetail() {
    Object.keys(containers).forEach(key => {
      containers[key].classList.toggle('d-none', tipo.value !== key);
    });
  }
  tipo.addEventListener('change', toggleDetail);
  toggleDetail();

  // ====== POPULAÇÕES DINÂMICAS ======
  const now = new Date(), year = now.getFullYear();
  // Anual
  const selAno = containers.anual.querySelector('select');
  for (let y = year; y <= year + 5; y++) selAno.add(new Option(y, y));

  // Semestral
  const selSem = containers.semestral.querySelector('select');
  for (let y = year; y <= year + 5; y++) {
    selSem.add(new Option(`1º Sem/${y}`, `S1/${y}`));
    selSem.add(new Option(`2º Sem/${y}`, `S2/${y}`));
  }

  // Trimestral
  const selTri = containers.trimestral.querySelector('select');
  ['Q1','Q2','Q3','Q4'].forEach(q => {
    for (let y = year; y <= year + 5; y++) {
      selTri.add(new Option(`${q}/${y}`, `${q}/${y}`));
    }
  });

  // Bimestral (Jan–Fev … Nov–Dez, 24 meses)
  const selBi = containers.bimestral.querySelector('select');
  for (let i = 0; i < 23; i++) {
    const d1 = new Date(year, i), d2 = new Date(year, i+1);
    const m1 = d1.toLocaleString('pt-BR',{month:'short'}),
          m2 = d2.toLocaleString('pt-BR',{month:'short'}),
          label1 = m1[0].toUpperCase()+m1.slice(1),
          label2 = m2[0].toUpperCase()+m2.slice(1),
          y1 = d1.getFullYear();
    selBi.add(new Option(
      `${label1}–${label2}/${y1}`,
      `${String(d1.getMonth()+1).padStart(2,'0')}-${String(d2.getMonth()+1).padStart(2,'0')}-${y1}`
    ));
  }

  // Mensal
  const meses = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
  const selMes = containers.mensal.querySelector('#ciclo_mensal_mes'),
        selAnoM = containers.mensal.querySelector('#ciclo_mensal_ano');
  meses.forEach((m,i) => selMes.add(new Option(m, String(i+1).padStart(2,'0'))));
  for (let y = year; y <= year + 5; y++) selAnoM.add(new Option(y, y));

  // ====== MULTI-SELECT RESPONSÁVEL ======
  const inputResp   = document.getElementById('responsavel_input'),
        listCont    = document.getElementById('responsavel_list'),
        containerCh = document.getElementById('responsavel_container'),
        hiddenResp  = document.getElementById('responsavel'),
        warning     = document.getElementById('responsavel_warning');

  inputResp.addEventListener('focus', () => listCont.classList.remove('d-none'));
  document.addEventListener('click', e => {
    if (!containerCh.contains(e.target) && !listCont.contains(e.target)) {
      listCont.classList.add('d-none');
    }
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
      const rem  = document.createElement('span');
      chip.className = 'chip';
      chip.textContent = text;
      rem.className = 'remove-chip';
      rem.innerHTML = '&times;';
      rem.onclick   = () => { chip.remove(); updateHidden(); inputResp.style.display = 'block'; };
      chip.appendChild(rem);
      containerCh.insertBefore(chip, inputResp);
      inputResp.value = '';
      updateHidden();
      inputResp.style.display = 'none';
    });
  });
  function updateHidden(){
    const ids = Array.from(containerCh.querySelectorAll('.chip')).map(ch => {
      const name = ch.firstChild.textContent;
      const li   = Array.from(listCont.querySelectorAll('li')).find(l => l.textContent === name);
      return li ? li.dataset.id : null;
    }).filter(Boolean);
    hiddenResp.value = ids.join(',');
    warning.classList.toggle('d-none', ids.length <= 1);
  }

  // ====== TOOLTIP ======
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el =>
    new bootstrap.Tooltip(el)
  );

  // ====== SUBMIT & IA ======
  const form      = document.getElementById('objectiveForm'),
        loading   = document.getElementById('loadingOverlay'),
        evalOvr   = document.getElementById('evaluationOverlay'),
        scoreBox  = document.querySelector('.score-value'),
        resultBox = document.getElementById('evaluationResult'),
        successO  = document.getElementById('saveMessageOverlay'),
        qualityBadge = document.getElementById('qualityBadge');

  // Mapeia nota -> qualidade (só front; opcional)
  function scoreToQuality(score) {
    if (score <= 2) return { id: 'péssimo', cls: 'q-pessimo', label: 'Péssimo' };
    if (score <= 4) return { id: 'ruim',    cls: 'q-ruim',    label: 'Ruim' };
    if (score <= 6) return { id: 'moderado',cls: 'q-moderado',label: 'Moderado' };
    if (score <= 8) return { id: 'bom',     cls: 'q-bom',     label: 'Bom' };
    return { id: 'ótimo',   cls: 'q-otimo',  label: 'Ótimo' };
  }

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(form);
    fd.append('evaluate','1');
    loading.classList.remove('d-none');

    try {
      const res  = await fetch(form.action, { method:'POST', body:fd });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();

      // guarda justificativa no hidden
      document.getElementById('justificativa_ia').value = data.justification ?? '';

      loading.classList.add('d-none');
      if (data.score == null || data.justification == null) {
        throw new Error('Resposta IA inválida');
      }

      // Preenche UI IA
      scoreBox.textContent  = data.score;
      resultBox.textContent = data.justification;

      // Badge de qualidade (opcional, mas bonito)
      const q = scoreToQuality(Number(data.score));
      qualityBadge.textContent = q.label;
      qualityBadge.className = 'quality-badge ' + q.cls;

      // (se quiser enviar para o backend via hidden já existente)
      const qualHidden = document.getElementById('qualidade');
      if (qualHidden) qualHidden.value = q.id;

      evalOvr.classList.remove('d-none');

      document.getElementById('saveObjective').onclick = async () => {
        evalOvr.classList.add('d-none');
        loading.classList.remove('d-none');
        const fd2 = new FormData(form);
        fd2.delete('evaluate');
        fd2.set('justificativa_ia', document.getElementById('justificativa_ia').value);
        if (qualHidden) fd2.set('qualidade', qualHidden.value);

        try {
          const res2 = await fetch(form.action, { method:'POST', body:fd2 });
          const ret  = await res2.json();
          loading.classList.add('d-none');
          if (ret.success) {
            const el = document.getElementById('saveAiMessage');
            if (el) {
              const objId = ret.id_objetivo ? `<strong>${ret.id_objetivo}</strong>` : 'Seu objetivo';
              el.innerHTML = `${objId} foi salvo com sucesso.<br>Vou submetê-lo à aprovação e te aviso assim que houver feedback do aprovador.`;
            }
            successO.classList.remove('d-none');
          } else {
            alert('Falha ao salvar o objetivo.');
          }
        } catch(err2) {
          console.error('Erro ao salvar:', err2);
          loading.classList.add('d-none');
          alert('Erro de rede ao salvar objetivo.');
        }
      };

      document.getElementById('editObjective').onclick = () => {
        evalOvr.classList.add('d-none');
      };

    } catch(err) {
      console.error('Fluxo IA erro:', err);
      loading.classList.add('d-none');
      alert('Erro ao avaliar o objetivo. Tente novamente.');
    }
  });

  document.getElementById('closeSaveMessage').onclick = () => {
    successO.classList.add('d-none');
    window.location.href = '/OKR_system/views/novo_objetivo.php';
  };

}); // fim DOMContentLoaded
</script>
</body>
</html>
