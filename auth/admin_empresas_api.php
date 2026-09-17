<?php
/**
 * auth/admin_empresas_api.php — ações do Painel de Empresas (admin_master).
 *   POST action=set_ativo, id_company, ativo (0|1), csrf_token
 */
declare(strict_types=1);

ini_set('display_errors', '0');
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/acl.php';

function pe_json(int $code, array $body): void {
  http_response_code($code);
  echo json_encode($body, JSON_UNESCAPED_UNICODE);
  exit;
}

if (empty($_SESSION['user_id'])) pe_json(401, ['success' => false, 'error' => 'Não autenticado']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') pe_json(405, ['success' => false, 'error' => 'Método não permitido']);

$sess = (string)($_SESSION['csrf_token'] ?? '');
$sent = (string)($_POST['csrf_token'] ?? '');
if ($sess === '' || !hash_equals($sess, $sent)) pe_json(403, ['success' => false, 'error' => 'Sessão expirada. Recarregue a página.']);

$uid = (int)$_SESSION['user_id'];
$pdo = pdo_conn();
$st = $pdo->prepare("
  SELECT 1 FROM rbac_user_role ur
    JOIN rbac_roles r ON r.role_id = ur.role_id AND r.is_active = 1
   WHERE ur.user_id = ? AND r.role_key = 'admin_master'
   LIMIT 1
");
$st->execute([$uid]);
if (!$st->fetchColumn()) pe_json(403, ['success' => false, 'error' => 'Acesso restrito a administradores do sistema.']);

$action = (string)($_POST['action'] ?? '');

if ($action === 'set_ativo') {
  $cid   = (int)($_POST['id_company'] ?? 0);
  $ativo = (string)($_POST['ativo'] ?? '') === '1' ? 1 : 0;
  if ($cid <= 0) pe_json(422, ['success' => false, 'error' => 'Empresa inválida']);

  try {
    $up = $pdo->prepare("UPDATE company SET ativo = ?, ativo_alterado_em = NOW(), ativo_alterado_por = ? WHERE id_company = ?");
    $up->execute([$ativo, $uid, $cid]);
    $ex = $pdo->prepare("SELECT 1 FROM company WHERE id_company = ?");
    $ex->execute([$cid]);
    if (!$ex->fetchColumn()) pe_json(404, ['success' => false, 'error' => 'Empresa não encontrada']);

    app_log('COMPANY_ATIVO_SET', ['id_company' => $cid, 'ativo' => $ativo, 'by' => $uid]);
    $total = (int)$pdo->query("SELECT COUNT(*) FROM company WHERE ativo = 1")->fetchColumn();
    pe_json(200, ['success' => true, 'ativo' => (bool)$ativo, 'total_ativas' => $total]);
  } catch (Throwable $e) {
    app_log('COMPANY_ATIVO_FAIL', ['id_company' => $cid, 'error' => $e->getMessage()]);
    pe_json(500, ['success' => false, 'error' => 'Não foi possível salvar. A migração 013 foi aplicada?']);
  }
}

pe_json(400, ['success' => false, 'error' => 'Ação inválida']);
