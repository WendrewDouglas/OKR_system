<?php
// api/chat_api.php - versão sem Composer, com erros explícitos
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

// Carrega .env simples (sem vlucas/phpdotenv)
function load_env($path) {
  if (!file_exists($path)) return;
  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    list($k, $v) = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v, " \t\n\r\0\x0B'\"");
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
  }
}
load_env(dirname(__DIR__) . '/.env');

try {
  if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['error' => 'Extensão cURL do PHP não está habilitada.'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?? '';
  if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'OPENAI_API_KEY não configurada no .env'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $raw = file_get_contents('php://input');
  $input = json_decode($raw, true);
  if (!is_array($input) || !isset($input['message']) || trim($input['message']) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem inválida ou vazia no request.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $message = trim($input['message']);

  $systemPrompt = $_ENV['CHAT_SYSTEM_PROMPT'] ?? 'Você é um assistente útil especialista em OKRs. Responda curto e direto.';

  $payload = [
    'model' => 'gpt-4o-mini',
    'messages' => [
      ['role' => 'system', 'content' => $systemPrompt],
      ['role' => 'user',   'content' => $message],
    ],
    'max_tokens'  => 150,
    'temperature' => 0.4,
  ];

  $ch = curl_init('https://api.openai.com/v1/chat/completions');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 30,
    // Se seu ambiente tiver problemas de CA bundle, aponte o arquivo PEM:
    // CURLOPT_CAINFO         => __DIR__ . '/cacert.pem',
  ]);
  $response = curl_exec($ch);
  $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr  = curl_error($ch);
  curl_close($ch);

  if ($curlErr) {
    http_response_code(502);
    echo json_encode(['error' => "Falha cURL: $curlErr"], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($status < 200 || $status >= 300) {
    $body = json_decode($response, true);
    $msg  = $body['error']['message'] ?? $response ?? "HTTP $status";
    http_response_code($status);
    echo json_encode(['error' => "OpenAI: $msg"], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $resp = json_decode($response, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode(['error' => 'Resposta inválida da OpenAI (JSON)'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $reply = $resp['choices'][0]['message']['content'] ?? null;
  if (!$reply) $reply = 'Sem resposta.';
  echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log('Chat API fatal: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Falha inesperada no servidor: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
