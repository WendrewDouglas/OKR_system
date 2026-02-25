<?php
/**
 * run_migration.php — Executa migrations pendentes no banco de dados.
 *
 * IMPORTANTE: Delete este arquivo do servidor após executar!
 * Acesse via browser: https://seusite.com/OKR_system/run_migration.php
 */
declare(strict_types=1);

// Proteção: só admin logado pode executar
session_start();
require_once __DIR__ . '/auth/config.php';
require_once __DIR__ . '/auth/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die('Acesso negado. Faça login primeiro.');
}

// Verifica se é admin_master via RBAC
try {
    $st = db()->prepare("
        SELECT 1
          FROM rbac_user_role ur
          JOIN rbac_roles r ON r.role_id = ur.role_id AND r.is_active = 1
         WHERE ur.user_id = :id AND r.role_key = 'admin_master'
         LIMIT 1
    ");
    $st->execute([':id' => $_SESSION['user_id']]);
    if (!$st->fetchColumn()) {
        http_response_code(403);
        die('Acesso negado. Apenas administradores podem executar migrations.');
    }
} catch (Throwable $e) {
    http_response_code(500);
    die('Erro ao verificar permissão: ' . htmlspecialchars($e->getMessage()));
}

// Token CSRF simples via query string
$token = $_GET['run'] ?? '';

?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>OKR System — Migrations</title>
<style>
  body { font-family: system-ui, sans-serif; background: #0d1117; color: #e6e9f2; padding: 2rem; max-width: 700px; margin: 0 auto; }
  h1 { font-size: 1.3rem; }
  .ok { color: #22c55e; }
  .err { color: #ef4444; }
  .warn { color: #f59e0b; }
  pre { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 12px; overflow-x: auto; font-size: .9rem; }
  a.btn { display: inline-block; margin-top: 12px; padding: 10px 16px; background: #f6c343; color: #111; border-radius: 8px; text-decoration: none; font-weight: 700; }
  a.btn:hover { filter: brightness(.9); }
</style>
</head>
<body>
<h1>OKR System — SQL Migrations</h1>

<?php
$migrationsDir = __DIR__ . '/api/migrations';
$files = glob($migrationsDir . '/*.sql');

if (!$files) {
    echo '<p class="warn">Nenhum arquivo .sql encontrado em api/migrations/</p>';
    exit;
}

sort($files);

echo '<p>Migrations encontradas: <strong>' . count($files) . '</strong></p>';

foreach ($files as $f) {
    $name = basename($f);
    echo "<p>- {$name}</p>";
}

if ($token !== 'execute') {
    echo '<p style="margin-top:20px;">Clique para executar todas as migrations:</p>';
    echo '<a class="btn" href="?run=execute">Executar Migrations</a>';
    echo '<p style="margin-top:12px;font-size:.85rem;color:#9aa4b2;">Isso criará as tabelas necessárias no banco de dados.</p>';
    exit;
}

// Executar migrations
echo '<hr style="border-color:#30363d;margin:20px 0;">';
echo '<h2>Executando...</h2>';

$pdo = db();

foreach ($files as $f) {
    $name = basename($f);
    $sql = file_get_contents($f);

    if (!$sql || trim($sql) === '') {
        echo "<p class='warn'>⚠ {$name} — arquivo vazio, pulando.</p>";
        continue;
    }

    try {
        $pdo->exec($sql);
        echo "<p class='ok'>✓ {$name} — executado com sucesso!</p>";
    } catch (Throwable $e) {
        $msg = htmlspecialchars($e->getMessage());
        // "Table already exists" não é erro crítico
        if (str_contains($e->getMessage(), 'already exists')) {
            echo "<p class='warn'>⚠ {$name} — tabela já existe (OK).</p>";
        } else {
            echo "<p class='err'>✗ {$name} — ERRO: {$msg}</p>";
        }
    }
}

echo '<hr style="border-color:#30363d;margin:20px 0;">';
echo '<p class="ok" style="font-size:1.1rem;font-weight:700;">Migration concluída!</p>';
echo '<p class="warn" style="font-weight:700;">IMPORTANTE: Delete este arquivo (run_migration.php) do servidor agora!</p>';
?>
</body>
</html>
