<?php
/**
 * api/chat_history.php — Returns last 50 chat messages for the authenticated user.
 * Used by frontend to restore conversation when opening the chat.
 *
 * GET /OKR_system/api/chat_history.php
 * Returns: { success: true, messages: [ { role, content, created_at } ] }
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sessionId = $_SESSION['chat_session_id'] ?? '';
    if (!$sessionId) {
        // No session yet, return empty
        echo json_encode(['success' => true, 'messages' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = db();

    $st = $pdo->prepare("
        SELECT role, content, created_at
        FROM chat_conversas
        WHERE id_user = :u AND session_id = :s
        ORDER BY created_at ASC
        LIMIT 50
    ");
    $st->execute([':u' => $userId, ':s' => $sessionId]);
    $messages = $st->fetchAll();

    // Filter to only user/assistant messages (exclude system)
    $messages = array_values(array_filter($messages, fn($m) => in_array($m['role'], ['user', 'assistant'], true)));

    echo json_encode(['success' => true, 'messages' => $messages], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    error_log('Chat history error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao carregar histórico.'], JSON_UNESCAPED_UNICODE);
}
