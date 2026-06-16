<?php
declare(strict_types=1);

// =============================================================
// Segurança do módulo LP_IA: sessão, CSRF, honeypot, rate limit,
// reCAPTCHA opcional e validação/sanitização de campos.
// =============================================================

require_once __DIR__ . '/db.php';

/* ------------------------------------------------------------------ */
/* Sessão isolada do módulo                                            */
/* ------------------------------------------------------------------ */

function lp_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Nome de sessão próprio para não colidir com o app OKR.
    session_name('LPIASESS');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    @session_start();
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

function lp_csrf_token(): string
{
    lp_session_start();
    if (empty($_SESSION['lp_csrf'])) {
        $_SESSION['lp_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['lp_csrf'];
}

function lp_csrf_check(?string $token): bool
{
    lp_session_start();
    $expected = $_SESSION['lp_csrf'] ?? '';
    return is_string($token) && $token !== '' && $expected !== ''
        && hash_equals($expected, $token);
}

/* ------------------------------------------------------------------ */
/* Honeypot                                                            */
/* ------------------------------------------------------------------ */

/**
 * Campo isca "website". Bots tendem a preencher; humanos não veem (CSS).
 * Retorna true se for spam (campo preenchido).
 */
function lp_honeypot_tripped(array $input): bool
{
    $hp = $input['website'] ?? '';
    return is_string($hp) && trim($hp) !== '';
}

/* ------------------------------------------------------------------ */
/* Rate limit (janela fixa por bucket + IP, persistido em lp_rate_limits) */
/* ------------------------------------------------------------------ */

/**
 * Retorna true se a requisição está DENTRO do limite (permitida).
 */
function lp_rate_limit(string $bucket, int $maxHits, int $windowSeconds): bool
{
    try {
        $pdo = lp_db();
        $ip  = lp_client_ip();
        $now = new DateTimeImmutable('now');

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id, hits, window_start
               FROM lp_rate_limits
              WHERE bucket = ? AND ip_address = ?
              FOR UPDATE'
        );
        $stmt->execute([$bucket, $ip]);
        $row = $stmt->fetch();

        if ($row === false) {
            $pdo->prepare(
                'INSERT INTO lp_rate_limits (bucket, ip_address, hits, window_start)
                 VALUES (?, ?, 1, ?)'
            )->execute([$bucket, $ip, $now->format('Y-m-d H:i:s')]);
            $pdo->commit();
            return true;
        }

        $windowStart = new DateTimeImmutable($row['window_start']);
        $elapsed = $now->getTimestamp() - $windowStart->getTimestamp();

        if ($elapsed > $windowSeconds) {
            // Nova janela.
            $pdo->prepare(
                'UPDATE lp_rate_limits SET hits = 1, window_start = ? WHERE id = ?'
            )->execute([$now->format('Y-m-d H:i:s'), $row['id']]);
            $pdo->commit();
            return true;
        }

        if ((int) $row['hits'] >= $maxHits) {
            $pdo->commit();
            return false;
        }

        $pdo->prepare('UPDATE lp_rate_limits SET hits = hits + 1 WHERE id = ?')
            ->execute([$row['id']]);
        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Em caso de erro de infra, não bloqueia o usuário legítimo.
        error_log('[LP_IA] rate_limit erro: ' . $e->getMessage());
        return true;
    }
}

/* ------------------------------------------------------------------ */
/* reCAPTCHA (OPCIONAL / configurável)                                 */
/* ------------------------------------------------------------------ */

function lp_captcha_enabled(): bool
{
    // Controle ESPECIFICO da LP, independente do reCAPTCHA do app OKR.
    // Default DESLIGADO: a LP protege-se com honeypot + rate limit. Ative com
    // LP_CAPTCHA=on apenas se o front da LP injetar o token reCAPTCHA.
    $flag = getenv('LP_CAPTCHA');
    if ($flag === false || !in_array(strtolower((string) $flag), ['1', 'true', 'on', 'yes'], true)) {
        return false;
    }
    $provider = defined('CAPTCHA_PROVIDER') ? strtolower((string) CAPTCHA_PROVIDER) : 'off';
    $secret   = defined('CAPTCHA_SECRET') ? (string) CAPTCHA_SECRET : '';
    return $provider !== 'off' && $provider !== '' && $secret !== '';
}


/**
 * Verifica o token do reCAPTCHA quando habilitado. Se desabilitado, retorna true
 * (a proteção fica por conta de honeypot + rate limit).
 */
function lp_captcha_verify(?string $token): bool
{
    if (!lp_captcha_enabled()) {
        return true;
    }
    if (!is_string($token) || $token === '') {
        return false;
    }

    $provider = strtolower((string) CAPTCHA_PROVIDER);
    $url = $provider === 'hcaptcha'
        ? 'https://hcaptcha.com/siteverify'
        : 'https://www.google.com/recaptcha/api/siteverify';

    $postData = http_build_query([
        'secret'   => CAPTCHA_SECRET,
        'response' => $token,
        'remoteip' => lp_client_ip(),
    ]);

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postData,
                CURLOPT_TIMEOUT        => 8,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $postData,
                'timeout' => 8,
            ]]);
            $resp = @file_get_contents($url, false, $ctx);
        }

        if (!is_string($resp) || $resp === '') {
            // Falha de rede: não derruba o lead por causa do captcha.
            error_log('[LP_IA] captcha: sem resposta do provedor.');
            return true;
        }

        $data = json_decode($resp, true);
        if (!is_array($data) || empty($data['success'])) {
            return false;
        }

        // reCAPTCHA v3: valida score mínimo se presente.
        if (isset($data['score']) && defined('RECAPTCHA_MIN_SCORE')) {
            return (float) $data['score'] >= (float) RECAPTCHA_MIN_SCORE;
        }
        return true;
    } catch (\Throwable $e) {
        error_log('[LP_IA] captcha verify erro: ' . $e->getMessage());
        return true;
    }
}

/* ------------------------------------------------------------------ */
/* Validação / sanitização                                             */
/* ------------------------------------------------------------------ */

function lp_str(array $input, string $key, int $max = 255): string
{
    $v = $input[$key] ?? '';
    if (!is_string($v)) {
        $v = '';
    }
    $v = trim($v);
    // Remove caracteres de controle.
    $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}

function lp_valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 190;
}

/**
 * Normaliza WhatsApp para dígitos. Aceita 10 a 13 dígitos (com/sem DDI).
 * Retorna string só de dígitos ou '' se inválido.
 */
function lp_normalize_whatsapp(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    $len = strlen($digits);
    if ($len < 10 || $len > 13) {
        return '';
    }
    return $digits;
}
