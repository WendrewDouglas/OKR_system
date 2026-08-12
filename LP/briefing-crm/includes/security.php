<?php
declare(strict_types=1);

// =============================================================
// Segurança do módulo "Briefing CRM": sessão própria (BCSESS), CSRF,
// honeypot, rate limit persistido em bc_rate_limits e validadores.
// Espelha LP/perspectivas/includes/security.php.
// =============================================================

require_once __DIR__ . '/db.php';

/* ------------------------------------------------------------------ */
/* Sessão isolada do módulo                                            */
/* ------------------------------------------------------------------ */

function bc_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Nome próprio: não colide com o app OKR (PHPSESSID) nem com PGSESS.
    session_name('BCSESS');
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
/* CSRF                                                               */
/* ------------------------------------------------------------------ */

function bc_csrf_token(): string
{
    bc_session_start();
    if (empty($_SESSION['bc_csrf'])) {
        $_SESSION['bc_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['bc_csrf'];
}

function bc_csrf_check(?string $token): bool
{
    bc_session_start();
    $expected = $_SESSION['bc_csrf'] ?? '';
    return is_string($token) && $token !== '' && $expected !== ''
        && hash_equals($expected, $token);
}

/* ------------------------------------------------------------------ */
/* Honeypot                                                           */
/* ------------------------------------------------------------------ */

/**
 * Campo isca "website": invisível via CSS, bots tendem a preencher.
 * Retorna true se for spam.
 */
function bc_honeypot_tripped(array $input): bool
{
    $hp = $input['website'] ?? '';
    return is_string($hp) && trim($hp) !== '';
}

/* ------------------------------------------------------------------ */
/* Rate limit (janela fixa por rate_key, persistido)                  */
/* ------------------------------------------------------------------ */

/**
 * Retorna true se a requisição está DENTRO do limite (permitida).
 * rate_key ex.: "start:<ip>" ou "finish:<email>".
 */
function bc_rate_limit(string $rateKey, int $maxHits, int $windowSeconds): bool
{
    $rateKey = mb_substr($rateKey, 0, 190);
    $pdo = null;
    try {
        $pdo = bc_db();
        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id, hits, window_start FROM bc_rate_limits WHERE rate_key = ? FOR UPDATE'
        );
        $stmt->execute([$rateKey]);
        $row = $stmt->fetch();

        if ($row === false) {
            $pdo->prepare('INSERT INTO bc_rate_limits (rate_key, hits, window_start) VALUES (?, 1, ?)')
                ->execute([$rateKey, $now]);
            $pdo->commit();
            return true;
        }

        $elapsed = strtotime($now) - strtotime((string) $row['window_start']);

        if ($elapsed > $windowSeconds) {
            $pdo->prepare('UPDATE bc_rate_limits SET hits = 1, window_start = ? WHERE id = ?')
                ->execute([$now, $row['id']]);
            $pdo->commit();
            return true;
        }

        if ((int) $row['hits'] >= $maxHits) {
            $pdo->commit();
            return false;
        }

        $pdo->prepare('UPDATE bc_rate_limits SET hits = hits + 1 WHERE id = ?')
            ->execute([$row['id']]);
        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Erro de infra não bloqueia respondente legítimo.
        error_log('[BC] rate_limit erro: ' . $e->getMessage());
        return true;
    }
}

/* ------------------------------------------------------------------ */
/* Validação / sanitização                                            */
/* ------------------------------------------------------------------ */

function bc_str(array $input, string $key, int $max = 255): string
{
    $v = $input[$key] ?? '';
    return bc_clean_str(is_string($v) ? $v : '', $max);
}

/**
 * trim + remove caracteres de controle (preserva \n \r \t) + corta.
 */
function bc_clean_str(string $v, int $max = 255): string
{
    $v = trim($v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}

function bc_valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 150;
}

/**
 * Normaliza WhatsApp para dígitos. Aceita 10 a 13 dígitos (com/sem DDI).
 * Retorna '' se inválido.
 */
function bc_normalize_whatsapp(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    $len = strlen($digits);
    if ($len < 10 || $len > 13) {
        return '';
    }
    return $digits;
}

function bc_normalize_name(string $raw): string
{
    $v = bc_clean_str($raw, 150);
    $v = preg_replace('/\s+/u', ' ', $v) ?? '';
    return trim($v);
}
