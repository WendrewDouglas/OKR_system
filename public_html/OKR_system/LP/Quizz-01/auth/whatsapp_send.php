<?php
// auth/whatsapp_send.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/* ==================== LOG & DEBUG BÁSICO ==================== */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$__logFile = __DIR__ . '/../views/error_log';
if (!file_exists($__logFile)) { @touch($__logFile); }
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', $__logFile);

function jexit(int $code, array $payload) {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==================== DEPENDÊNCIAS ==================== */
$pathsTried = [];
try {
  // Config / ACL (usa pdo_conn())
  $paths = [
    __DIR__ . '/config.php',
    __DIR__ . '/../auth/acl.php', // pdo_conn() costuma estar aqui
    __DIR__ . '/functions.php',   // helpers de e-mail/reset que você já utiliza
  ];
  foreach ($paths as $p) {
    $pathsTried[] = $p;
    if (is_file($p)) require_once $p;
  }
} catch (Throwable $e) {
  error_log('[whatsapp_send.php] Falha ao incluir dependências: ' . $e->getMessage());
  jexit(500, ['ok'=>false, 'error'=>'Falha interna de dependências.']);
}

/* ========== GARANTE pdo_conn() ========== */
if (!function_exists('pdo_conn')) {
  // Se seu pdo_conn estiver em outro arquivo, ajuste aqui o include.
  error_log('[whatsapp_send.php] pdo_conn() não encontrado. Paths tentados: ' . implode(' | ', $pathsTried));
  jexit(500, ['ok'=>false, 'error'=>'Conexão indisponível.']);
}

/* ==================== LÊ PAYLOAD ==================== */
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true) ?: [];

$sid            = isset($in['session_token']) ? trim((string)$in['session_token']) : '';
$telefone_e164  = isset($in['telefone_e164']) ? trim((string)$in['telefone_e164']) : '';
$whats_optin    = isset($in['whatsapp_optin']) ? (bool)$in['whatsapp_optin'] : null;

// opcionais (se quiser preencher no lead)
$country_code   = isset($in['country_code']) ? trim((string)$in['country_code']) : null; // ex: "BR"
$ddi            = isset($in['ddi']) ? trim((string)$in['ddi']) : null;                   // ex: "+55"

if ($sid === '') {
  jexit(400, ['ok'=>false, 'error'=>'session_token ausente']);
}
if ($telefone_e164 === '') {
  jexit(400, ['ok'=>false, 'error'=>'telefone_e164 ausente']);
}
if ($telefone_e164[0] !== '+') {
  // força formato E.164 com prefixo '+'
  error_log('[whatsapp_send.php] telefone_e164 sem +: ' . $telefone_e164);
}

/* ==================== DB ==================== */
$pdo = null;
try { $pdo = pdo_conn(); } catch (Throwable $e) {
  error_log('[whatsapp_send.php] Erro conexão PDO: ' . $e->getMessage());
  jexit(500, ['ok'=>false, 'error'=>'Falha de conexão']);
}

/* ==================== 1) RESOLVE SESSÃO/LEAD/VERSÃO ==================== */
try {
  // Ajuste os nomes de tabela/colunas conforme seu schema real.
  $sql = "SELECT s.id_sessao, s.id_versao, s.id_lead
            FROM quiz_sessions s
           WHERE s.session_token = :sid
           LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([':sid'=>$sid]);
  $sess = $st->fetch(PDO::FETCH_ASSOC);

  if (!$sess) {
    jexit(404, ['ok'=>false, 'error'=>'Sessão não encontrada ou expirada.']);
  }

  $id_sessao = $sess['id_sessao'];
  $id_versao = $sess['id_versao'];
  $id_lead   = $sess['id_lead'];

} catch (Throwable $e) {
  error_log('[whatsapp_send.php] Falha ao resolver sessão: ' . $e->getMessage());
  jexit(500, ['ok'=>false, 'error'=>'Falha ao resolver sessão']);
}

/* ==================== 2) LOCALIZA RESULTADO DA SESSÃO ==================== */
try {
  // Espera-se que o resultado já exista (result.php chama finalize antes).
  $sql = "SELECT r.id_resultado, r.pdf_path, r.pdf_url
            FROM quiz_results r
           WHERE r.id_sessao = :id_sessao
           ORDER BY r.created_at DESC
           LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([':id_sessao'=>$id_sessao]);
  $res = $st->fetch(PDO::FETCH_ASSOC);

  if (!$res) {
    jexit(409, ['ok'=>false, 'error'=>'Resultado não encontrado para a sessão. Finalize o diagnóstico antes de solicitar o envio.']);
  }

  $id_resultado = $res['id_resultado'];
  $pdf_path_db  = $res['pdf_path'] ?? null;
  $pdf_url_db   = $res['pdf_url']  ?? null;

} catch (Throwable $e) {
  error_log('[whatsapp_send.php] Falha ao localizar resultado: ' . $e->getMessage());
  jexit(500, ['ok'=>false, 'error'=>'Falha ao localizar resultado']);
}

/* ==================== 3) ATUALIZA TELEFONE NO LEAD ==================== */
try {
  // Ajuste os nomes dos campos no LEAD conforme seu schema.
  $sql = "UPDATE leads
             SET phone_e164_last = :phone,
                 country_code_last = :cc,
                 ddi_last = :ddi,
                 whatsapp_optin_last = :opt,
                 updated_at = NOW()
           WHERE id_lead = :id
           LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':phone' => $telefone_e164,
    ':cc'    => $country_code,
    ':ddi'   => $ddi,
    ':opt'   => $whats_optin,
    ':id'    => $id_lead
  ]);
} catch (Throwable $e) {
  error_log('[whatsapp_send.php] Falha ao atualizar telefone do lead: ' . $e->getMessage());
  jexit(500, ['ok'=>false, 'error'=>'Não foi possível atualizar o telefone no cadastro']);
}

/* ==================== 4) CARREGA DADOS DO LEAD (para o corpo do e-mail) ==================== */
try {
  // Ajuste os nomes conforme seu schema do lead_start (start.php).
  $sql = "SELECT 
            nome,
            email,
            cargo,
            consent_termos,
            consent_marketing,
            utm_source, utm_medium, utm_campaign, utm_content, utm_term
          FROM leads
          WHERE id_lead = :id
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([':id'=>$id_lead]);
  $lead = $st->fetch(PDO::FETCH_ASSOC);

  if (!$lead) {
    jexit(404, ['ok'=>false, 'error'=>'Lead não encontrado']);
  }

} catch (Throwable $e) {
  error_log('[whatsapp_send.php] Falha ao carregar lead: ' . $e->getMessage());
  jexit(500, ['ok'=>false, 'error'=>'Falha ao carregar informações do lead']);
}

/* ==================== 5) DEFINE PRIMEIRO NOME ==================== */
function first_name_capitalized(?string $full): string {
  $full = trim((string)$full);
  if ($full === '') return 'Você';
  $p = preg_split('/\s+/', $full);
  $fn = $p[0] ?? $full;
  $fn = mb_strtolower($fn, 'UTF-8');
  $fn = mb_strtoupper(mb_substr($fn, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($fn, 1, null, 'UTF-8');
  return $fn;
}
$primeiroNome = first_name_capitalized($lead['nome'] ?? '');

/* ==================== 6) OBTÉM/BAIXA O PDF PARA ANEXO ==================== */
$anexoFinalPath = null;
$anexoNome      = $id_resultado . '.pdf';

try {
  // 6.1 Preferir caminho local salvo no DB
  if ($pdf_path_db && is_file($pdf_path_db) && is_readable($pdf_path_db)) {
    $anexoFinalPath = $pdf_path_db;
  }

  // 6.2 Se não houver path local, tentar baixar da URL salva
  if (!$anexoFinalPath && $pdf_url_db) {
    $tmpDir = sys_get_temp_dir();
    $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . $anexoNome;
    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    $bin = @file_get_contents($pdf_url_db, false, $ctx);
    if ($bin && strlen($bin) > 1000) { // sanity check
      @file_put_contents($tmpPath, $bin);
      if (is_file($tmpPath)) {
        $anexoFinalPath = $tmpPath;
      }
    }
  }

  // 6.3 Se ainda não existir, informar para gerar antes (result.php já chama genPDF)
  if (!$anexoFinalPath) {
    jexit(409, ['ok'=>false, 'error'=>'PDF não encontrado. Gere o PDF do resultado antes de solicitar o envio.']);
  }

} catch (Throwable $e) {
  error_log('[whatsapp_send.php] Falha ao preparar anexo PDF: ' . $e->getMessage());
  jexit(500, ['ok'=>false, 'error'=>'Falha ao preparar anexo do relatório']);
}

/* ==================== 7) MONTA O CORPO DO E-MAIL (HTML) ==================== */
function bool_label($v): string { return $v ? 'Sim' : 'Não'; }

$lead_nome   = htmlspecialchars((string)($lead['nome'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$lead_email  = htmlspecialchars((string)($lead['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$lead_cargo  = htmlspecialchars((string)($lead['cargo'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$cons_termos = bool_label(!empty($lead['consent_termos']));
$cons_mark   = bool_label(!empty($lead['consent_marketing']));

$utms = [
  'utm_source'   => $lead['utm_source']   ?? null,
  'utm_medium'   => $lead['utm_medium']   ?? null,
  'utm_campaign' => $lead['utm_campaign'] ?? null,
  'utm_content'  => $lead['utm_content']  ?? null,
  'utm_term'     => $lead['utm_term']     ?? null,
];
$utmsHtml = '';
foreach ($utms as $k=>$v) {
  if ($v !== null && $v !== '') {
    $utmsHtml .= '<tr><td style="padding:4px 8px;color:#555;">'.htmlspecialchars($k,ENT_QUOTES).'</td><td style="padding:4px 8px;">'.htmlspecialchars((string)$v,ENT_QUOTES).'</td></tr>';
  }
}
if ($utmsHtml === '') { $utmsHtml = '<tr><td colspan="2" style="padding:4px 8px;color:#777;">(sem UTMs)</td></tr>'; }

$emailHtml = <<<HTML
<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,Sans-Serif; color:#111;">
  <h2 style="margin:0 0 12px 0;">Novo pedido de envio (Landing Page)</h2>
  <p style="margin:0 0 8px 0;">Segue anexo o PDF do resultado para conferência.</p>

  <h3 style="margin:16px 0 6px 0;">Dados do lead (captados no start)</h3>
  <table style="border-collapse:collapse;min-width:420px;">
    <tr><td style="padding:4px 8px;color:#555;">Nome</td><td style="padding:4px 8px;">{$lead_nome}</td></tr>
    <tr><td style="padding:4px 8px;color:#555;">E-mail</td><td style="padding:4px 8px;">{$lead_email}</td></tr>
    <tr><td style="padding:4px 8px;color:#555;">Cargo</td><td style="padding:4px 8px;">{$lead_cargo}</td></tr>
    <tr><td style="padding:4px 8px;color:#555;">Consentimento (Termos)</td><td style="padding:4px 8px;">{$cons_termos}</td></tr>
    <tr><td style="padding:4px 8px;color:#555;">Consentimento (Marketing)</td><td style="padding:4px 8px;">{$cons_mark}</td></tr>
  </table>

  <h3 style="margin:16px 0 6px 0;">UTMs</h3>
  <table style="border-collapse:collapse;min-width:420px;">
    {$utmsHtml}
  </table>

  <hr style="margin:16px 0;border:none;border-top:1px solid #eee;">
  <p style="margin:0;color:#666;font-size:12px;">
    Referências: id_sessao=<b>{$id_sessao}</b> | id_resultado=<b>{$id_resultado}</b> | id_versao=<b>{$id_versao}</b>
  </p>
</div>
HTML;

/* ==================== 8) ENVIA O E-MAIL ==================== */
$toEmail = 'wendrew.douglas@gmail.com';
$subject = 'LP ' . $id_versao . ' - ' . $primeiroNome;

// Tenta usar PHPMailer se disponível nos seus helpers; senão, tenta via composer; por fim, tenta mail()
$sent = false;
$sendErr = null;

try {
  if (function_exists('planningbi_mailer_send')) {
    // Caso você tenha criado um helper no functions.php (ajuste o nome se diferente)
    $sent = planningbi_mailer_send($toEmail, $subject, $emailHtml, [$anexoFinalPath => $anexoNome]);
  } else {
    // Tenta PHPMailer padrão via Composer
    $autoloads = [
      __DIR__ . '/../vendor/autoload.php',
      __DIR__ . '/vendor/autoload.php',
      dirname(__DIR__,2) . '/vendor/autoload.php',
    ];
    foreach ($autoloads as $a) {
      if (is_file($a)) { require_once $a; break; }
    }

    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
      $mail = new PHPMailer\PHPMailer\PHPMailer(true);
      // ====== AJUSTE AQUI SEUS PARAMS SMTP (se necessários) ======
      // $mail->isSMTP();
      // $mail->Host = 'smtp.seudominio.com';
      // $mail->SMTPAuth = true;
      // $mail->Username = '...';
      // $mail->Password = '...';
      // $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      // $mail->Port = 587;

      $mail->CharSet  = 'UTF-8';
      $mail->setFrom('no-reply@planningbi.com.br', 'PlanningBI');
      $mail->addAddress($toEmail);
      $mail->Subject  = $subject;
      $mail->isHTML(true);
      $mail->Body     = $emailHtml;

      // Anexo
      $mail->addAttachment($anexoFinalPath, $anexoNome);

      $sent = $mail->send();
    } else {
      // Fallback rudimentar via mail() SEM garantia de anexos universais — recomendamos configurar PHPMailer
      // Para manter o compromisso (não enviar sem anexo), marcamos erro se PHPMailer indisponível.
      throw new RuntimeException('PHPMailer indisponível; configure o envio por SMTP/PHPMailer nos helpers.');
    }
  }
} catch (Throwable $e) {
  $sendErr = $e->getMessage();
  error_log('[whatsapp_send.php] Falha ao enviar e-mail: ' . $sendErr);
  $sent = false;
}

if (!$sent) {
  jexit(500, ['ok'=>false, 'error'=>'Falha ao enviar o e-mail', 'details'=>$sendErr]);
}

/* ==================== 9) OK ==================== */
jexit(200, [
  'ok'          => true,
  'message'     => 'Email enviado',
  'id_resultado'=> $id_resultado,
  'id_versao'   => $id_versao
]);
