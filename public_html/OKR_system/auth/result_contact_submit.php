<?php
// OKR_system/auth/result_contact_submit.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';   // carrega .env, constantes e autoload (se existir)
require_once __DIR__ . '/functions.php'; // app_log(), smtp helpers, etc.

function jexit(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // --- Lê JSON ---
    $raw = file_get_contents('php://input');
    if ($raw === false) $raw = '';
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        jexit(400, ['ok'=>false, 'error'=>'JSON inválido.']);
    }

    $sessionToken = trim((string)($in['session_token'] ?? ''));
    $phoneE164    = trim((string)($in['telefone_e164'] ?? ''));
    $pdfHintUrl   = isset($in['pdf_hint_url']) ? trim((string)$in['pdf_hint_url']) : null;

    if ($sessionToken === '' || $phoneE164 === '') {
        jexit(400, ['ok'=>false, 'error'=>'Parâmetros obrigatórios ausentes: session_token e/ou telefone_e164.']);
    }

    // --- Abre PDO ---
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // =======================
    // 1) Localiza o lead
    // =======================
    // Tentativas por possíveis nomes de coluna (ajuste aqui se já souber o nome exato):
    $lead = null;
    $queries = [
        "SELECT * FROM lp001_quiz_leads WHERE session_token = :sid LIMIT 1",
        "SELECT * FROM lp001_quiz_leads WHERE sid = :sid LIMIT 1",
        "SELECT * FROM lp001_quiz_leads WHERE token = :sid LIMIT 1",
    ];
    foreach ($queries as $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([':sid' => $sessionToken]);
            $lead = $st->fetch();
            if ($lead) break;
        } catch (\Throwable $e) {
            // ignora e tenta a próxima
        }
    }
    if (!$lead) {
        jexit(404, ['ok'=>false, 'error'=>'Sessão/lead não encontrado para este session_token.']);
    }

    // Extrai campos úteis (com fallback de nomes)
    $leadId   = (int)($lead['id'] ?? $lead['id_lead'] ?? 0);
    $userId   = (int)($lead['id_usuario'] ?? 0); // pode ser 0 se não houver
    $nome     = (string)($lead['nome'] ?? $lead['lead_nome'] ?? $lead['name'] ?? 'Lead');
    $email    = (string)($lead['email'] ?? $lead['lead_email'] ?? $lead['mail'] ?? '');
    $dtRes    = (string)($lead['resultado_at'] ?? $lead['updated_at'] ?? $lead['created_at'] ?? date('Y-m-d H:i:s'));

    if ($leadId <= 0) {
        // Como última saída, usa o userId; se também não tiver, usa hash do token (somente para nome do arquivo)
        $leadId = $userId > 0 ? $userId : hexdec(substr(hash('sha1', $sessionToken), 0, 6));
    }

    // =======================
    // 2) Persiste o WhatsApp
    // =======================
    // Tenta atualizar por session_token/sid/token
    $updSQLs = [
        "UPDATE lp001_quiz_leads SET whatsapp_e164 = :w, dt_whatsapp_at = NOW() WHERE session_token = :sid",
        "UPDATE lp001_quiz_leads SET whatsapp_e164 = :w, dt_whatsapp_at = NOW() WHERE sid = :sid",
        "UPDATE lp001_quiz_leads SET whatsapp_e164 = :w, dt_whatsapp_at = NOW() WHERE token = :sid",
    ];
    $affected = 0;
    foreach ($updSQLs as $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([':w' => $phoneE164, ':sid' => $sessionToken]);
            $affected += $st->rowCount();
        } catch (\Throwable $e) {
            // segue
        }
        if ($affected > 0) break;
    }
    if ($affected === 0) {
        // Se nenhuma das colunas acima existir, tenta por id (se o SELECT trouxe id)
        if (!empty($lead['id'])) {
            $st = $pdo->prepare("UPDATE lp001_quiz_leads SET whatsapp_e164 = :w, dt_whatsapp_at = NOW() WHERE id = :id");
            $st->execute([':w' => $phoneE164, ':id' => (int)$lead['id']]);
            $affected = $st->rowCount();
        }
    }

    // =======================
    // 3) Garante o PDF
    // =======================
    $docsDir = dirname(__DIR__) . '/LP/Quizz-01/documents';
    if (!is_dir($docsDir)) {
        @mkdir($docsDir, 0775, true);
    }
    if (!is_writable($docsDir)) {
        jexit(500, ['ok'=>false, 'error'=>'Diretório de documentos não é gravável: '.$docsDir]);
    }

    $targetName = sprintf('%d_resultado_%s.pdf', $leadId, date('Ymd'));
    $targetPath = $docsDir . '/' . $targetName;

    $ensurePdf = function(string $destPath) use ($pdfHintUrl, $sessionToken) : void {
        // Se já existe, nada a fazer
        if (is_file($destPath) && filesize($destPath) > 0) return;

        // 3.a) Tenta baixar pelo pdf_hint_url
        $downloaded = false;
        $candidateUrl = $pdfHintUrl ?: '';
        if ($candidateUrl !== '') {
            $bin = @file_get_contents($candidateUrl);
            if ($bin !== false && strlen($bin) > 500) { // sanity
                @file_put_contents($destPath, $bin);
                if (is_file($destPath) && filesize($destPath) > 0) {
                    $downloaded = true;
                }
            }
        }
        if ($downloaded) return;

        // 3.b) Chama internamente o report_generate.php via cURL (mesmo host)
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
                   . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $endpoint = $baseUrl . '/OKR_system/LP/Quizz-01/auth/report_generate.php';

        $ch = curl_init($endpoint);
        $payload = json_encode(['session_token' => $sessionToken], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp !== false) {
            $j = json_decode($resp, true);
            $u = '';
            if (is_array($j)) {
                $u = (string)($j['pdf_url_segura'] ?? $j['pdf_url'] ?? '');
            }
            if ($u !== '') {
                $bin = @file_get_contents($u);
                if ($bin !== false && strlen($bin) > 500) {
                    @file_put_contents($destPath, $bin);
                    if (is_file($destPath) && filesize($destPath) > 0) return;
                }
            }
        } else {
            app_log('PDF_GEN_CALL_FAIL', ['error' => $err]);
        }

        // 3.c) Fallback final: gerar um PDF mínimo (sem libs externas)
        // Cria um PDF super simples em “modo manual” (estrutura básica) – suficiente para anexo.
        $texto = "Resultado do Quiz\n\nNome: {$GLOBALS['nome']}\nData/Hora: {$GLOBALS['dtRes']}\nWhatsApp: {$GLOBALS['phoneE164']}\n\n(Documento gerado automaticamente.)";
        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj<<>>endobj\n";
        $stream = "BT /F1 12 Tf 72 720 Td (" . str_replace(['\\','(',')',"\r","\n"], ['\\\\','\(', '\)', '', ') Tj T* ('], $texto) . ") Tj ET";
        $len = strlen($stream);
        $pdf .= "2 0 obj<< /Length $len >>stream\n$stream\nendstream\nendobj\n";
        $pdf .= "3 0 obj<</Type /Page /Parent 4 0 R /Resources<</Font<</F1 5 0 R>>>> /MediaBox[0 0 612 792] /Contents 2 0 R>>endobj\n";
        $pdf .= "4 0 obj<</Type /Pages /Kids[3 0 R] /Count 1>>endobj\n";
        $pdf .= "5 0 obj<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>endobj\n";
        $pdf .= "6 0 obj<</Type /Catalog /Pages 4 0 R>>endobj\n";
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 7\n0000000000 65535 f \n";
        $offsets = [0, 9, 26 + 0, 0, 0, 0, 0]; // não precisa ser perfeito; leitores toleram
        // Para simplificar, grava xref “burro”
        $cursor = 0; $lines = explode("\n", $pdf); $acc = 0; $idx=0; $xref = "";
        foreach ($lines as $line) { $bytes = strlen($line)+1; $xref .= sprintf("%010d 00000 n \n", $acc); $acc += $bytes; $idx++; if ($idx>=7) break; }
        $pdf = substr($pdf, 0, $xrefPos) . "xref\n0 7\n$xref" . "trailer<</Size 7/Root 6 0 R>>\nstartxref\n$xrefPos\n%%EOF";
        @file_put_contents($destPath, $pdf);
        if (!is_file($destPath) || filesize($destPath) < 50) {
            throw new RuntimeException('Falha ao garantir o PDF do resultado.');
        }
    };

    $GLOBALS['nome'] = $nome;
    $GLOBALS['dtRes'] = $dtRes;
    $GLOBALS['phoneE164'] = $phoneE164;
    $ensurePdf($targetPath);

    // =======================
    // 4) Envia o e-mail com anexo
    // =======================
    $to  = 'contato@planningbi.com.br';
    $cc  = 'wendrew.douglas@gmail.com';
    $subject = sprintf('Resultado do Quiz – %s – %s', $nome ?: 'Lead', date('Y-m-d H:i'));

    $html = <<<HTML
    <html><head><meta charset="UTF-8"><title>Resultado do Quiz</title></head>
    <body style="font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#111">
      <p><b>Nome:</b> {$nome}</p>
      <p><b>Data/hora do resultado:</b> {$dtRes}</p>
      <p><b>WhatsApp:</b> {$phoneE164}</p>
      <p>PDF gerado em anexo: <code>{$targetName}</code></p>
      <hr style="border:none;border-top:1px solid #eee;margin:12px 0">
      <p>PlanningBI – OKR System</p>
    </body></html>
    HTML;

    // Envio com PHPMailer (necessário para anexos)
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        throw new RuntimeException('PHPMailer não está disponível. Instale via Composer para permitir anexos.');
    }
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
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
        $mail->SMTPOptions = ['ssl' => ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]];

        $sender     = defined('SMTP_FROM') && SMTP_FROM ? SMTP_FROM : (defined('SMTP_USER') ? SMTP_USER : 'no-reply@planningbi.com.br');
        $senderName = defined('SMTP_FROM_NAME') && SMTP_FROM_NAME ? SMTP_FROM_NAME : 'OKR System';

        $mail->setFrom($sender, $senderName);
        $mail->Sender = $sender;

        // Destinatários
        $mail->addAddress($to);
        if ($cc) $mail->addCC($cc);

        $mail->Subject = $subject;
        $mail->msgHTML($html);
        $mail->AltBody = strip_tags($html);

        // Anexo
        $mail->addAttachment($targetPath, $targetName, \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, 'application/pdf');

        $mail->send();

        app_log('RESULT_MAIL_SENT', [
            'to'   => mask_email($to),
            'cc'   => mask_email($cc),
            'file' => basename($targetPath),
        ]);
    } catch (\Throwable $e) {
        app_log('RESULT_MAIL_FAIL', ['error' => $e->getMessage()]);
        throw new RuntimeException('Falha ao enviar e-mail com anexo: '.$e->getMessage());
    }

    // Resposta OK
    jexit(200, [
        'ok'            => true,
        'lead_id'       => $leadId,
        'user_id'       => $userId,
        'pdf_path'      => $targetPath,
        'pdf_filename'  => $targetName,
        'email_to'      => $to,
        'email_cc'      => $cc
    ]);

} catch (\Throwable $e) {
    jexit(500, ['ok'=>false, 'error'=>$e->getMessage()]);
}
