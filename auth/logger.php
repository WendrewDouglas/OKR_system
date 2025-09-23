<?php
// /OKR_system/auth/logger.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

/** Mascara campos sensíveis em arrays (POST/GET/etc.) */
function pb_sanitize_array(array $arr): array {
  $maskKeys = [
    'senha','password','pass','secret','secreto','token','csrf','pepper','hash',
    'otp','app_token','api_key','api_secret','chave','private','privada'
  ];
  $out = [];
  foreach ($arr as $k => $v) {
    $lk = mb_strtolower((string)$k, 'UTF-8');
    $isSensitive = false;
    foreach ($maskKeys as $mk) {
      if (strpos($lk, $mk) !== false) { $isSensitive = true; break; }
    }
    if ($isSensitive) {
      $out[$k] = '***';
    } else {
      // Evita gigantismo
      if (is_string($v) && mb_strlen($v, 'UTF-8') > 2000) {
        $out[$k] = mb_substr($v, 0, 2000, 'UTF-8') . '…';
      } else {
        $out[$k] = $v;
      }
    }
  }
  return $out;
}

/**
 * Escreve um log estruturado em /views/error_log/kr_error-YYYY-MM-DD.log
 * @return string req_id (para correlação)
 */
function pb_log_error(string $category, string $message, array $context = []): string {
  $projectRoot = dirname(__DIR__); // /OKR_system
  $dir = $projectRoot . '/views/error_log';
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

  $file = $dir . '/kr_error-' . date('Y-m-d') . '.log';

  $reqId = $context['req_id']
        ?? ($_POST['req_id'] ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8))));

  $payload = [
    'ts'       => date('c'),
    'req_id'   => $reqId,
    'user_id'  => $_SESSION['user_id'] ?? null,
    'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
    'uri'      => $_SERVER['REQUEST_URI'] ?? null,
    'method'   => $_SERVER['REQUEST_METHOD'] ?? null,
    'category' => $category,
    'message'  => $message,
    'context'  => [
      'get'   => pb_sanitize_array($_GET ?? []),
      'post'  => pb_sanitize_array($_POST ?? []),
      'extra' => pb_sanitize_array($context),
    ],
  ];

  @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
  return $reqId;
}
