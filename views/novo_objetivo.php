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
        /* (todo o CSS permanece igual, sem alterações) */
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
        .overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            display: flex; align-items: center; justify-content: center;
            z-index: 2000;
        }
        .overlay.d-none {
            display: none !important;
        }
        .overlay-content {
            background: #fff;
            padding: 2rem;
            border-radius: 6px;
            text-align: center;
            max-width: 90%;
        }
        .evaluation-actions { margin-top: 1.5rem; display: flex; justify-content: center; gap: 1rem; }
        .overlay-content.card {
        max-width: 500px;
        border-radius: 1rem;
        background-color: #fff;
        }
        .score-box {
        background: rgba(13,110,253,0.1);
        border-radius: 0.5rem;
        padding: 1rem 0;
        }
        .justification {
        font-size: 1rem;
        line-height: 1.5;
        }
        .evaluation-actions .btn {
        transition: background-color .2s, transform .2s;
        }
        .evaluation-actions .btn:hover {
        transform: translateY(-2px);
        }
        #saveMessageOverlay .overlay-content.card {
        max-width: 500px;
        border-radius: 1rem;
        background-color: #fff;
        }
        .ciclo-row {
        display: flex;
        align-items: flex-end;
        gap: 1rem;
        flex-wrap: nowrap;
        }
        .ciclo-row > .col {
        flex: 1;
        }
        .ciclo-row .detalhe.d-flex > .form-control {
        flex: 1;
        width: auto;
        }
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

                    <!-- (CAMPO DE PRAZO FINAL FOI REMOVIDO AQUI) -->

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

    <!-- Overlays para carregamento e IA -->
    <div id="loadingOverlay" class="overlay d-none">
        <div class="overlay-content">
            <i class="fas fa-spinner fa-spin fa-2x"></i>
            <p>Carregando objetivo...</p>
        </div>
    </div>

    <div id="evaluationOverlay" class="overlay d-none">
    <div class="overlay-content card p-4 shadow-lg">
        <h2 class="mb-4 text-center">Considerações sobre o objetivo</h2>

        <div class="score-box text-center mb-4">
        <span class="score-value display-1 fw-bold text-primary"></span>
        </div>

        <div id="evaluationResult" class="justification text-muted mb-4"></div>

        <div class="evaluation-actions d-flex justify-content-center gap-3">
        <button id="saveObjective" class="btn btn-primary btn-lg rounded-pill px-4">
            <i class="fas fa-check me-2"></i> Continuar e Salvar
        </button>
        <button id="editObjective" class="btn btn-outline-secondary btn-lg rounded-pill px-4">
            <i class="fas fa-pen me-2"></i>Editar
        </button>
        </div>
    </div>
    </div>

    <div id="saveMessageOverlay" class="overlay d-none">
    <div class="overlay-content card p-4 shadow-lg text-center">
        <p class="mb-3">
        Objetivo salvo! Aguarde aprovação do objetivo.<br>
        Enquanto isso poderá consultá-lo em Meus OKRs.
        </p>
        <button id="closeSaveMessage" class="btn btn-primary btn-lg rounded-pill px-4">
        <i class="fas fa-check me-2"></i> OK
        </button>
    </div>
    </div>

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
        successO  = document.getElementById('saveMessageOverlay');

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(form);
    fd.append('evaluate','1');
    loading.classList.remove('d-none');

    try {
      const res  = await fetch(form.action, { method:'POST', body:fd });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      console.log('IA →', data);

      // ──────────────── AQUI ────────────────
      // preenche o campo hidden com a justificativa
      document.getElementById('justificativa_ia').value = data.justification;
      console.log('Hidden justification:', document.getElementById('justificativa_ia').value);
      // ───────────────────────────────────────

      loading.classList.add('d-none');
      if (data.score == null || data.justification == null) {
        throw new Error('Resposta IA inválida');
      }
      scoreBox.textContent  = data.score;
      resultBox.textContent = data.justification;
      evalOvr.classList.remove('d-none');

      document.getElementById('saveObjective').onclick = async () => {
        evalOvr.classList.add('d-none');
        loading.classList.remove('d-none');
        const fd2 = new FormData(form);
        fd2.delete('evaluate');
        // garante que o hidden está incluso
        fd2.set('justificativa_ia', document.getElementById('justificativa_ia').value);
        // debug: liste tudo
        for (let [k,v] of fd2.entries()) console.log('fd2:', k, '=', v);

        try {
          const res2 = await fetch(form.action, { method:'POST', body:fd2 });
          const ret  = await res2.json();
          loading.classList.add('d-none');
          if (ret.success) successO.classList.remove('d-none');
          else            alert('Falha ao salvar o objetivo.');
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