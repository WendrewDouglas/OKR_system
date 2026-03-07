<?php
// /app/bootstrap/response.php
declare(strict_types=1);

/**
 * Resposta JSON padrão (procedural).
 * Sempre encerra o script com exit.
 */

function json_ok(array $data = [], int $code = 200): void {
  while (ob_get_level()) { @ob_end_clean(); }
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
  exit;
}

function json_fail(int $code, string $message, array $extra = []): void {
  while (ob_get_level()) { @ob_end_clean(); }
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array_merge([
    'success' => false,
    'message' => $message
  ], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}