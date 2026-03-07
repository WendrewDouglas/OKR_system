<?php
// OKR_system/LP/Quizz-01/auth/result_contact_submit.php
declare(strict_types=1);

// ===== Bootstrap básico + logs =====
header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set('America/Sao_Paulo');

try {
    // Carrega config e funções (3 níveis acima)
    $CONFIG = __DIR__ . '/../../../auth/config.php';
    if (!is_file($CONFIG)) {
        error_log('[RESULT_CONTACT] config_missing path=' . $CONFIG);
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'CFG_MISSING']);
        exit;
    }
    require_once $CONFIG;

    $FUNCS = __DIR__ . '/../../../auth/functions.php';
    if (is_file($FUNCS)) require_once $FUNCS;

    ini_set('log_errors', '1');

    // ===== Entrada JSON =====
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true);
    if (!is_array($in)) {
        error_log('[RESULT_CONTACT] invalid_json body=' . substr($raw,0,200));
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'INVALID_JSON']);
        exit;
    }

    $sessionToken = trim((string)($in['session_token'] ?? ''));
    $phoneE164    = trim((string)($in['telefone_e164'] ?? ''));
    $pdfHintUrl   = trim((string)($in['pdf_hint_url'] ?? '')); // ex.: /LP/Quizz-01/pdf/report_HASH.pdf

    if ($phoneE164 === '') {
        error_log('[RESULT_CONTACT] missing_phone session=' . $sessionToken);
        http_response_code(422);
        echo json_encode(['ok'=>false,'error'=>'PHONE_REQUIRED']);
        exit;
    }

    // ===== Conexão DB =====
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // ===== 1) Lead mais recente =====
    $stmt = $pdo->query("SELECT id_lead, nome, email, dt_update FROM lp001_quiz_leads ORDER BY dt_update DESC LIMIT 1");
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lead) {
        error_log('[RESULT_CONTACT] no_lead_found');
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'LEAD_NOT_FOUND']);
        exit;
    }

    $idLead   = (int)$lead['id_lead'];
    $leadNome = (string)($lead['nome'] ?? '');
    $leadMail = (string)($lead['email'] ?? '');

    // ===== 2) Atualizar telefone + opt-in =====
    $upd = $pdo->prepare("
        UPDATE lp001_quiz_leads
           SET telefone_whatsapp_e164 = :tel,
               whatsapp_optin         = 1,
               whatsapp_optin_dt      = NOW(),
               dt_update              = NOW()
         WHERE id_lead = :id
        LIMIT 1
    ");
    $upd->execute([':tel'=>$phoneE164, ':id'=>$idLead]);
    if ($upd->rowCount() < 1) {
        error_log('[RESULT_CONTACT] lead_update_fail id_lead=' . $idLead);
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'LEAD_UPDATE_FAIL']);
        exit;
    }
    error_log('[RESULT_CONTACT] lead_update_ok id_lead=' . $idLead . ' phone=' . $phoneE164);

    // ===== 3) Resolver link público do PDF (ajuste /LP -> /OKR_system/LP) =====
    // Normaliza caminho vindo do front (ex.: "/LP/Quizz-01/pdf/report_HASH.pdf")
    $pdfHintUrl = '/' . ltrim($pdfHintUrl, '/');

    // Se iniciar em /LP/, prefixa /OKR_system para casar com a estrutura real do host
    $publicPathWeb = (strpos($pdfHintUrl, '/LP/') === 0)
        ? '/OKR_system' . $pdfHintUrl
        : $pdfHintUrl;

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'planningbi.com.br';
    $publicPdfUrl = $scheme . '://' . $host . $publicPathWeb;

    // Caminho no filesystem correspondente (para log/diagnóstico)
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $srcAbs  = $docRoot . $publicPathWeb; // /home/.../public_html + /OKR_system/LP/Quizz-01/pdf/report_HASH.pdf
    if (!is_file($srcAbs)) {
        error_log('[RESULT_CONTACT] pdf_not_found src=' . $srcAbs . ' web=' . $publicPathWeb);
    } else {
        error_log('[RESULT_CONTACT] pdf_found src=' . $srcAbs);
    }

    // ===== 4) Enviar e-mail IMEDIATO (somente Wendrew) =====
    $subject = 'Novo resultado de quiz + contato (WhatsApp)';
    $leadNomeSafe = htmlspecialchars($leadNome ?: '(sem nome)', ENT_QUOTES, 'UTF-8');
    $leadMailSafe = htmlspecialchars($leadMail ?: '(sem e-mail)', ENT_QUOTES, 'UTF-8');
    $msgDateTime  = date('Y-m-d H:i:s');

    $pdfBlock = '<p><b>PDF:</b> ainda não disponível.</p>';
    if ($publicPdfUrl) {
        $safeUrl = htmlspecialchars($publicPdfUrl, ENT_QUOTES, 'UTF-8');
        $pdfBlock = <<<HTML
        <p style="margin:12px 0 18px">
          <a href="{$safeUrl}" target="_blank" rel="noopener"
             style="display:inline-block;padding:10px 14px;border-radius:8px;
                    background:#1a73e8;color:#fff;text-decoration:none;font-weight:700;">
            Baixar relatório em PDF
          </a>
        </p>
        <p style="font-size:12px;color:#555;margin-top:-10px;">Se o arquivo ainda não abrir, aguarde alguns instantes e tente novamente.</p>
        HTML;
    }

    $html = <<<HTML
    <html><head><meta charset="UTF-8"><title>Contato do Quiz</title></head>
    <body style="font-family:Arial,Helvetica,sans-serif; font-size:15px; color:#111">
      <h3>Novo contato (WhatsApp) – LP/Quizz-01</h3>
      <p><b>Nome:</b> {$leadNomeSafe}</p>
      <p><b>E-mail:</b> {$leadMailSafe}</p>
      <p><b>WhatsApp (E.164):</b> {$phoneE164}</p>
      <p><b>Data/Hora (servidor):</b> {$msgDateTime}</p>
      {$pdfBlock}
      <p><i>Envio imediato para reduzir latência.</i></p>
      <hr style="border:none; border-top:1px solid #eee; margin:16px 0">
      <p>PlanningBI – OKR System</p>
    </body></html>
    HTML;

    $sendNow = sendTransactionalMail(
        'wendrew.douglas@gmail.com',
        $subject,
        $html,
        defined('SMTP_FROM') ? SMTP_FROM : 'no-reply@planningbi.com.br',
        defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'OKR System',
        false // evita I/O extra (.eml)
    );
    if ($sendNow) {
        error_log('[RESULT_CONTACT] smtp_ok to=wendrew.douglas@gmail.com (immediate)');
    } else {
        error_log('[RESULT_CONTACT] smtp_fail to=wendrew.douglas@gmail.com (immediate)');
    }

    // ===== 5) Resposta OK =====
    echo json_encode([
        'ok'          => true,
        'id_lead'     => $idLead,
        'whats_saved' => $phoneE164,
        'pdf_saved'   => is_file($srcAbs), // reflete o arquivo real
        'pdf_path'    => $publicPathWeb,   // /OKR_system/LP/Quizz-01/pdf/report_HASH.pdf
        'pdf_public'  => $publicPdfUrl     // URL pública clicável
    ]);
    exit;

} catch (Throwable $e) {
    error_log('[RESULT_CONTACT] EXCEPTION ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'EXCEPTION','message'=>$e->getMessage()]);
    exit;
}
