<?php
// LP/Quizz-01/auth/whatsapp_send.php
// Salva telefone WhatsApp do lead e envia e-mail de notificacao
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Carrega functions.php para sendTransactionalMail()
$funcsPath = dirname(__DIR__, 3) . '/auth/functions.php';
if (is_file($funcsPath)) require_once $funcsPath;

$in = json_input();

$sessionToken = trim((string)($in['session_token'] ?? ''));
$phoneE164    = trim((string)($in['telefone_e164'] ?? ''));
$whatsOptin   = !empty($in['whatsapp_optin']);

if ($sessionToken === '') fail('session_token ausente', 400);
if ($phoneE164 === '')    fail('telefone_e164 ausente', 400);

$pdo = pdo();

// 1) Resolver sessao e lead
$st = $pdo->prepare("
    SELECT s.id_sessao, s.id_versao, s.id_lead
      FROM lp001_quiz_sessoes s
     WHERE s.session_token = ?
     LIMIT 1
");
$st->execute([$sessionToken]);
$sess = $st->fetch();

if (!$sess) fail('Sessao nao encontrada ou expirada.', 404);

$idSessao = (int)$sess['id_sessao'];
$idVersao = (int)$sess['id_versao'];
$idLead   = (int)$sess['id_lead'];

// 2) Verificar se resultado (scores) existe
$st = $pdo->prepare("
    SELECT id_score, pdf_path
      FROM lp001_quiz_scores
     WHERE id_sessao = ?
     ORDER BY dt_calculo DESC
     LIMIT 1
");
$st->execute([$idSessao]);
$score = $st->fetch();

if (!$score) fail('Resultado nao encontrado. Finalize o diagnostico antes.', 409);

// 3) Atualizar telefone no lead
$upd = $pdo->prepare("
    UPDATE lp001_quiz_leads
       SET telefone_whatsapp_e164 = ?,
           whatsapp_optin         = 1,
           whatsapp_optin_dt      = NOW(),
           dt_update              = NOW()
     WHERE id_lead = ?
     LIMIT 1
");
$upd->execute([$phoneE164, $idLead]);

// 4) Carregar dados do lead
$st = $pdo->prepare("SELECT nome, email FROM lp001_quiz_leads WHERE id_lead = ? LIMIT 1");
$st->execute([$idLead]);
$lead = $st->fetch();

if (!$lead) fail('Lead nao encontrado.', 404);

$leadNome = htmlspecialchars((string)($lead['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
$leadEmail = htmlspecialchars((string)($lead['email'] ?? ''), ENT_QUOTES, 'UTF-8');

// 5) Resolver PDF URL
$pdfPath = $score['pdf_path'] ?? null;
$pdfBlock = '<p><b>PDF:</b> ainda nao disponivel.</p>';
if ($pdfPath) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'planningbi.com.br';

    // pdf_path pode ser relativo (LP/Quizz-01/pdf/...) ou absoluto
    $webPath = (strpos($pdfPath, '/') === 0) ? $pdfPath : '/OKR_system/' . $pdfPath;
    $publicUrl = $scheme . '://' . $host . $webPath;
    $safeUrl = htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8');

    $pdfBlock = <<<HTML
    <p style="margin:12px 0 18px">
      <a href="{$safeUrl}" target="_blank" rel="noopener"
         style="display:inline-block;padding:10px 14px;border-radius:8px;
                background:#1a73e8;color:#fff;text-decoration:none;font-weight:700;">
        Baixar relatorio em PDF
      </a>
    </p>
    HTML;
}

// 6) Enviar e-mail de notificacao
$msgDateTime = date('Y-m-d H:i:s');
$subject = 'LP Quiz - Contato WhatsApp - ' . ($lead['nome'] ?: 'Lead #' . $idLead);

$html = <<<HTML
<html><head><meta charset="UTF-8"><title>Contato Quiz WhatsApp</title></head>
<body style="font-family:Arial,Helvetica,sans-serif; font-size:15px; color:#111">
  <h3>Novo contato (WhatsApp) - LP/Quizz-01</h3>
  <p><b>Nome:</b> {$leadNome}</p>
  <p><b>E-mail:</b> {$leadEmail}</p>
  <p><b>WhatsApp (E.164):</b> {$phoneE164}</p>
  <p><b>Data/Hora:</b> {$msgDateTime}</p>
  {$pdfBlock}
  <hr style="border:none; border-top:1px solid #eee; margin:16px 0">
  <p style="font-size:12px;color:#666;">
    id_sessao={$idSessao} | id_lead={$idLead} | id_versao={$idVersao}
  </p>
</body></html>
HTML;

$sent = false;
$sendErr = null;

try {
    if (function_exists('sendTransactionalMail')) {
        $sent = sendTransactionalMail(
            'wendrew.douglas@gmail.com',
            $subject,
            $html,
            defined('SMTP_FROM') ? SMTP_FROM : 'no-reply@planningbi.com.br',
            defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'OKR System',
            false
        );
    } else {
        // Fallback: tenta mail() nativo
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: OKR System <no-reply@planningbi.com.br>\r\n";
        $sent = mail('wendrew.douglas@gmail.com', $subject, $html, $headers);
    }
} catch (Throwable $e) {
    $sendErr = $e->getMessage();
    error_log('[whatsapp_send.php] Falha email: ' . $sendErr);
    $sent = false;
}

if (!$sent) {
    error_log('[whatsapp_send.php] Email nao enviado. Err: ' . ($sendErr ?? 'unknown'));
    // Nao bloqueia o usuario — telefone ja foi salvo
}

// 7) Resposta OK
echo json_encode([
    'ok'          => true,
    'status'      => $sent ? 'sent' : 'queued',
    'message'     => 'Solicitacao registrada com sucesso.',
    'id_lead'     => $idLead,
    'whats_saved' => $phoneE164,
], JSON_UNESCAPED_UNICODE);
