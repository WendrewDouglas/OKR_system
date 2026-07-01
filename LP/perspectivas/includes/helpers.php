<?php
declare(strict_types=1);

// =============================================================
// Helpers gerais do módulo "Perspectivas de Gestão":
// I/O JSON (envelope {ok,...}), IP/UA, tokens e texto de consentimento LGPD.
// =============================================================

require_once __DIR__ . '/db.php';

/* ------------------------------------------------------------------ */
/* Respostas JSON — envelope padronizado                              */
/* ------------------------------------------------------------------ */

function pg_json_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

/**
 * Lê o corpo da requisição. Aceita JSON (application/json) ou form POST.
 */
function pg_input(): array
{
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $j   = json_decode($raw ?: '[]', true);
        return is_array($j) ? $j : [];
    }
    return $_POST;
}

/**
 * Sucesso. Envelope: {"ok":true,"data":{...}}.
 */
function pg_ok(array $data = []): void
{
    pg_json_headers();
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Erro. Envelope: {"ok":false,"error":{"code","message","fields"}}.
 * Nunca expõe stack trace — mensagens são sempre amigáveis.
 */
function pg_fail(string $code, int $http = 400, string $message = '', array $fields = []): void
{
    http_response_code($http);
    pg_json_headers();
    $error = ['code' => $code, 'message' => $message !== '' ? $message : 'Não foi possível processar a solicitação.'];
    if (!empty($fields)) {
        $error['fields'] = $fields;
    }
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------ */
/* Cliente: IP, user agent                                            */
/* ------------------------------------------------------------------ */

function pg_client_ip(): string
{
    // Shared hosting: confiamos apenas no REMOTE_ADDR. Cabeçalhos de proxy
    // não são confiáveis para decisões de segurança.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return mb_substr((string) $ip, 0, 45);
}

function pg_user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
}

/* ------------------------------------------------------------------ */
/* Tokens                                                             */
/* ------------------------------------------------------------------ */

function pg_generate_token(): string
{
    return bin2hex(random_bytes(24)); // 48 hex chars, não sequencial
}

/* ------------------------------------------------------------------ */
/* Sessão do formulário                                               */
/* ------------------------------------------------------------------ */

/**
 * Carrega uma sessão do formulário pelo token público. Retorna a linha
 * (id, id_company, id_user, status, current_block, ...) ou null.
 */
function pg_session_by_token(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, session_token, id_company, id_user, email_informado,
                form_slug, form_version, status, current_block
           FROM pg_form_sessions
          WHERE session_token = :token
          LIMIT 1'
    );
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/* ------------------------------------------------------------------ */
/* LGPD: texto exato do aceite (gravado como prova em pg_consents).    */
/* Ao alterar o texto, incrementar PG_CONSENT_VERSION em bootstrap.php. */
/* ------------------------------------------------------------------ */

function pg_consent_text(): string
{
    return 'Declaro que compreendo que minhas respostas serão utilizadas pela '
        . 'PlanningBI exclusivamente para fins de diagnóstico estratégico da FMX, '
        . 'podendo ser analisadas de forma individual e consolidada, e autorizo o '
        . 'tratamento dos meus dados — nome, e-mail e telefone — para essa '
        . 'finalidade, conforme a LGPD.';
}
