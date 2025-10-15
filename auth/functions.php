<?php
/**auth/functions.php
 * Funções de e-mail transacional (verificação e reset de senha)
 * - Prioriza SMTP (PHPMailer) com Titan
 * - Fallback para mail() com envelope -f
 * - Salva cópia .eml em auth/outbox/ (auditoria)
 */

declare(strict_types=1);

// Autoload Composer (raiz OU /auth)
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) { $autoload = __DIR__ . '/vendor/autoload.php'; }
if (file_exists($autoload)) { require_once $autoload; }

require_once __DIR__ . '/config.php';

/* ---------- Logger/Mask ---------- */
if (!function_exists('app_log')) {
    function app_log(string $message, array $context = []): void {
        $ts = date('c');
        $line = '[' . $ts . '] ' . $message;
        if ($context) $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        error_log($line);
    }
}
if (!function_exists('mask_email')) {
    function mask_email(string $email): string {
        if (!str_contains($email, '@')) return $email;
        [$u, $d] = explode('@', $email, 2);
        $uMasked = mb_substr($u, 0, 1) . str_repeat('*', max(0, mb_strlen($u)-1));
        return $uMasked . '@' . $d;
    }
}

/* ---------- SMTP Host helper (IPv4 opcional) ---------- */
if (!defined('SMTP_FORCE_IPV4')) {
    define('SMTP_FORCE_IPV4', false); // altere para true se IPv6 do host der problema
}
function smtp_resolved_host(string $host): string {
    return SMTP_FORCE_IPV4 ? gethostbyname($host) : $host;
}

if (!function_exists('generateSelectorVerifier')) {
    function generateSelectorVerifier(): array {
        // selector de 16 bytes (32 hex) e verifier de 32 bytes (64 hex)
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        return [$selector, $verifier];
    }
}

if (!function_exists('hashVerifier')) {
    function hashVerifier(string $verifier): string {
        // hash consistente com pepper (não reversível)
        return hash('sha256', APP_TOKEN_PEPPER . $verifier);
    }
}

if (!function_exists('verifyCaptchaOrFail')) {
    function verifyCaptchaOrFail(?string $token, string $ip): void {
        if (CAPTCHA_PROVIDER === 'off') return;

        if (!$token) {
            throw new RuntimeException('Verificação anti-robô falhou.');
        }

        $url = (CAPTCHA_PROVIDER === 'recaptcha')
            ? 'https://www.google.com/recaptcha/api/siteverify'
            : 'https://hcaptcha.com/siteverify';

        $post = http_build_query([
            'secret'   => CAPTCHA_SECRET,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('Falha ao verificar CAPTCHA: ' . $err);
        }

        $data = json_decode($resp, true);
        $ok = (bool)($data['success'] ?? false);

        // reCAPTCHA v3: considerar a pontuação
        if (CAPTCHA_PROVIDER === 'recaptcha' && array_key_exists('score', $data)) {
            $ok = $ok && ((float)$data['score'] >= (float)RECAPTCHA_MIN_SCORE);
        }

        if (!$ok) {
            throw new RuntimeException('Verificação anti-robô inválida.');
        }
    }
}

if (!function_exists('passwordPolicyCheck')) {
    function passwordPolicyCheck(string $pwd): array {
        // ajuste conforme sua regra de cadastro; mínimo recomendado: 10+
        $ok = (mb_strlen($pwd) >= 10);
        return ['ok' => $ok, 'msg' => $ok ? '' : 'A senha deve ter ao menos 10 caracteres.'];
    }
}

if (!function_exists('bestPasswordHash')) {
    function bestPasswordHash(string $pwd): string {
        if (defined('PASSWORD_ARGON2ID')) {
            // custos razoáveis; ajuste conforme o host
            return password_hash($pwd, PASSWORD_ARGON2ID, [
                'memory_cost' => 1 << 17, // 128 MB
                'time_cost'   => 3,
                'threads'     => 2,
            ]);
        }
        return password_hash($pwd, PASSWORD_DEFAULT);
    }
}

if (!function_exists('rateLimitResetRequestOrFail')) {
    function rateLimitResetRequestOrFail(PDO $pdo, ?int $userId, string $ip): void {
        // Limites sugeridos: 3 por usuário/15min e 10 por IP/15min
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM usuarios_password_resets
            WHERE ip_request = ? AND created_at >= (NOW() - INTERVAL 15 MINUTE)
        ");
        $st->execute([$ip]);
        $cntIp = (int)$st->fetchColumn();
        if ($cntIp >= 10) {
            throw new RuntimeException('Muitos pedidos recentes. Tente novamente em alguns minutos.');
        }

        if ($userId !== null) {
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM usuarios_password_resets
                WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 15 MINUTE)
            ");
            $st->execute([$userId]);
            $cntUser = (int)$st->fetchColumn();
            if ($cntUser >= 3) {
                throw new RuntimeException('Muitos pedidos recentes. Tente novamente em alguns minutos.');
            }
        }
    }
}



/* ---------- Wrapper de envio ---------- */
function sendTransactionalMail(
    string $to,
    string $subject,
    string $html,
    string $from = 'no-reply@planningbi.com.br',
    string $fromName = 'OKR System',
    bool $saveOutbox = true
): bool {
    // Auditoria .eml
    $savedFile = null;
    if ($saveOutbox) {
        $outDir = __DIR__ . '/outbox';
        if (!is_dir($outDir)) @mkdir($outDir, 0775, true);
        $messageId = sprintf('<%s@planningbi.com.br>', bin2hex(random_bytes(8)));
        $raw = "Message-ID: {$messageId}\r\n";
        $raw .= "Date: " . date('r') . "\r\n";
        $raw .= "MIME-Version: 1.0\r\n";
        $raw .= "Content-Type: text/html; charset=UTF-8\r\n";
        $raw .= "From: {$fromName} <{$from}>\r\n";
        $raw .= "Reply-To: {$from}\r\n";
        $raw .= "Subject: {$subject}\r\n";
        $raw .= "To: {$to}\r\n\r\n";
        $raw .= $html;
        $fname = sprintf('%s/%s_%s.eml', $outDir, date('Ymd_His'), preg_replace('/[^a-z0-9_.-]+/i','_', $to));
        @file_put_contents($fname, $raw);
        if (is_file($fname)) $savedFile = basename($fname);
    }

    // Preferência SMTP se disponível
    $phpmailerAvailable = class_exists(\PHPMailer\PHPMailer\PHPMailer::class);
    $hasSMTP = defined('SMTP_HOST') && SMTP_HOST;

    if ($phpmailerAvailable && $hasSMTP) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = smtp_resolved_host(SMTP_HOST);
            $mail->Port       = (int)(SMTP_PORT ?? 587);
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER ?? '';
            $mail->Password   = SMTP_PASS ?? '';
            $mail->SMTPSecure = ($mail->Port === 465)
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet    = 'UTF-8';
            // TLS 1.2 forçado (alguns hosts antigos)
            $mail->SMTPOptions = ['ssl' => ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]];

            // Remetente: para máxima compatibilidade, use a própria mailbox autenticada
            $sender = defined('SMTP_FROM') && SMTP_FROM ? SMTP_FROM : (defined('SMTP_USER') ? SMTP_USER : $from);
            $senderName = defined('SMTP_FROM_NAME') && SMTP_FROM_NAME ? SMTP_FROM_NAME : $fromName;
            $mail->setFrom($sender, $senderName);
            $mail->Sender = $sender; // Return-Path (envelope)

            // Se quiser preservar o "from" passado para respostas
            if (strcasecmp($sender, $from) !== 0) {
                $mail->addReplyTo($from, $fromName);
            }

            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->msgHTML($html);
            $mail->AltBody = strip_tags($html);

            $mail->send();

            app_log('SMTP_SEND_OK', [
                'to'   => mask_email($to),
                'file' => $savedFile,
                'host' => SMTP_HOST,
                'port' => (int)(SMTP_PORT ?? 587)
            ]);
            return true;
        } catch (\Throwable $e) {
            app_log('SMTP_SEND_FAIL', [
                'to'    => mask_email($to),
                'error' => $e->getMessage(),
                'host'  => SMTP_HOST,
                'port'  => (int)(SMTP_PORT ?? 587)
            ]);
            // Continua no fallback
        }
    }

    // Fallback mail()
    $sender = defined('SMTP_FROM') && SMTP_FROM ? SMTP_FROM : $from;
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$sender}>\r\n";
    if (strcasecmp($sender, $from) !== 0) {
        $headers .= "Reply-To: {$from}\r\n";
    }
    $headers .= "X-Mailer: OKRSystem/1.0\r\n";

    $params = "-f {$sender}";
    $ok = @mail($to, $subject, $html, $headers, $params);

    app_log('MAIL_FALLBACK_SEND', [
        'to'     => mask_email($to),
        'result' => $ok ? 'accepted_by_mail' : 'mail_returned_false',
        'file'   => $savedFile
    ]);

    if (!$ok) {
        $ok2 = @mail($to, $subject, $html, $headers);
        app_log('MAIL_FALLBACK_RETRY', [
            'to'     => mask_email($to),
            'result' => $ok2 ? 'accepted_by_mail' : 'mail_returned_false'
        ]);
        return $ok2;
    }
    return true;
}

/* ---------- E-mails específicos ---------- */
function sendPasswordResetEmail(string $to, string $selector, string $verifier): bool {
    $subject = 'Recuperação de senha – OKR System';

    // Monte a URL com selector + verifier (HTTPS!)
    $base   = 'https://planningbi.com.br/OKR_system/views/password_reset.php';
    $query  = http_build_query(['selector' => $selector, 'verifier' => $verifier]);
    $link   = $base . '?' . $query;

    $html = <<<HTML
    <html>
    <head><meta charset="UTF-8"><title>Recuperação de Senha</title></head>
    <body style="font-family:Arial,Helvetica,sans-serif; font-size:15px; color:#111">
      <p>Olá,</p>
      <p>Use o link abaixo para redefinir sua senha do OKR System (expira em 1 hora):</p>
      <p><a href="{$link}" target="_blank" rel="noopener">Redefinir minha senha</a></p>
      <p>Se você não solicitou, pode ignorar este aviso com segurança.</p>
      <hr style="border:none; border-top:1px solid #eee; margin:16px 0">
      <p>PlanningBI – OKR System</p>
    </body></html>
    HTML;

    $from     = defined('SMTP_FROM')      ? SMTP_FROM      : 'no-reply@planningbi.com.br';
    $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'OKR System';

    return sendTransactionalMail($to, $subject, $html, $from, $fromName);
}

function sendEmailVerificationCode(string $to, string $code): bool {
    $subject = 'Seu código de verificação – OKR System';
    $ttlMins = 10;

    $html = <<<HTML
    <html><head><meta charset="UTF-8"><title>Código de verificação</title></head>
    <body style="font-family:Arial,Helvetica,sans-serif; font-size:15px; color:#111">
      <p>Olá,</p>
      <p>Seu código de verificação é:</p>
      <p style="font-size:26px; letter-spacing:4px; font-weight:bold">{$code}</p>
      <p>Ele expira em {$ttlMins} minutos. Se você não solicitou, ignore este e-mail.</p>
      <hr style="border:none; border-top:1px solid #eee; margin:16px 0">
      <p>PlanningBI – OKR System</p>
    </body></html>
    HTML;

    $from    = defined('SMTP_FROM') ? SMTP_FROM : 'no-reply@planningbi.com.br';
    $fromName= defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'OKR System';

    return sendTransactionalMail($to, $subject, $html, $from, $fromName);
}


/* =======================================================================
 *  NOVO: Helpers para fluxo de boas-vindas com criação de senha
 * ======================================================================= */

if (!function_exists('createPasswordReset')) {
    /**
     * Cria um reset de senha (selector + verifier) para o usuário e registra em usuarios_password_resets.
     * Retorna [selector, verifier, expiraEmDateTimeStr].
     */
    function createPasswordReset(PDO $pdo, int $userId, string $ip = '', string $ua = '', int $ttlSeconds = 3600): array {
        // Rate limit defensivo para não gerar múltiplos resets em massa
        rateLimitResetRequestOrFail($pdo, $userId, $ip);

        [$selector, $verifier] = generateSelectorVerifier();
        $verifierHash = hashVerifier($verifier);

        $sql = "
            INSERT INTO usuarios_password_resets
                (user_id, selector, verifier_hash, expira_em, created_at, ip_request, user_agent_request)
            VALUES
                (:uid,    :sel,     :vh,           DATE_ADD(NOW(), INTERVAL :ttl SECOND), NOW(), :ip, :ua)
        ";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':uid' => $userId,
            ':sel' => $selector,
            ':vh'  => $verifierHash,
            ':ttl' => $ttlSeconds,
            ':ip'  => substr($ip ?? '', 0, 45),
            ':ua'  => substr($ua ?? '', 255),
        ]);

        $expira = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))
                    ->add(new DateInterval('PT' . max(1, (int)$ttlSeconds) . 'S'))
                    ->format('Y-m-d H:i:s');

        return [$selector, $verifier, $expira];
    }
}

if (!function_exists('sendWelcomeEmailWithReset')) {
    /**
     * E-mail de boas-vindas com link para criar a senha (mesma página de reset).
     */
    function sendWelcomeEmailWithReset(string $to, string $name, ?string $orgName, string $selector, string $verifier): bool {
        $base   = 'https://planningbi.com.br/OKR_system/views/password_reset.php';
        $query  = http_build_query(['selector' => $selector, 'verifier' => $verifier]);
        $link   = $base . '?' . $query;

        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $orgTxt = $orgName ? " na <b>".htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8')."</b>" : '';
        $subject = 'Bem-vindo(a) ao OKR System';
        $html = <<<HTML
        <html><head><meta charset="UTF-8"><title>Boas-vindas</title></head>
        <body style="font-family:Arial,Helvetica,sans-serif; font-size:15px; color:#111">
          <p>Olá, <b>{$safeName}</b>!</p>
          <p>Sua conta do OKR System foi criada{$orgTxt}. Para começar, crie sua senha no link abaixo (válido por 1 hora):</p>
          <p><a href="{$link}" target="_blank" rel="noopener">Criar minha senha e acessar</a></p>
          <p>Se você não estava esperando este e-mail, pode ignorá-lo.</p>
          <hr style="border:none; border-top:1px solid #eee; margin:16px 0">
          <p>PlanningBI – OKR System</p>
        </body></html>
        HTML;

        $from     = defined('SMTP_FROM') ? SMTP_FROM : 'no-reply@planningbi.com.br';
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'OKR System';

        return sendTransactionalMail($to, $subject, $html, $from, $fromName);
    }
}
