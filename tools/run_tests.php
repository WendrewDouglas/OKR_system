<?php
/**
 * Runner de testes PHPUnit no servidor via HTTP.
 * Protegido por token. Uso temporário para servidores sem shell.
 *
 * USO: curl "https://…/tools/run_tests.php?token=TOKEN&suite=unit"
 */
declare(strict_types=1);

set_time_limit(120);
ini_set('memory_limit', '256M');

header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__) . '/auth/config.php';
$expectedToken = (string)env('HEALTH_CHECK_TOKEN', '');
$givenToken    = (string)($_GET['token'] ?? '');

if ($expectedToken === '' || !hash_equals($expectedToken, $givenToken)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$projectRoot = dirname(__DIR__);
$phpunit     = $projectRoot . '/vendor/bin/phpunit';
$config      = $projectRoot . '/tests/phpunit.xml';

if (!is_file($phpunit)) {
    echo "ERRO: PHPUnit não encontrado em {$phpunit}\n";
    echo "Execute run_composer.php?action=update primeiro.\n";
    exit;
}

$suite = $_GET['suite'] ?? 'unit';
$allowed = ['unit', 'smoke', 'integration'];
if (!in_array($suite, $allowed, true)) {
    echo "Suites disponíveis: unit, smoke, integration\n";
    exit;
}

$home = getenv('HOME') ?: '/home2/planni40';
$phpBin = '/usr/local/bin/php';

// Detecta base URL real para smoke tests
$serverName = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '';
$scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl    = ($serverName !== '' && $serverName !== 'localhost')
    ? "{$scheme}://{$serverName}/OKR_system"
    : 'http://localhost/OKR_system';

$cmd = "export HOME=" . escapeshellarg($home)
     . " && export TEST_BASE_URL=" . escapeshellarg($baseUrl)
     . " && cd " . escapeshellarg($projectRoot)
     . " && {$phpBin} " . escapeshellarg($phpunit)
     . " --configuration " . escapeshellarg($config)
     . " --testsuite " . escapeshellarg($suite)
     . " --colors=never 2>&1";

echo "=== PHPUnit ({$suite}) ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo "PHP: " . PHP_VERSION . "\n\n";

$output = shell_exec($cmd);
echo $output;
