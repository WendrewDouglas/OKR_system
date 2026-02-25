<?php
/**
 * Runner temporário: executa composer update no servidor via HTTP.
 * Protegido por token. Auto-deleta após execução bem-sucedida.
 *
 * USO: curl "https://…/tools/run_composer.php?token=TOKEN&action=update"
 */
declare(strict_types=1);

// Aumenta limites para composer
set_time_limit(300);
ini_set('memory_limit', '512M');

header('Content-Type: text/plain; charset=utf-8');

// Token de proteção (mesmo do health check)
require_once dirname(__DIR__) . '/auth/config.php';
$expectedToken = (string)env('HEALTH_CHECK_TOKEN', '');
$givenToken    = (string)($_GET['token'] ?? '');

if ($expectedToken === '' || !hash_equals($expectedToken, $givenToken)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$action = $_GET['action'] ?? '';
if (!in_array($action, ['update', 'install', 'status', 'selfdelete'], true)) {
    echo "Ações disponíveis: ?action=status | update | install | selfdelete\n";
    exit;
}

$projectRoot = dirname(__DIR__);

// Detecta composer
$composerPaths = [
    '/usr/local/bin/composer',
    '/usr/bin/composer',
    '/opt/cpanel/composer/bin/composer',
    $projectRoot . '/composer.phar',
];
$composerBin = null;
foreach ($composerPaths as $p) {
    if (is_file($p)) { $composerBin = $p; break; }
}

// Detecta PHP CLI
$phpPaths = [
    '/usr/local/bin/php',
    '/usr/local/bin/ea-php83',
    '/usr/local/bin/ea-php82',
    '/usr/local/bin/ea-php81',
    '/usr/bin/php',
];
$phpBin = 'php'; // fallback
foreach ($phpPaths as $p) {
    if (is_file($p)) { $phpBin = $p; break; }
}

if ($action === 'status') {
    echo "=== Environment ===\n";
    echo "PHP: " . PHP_VERSION . " (" . PHP_BINARY . ")\n";
    echo "PHP CLI: {$phpBin}\n";
    echo "Composer: " . ($composerBin ?: 'NOT FOUND') . "\n";
    echo "Project root: {$projectRoot}\n";
    echo "composer.json exists: " . (is_file("{$projectRoot}/composer.json") ? 'YES' : 'NO') . "\n";
    echo "composer.lock exists: " . (is_file("{$projectRoot}/composer.lock") ? 'YES' : 'NO') . "\n";
    echo "vendor/ exists: " . (is_dir("{$projectRoot}/vendor") ? 'YES' : 'NO') . "\n";
    echo "vendor/bin/phpunit: " . (is_file("{$projectRoot}/vendor/bin/phpunit") ? 'YES' : 'NO') . "\n";

    // Se composer não encontrado, tenta download
    if (!$composerBin) {
        echo "\nComposer não encontrado. Use ?action=update para baixá-lo automaticamente.\n";
    }

    // Lista binários PHP disponíveis
    echo "\n=== PHP binaries ===\n";
    foreach ($phpPaths as $p) {
        echo "  {$p}: " . (is_file($p) ? 'EXISTS' : '-') . "\n";
    }
    exit;
}

if ($action === 'selfdelete') {
    if (@unlink(__FILE__)) {
        echo "run_composer.php deletado com sucesso.\n";
    } else {
        echo "Falha ao deletar. Remova manualmente.\n";
    }
    exit;
}

// update ou install
echo "=== Composer {$action} ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

// Se composer não existe, baixa composer.phar
if (!$composerBin) {
    echo "Composer não encontrado no sistema. Baixando composer.phar...\n";
    $pharPath = "{$projectRoot}/composer.phar";
    $installerUrl = 'https://getcomposer.org/installer';

    $installer = file_get_contents($installerUrl);
    if ($installer === false) {
        echo "ERRO: Não foi possível baixar o installer do Composer.\n";
        exit(1);
    }

    file_put_contents("{$projectRoot}/composer-setup.php", $installer);

    $cmd = "{$phpBin} {$projectRoot}/composer-setup.php --install-dir=" . escapeshellarg($projectRoot) . " --filename=composer.phar 2>&1";
    echo "$ {$cmd}\n";
    echo shell_exec($cmd) . "\n";

    @unlink("{$projectRoot}/composer-setup.php");

    if (is_file($pharPath)) {
        $composerBin = $pharPath;
        echo "composer.phar instalado com sucesso.\n\n";
    } else {
        echo "ERRO: Falha ao instalar composer.phar.\n";
        exit(1);
    }
}

// Monta comando
$composerCmd = is_file($composerBin) && pathinfo($composerBin, PATHINFO_EXTENSION) === 'phar'
    ? "{$phpBin} " . escapeshellarg($composerBin)
    : escapeshellarg($composerBin);

$flags = $action === 'update'
    ? 'update --no-interaction --no-progress --optimize-autoloader 2>&1'
    : 'install --no-interaction --no-progress --optimize-autoloader 2>&1';

$home = getenv('HOME') ?: '/home2/planni40';
$fullCmd = "export HOME=" . escapeshellarg($home) . " && export COMPOSER_HOME=" . escapeshellarg($home . '/.composer') . " && cd " . escapeshellarg($projectRoot) . " && {$composerCmd} {$flags}";
echo "$ {$fullCmd}\n\n";

// Executa
$output = shell_exec($fullCmd);
echo $output . "\n";

// Verifica resultado
if (is_file("{$projectRoot}/vendor/bin/phpunit")) {
    echo "\n=== PHPUnit instalado com sucesso! ===\n";
    $phpunitVer = shell_exec("{$phpBin} " . escapeshellarg("{$projectRoot}/vendor/bin/phpunit") . " --version 2>&1");
    echo trim($phpunitVer) . "\n";
} else {
    echo "\n=== AVISO: vendor/bin/phpunit não encontrado após {$action} ===\n";
}
