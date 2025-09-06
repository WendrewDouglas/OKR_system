<?php
/**
 * /OKR_system/auth/email_verify_request.php
 * Gera e envia o código de verificação por e-mail.
 * - Entrada: JSON { email } OU form POST (email)
 * - CSRF: header X-CSRF-Token OU campo csrf_token (compara com $_SESSION['csrf_token'])
 * - Validação: formato de e-mail + MX do domínio
 * - Rate limit: por e-mail (3/15min) e por IP (10/15min)
 * - Persistência: okr_email_verifications (status='pending', code_hash, expires_at)
 * - Envio: sendEmailVerificationCode() (PHPMailer → fallback mail())
 * - Saída: JSON { ok, token, ttl }
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/bootstrap_logging.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

function json_out(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- Método ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(405, ['ok' => false, 'error' => 'Método não permitido']);
}

/* ---------- CSRF ---------- */
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfPost   = $_POST['csrf_token'] ?? '';
$csrfSess   = $_SESSION['csrf_token'] ?? '';
if ((!$csrfHeader && !$csrfPost) || !hash_equals($csrfSess, $csrfHeader ?: $csrfPost)) {
    app_log('EMAIL_VERIFY_REQ_CSRF_FAIL');
    json_out(400, ['ok' => false, 'error' => 'CSRF inválido']);
}

/* ---------- Entrada ---------- */
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
$email = is_array($in) && array_key_exists('email', $in)
    ? trim((string)$in['email'])
    : trim((string)($_POST['email'] ?? ''));

app_log('EMAIL_VERIFY_REQ_START', ['email' => mask_email($email)]);

/* ---------- Validação de e-mail ---------- */
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    app_log('EMAIL_VERIFY_REQ_INVALID_EMAIL', ['email' => $email]);
    json_out(400, ['ok' => false, 'error' => 'E-mail inválido']);
}
[, $domain] = explode('@', $email, 2);
if (!$domain || !checkdnsrr($domain, 'MX')) {
    app_log('EMAIL_VERIFY_REQ_NO_MX', ['email' => mask_email($email), 'domain' => $domain ?? '']);
    json_out(400, ['ok' => false, 'error' => 'Domínio de e-mail sem MX válido']);
}

/* ---------- DB ---------- */
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    // $options vem do config.php; garante defaults seguros se não setado
    $pdoOpts = ($options ?? []) + [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOpts);
} catch (Throwable $e) {
    app_log('EMAIL_VERIFY_REQ_DB_CONN_ERROR', ['message' => $e->getMessage()]);
    json_out(500, ['ok' => false, 'error' => 'Erro de conexão']);
}

/* ---------- Rate limit (e-mail e IP) ---------- */
try {
    // por e-mail: máx 3 em 15min
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM okr_email_verifications
         WHERE email = ? AND created_at >= (NOW() - INTERVAL 15 MINUTE)
    ");
    $st->execute([$email]);
    $countEmail = (int)$st->fetchColumn();

    // por IP: máx 10 em 15min
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM okr_email_verifications
         WHERE ip_address = ? AND created_at >= (NOW() - INTERVAL 15 MINUTE)
    ");
    $st->execute([$ip]);
    $countIp = (int)$st->fetchColumn();

    if ($countEmail >= 3 || $countIp >= 10) {
        app_log('EMAIL_VERIFY_REQ_RATELIMIT', [
            'email' => mask_email($email),
            'cnt_email_15m' => $countEmail,
            'cnt_ip_15m'    => $countIp,
            'ip'            => $ip
        ]);
        json_out(429, ['ok' => false, 'error' => 'Muitos pedidos recentes. Tente novamente em alguns minutos.']);
    }
} catch (Throwable $e) {
    app_log('EMAIL_VERIFY_REQ_RATELIMIT_ERR', ['message' => $e->getMessage()]);
    json_out(500, ['ok' => false, 'error' => 'Erro ao aplicar rate limit']);
}

/* ---------- Geração e persistência ---------- */
$token = bin2hex(random_bytes(32));     // 64 hex
$code  = (string)random_int(10000, 99999); // 5 dígitos
$hash  = password_hash($code, PASSWORD_DEFAULT);
$ttl   = 600; // 10 minutos
$ua    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$ip    = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);

try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare("
        INSERT INTO okr_email_verifications
            (email, token, code_hash, status, attempts, expires_at, ip_address, user_agent, created_at)
        VALUES
            (?,     ?,     ?,         'pending', 0,       DATE_ADD(NOW(), INTERVAL ? SECOND), ?,         ?,          NOW())
    ");
    $ins->execute([$email, $token, $hash, $ttl, $ip, $ua]);
    $pdo->commit();

    // Segurança: nunca logar o código em texto puro
    app_log('EMAIL_VERIFY_CODE_GENERATED', [
        'email' => mask_email($email),
        'token' => substr($token, 0, 12) . '…',
        'ttl'   => $ttl,
        'ip'    => $ip
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    app_log('EMAIL_VERIFY_REQ_INSERT_ERR', ['message' => $e->getMessage()]);
    json_out(500, ['ok' => false, 'error' => 'Erro ao gerar verificação']);
}

/* ---------- Envio do e-mail ---------- */
$sent = false;
try {
    $sent = sendEmailVerificationCode($email, $code);
} catch (Throwable $e) {
    app_log('EMAIL_VERIFY_SEND_THROWABLE', ['error' => $e->getMessage()]);
    $sent = false;
}

if (!$sent) {
    // marca status = send_error para fins de auditoria
    try {
        $upd = $pdo->prepare("UPDATE okr_email_verifications SET status='send_error' WHERE token = ?");
        $upd->execute([$token]);
    } catch (Throwable $e) {
        app_log('EMAIL_VERIFY_MARK_SEND_ERROR_FAIL', ['message' => $e->getMessage()]);
    }

    app_log('EMAIL_VERIFY_SEND_FAIL', ['email' => mask_email($email), 'token' => substr($token, 0, 12) . '…']);
    json_out(502, ['ok' => false, 'error' => 'Falha ao enviar e-mail.']);
}

/* ---------- OK ---------- */
app_log('EMAIL_VERIFY_SEND_OK', ['email' => mask_email($email), 'token' => substr($token, 0, 12) . '…']);
json_out(200, ['ok' => true, 'token' => $token, 'ttl' => $ttl]);
