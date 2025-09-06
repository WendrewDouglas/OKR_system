<?php
/**
 * Valida o código de verificação (5 dígitos) vinculado a um token.
 * - Entrada: JSON { token, code } OU POST token/code
 * - CSRF: cabeçalho X-CSRF-Token OU campo csrf_token (comparado à $_SESSION['csrf_token'])
 * - Saída: JSON
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/bootstrap_logging.php';
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php'; // DB_HOST, DB_NAME, DB_USER, DB_PASS, $options

/* ---------- Helpers ---------- */
function json_response(int $http, array $payload): void {
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function input_field(string $name): ?string {
    static $jsonIn = null;
    if ($jsonIn === null) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        $jsonIn = is_array($decoded) ? $decoded : [];
    }
    if (isset($jsonIn[$name])) return is_scalar($jsonIn[$name]) ? trim((string)$jsonIn[$name]) : null;
    if (isset($_POST[$name]))  return trim((string)$_POST[$name]);
    return null;
}

/* ---------- Método ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['ok'=>false,'error'=>'Método não permitido']);
}

/* ---------- CSRF ---------- */
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfPost   = $_POST['csrf_token'] ?? '';
$csrfSess   = $_SESSION['csrf_token'] ?? '';

if ((!$csrfHeader && !$csrfPost) || !hash_equals($csrfSess, $csrfHeader ?: $csrfPost)) {
    app_log('EMAIL_VERIFY_CHECK_CSRF_FAIL');
    json_response(400, ['ok'=>false,'error'=>'CSRF inválido']);
}

/* ---------- Entrada ---------- */
$token = input_field('token');
$code  = input_field('code');

app_log('EMAIL_VERIFY_CHECK_START', ['token' => $token ? substr($token, 0, 12).'…' : null]);

if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    json_response(400, ['ok'=>false,'error'=>'Token inválido']);
}
if (!$code || !preg_match('/^\d{5}$/', $code)) {
    json_response(400, ['ok'=>false,'error'=>'Código inválido']);
}

/* ---------- DB ---------- */
try {
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (Throwable $e) {
    app_log('EMAIL_VERIFY_CHECK_DB_CONN_ERROR', ['message'=>$e->getMessage()]);
    json_response(500, ['ok'=>false,'error'=>'Erro de conexão']);
}

/* ---------- Validação com lock ---------- */
try {
    $pdo->beginTransaction();

    $sel = $pdo->prepare("
        SELECT id, email, code_hash, status, attempts, expires_at
          FROM okr_email_verifications
         WHERE token = :t
         FOR UPDATE
    ");
    $sel->execute([':t' => $token]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack();
        app_log('EMAIL_VERIFY_CHECK_NOT_FOUND', ['token'=>substr($token,0,12).'…']);
        json_response(400, ['ok'=>false,'error'=>'Token não encontrado']);
    }

    if ($row['status'] === 'verified') {
        $pdo->commit();
        app_log('EMAIL_VERIFY_CHECK_ALREADY_VERIFIED', ['token'=>substr($token,0,12).'…']);
        json_response(200, ['ok'=>true]);
    }

    if (strtotime($row['expires_at']) < time()) {
        $pdo->prepare("UPDATE okr_email_verifications SET status='expired' WHERE id=?")
            ->execute([$row['id']]);
        $pdo->commit();
        app_log('EMAIL_VERIFY_CHECK_EXPIRED', ['token'=>substr($token,0,12).'…']);
        json_response(400, ['ok'=>false,'error'=>'Código expirado']);
    }

    $attempts = (int)$row['attempts'];
    if ($attempts >= 5) {
        $pdo->prepare("UPDATE okr_email_verifications SET status='expired' WHERE id=?")
            ->execute([$row['id']]);
        $pdo->commit();
        app_log('EMAIL_VERIFY_CHECK_TOO_MANY_ATTEMPTS', ['token'=>substr($token,0,12).'…','attempts'=>$attempts]);
        json_response(429, ['ok'=>false,'error'=>'Muitas tentativas. Solicite novo código.']);
    }

    if (!password_verify($code, $row['code_hash'])) {
        $pdo->prepare("UPDATE okr_email_verifications SET attempts = attempts + 1 WHERE id=?")
            ->execute([$row['id']]);
        $pdo->commit();
        app_log('EMAIL_VERIFY_CHECK_INCORRECT', ['token'=>substr($token,0,12).'…','attempts'=>($attempts+1)]);
        json_response(400, ['ok'=>false,'error'=>'Código incorreto']);
    }

    $pdo->prepare("
        UPDATE okr_email_verifications
           SET status='verified', verified_at=NOW()
         WHERE id=?
    ")->execute([$row['id']]);

    $pdo->commit();
    app_log('EMAIL_VERIFY_CHECK_OK', ['token'=>substr($token,0,12).'…']);
    json_response(200, ['ok'=>true]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    app_log('EMAIL_VERIFY_CHECK_EXCEPTION', ['message'=>$e->getMessage()]);
    json_response(500, ['ok'=>false,'error'=>'Erro interno na validação do código']);
}
