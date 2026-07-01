<?php
declare(strict_types=1);

// =============================================================
// Segurança do módulo "Perspectivas de Gestão": sessão própria (PGSESS),
// CSRF, honeypot, rate limit persistido em pg_rate_limits e helpers de
// validação/sanitização. Espelha LP/lp-ia/includes/security.php.
// =============================================================

require_once __DIR__ . '/db.php';

/* ------------------------------------------------------------------ */
/* Sessão isolada do módulo                                            */
/* ------------------------------------------------------------------ */

function pg_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Nome de sessão próprio para não colidir com o app OKR nem com a LP_IA.
    session_name('PGSESS');
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

function pg_csrf_token(): string
{
    pg_session_start();
    if (empty($_SESSION['pg_csrf'])) {
        $_SESSION['pg_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['pg_csrf'];
}

function pg_csrf_check(?string $token): bool
{
    pg_session_start();
    $expected = $_SESSION['pg_csrf'] ?? '';
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
function pg_honeypot_tripped(array $input): bool
{
    $hp = $input['website'] ?? '';
    return is_string($hp) && trim($hp) !== '';
}

/* ------------------------------------------------------------------ */
/* Rate limit (janela fixa por rate_key, persistido em pg_rate_limits) */
/* ------------------------------------------------------------------ */

/**
 * Retorna true se a requisição está DENTRO do limite (permitida).
 * rate_key ex.: "start:<ip>" ou "finish:<email>".
 */
function pg_rate_limit(string $rateKey, int $maxHits, int $windowSeconds): bool
{
    $rateKey = mb_substr($rateKey, 0, 190);
    try {
        $pdo = pg_db();
        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id, hits, window_start
               FROM pg_rate_limits
              WHERE rate_key = ?
              FOR UPDATE'
        );
        $stmt->execute([$rateKey]);
        $row = $stmt->fetch();

        if ($row === false) {
            $pdo->prepare(
                'INSERT INTO pg_rate_limits (rate_key, hits, window_start)
                 VALUES (?, 1, ?)'
            )->execute([$rateKey, $now]);
            $pdo->commit();
            return true;
        }

        $elapsed = strtotime($now) - strtotime((string) $row['window_start']);

        if ($elapsed > $windowSeconds) {
            // Nova janela.
            $pdo->prepare('UPDATE pg_rate_limits SET hits = 1, window_start = ? WHERE id = ?')
                ->execute([$now, $row['id']]);
            $pdo->commit();
            return true;
        }

        if ((int) $row['hits'] >= $maxHits) {
            $pdo->commit();
            return false;
        }

        $pdo->prepare('UPDATE pg_rate_limits SET hits = hits + 1 WHERE id = ?')
            ->execute([$row['id']]);
        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Em caso de erro de infra, não bloqueia o usuário legítimo.
        error_log('[PG] rate_limit erro: ' . $e->getMessage());
        return true;
    }
}

/* ------------------------------------------------------------------ */
/* Validação / sanitização                                             */
/* ------------------------------------------------------------------ */

/**
 * Extrai string de um array de input, com trim, remoção de controles e corte.
 */
function pg_str(array $input, string $key, int $max = 255): string
{
    $v = $input[$key] ?? '';
    return pg_clean_str(is_string($v) ? $v : '', $max);
}

/**
 * Sanitiza uma string qualquer: trim + remove caracteres de controle
 * (preserva \n \r \t para textos longos) + corta no tamanho.
 */
function pg_clean_str(string $v, int $max = 255): string
{
    $v = trim($v);
    // Remove controles exceto tab(09), lf(0A), cr(0D).
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}

function pg_valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 150;
}

/**
 * Normaliza WhatsApp para dígitos. Aceita 10 a 13 dígitos (com/sem DDI).
 * Retorna string só de dígitos ou '' se inválido.
 */
function pg_normalize_whatsapp(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    $len = strlen($digits);
    if ($len < 10 || $len > 13) {
        return '';
    }
    return $digits;
}

/**
 * Normaliza nome: colapsa espaços, corta tamanho. Retorna '' se vazio.
 */
function pg_normalize_name(string $raw): string
{
    $v = pg_clean_str($raw, 150);
    $v = preg_replace('/\s+/u', ' ', $v) ?? '';
    return trim($v);
}

/**
 * Separa nome completo em [primeiro_nome, ultimo_nome].
 * ultimo_nome pode ser '' quando o respondente informa só um nome.
 */
function pg_split_name(string $fullName): array
{
    $full = pg_normalize_name($fullName);
    if ($full === '') {
        return ['', ''];
    }
    $parts = explode(' ', $full);
    $primeiro = array_shift($parts);
    $ultimo   = trim(implode(' ', $parts));
    return [mb_substr($primeiro, 0, 100), mb_substr($ultimo, 0, 100)];
}
