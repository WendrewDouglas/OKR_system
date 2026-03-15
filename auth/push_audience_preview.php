<?php
/**
 * auth/push_audience_preview.php
 * Preview de audiencia — autenticacao via sessao.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push_helpers.php';

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'message' => 'Nao autorizado']); exit;
}

$pdo = pdo_conn();
$st = $pdo->prepare("SELECT 1 FROM rbac_user_role ur JOIN rbac_roles r ON r.role_id=ur.role_id AND r.is_active=1 WHERE ur.user_id=? AND r.role_key='admin_master' LIMIT 1");
$st->execute([(int)$_SESSION['user_id']]);
if (!$st->fetchColumn()) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Acesso restrito']); exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true) ?: [];
$filters = $in['filters'] ?? [];
if (!is_array($filters)) $filters = json_decode((string)$filters, true) ?: [];

$count = push_count_audience($filters, $pdo);
$users = push_list_audience($filters, $pdo, 50);

echo json_encode(['ok' => true, 'count' => $count, 'users' => $users]);
