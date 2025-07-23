<?php
// views/objetivos_create.php

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

// Data mínima para seleção de data futura
$minDate = date('Y-m-d', strtotime('+1 day'));
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
        /* Container Flex para o form */
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
        /* Estilos Gerais */
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
        /* Multi-select chips */
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
        /* Overlays para AI e carregamento */
        .overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            display: flex; align-items: center; justify-content: center;
            z-index: 2000;
        }
        /* garante que .d-none oculte a overlay mesmo com .overlay */
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
        /* Card da avaliação */
        .overlay-content.card {
        max-width: 500px;
        border-radius: 1rem;
        background-color: #fff;
        }

        /* Caixa da nota */
        .score-box {
        background: rgba(13,110,253,0.1);
        border-radius: 0.5rem;
        padding: 1rem 0;
        }

        /* Justificativa */
        .justification {
        font-size: 1rem;
        line-height: 1.5;
        }

        /* Ações (botões) */
        .evaluation-actions .btn {
        transition: background-color .2s, transform .2s;
        }
        .evaluation-actions .btn:hover {
        transform: translateY(-2px);
        }
        /* Dentro do seu <style> existente */
        #saveMessageOverlay .overlay-content.card {
        max-width: 500px;
        border-radius: 1rem;
        background-color: #fff;
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

                <form id="objectiveForm" action="/OKR_system/controllers/objetivos_salvar.php" method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">

                    <!-- Nome -->
                    <div class="form-group">
                        <label for="nome_objetivo">
                            <i class="fas fa-info-circle info-inline" data-bs-toggle="tooltip" title="Informe um título descritivo para o objetivo."></i>
                            Nome do Objetivo<span class="text-danger">*</span>
                        </label>
                        <input type="text" id="nome_objetivo" name="nome_objetivo" class="form-control" required>
                    </div>

                    <!-- Ano e Tipo -->
                    <div class="form-two-col">
                        <div class="form-group">
                            <label for="ano_referencia">
                                <i class="fas fa-info-circle info-inline" data-bs-toggle="tooltip" title="Selecione o ano ao qual o objetivo se refere."></i>
                                Ano de Referência<span class="text-danger">*</span>
                            </label>
                            <input type="number" id="ano_referencia" name="ano_referencia" class="form-control" min="2000" max="2100" required>
                        </div>
                        <div class="form-group">
                            <label for="tipo_objetivo">
                                <i class="fas fa-info-circle info-inline" data-bs-toggle="tooltip" title="Escolha se o objetivo é estratégico, operacional ou tático."></i>
                                Tipo de Objetivo<span class="text-danger">*</span>
                            </label>
                            <select id="tipo_objetivo" name="tipo_objetivo" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($tipos as $t): ?>
                                    <option value="<?=htmlspecialchars($t['id_tipo'])?>">
                                        <?=htmlspecialchars($t['descricao_exibicao'])?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Pilar e Prazo -->
                    <div class="form-two-col">
                        <div class="form-group">
                            <label for="pilar_bsc">
                                <i class="fas fa-info-circle info-inline" data-bs-toggle="tooltip" title="Selecione o pilar do Balanced Scorecard correspondente."></i>
                                Pilar BSC<span class="text-danger">*</span>
                            </label>
                            <select id="pilar_bsc" name="pilar_bsc" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($pilares as $p): ?>
                                    <option value="<?=htmlspecialchars($p['id_pilar'])?>">
                                        <?=htmlspecialchars($p['descricao_exibicao'])?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="prazo_final">
                                <i class="fas fa-info-circle info-inline" data-bs-toggle="tooltip" title="Defina a data limite para conclusão do objetivo (futura)."></i>
                                Prazo Final<span class="text-danger">*</span>
                            </label>
                            <input type="date" id="prazo_final" name="prazo_final" class="form-control" min="<?=$minDate?>" required>
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

        <!-- Nova área de destaque da nota -->
        <div class="score-box text-center mb-4">
        <span class="score-value display-1 fw-bold text-primary"></span>
        </div>

        <!-- Justificativa -->
        <div id="evaluationResult" class="justification text-muted mb-4"></div>

        <div class="evaluation-actions d-flex justify-content-center gap-3">
        <!-- Botões mais modernos -->
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
        // Multi-select chips logic
        const inputResp = document.getElementById('responsavel_input');
        const listContainer = document.getElementById('responsavel_list');
        const containerChips = document.getElementById('responsavel_container');
        const hiddenResp = document.getElementById('responsavel');
        const warning = document.getElementById('responsavel_warning');

        inputResp.addEventListener('focus', () => listContainer.classList.remove('d-none'));
        inputResp.addEventListener('click', () => listContainer.classList.remove('d-none'));
        document.addEventListener('click', e => {
            if (!containerChips.contains(e.target) && !listContainer.contains(e.target)) {
                listContainer.classList.add('d-none');
            }
        });

        inputResp.addEventListener('input', () => {
            const filter = inputResp.value.toLowerCase();
            listContainer.querySelectorAll('li').forEach(li => {
                li.style.display = li.textContent.toLowerCase().includes(filter) ? '' : 'none';
            });
        });

        listContainer.querySelectorAll('li').forEach(li => {
            li.addEventListener('click', () => {
                const text = li.textContent;
                const chip = document.createElement('span');
                chip.className = 'chip';
                chip.textContent = text;
                const removeBtn = document.createElement('span');
                removeBtn.className = 'remove-chip';
                removeBtn.innerHTML = '&times;';
                removeBtn.addEventListener('click', () => {
                    chip.remove();
                    updateHidden();
                    inputResp.style.display = 'block';
                });
                chip.appendChild(removeBtn);
                containerChips.insertBefore(chip, inputResp);
                inputResp.value = '';
                updateHidden();
                inputResp.style.display = 'none';
            });
        });

        function updateHidden() {
            const chips = Array.from(containerChips.querySelectorAll('.chip'));
            const ids = chips.map(ch => {
                const name = ch.firstChild.textContent;
                const li = Array.from(listContainer.querySelectorAll('li')).find(l => l.textContent === name);
                return li ? li.dataset.id : null;
            }).filter(Boolean);
            hiddenResp.value = ids.join(',');
            if (ids.length > 1) warning.classList.remove('d-none');
            else warning.classList.add('d-none');
        }

        // Tooltips
        const tooltipTriggerList = [...document.querySelectorAll('[data-bs-toggle="tooltip"]')];
        tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));

        // AI evaluation on submit
        const form = document.getElementById('objectiveForm');
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(form);
            formData.append('evaluate', '1');

            // exibe tela de carregamento
            document.getElementById('loadingOverlay').classList.remove('d-none');

            try {
                const res = await fetch('/OKR_system/auth/salvar_objetivo.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json(); // espera { score: number, justification: string }

                // oculta carregamento, mostra avaliação
                document.getElementById('loadingOverlay').classList.add('d-none');
                const evalDiv = document.getElementById('evaluationResult');
                document.querySelector('.score-value').textContent = data.score;
                document.getElementById('evaluationResult').textContent = data.justification;
                document.getElementById('evaluationOverlay').classList.remove('d-none');

                // salvar após avaliação
                document.getElementById('saveObjective').onclick = async () => {
                    document.getElementById('evaluationOverlay').classList.add('d-none');
                    document.getElementById('loadingOverlay').classList.remove('d-none');
                    formData.set('evaluate', '0');
                    await fetch('/OKR_system/auth/salvar_objetivo.php', {
                        method: 'POST',
                        body: formData
                    });
                    document.getElementById('loadingOverlay').classList.add('d-none');
                    document.getElementById('saveMessageOverlay').classList.remove('d-none');
                };

                // voltar à edição
                document.getElementById('editObjective').onclick = () => {
                    document.getElementById('evaluationOverlay').classList.add('d-none');
                };

            } catch (err) {
                console.error(err);
                document.getElementById('loadingOverlay').classList.add('d-none');
                alert('Erro ao avaliar o objetivo. Tente novamente.');
            }
        });
        document.getElementById('closeSaveMessage').addEventListener('click', () => {
        // esconde o overlay
        document.getElementById('saveMessageOverlay').classList.add('d-none');
        // opcional: se quiser recarregar ou redirecionar de volta
        // window.location.href = '/OKR_system/views/objetivos_create.php';
        });

    </script>
</body>
</html>
