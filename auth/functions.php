<?php
/**
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
function sendPasswordResetEmail(string $to, string $token): bool {
    $subject = 'Recuperação de senha – OKR System';
    $link    = 'https://planningbi.com.br/OKR_system/views/password_reset.php?token=' . urlencode($token);

    $html = <<<HTML
    <html>
    <head><meta charset="UTF-8"><title>Recuperação de Senha</title></head>
    <body style="font-family:Arial,Helvetica,sans-serif; font-size:15px; color:#111">
      <p>Olá,</p>
      <p>Clicando no link abaixo você poderá redefinir sua senha do OKR System:</p>
      <p><a href="{$link}">Redefinir minha senha</a></p>
      <p>Este link expira em 1 hora.</p>
      <p>Se não foi você quem solicitou, ignore esta mensagem.</p>
      <hr style="border:none; border-top:1px solid #eee; margin:16px 0">
      <p>PlanningBI – OKR System</p>
    </body>
    </html>
    HTML;

    // From padrão: a própria mailbox autenticada
    $from    = defined('SMTP_FROM') ? SMTP_FROM : 'no-reply@planningbi.com.br';
    $fromName= defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'OKR System';

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
