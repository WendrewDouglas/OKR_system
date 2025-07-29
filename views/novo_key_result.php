<?php
// views/novo_key_result.php

// DEV ONLY: exibe erros na tela (remova em produção)
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

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
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

// Buscar todos os objetivos (id + descrição + status_aprovacao)
$objetivosStmt = $pdo->prepare("SELECT id_objetivo, descricao, status_aprovacao FROM objetivos ORDER BY dt_prazo ASC");
$objetivosStmt->execute();
$objetivos = $objetivosStmt->fetchAll();

// Buscar tipos de KR do domínio
$tiposKrStmt = $pdo->query("SELECT id_tipo, descricao_exibicao FROM dom_tipo_kr ORDER BY descricao_exibicao");
$tiposKr     = $tiposKrStmt->fetchAll();

// Buscar naturezas de KR do domínio
$natStmt     = $pdo->query("SELECT id_natureza, descricao_exibicao FROM dom_natureza_kr ORDER BY descricao_exibicao");
$naturezasKr = $natStmt->fetchAll();

// Buscar usuários para campo Responsável do KR
$usersStmt = $pdo->query("SELECT id_user, primeiro_nome, ultimo_nome FROM usuarios ORDER BY primeiro_nome");
$users     = $usersStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cadastrar Novo Key Result – OKR System</title>
  <link rel="stylesheet" href="/OKR_system/assets/css/base.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
  <link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css"
        crossorigin="anonymous"/>
  <style>
    /* Mesma estilização usada em novo_objetivo.php */
    .main-wrapper { display: flex; gap: 2%; margin: 2rem; align-items: flex-start; }
    .form-container {
      flex: 2; background: #fff; padding: 1rem;
      border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    h1 { display:flex; align-items:center; font-size:1.75rem; margin-bottom:1.5rem; }
    .info-inline { margin-right:6px; color:#6c757d; cursor:pointer; }
    .form-group { margin-bottom:1rem; }
    label { display:flex; align-items:center; font-weight:500; margin-bottom:0.25rem; }
    input.form-control, select.form-control, textarea.form-control {
      width:100%; padding:0.5rem; border:1px solid #ccc; border-radius:4px;
    }
    .form-two-col { display:grid; grid-template-columns:2fr 1fr; gap:1rem; }
    .form-four-col { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; }
    @media(max-width:800px) { .form-four-col { grid-template-columns:1fr 1fr; } }
    @media(max-width:600px) {
      .form-two-col { grid-template-columns:1fr; }
      .form-four-col { grid-template-columns:1fr; }
    }
    .btn-save { display:block; margin:2rem auto 0; width:160px; padding:0.5rem; }
    /* Força texto em caixa alta */
    #id_objetivo, #id_objetivo option, #status_objetivo { text-transform: uppercase; }
    .kr-details-box {
      background: #f8f9fa;
      border: 1px solid #e9ecef;
      border-radius: 6px;
      padding: 1rem;
      margin-top: 1.5rem;
    }
    .kr-details-box h2 {
      font-size: 1.25rem;
      margin-bottom: 1rem;
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../views/partials/sidebar.php'; ?>
  <div class="content">
    <?php include __DIR__ . '/../views/partials/header.php'; ?>
    <main id="main-content" class="main-wrapper">
      <div class="form-container">
        <h1><i class="fas fa-bullseye info-inline"></i>Cadastrar Novo Key Result</h1>

        <form id="krForm" action="/OKR_system/auth/salvar_kr.php" method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">

          <!-- Objetivo associado + status -->
          <div class="form-two-col">
            <div class="form-group">
              <label for="id_objetivo">
                <i class="fas fa-info-circle info-inline" data-bs-toggle="tooltip" title="Selecione o objetivo ao qual este Key Result está vinculado."></i>
                Objetivo Associado<span class="text-danger">*</span>
              </label>
              <select id="id_objetivo" name="id_objetivo" class="form-control" required>
                <option value="">Selecione...</option>
                <?php foreach ($objetivos as $o): ?>
                  <option value="<?=htmlspecialchars($o['id_objetivo'])?>" data-status="<?=htmlspecialchars($o['status_aprovacao'])?>">
                    <?=htmlspecialchars($o['descricao'])?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="status_objetivo">Status do Objetivo</label>
              <input type="text" id="status_objetivo" class="form-control" readonly>
            </div>
          </div>

          <!-- Caixa de detalhes do KR -->
          <div class="kr-details-box">
            <h2>Detalhes do Key Result</h2>

            <!-- Descrição -->
            <div class="form-group">
              <label for="descricao_kr">Descrição do Key Result<span class="text-danger">*</span></label>
              <textarea id="descricao_kr" name="descricao" class="form-control" rows="3" required></textarea>
            </div>

            <!-- Baseline, Meta, Unidade e Direção em uma linha -->
            <div class="form-four-col">
              <div class="form-group">
                <label for="baseline">Baseline<span class="text-danger">*</span></label>
                <input type="number" step="0.01" id="baseline" name="baseline" class="form-control" required>
              </div>
              <div class="form-group">
                <label for="meta">Meta<span class="text-danger">*</span></label>
                <input type="number" step="0.01" id="meta" name="meta" class="form-control" required>
              </div>
              <div class="form-group">
                <label for="unidade_medida">Unidade de Medida</label>
                <input type="text" id="unidade_medida" name="unidade_medida" class="form-control">
              </div>
              <div class="form-group">
                <label for="direcao_metrica">Direção da Métrica</label>
                <select id="direcao_metrica" name="direcao_metrica" class="form-control">
                  <option value="">Selecione...</option>
                  <option value="MAIOR_MELHOR">Maior Melhor</option>
                  <option value="MENOR_MELHOR">Menor Melhor</option>
                  <option value="INTERVALO_IDEAL">Intervalo Ideal</option>
                </select>
              </div>
            </div>

            <!-- Tipo e Natureza -->
            <div class="form-two-col">
              <div class="form-group">
                <label for="tipo_kr">Tipo de KR</label>
                <select id="tipo_kr" name="tipo_kr" class="form-control">
                  <option value="">Selecione...</option>
                  <?php foreach($tiposKr as $t): ?>
                  <option value="<?=htmlspecialchars($t['id_tipo'])?>"><?=htmlspecialchars($t['descricao_exibicao'])?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label for="natureza_kr">Natureza do KR</label>
                <select id="natureza_kr" name="natureza_kr" class="form-control">
                  <option value="">Selecione...</option>
                  <?php foreach($naturezasKr as $n): ?>
                  <option value="<?=htmlspecialchars($n['id_natureza'])?>"><?=htmlspecialchars($n['descricao_exibicao'])?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- Datas -->
            <div class="form-two-col">
              <div class="form-group">
                <label for="data_inicio">Data de Início</label>
                <input type="date" id="data_inicio" name="data_inicio" class="form-control">
              </div>
              <div class="form-group">
                <label for="data_fim">Data de Fim</label>
                <input type="date" id="data_fim" name="data_fim" class="form-control">
              </div>
            </div>

            <!-- Responsável -->
            <div class="form-group">
              <label for="responsavel_kr">Responsável pelo KR</label>
              <select id="responsavel_kr" name="responsavel" class="form-control">
                <option value="">Selecione...</option>
                <?php foreach($users as $u): ?>
                <option value="<?=htmlspecialchars($u['id_user'])?>"><?=htmlspecialchars($u['primeiro_nome'].' '.$u['ultimo_nome'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-save">Salvar Key Result</button>
        </form>
      </div>
      <?php include __DIR__ . '/../views/partials/chat.php'; ?>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Inicializa tooltips do Bootstrap
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

    // Atualiza campo de status ao selecionar um objetivo
    document.getElementById('id_objetivo').addEventListener('change', function() {
      const statusField = document.getElementById('status_objetivo');
      let status = this.options[this.selectedIndex].getAttribute('data-status') || '';
      statusField.value = status.toUpperCase();
    });

    // Preenche inicialmente se já houver valor selecionado
    window.addEventListener('DOMContentLoaded', () => {
      const sel = document.getElementById('id_objetivo');
      if (sel.value) sel.dispatchEvent(new Event('change'));
    });
  </script>
</body>
</html>
