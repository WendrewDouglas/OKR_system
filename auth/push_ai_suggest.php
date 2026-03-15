<?php
/**
 * auth/push_ai_suggest.php
 * Gera sugestoes de IA para push — autenticacao via sessao.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/acl.php';
require_once __DIR__ . '/push_helpers.php';

// Limpa qualquer output de warnings dos requires
ob_clean();

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'message' => 'Nao autorizado']); exit;
}

$uid = (int)$_SESSION['user_id'];

// Verifica admin_master
$pdo = pdo_conn();
$st = $pdo->prepare("SELECT 1 FROM rbac_user_role ur JOIN rbac_roles r ON r.role_id=ur.role_id AND r.is_active=1 WHERE ur.user_id=? AND r.role_key='admin_master' LIMIT 1");
$st->execute([$uid]);
if (!$st->fetchColumn()) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Acesso restrito']); exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true) ?: [];

$prompt = trim($in['prompt'] ?? '');
if (!$prompt) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'message' => 'Prompt obrigatorio']); exit;
}

$context = [
  'categoria' => $in['categoria'] ?? '',
  'tom'       => $in['tom'] ?? 'profissional',
  'urgencia'  => $in['urgencia'] ?? 'normal',
  'audiencia' => $in['audiencia'] ?? '',
];

$result = push_ai_suggest($prompt, $context);

if (isset($result['error'])) {
  http_response_code(502);
  echo json_encode(['ok' => false, 'message' => $result['error']]); exit;
}

// Salva no historico
$campaignId = !empty($in['id_campaign']) ? (int)$in['id_campaign'] : null;
try {
  $pdo->prepare("INSERT INTO push_ai_suggestions (id_campaign, prompt, response_json, created_by) VALUES (?,?,?,?)")
    ->execute([$campaignId, $prompt, json_encode($result, JSON_UNESCAPED_UNICODE), $uid]);
} catch (Throwable $e) { /* ignora se tabela nao existir ainda */ }

echo json_encode(['ok' => true, 'suggestions' => $result['suggestions'], 'tokens' => $result['tokens']]);
