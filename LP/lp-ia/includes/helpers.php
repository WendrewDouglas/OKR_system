<?php
declare(strict_types=1);

// =============================================================
// Helpers gerais do módulo LP_IA: settings, JSON I/O, IP/UA,
// logging de eventos, e-mail (SMTP via config do OKR) e tokens.
// =============================================================

require_once __DIR__ . '/db.php';

/* ------------------------------------------------------------------ */
/* Respostas JSON                                                      */
/* ------------------------------------------------------------------ */

function lp_json_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

function lp_input(): array
{
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $j   = json_decode($raw ?: '[]', true);
        return is_array($j) ? $j : [];
    }
    return $_POST;
}

function lp_ok(array $data = []): void
{
    lp_json_headers();
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function lp_fail(string $message, int $code = 400, array $extra = []): void
{
    http_response_code($code);
    lp_json_headers();
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------ */
/* Cliente: IP, user agent, referrer, UTM                             */
/* ------------------------------------------------------------------ */

function lp_client_ip(): string
{
    // Em shared hosting confiamos no REMOTE_ADDR. Cabeçalhos de proxy não são
    // confiáveis e não são usados para decisões de segurança.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return mb_substr((string) $ip, 0, 45);
}

function lp_user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function lp_referrer(): ?string
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $ref = trim((string) $ref);
    return $ref === '' ? null : mb_substr($ref, 0, 400);
}

/**
 * Normaliza/limita um campo UTM ou similar.
 */
function lp_clean_param($value, int $max = 120): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    // Mantém apenas caracteres seguros e razoáveis para UTM.
    $value = preg_replace('/[^\w\s.\-:+%@\/]/u', '', $value) ?? '';
    return mb_substr($value, 0, $max);
}

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

function lp_settings(int $landingId): array
{
    static $cache = [];
    if (isset($cache[$landingId])) {
        return $cache[$landingId];
    }

    $stmt = lp_db()->prepare(
        'SELECT setting_key, setting_value FROM lp_settings WHERE landing_id = ?'
    );
    $stmt->execute([$landingId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['setting_key']] = $row['setting_value'];
    }

    return $cache[$landingId] = $out;
}

function lp_setting(int $landingId, string $key, $default = null)
{
    $all = lp_settings($landingId);
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function lp_setting_bool(int $landingId, string $key, bool $default = false): bool
{
    $v = lp_setting($landingId, $key);
    if ($v === null) {
        return $default;
    }
    return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
}

function lp_setting_int(int $landingId, string $key, int $default = 0): int
{
    $v = lp_setting($landingId, $key);
    return ($v === null || $v === '') ? $default : (int) $v;
}

function lp_money_br(int $cents): string
{
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

/* ------------------------------------------------------------------ */
/* Cupons                                                              */
/* ------------------------------------------------------------------ */

/**
 * Resolve um cupom válido para a landing. Retorna o registro (array) quando
 * ativo, dentro da validade e dentro do limite de usos; caso contrário null.
 * O preço EXIBIDO e COBRADO vem sempre daqui (nunca do frontend).
 */
function lp_resolve_coupon(int $landingId, string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }

    $stmt = lp_db()->prepare(
        'SELECT id, code, price_cents, label, max_uses, used_count, valid_until, active
           FROM lp_coupons
          WHERE landing_id = ? AND UPPER(code) = ?
          LIMIT 1'
    );
    $stmt->execute([$landingId, $code]);
    $row = $stmt->fetch();

    if ($row === false || (int) $row['active'] !== 1) {
        return null;
    }
    if ($row['valid_until'] !== null && $row['valid_until'] !== ''
        && strtotime($row['valid_until']) < time()) {
        return null;
    }
    if ($row['max_uses'] !== null && (int) $row['used_count'] >= (int) $row['max_uses']) {
        return null;
    }

    return $row;
}

/* ------------------------------------------------------------------ */
/* Tokens                                                              */
/* ------------------------------------------------------------------ */

function lp_generate_token(): string
{
    return bin2hex(random_bytes(16)); // 32 hex chars, não sequencial
}

/* ------------------------------------------------------------------ */
/* Eventos / tracking                                                  */
/* ------------------------------------------------------------------ */

function lp_log_event(int $landingId, string $eventType, array $opts = []): void
{
    try {
        $meta = $opts['metadata'] ?? null;
        $metaJson = null;
        if ($meta !== null) {
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
            if ($metaJson === false) {
                $metaJson = null;
            }
        }

        $stmt = lp_db()->prepare(
            'INSERT INTO lp_events
                (landing_id, lead_id, event_type, coupon_code,
                 utm_source, utm_medium, utm_campaign, referrer,
                 metadata_json, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $landingId,
            $opts['lead_id']     ?? null,
            $eventType,
            $opts['coupon_code'] ?? null,
            $opts['utm_source']  ?? null,
            $opts['utm_medium']  ?? null,
            $opts['utm_campaign'] ?? null,
            $opts['referrer']    ?? lp_referrer(),
            $metaJson,
            lp_client_ip(),
            lp_user_agent(),
        ]);
    } catch (\Throwable $e) {
        // Tracking nunca deve quebrar a experiência do usuário.
        error_log('[LP_IA] log_event falhou: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* LGPD: consentimento e transparência (fonte única para página + prova) */
/* ------------------------------------------------------------------ */

/**
 * Texto exato do aceite (checkbox). Gravado como prova em lp_consents.
 * Ao alterar este texto, incrementar LP_IA_CONSENT_VERSION em bootstrap.php.
 */
function lp_consent_text(): string
{
    return 'Autorizo o contato por e-mail, WhatsApp e telefone sobre este '
        . 'treinamento e comunicações relacionadas. Declaro estar ciente de que '
        . 'a participação é opcional, que esta é uma iniciativa independente da '
        . 'PlanningBI e que o treinamento não garante vaga de emprego, '
        . 'contratação ou aprovação em processos seletivos. Meus dados serão '
        . 'utilizados apenas para essas finalidades e poderão ser removidos '
        . 'mediante solicitação.';
}

/**
 * Pontos do bloco de transparência (exibidos na landing).
 */
function lp_transparency_points(): array
{
    return [
        'A participação no treinamento é totalmente opcional.',
        'O treinamento não garante vaga de emprego.',
        'O treinamento não garante aprovação em processo seletivo.',
        'Esta é uma iniciativa independente da PlanningBI.',
        'O objetivo é exclusivamente o desenvolvimento profissional.',
    ];
}

/* ------------------------------------------------------------------ */
/* E-mail (usa SMTP_* do config do OKR via PHPMailer)                  */
/* ------------------------------------------------------------------ */

function lp_send_mail(string $to, string $subject, string $html, string $textAlt = ''): bool
{
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('[LP_IA] PHPMailer indisponível; e-mail não enviado.');
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet    = 'UTF-8';
        $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'localhost';
        $mail->SMTPAuth   = true;
        $mail->Username   = defined('SMTP_USER') ? SMTP_USER : '';
        $mail->Password   = defined('SMTP_PASS') ? SMTP_PASS : '';
        $mail->Port       = defined('SMTP_PORT') ? (int) SMTP_PORT : 587;
        $mail->SMTPSecure = ((int) $mail->Port === 465)
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $fromAddr = defined('SMTP_FROM') && SMTP_FROM !== '' ? SMTP_FROM : (defined('SMTP_USER') ? SMTP_USER : '');
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'PlanningBI';
        $mail->setFrom($fromAddr, $fromName);

        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $textAlt !== '' ? $textAlt : strip_tags($html);
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log('[LP_IA] Falha ao enviar e-mail: ' . $e->getMessage());
        return false;
    }
}

/**
 * Endereço interno para notificação de novos leads.
 * Usa LP_NOTIFY_EMAIL (.env) ou cai para SMTP_FROM/SMTP_USER.
 */
function lp_internal_notify_email(): string
{
    $env = getenv('LP_NOTIFY_EMAIL');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    if (defined('SMTP_FROM') && SMTP_FROM !== '') {
        return SMTP_FROM;
    }
    return defined('SMTP_USER') ? SMTP_USER : '';
}
