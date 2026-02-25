<?php
// /app/bootstrap/init.php
declare(strict_types=1);

/**
 * Bootstrap comum (procedural) para Views e Endpoints AJAX.
 * - session + config + functions + acl
 * - PDO ($pdo) disponível
 * - helpers csrf e json response
 */

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/../../auth/config.php';
require_once __DIR__ . '/../../auth/functions.php';
require_once __DIR__ . '/../../auth/acl.php';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/csrf.php';

// PDO (sempre disponível após init.php)
$pdo = db_pdo();

// Helper: usuário logado (sem forçar nada — endpoint pode exigir)
function current_user_id(): ?int {
  if (!isset($_SESSION['user_id'])) return null;
  $v = $_SESSION['user_id'];
  if (is_numeric($v)) return (int)$v;
  return null;
}

/**
 * Helper: exige login.
 * Usa funções já existentes do seu projeto quando disponíveis.
 */
function require_login_or_fail(): void {
  // Se você já tem função pronta, ela será usada.
  if (function_exists('is_logged_in')) {
    if (!is_logged_in()) json_fail(401, 'Sessão expirada. Faça login novamente.');
    return;
  }

  // Fallback simples
  if (empty($_SESSION['user_id'])) {
    json_fail(401, 'Sessão expirada. Faça login novamente.');
  }
}

/**
 * Helper: gate/ACL por path quando existir.
 * (mantém compatibilidade com seu padrão atual)
 */
function gate_by_path_if_available(string $path): void {
  if (function_exists('gate_page_by_path')) {
    gate_page_by_path($path);
  }
}