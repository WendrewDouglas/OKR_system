<?php
declare(strict_types=1);

// =============================================================
// Helpers de I/O, resposta JSON e leitura de sessão/respostas.
// Espelha LP/perspectivas/includes/helpers.php.
// =============================================================

function bc_json_headers(): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
    }
}

/**
 * Body JSON (preferencial) ou POST tradicional.
 */
function bc_input(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return is_array($_POST) ? $_POST : [];
}

function bc_ok(array $data = []): never
{
    bc_json_headers();
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * $fields: erros por question_key, renderizados inline no formulário.
 */
function bc_fail(string $code, int $httpStatus, string $message, array $fields = []): never
{
    bc_json_headers();
    http_response_code($httpStatus);
    $payload = ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    if ($fields !== []) {
        $payload['error']['fields'] = $fields;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function bc_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $v = $_SERVER[$key] ?? '';
        if (!is_string($v) || $v === '') {
            continue;
        }
        // X-Forwarded-For pode vir como lista; o primeiro é o cliente.
        $first = trim(explode(',', $v)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return mb_substr($first, 0, 45);
        }
    }
    return '';
}

function bc_user_agent(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return is_string($ua) ? mb_substr($ua, 0, 500) : '';
}

function bc_generate_token(): string
{
    return bin2hex(random_bytes(24)); // 48 hex chars, cabe em VARCHAR(80)
}

/**
 * Carrega a sessão pelo token. Retorna null se não existir.
 */
function bc_session_by_token(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id, session_token, nome_informado, email_informado, whatsapp_informado,
                escritorio, papel, status, current_block, completed_at
           FROM bc_sessions
          WHERE session_token = ?
          LIMIT 1'
    );
    $st->execute([$token]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/**
 * Respostas já gravadas, indexadas por question_key.
 * Usado para reidratar o formulário quando ela volta depois.
 */
function bc_answers_for_session(PDO $pdo, int $sessionId): array
{
    $st = $pdo->prepare(
        'SELECT question_key, answer_type, answer_text, answer_number, answer_json
           FROM bc_answers
          WHERE session_id = ?'
    );
    $st->execute([$sessionId]);

    $out = [];
    foreach ($st->fetchAll() as $row) {
        $key = (string) $row['question_key'];
        if ($row['answer_type'] === 'multi') {
            $decoded = json_decode((string) ($row['answer_json'] ?? '[]'), true);
            $out[$key] = is_array($decoded) ? $decoded : [];
        } elseif ($row['answer_type'] === 'scale') {
            $out[$key] = $row['answer_number'] !== null ? (int) $row['answer_number'] : null;
        } else {
            $out[$key] = $row['answer_text'];
        }
    }
    return $out;
}

/**
 * Blocos já preenchidos (todas as obrigatórias respondidas).
 * Serve para o indicador de progresso e para liberar o envio final.
 */
function bc_completed_blocks(array $answers): array
{
    $done = [];
    foreach (bc_block_order() as $block) {
        $ok = true;
        foreach (bc_block_question_keys($block) as $qkey) {
            $q = bc_questions()[$qkey];
            if (!($q['required'] ?? false)) {
                continue;
            }
            $v = $answers[$qkey] ?? null;
            if ($v === null || $v === '' || (is_array($v) && $v === [])) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $done[] = $block;
        }
    }
    return $done;
}

/**
 * Texto de consentimento LGPD. Gravado por extenso em bc_consents para
 * que a prova não dependa da versão atual do código.
 */
function bc_consent_text(): string
{
    return 'Autorizo o contato e o tratamento das informações que eu preencher '
        . 'neste briefing — incluindo meu nome, e-mail e telefone — com a '
        . 'finalidade exclusiva de estruturar a proposta do sistema de CRM. '
        . 'Os dados não são compartilhados com terceiros e podem ser '
        . 'excluídos a qualquer momento a meu pedido.';
}
