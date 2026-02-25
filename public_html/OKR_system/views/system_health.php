<?php
/**
 * System Health Dashboard — OKR System
 * Acesso restrito a admin_master.
 * Exibe health checks do sistema e resultados de testes PHPUnit.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/acl.php';

// Gate automático via dom_paginas
gate_page_by_path($_SERVER['SCRIPT_NAME'] ?? '');

// Redirect se não logado
if (!isset($_SESSION['user_id'])) {
    header('Location: /OKR_system/views/login.php');
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Token do health check (para AJAX dos testes)
$healthToken = (string)env('HEALTH_CHECK_TOKEN', '');

// --- Executar health checks inline (rápido, ~200ms) ---
$checks  = [];
$overall = 'PASS';

try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    $pdo->query('SELECT 1');
    $checks['db_alive'] = ['status' => 'PASS', 'label' => 'Conexão com BD'];
} catch (Throwable $e) {
    $checks['db_alive'] = ['status' => 'FAIL', 'label' => 'Conexão com BD', 'detail' => $e->getMessage()];
}

if (isset($pdo)) {
    // Core tables
    $coreTables = [
        'usuarios', 'company', 'objetivos', 'key_results', 'milestones_kr',
        'iniciativas', 'rbac_roles', 'rbac_capabilities', 'rbac_role_capability',
        'rbac_user_role', 'rbac_user_capability', 'dom_paginas', 'dom_status_kr',
    ];
    $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $missing  = array_diff($coreTables, $existing);
    $checks['core_tables'] = empty($missing)
        ? ['status' => 'PASS', 'label' => 'Tabelas Core', 'detail' => count($coreTables) . ' tabelas OK']
        : ['status' => 'FAIL', 'label' => 'Tabelas Core', 'detail' => 'Faltando: ' . implode(', ', $missing)];

    // Collation
    $collStmt = $pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND DATA_TYPE IN ('varchar','text','longtext','mediumtext','tinytext','char')
          AND COLLATION_NAME IS NOT NULL
          AND COLLATION_NAME != 'utf8mb4_unicode_ci'
    ");
    $badCollCount = (int)$collStmt->fetchColumn();
    $checks['collation'] = $badCollCount === 0
        ? ['status' => 'PASS', 'label' => 'Collation UTF8MB4']
        : ['status' => 'WARN', 'label' => 'Collation UTF8MB4', 'detail' => $badCollCount . ' colunas com collation diferente'];

    // Orphan KRs
    $orphanKrs = (int)$pdo->query("
        SELECT COUNT(*) FROM key_results k
        LEFT JOIN objetivos o ON o.id_objetivo = k.id_objetivo
        WHERE o.id_objetivo IS NULL
    ")->fetchColumn();
    $checks['orphan_krs'] = $orphanKrs === 0
        ? ['status' => 'PASS', 'label' => 'KRs Órfãos']
        : ['status' => 'WARN', 'label' => 'KRs Órfãos', 'detail' => $orphanKrs . ' encontrados'];

    // Orphan iniciativas
    $orphanIni = (int)$pdo->query("
        SELECT COUNT(*) FROM iniciativas i
        LEFT JOIN key_results k ON k.id_kr = i.id_kr
        WHERE k.id_kr IS NULL
    ")->fetchColumn();
    $checks['orphan_iniciativas'] = $orphanIni === 0
        ? ['status' => 'PASS', 'label' => 'Iniciativas Órfãs']
        : ['status' => 'WARN', 'label' => 'Iniciativas Órfãs', 'detail' => $orphanIni . ' encontradas'];

    // Orphan milestones
    $orphanMs = (int)$pdo->query("
        SELECT COUNT(*) FROM milestones_kr m
        LEFT JOIN key_results k ON k.id_kr = m.id_kr
        WHERE k.id_kr IS NULL
    ")->fetchColumn();
    $checks['orphan_milestones'] = $orphanMs === 0
        ? ['status' => 'PASS', 'label' => 'Milestones Órfãos']
        : ['status' => 'WARN', 'label' => 'Milestones Órfãos', 'detail' => $orphanMs . ' encontrados'];

    // Users without role
    $noRole = (int)$pdo->query("
        SELECT COUNT(*) FROM usuarios u
        LEFT JOIN rbac_user_role ur ON ur.user_id = u.id_user
        WHERE ur.user_id IS NULL
    ")->fetchColumn();
    $checks['users_without_role'] = $noRole === 0
        ? ['status' => 'PASS', 'label' => 'Usuários sem Papel']
        : ['status' => 'WARN', 'label' => 'Usuários sem Papel', 'detail' => $noRole . ' sem role RBAC'];
}

// PHP version
$phpVer = PHP_VERSION;
$checks['php_version'] = version_compare($phpVer, '8.1.0', '>=')
    ? ['status' => 'PASS', 'label' => 'Versão PHP', 'detail' => $phpVer]
    : ['status' => 'WARN', 'label' => 'Versão PHP', 'detail' => $phpVer . ' (recomendado >= 8.1)'];

// Disk space
$freeBytes = @disk_free_space(dirname(__DIR__));
if ($freeBytes !== false) {
    $freeMb = round($freeBytes / 1048576, 1);
    $checks['disk_space'] = $freeMb > 100
        ? ['status' => 'PASS', 'label' => 'Espaço em Disco', 'detail' => number_format($freeMb, 0, ',', '.') . ' MB livres']
        : ['status' => 'WARN', 'label' => 'Espaço em Disco', 'detail' => number_format($freeMb, 0, ',', '.') . ' MB livres'];
} else {
    $checks['disk_space'] = ['status' => 'WARN', 'label' => 'Espaço em Disco', 'detail' => 'Indisponível'];
}

// Overall
foreach ($checks as $c) {
    if ($c['status'] === 'FAIL') { $overall = 'FAIL'; break; }
    if ($c['status'] === 'WARN') { $overall = 'WARN'; }
}

$passCount = count(array_filter($checks, fn($c) => $c['status'] === 'PASS'));
$warnCount = count(array_filter($checks, fn($c) => $c['status'] === 'WARN'));
$failCount = count(array_filter($checks, fn($c) => $c['status'] === 'FAIL'));
$totalChecks = count($checks);

// Verifica se PHPUnit está instalado
$phpunitInstalled = is_file(dirname(__DIR__) . '/vendor/bin/phpunit');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>System Health – OKR System</title>

<link rel="stylesheet" href="/OKR_system/assets/css/base.css">
<link rel="stylesheet" href="/OKR_system/assets/css/components.css">
<link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
<link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

<style>
.main-wrapper {
  padding: 2rem 2rem 2rem 1.5rem;
  margin-right: var(--chat-w, 0);
  transition: margin-right .25s ease;
}
@media (max-width: 991px) { .main-wrapper { padding: 1rem; } }

.sh-page-title {
  font-size: 1.5rem;
  font-weight: 800;
  color: var(--gold, #F1C40F);
  margin-bottom: 0.25rem;
}
.sh-page-subtitle {
  font-size: 0.85rem;
  color: var(--text-secondary, #aaa);
  margin-bottom: 1.5rem;
}

/* Overall banner */
.sh-overall {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem 1.5rem;
  border-radius: 12px;
  margin-bottom: 1.5rem;
  font-weight: 700;
  font-size: 1.1rem;
}
.sh-overall.pass { background: rgba(39,174,96,0.15); color: #27ae60; border: 1px solid rgba(39,174,96,0.3); }
.sh-overall.warn { background: rgba(241,196,15,0.15); color: #f1c40f; border: 1px solid rgba(241,196,15,0.3); }
.sh-overall.fail { background: rgba(231,76,60,0.15); color: #e74c3c; border: 1px solid rgba(231,76,60,0.3); }
.sh-overall i { font-size: 1.5rem; }
.sh-overall-counts {
  margin-left: auto;
  font-size: 0.8rem;
  font-weight: 400;
  display: flex;
  gap: 1rem;
}
.sh-overall-counts span { opacity: 0.9; }

/* Cards grid */
.sh-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 1rem;
  margin-bottom: 2rem;
}
.sh-card {
  background: var(--card, #1a1f2b);
  border: 1px solid var(--border, #2a2f3b);
  border-radius: 12px;
  padding: 1rem 1.25rem;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  transition: border-color 0.2s;
}
.sh-card:hover { border-color: var(--gold, #F1C40F); }
.sh-card-icon {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.9rem;
  flex-shrink: 0;
}
.sh-card-icon.pass { background: rgba(39,174,96,0.15); color: #27ae60; }
.sh-card-icon.warn { background: rgba(241,196,15,0.15); color: #f1c40f; }
.sh-card-icon.fail { background: rgba(231,76,60,0.15); color: #e74c3c; }
.sh-card-label { font-weight: 600; font-size: 0.85rem; color: var(--text, #eee); }
.sh-card-detail { font-size: 0.75rem; color: var(--text-secondary, #aaa); margin-top: 2px; }

/* Section headers */
.sh-section {
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--text, #eee);
  margin: 1.5rem 0 0.75rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.sh-section i { color: var(--gold, #F1C40F); font-size: 0.9rem; }

/* Test runner */
.sh-test-panel {
  background: var(--card, #1a1f2b);
  border: 1px solid var(--border, #2a2f3b);
  border-radius: 12px;
  padding: 1.25rem;
  margin-bottom: 1rem;
}
.sh-test-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-bottom: 1rem;
}
.sh-btn-group { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.sh-btn {
  padding: 0.5rem 1rem;
  border: 1px solid var(--border, #2a2f3b);
  border-radius: 8px;
  background: var(--card, #1a1f2b);
  color: var(--text, #eee);
  font-size: 0.8rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
.sh-btn:hover { border-color: var(--gold, #F1C40F); color: var(--gold, #F1C40F); }
.sh-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.sh-btn.running { border-color: var(--gold, #F1C40F); color: var(--gold, #F1C40F); }
.sh-btn.running i { animation: spin 1s linear infinite; }

.sh-test-output {
  background: #0d1117;
  border: 1px solid var(--border, #2a2f3b);
  border-radius: 8px;
  padding: 1rem;
  font-family: 'Courier New', monospace;
  font-size: 0.78rem;
  line-height: 1.6;
  color: #c9d1d9;
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 500px;
  overflow-y: auto;
  min-height: 80px;
}
.sh-test-output .line-ok { color: #27ae60; }
.sh-test-output .line-fail { color: #e74c3c; }
.sh-test-output .line-skip { color: #f1c40f; }
.sh-test-output .line-header { color: #58a6ff; font-weight: 700; }

/* Test result summary bar */
.sh-test-summary {
  display: flex;
  gap: 1rem;
  padding: 0.75rem 1rem;
  border-radius: 8px;
  margin-bottom: 0.75rem;
  font-size: 0.8rem;
  font-weight: 600;
}
.sh-test-summary.pass { background: rgba(39,174,96,0.1); border: 1px solid rgba(39,174,96,0.2); }
.sh-test-summary.fail { background: rgba(231,76,60,0.1); border: 1px solid rgba(231,76,60,0.2); }
.sh-test-summary.pending { background: rgba(255,255,255,0.03); border: 1px solid var(--border, #2a2f3b); }
.sh-test-stat { display: flex; align-items: center; gap: 0.35rem; }
.sh-test-stat.ok { color: #27ae60; }
.sh-test-stat.fail { color: #e74c3c; }
.sh-test-stat.skip { color: #f1c40f; }
.sh-test-stat.total { color: var(--text-secondary, #aaa); }

/* Timestamp */
.sh-timestamp {
  font-size: 0.75rem;
  color: var(--text-secondary, #aaa);
  margin-top: 1.5rem;
  text-align: right;
}

@keyframes spin { 100% { transform: rotate(360deg); } }

/* Cron info */
.sh-info-box {
  background: rgba(88,166,255,0.08);
  border: 1px solid rgba(88,166,255,0.2);
  border-radius: 8px;
  padding: 0.75rem 1rem;
  font-size: 0.8rem;
  color: #8bb9e0;
  margin-bottom: 1rem;
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
}
.sh-info-box i { margin-top: 2px; flex-shrink: 0; }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<div class="content">
  <?php include __DIR__ . '/partials/header.php'; ?>

  <main class="main-wrapper">
    <h1 class="sh-page-title">System Health</h1>
    <p class="sh-page-subtitle">Monitoramento do sistema e resultados de testes automatizados</p>

    <!-- Overall status -->
    <div class="sh-overall <?= strtolower($overall) ?>">
      <i class="fas <?= $overall === 'PASS' ? 'fa-check-circle' : ($overall === 'FAIL' ? 'fa-times-circle' : 'fa-exclamation-triangle') ?>"></i>
      <span>Status Geral: <?= $overall ?></span>
      <div class="sh-overall-counts">
        <span><i class="fas fa-check-circle" style="color:#27ae60"></i> <?= $passCount ?> OK</span>
        <?php if ($warnCount > 0): ?>
        <span><i class="fas fa-exclamation-triangle" style="color:#f1c40f"></i> <?= $warnCount ?> Avisos</span>
        <?php endif; ?>
        <?php if ($failCount > 0): ?>
        <span><i class="fas fa-times-circle" style="color:#e74c3c"></i> <?= $failCount ?> Falhas</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Health checks grid -->
    <div class="sh-section"><i class="fas fa-heartbeat"></i> Health Checks</div>
    <div class="sh-grid">
      <?php foreach ($checks as $key => $check): ?>
      <div class="sh-card">
        <div class="sh-card-icon <?= strtolower($check['status']) ?>">
          <i class="fas <?= $check['status'] === 'PASS' ? 'fa-check' : ($check['status'] === 'FAIL' ? 'fa-times' : 'fa-exclamation') ?>"></i>
        </div>
        <div>
          <div class="sh-card-label"><?= htmlspecialchars($check['label'] ?? $key) ?></div>
          <?php if (!empty($check['detail'])): ?>
          <div class="sh-card-detail"><?= htmlspecialchars($check['detail']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- PHPUnit tests section -->
    <div class="sh-section"><i class="fas fa-flask"></i> Testes Automatizados</div>

    <?php if (!$phpunitInstalled): ?>
    <div class="sh-info-box">
      <i class="fas fa-info-circle"></i>
      <span>PHPUnit não instalado localmente. Os testes rodam no servidor via <code>run_tests.php</code>.</span>
    </div>
    <?php endif; ?>

    <div class="sh-info-box">
      <i class="fas fa-clock"></i>
      <span>Os testes rodam no servidor de produção. Unit tests levam ~5s, Smoke tests ~10s.</span>
    </div>

    <!-- Unit tests -->
    <div class="sh-test-panel">
      <div class="sh-test-header">
        <span style="font-weight:600; font-size:0.9rem; color:var(--text,#eee)">
          <i class="fas fa-code" style="color:var(--gold,#F1C40F);margin-right:0.4rem"></i>Unit Tests
        </span>
        <div class="sh-btn-group">
          <button class="sh-btn" onclick="runTests('unit')" id="btn-unit">
            <i class="fas fa-play"></i> Executar
          </button>
        </div>
      </div>
      <div id="summary-unit" class="sh-test-summary pending">
        <span class="sh-test-stat total"><i class="fas fa-circle"></i> Aguardando execução...</span>
      </div>
      <div class="sh-test-output" id="output-unit">Clique em "Executar" para rodar os testes unitários.</div>
    </div>

    <!-- Smoke tests -->
    <div class="sh-test-panel">
      <div class="sh-test-header">
        <span style="font-weight:600; font-size:0.9rem; color:var(--text,#eee)">
          <i class="fas fa-cloud" style="color:var(--gold,#F1C40F);margin-right:0.4rem"></i>Smoke Tests
        </span>
        <div class="sh-btn-group">
          <button class="sh-btn" onclick="runTests('smoke')" id="btn-smoke">
            <i class="fas fa-play"></i> Executar
          </button>
        </div>
      </div>
      <div id="summary-smoke" class="sh-test-summary pending">
        <span class="sh-test-stat total"><i class="fas fa-circle"></i> Aguardando execução...</span>
      </div>
      <div class="sh-test-output" id="output-smoke">Clique em "Executar" para rodar os smoke tests (DB + API).</div>
    </div>

    <div class="sh-timestamp">
      Verificação em <?= date('d/m/Y H:i:s') ?>
    </div>
  </main>

  <?php include __DIR__ . '/partials/chat.php'; ?>
</div>

<script>
const HC_TOKEN = <?= json_encode($healthToken) ?>;

function getBaseUrl() {
  // Detecta se estamos em produção ou local
  const host = window.location.hostname;
  return window.location.origin + '/OKR_system';
}

function runTests(suite) {
  const btn = document.getElementById('btn-' + suite);
  const output = document.getElementById('output-' + suite);
  const summary = document.getElementById('summary-' + suite);

  // Estado loading
  btn.disabled = true;
  btn.classList.add('running');
  btn.innerHTML = '<i class="fas fa-spinner"></i> Executando...';
  output.textContent = 'Executando testes ' + suite + '...\nIsso pode levar alguns segundos.\n';
  summary.className = 'sh-test-summary pending';
  summary.innerHTML = '<span class="sh-test-stat total"><i class="fas fa-spinner fa-spin"></i> Executando...</span>';

  const url = getBaseUrl() + '/tools/run_tests.php?token=' + encodeURIComponent(HC_TOKEN) + '&suite=' + suite;

  fetch(url)
    .then(r => r.text())
    .then(text => {
      output.innerHTML = highlightOutput(text);
      parseSummary(text, summary);
      output.scrollTop = output.scrollHeight;
    })
    .catch(err => {
      output.textContent = 'ERRO: ' + err.message;
      summary.className = 'sh-test-summary fail';
      summary.innerHTML = '<span class="sh-test-stat fail"><i class="fas fa-times-circle"></i> Erro de conexão</span>';
    })
    .finally(() => {
      btn.disabled = false;
      btn.classList.remove('running');
      btn.innerHTML = '<i class="fas fa-play"></i> Executar';
    });
}

function highlightOutput(text) {
  return text.split('\n').map(line => {
    if (/^===/.test(line) || /^PHPUnit/.test(line) || /^Time:/.test(line)) {
      return '<span class="line-header">' + esc(line) + '</span>';
    }
    if (/OK \(/.test(line) || /^\.+$/.test(line.trim())) {
      return '<span class="line-ok">' + esc(line) + '</span>';
    }
    if (/FAILURES!|ERRORS!|FAIL/i.test(line)) {
      return '<span class="line-fail">' + esc(line) + '</span>';
    }
    if (/skipped|incomplete|risky/i.test(line)) {
      return '<span class="line-skip">' + esc(line) + '</span>';
    }
    return esc(line);
  }).join('\n');
}

function parseSummary(text, el) {
  // Tenta capturar "OK (119 tests, 278 assertions)"
  let m = text.match(/OK \((\d+) tests?, (\d+) assertions?\)/);
  if (m) {
    el.className = 'sh-test-summary pass';
    el.innerHTML =
      '<span class="sh-test-stat ok"><i class="fas fa-check-circle"></i> ' + m[1] + ' testes passaram</span>' +
      '<span class="sh-test-stat total"><i class="fas fa-list"></i> ' + m[2] + ' assertions</span>';
    return;
  }

  // "Tests: X, Assertions: Y, Failures: Z, Skipped: W"
  m = text.match(/Tests:\s*(\d+).*Assertions:\s*(\d+)/);
  if (m) {
    const tests = m[1], assertions = m[2];
    const failures = (text.match(/Failures:\s*(\d+)/) || [0,0])[1];
    const errors   = (text.match(/Errors:\s*(\d+)/) || [0,0])[1];
    const skipped  = (text.match(/Skipped:\s*(\d+)/) || [0,0])[1];

    const hasFail = parseInt(failures) > 0 || parseInt(errors) > 0;
    el.className = 'sh-test-summary ' + (hasFail ? 'fail' : 'pass');
    let html = '<span class="sh-test-stat total"><i class="fas fa-list"></i> ' + tests + ' testes</span>';
    html += '<span class="sh-test-stat total"><i class="fas fa-check-double"></i> ' + assertions + ' assertions</span>';
    if (parseInt(failures) > 0) html += '<span class="sh-test-stat fail"><i class="fas fa-times-circle"></i> ' + failures + ' falhas</span>';
    if (parseInt(errors) > 0) html += '<span class="sh-test-stat fail"><i class="fas fa-bug"></i> ' + errors + ' erros</span>';
    if (parseInt(skipped) > 0) html += '<span class="sh-test-stat skip"><i class="fas fa-forward"></i> ' + skipped + ' pulados</span>';
    el.innerHTML = html;
    return;
  }

  // Não conseguiu parsear
  if (/ERRO|Error|Fatal/i.test(text)) {
    el.className = 'sh-test-summary fail';
    el.innerHTML = '<span class="sh-test-stat fail"><i class="fas fa-times-circle"></i> Erro na execução</span>';
  } else {
    el.className = 'sh-test-summary pending';
    el.innerHTML = '<span class="sh-test-stat total"><i class="fas fa-question-circle"></i> Resultado indeterminado</span>';
  }
}

function esc(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}
</script>
</body>
</html>
