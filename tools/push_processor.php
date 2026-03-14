<?php
/**
 * tools/push_processor.php
 * Processa campanhas push agendadas (chamado por cron).
 *
 * Uso via cron (a cada 1-5 minutos):
 *   php /home2/planni40/public_html/OKR_system/tools/push_processor.php
 *
 * Ou via HTTP com token:
 *   GET /OKR_system/tools/push_processor.php?token=HEALTH_CHECK_TOKEN
 */
declare(strict_types=1);

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
  header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/push_helpers.php';

// Seguranca: CLI ou token valido
if (!$isCli) {
  $token = $_GET['token'] ?? '';
  $expected = (string)env('HEALTH_CHECK_TOKEN', '');
  if (!$expected || $token !== $expected) {
    http_response_code(403);
    echo json_encode(['error' => 'Token invalido']);
    exit;
  }
}

try {
  $pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
  );

  // Busca campanhas prontas para envio
  $st = $pdo->query("
    SELECT id_campaign FROM push_campaigns
     WHERE status = 'scheduled'
       AND scheduled_at <= NOW()
     ORDER BY scheduled_at ASC
     LIMIT 10
  ");
  $campaigns = $st->fetchAll(PDO::FETCH_COLUMN);

  $results = [];
  foreach ($campaigns as $campaignId) {
    $result = push_process_campaign($pdo, (int)$campaignId);
    $results[] = ['id_campaign' => $campaignId] + $result;
    $msg = sprintf(
      "[push_processor] Campaign #%d: target=%d sent=%d failed=%d",
      $campaignId, $result['total_target'] ?? 0, $result['sent'] ?? 0, $result['failed'] ?? 0
    );
    if ($isCli) echo $msg . PHP_EOL;
    error_log($msg);
  }

  if (empty($campaigns)) {
    $msg = "[push_processor] Nenhuma campanha pendente.";
    if ($isCli) echo $msg . PHP_EOL;
  }

  if (!$isCli) {
    echo json_encode(['ok' => true, 'processed' => count($campaigns), 'results' => $results]);
  }
} catch (Throwable $e) {
  $msg = "[push_processor] ERRO: " . $e->getMessage();
  error_log($msg);
  if ($isCli) { echo $msg . PHP_EOL; exit(1); }
  http_response_code(500);
  echo json_encode(['error' => 'Erro interno']);
}
