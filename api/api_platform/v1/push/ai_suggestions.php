<?php
// POST /push/ai/suggestions
declare(strict_types=1);
$auth = api_require_auth();
$pdo = api_db();
$uid = (int)$auth['sub'];
if (!api_is_admin_master($pdo, $uid)) api_error('E_FORBIDDEN', 'Acesso restrito.', 403);

require_once dirname(__DIR__, 3) . '/../auth/push_helpers.php';

$in = api_input();
$prompt = api_str($in['prompt'] ?? '');
if (!$prompt) api_error('E_INPUT', 'Prompt obrigatorio.', 422);

$context = [
  'categoria' => $in['categoria'] ?? '',
  'tom'       => $in['tom'] ?? 'profissional',
  'urgencia'  => $in['urgencia'] ?? 'normal',
  'audiencia' => $in['audiencia'] ?? '',
];

$result = push_ai_suggest($prompt, $context);

if (isset($result['error'])) {
  api_error('E_AI', $result['error'], 502);
}

// Salva no historico
$campaignId = !empty($in['id_campaign']) ? (int)$in['id_campaign'] : null;
$pdo->prepare("INSERT INTO push_ai_suggestions (id_campaign, prompt, response_json, created_by) VALUES (?,?,?,?)")
  ->execute([$campaignId, $prompt, json_encode($result, JSON_UNESCAPED_UNICODE), $uid]);

api_json(['ok' => true, 'suggestions' => $result['suggestions'], 'tokens' => $result['tokens']]);
