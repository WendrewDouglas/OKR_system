<?php
/**
 * api/chat_api.php — AI Chat API with auth, DB history, and real context.
 *
 * - Requires authenticated session (401 if not)
 * - Loads conversation history from chat_conversas
 * - Builds system prompt with real company data via chat_context_builder
 * - Saves user messages and assistant responses to DB
 * - Caches context in session for 5 minutes
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Bootstrap ---
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/chat_context_builder.php';

// --- Session ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // --- Auth check ---
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado. Faça login para usar o chat.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- cURL check ---
    if (!function_exists('curl_init')) {
        http_response_code(500);
        echo json_encode(['error' => 'Extensão cURL do PHP não está habilitada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- API key ---
    $apiKey = (string)env('OPENAI_API_KEY', '');
    if (!$apiKey) {
        http_response_code(500);
        echo json_encode(['error' => 'OPENAI_API_KEY não configurada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Parse input ---
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    // Handle "clear" action
    if (is_array($input) && ($input['action'] ?? '') === 'clear') {
        $pdo = db();
        $sessionId = $_SESSION['chat_session_id'] ?? '';
        if ($sessionId) {
            $st = $pdo->prepare("DELETE FROM chat_conversas WHERE id_user = :u AND session_id = :s");
            $st->execute([':u' => $userId, ':s' => $sessionId]);
        }
        // Generate new session
        $_SESSION['chat_session_id'] = bin2hex(random_bytes(16));
        unset($_SESSION['chat_context_cache'], $_SESSION['chat_context_time']);
        echo json_encode(['success' => true, 'message' => 'Conversa limpa.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!is_array($input) || !isset($input['message']) || trim($input['message']) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Mensagem inválida ou vazia.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $message = trim($input['message']);

    // --- DB connection ---
    $pdo = db();

    // --- Get company ID ---
    $st = $pdo->prepare("SELECT id_company FROM usuarios WHERE id_user = :u LIMIT 1");
    $st->execute([':u' => $userId]);
    $companyId = (int)($st->fetchColumn() ?: 0);

    // --- Ensure chat session ID ---
    if (empty($_SESSION['chat_session_id'])) {
        $_SESSION['chat_session_id'] = bin2hex(random_bytes(16));
    }
    $sessionId = $_SESSION['chat_session_id'];

    // --- Build system prompt (cached 5 min) ---
    $cacheTime = (int)($_SESSION['chat_context_time'] ?? 0);
    $cacheCompany = (int)($_SESSION['chat_context_company'] ?? 0);
    if (
        empty($_SESSION['chat_context_cache'])
        || (time() - $cacheTime) > 300
        || $cacheCompany !== $companyId
    ) {
        $_SESSION['chat_context_cache']   = chat_build_context($userId, $companyId);
        $_SESSION['chat_context_time']    = time();
        $_SESSION['chat_context_company'] = $companyId;
    }
    $systemPrompt = $_SESSION['chat_context_cache'];

    // --- Load conversation history (last 20 messages) ---
    $st = $pdo->prepare("
        SELECT role, content
        FROM chat_conversas
        WHERE id_user = :u AND session_id = :s
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $st->execute([':u' => $userId, ':s' => $sessionId]);
    $history = array_reverse($st->fetchAll());

    // --- Build messages array ---
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
    ];
    foreach ($history as $h) {
        if (in_array($h['role'], ['user', 'assistant'], true)) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    // --- Save user message to DB ---
    $stInsert = $pdo->prepare("
        INSERT INTO chat_conversas (id_user, id_company, role, content, session_id)
        VALUES (:u, :c, 'user', :content, :s)
    ");
    $stInsert->execute([
        ':u' => $userId,
        ':c' => $companyId,
        ':content' => $message,
        ':s' => $sessionId,
    ]);

    // --- Call OpenAI ---
    $payload = [
        'model'       => 'gpt-4o-mini',
        'messages'    => $messages,
        'max_tokens'  => 1024,
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

    $reply = $resp['choices'][0]['message']['content'] ?? 'Sem resposta.';
    $tokensUsed = (int)($resp['usage']['total_tokens'] ?? 0);

    // --- Save assistant response to DB ---
    $stInsert = $pdo->prepare("
        INSERT INTO chat_conversas (id_user, id_company, role, content, tokens_used, session_id)
        VALUES (:u, :c, 'assistant', :content, :tokens, :s)
    ");
    $stInsert->execute([
        ':u' => $userId,
        ':c' => $companyId,
        ':content' => $reply,
        ':tokens' => $tokensUsed,
        ':s' => $sessionId,
    ]);

    echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    error_log('Chat API fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao processar. Tente novamente ou contate o administrador.'], JSON_UNESCAPED_UNICODE);
}
