<?php
// auth/notify.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function notify_inapp(PDO $pdo, int $userId, string $titulo, string $mensagem, ?string $url=null, array $meta=[]): void {
  $st = $pdo->prepare("INSERT INTO notificacoes (id_user, tipo, titulo, mensagem, url, meta_json) VALUES (?, 'aprovacao', ?, ?, ?, JSON_OBJECT())");
  // substitui JSON_OBJECT() por :meta se preferir passar de fato
  $st->execute([$userId, $titulo, $mensagem, $url]);
}

function notify_email(string $toEmail, string $subject, string $bodyHtml): void {
  // Simples: mail() — substitua por PHPMailer + SMTP para produção
  if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return;
  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-type:text/html;charset=UTF-8\r\n";
  $headers .= "From: OKR System <no-reply@seu-dominio.com>\r\n";
  @mail($toEmail, $subject, $bodyHtml, $headers);
}

function notify_whatsapp(?string $phoneE164, string $text): void {
  // Integração com WhatsApp Cloud API (Meta) OU Twilio (ajuste aqui)
  if (!$phoneE164) return;
  if (!defined('WHATSAPP_TOKEN') || !defined('WHATSAPP_PHONE_ID')) return;

  $url = "https://graph.facebook.com/v17.0/".WHATSAPP_PHONE_ID."/messages";
  $payload = [
    'messaging_product' => 'whatsapp',
    'to'   => $phoneE164, // ex.: +5511999998888
    'type' => 'text',
    'text' => ['body' => $text]
  ];
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer '.WHATSAPP_TOKEN
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 7
  ]);
  @curl_exec($ch);
  @curl_close($ch);
}

/**
 * Dispara notificações no trio: in-app, email, whatsapp
 * $context: ['module','id','acao','status','obs','solicitante_id','aprovador_id']
 */
function notify_event(PDO $pdo, array $context): void {
  // Descobre quem deve ser avisado (criador sempre; se foi reenvio, avisar aprovadores também)
  $module = $context['module'];
  $id     = $context['id'];
  $acao   = $context['acao'];   // approve|reject|resubmit
  $status = $context['status']; // aprovado|reprovado|pendente

  // Busca criador por ID (preferencial) ou por nome (legado)
  $creator = ['id'=>null,'nome'=>null,'email'=>null,'phone'=>null];

  if ($module === 'objetivo') {
    $st = $pdo->prepare("
      SELECT o.id_user_criador, o.usuario_criador,
             u.email_corporativo AS email, u.telefone AS phone,
             CONCAT(u.primeiro_nome,' ',COALESCE(u.ultimo_nome,'')) AS nome
      FROM objetivos o
      LEFT JOIN usuarios u ON u.id_user = o.id_user_criador
      WHERE o.id_objetivo = ?");
    $st->execute([(int)$id]);
    $creator = $st->fetch() ?: $creator;

  } elseif ($module === 'kr') {
    $st = $pdo->prepare("
      SELECT k.id_user_criador, k.usuario_criador,
             u.email_corporativo AS email, u.telefone AS phone,
             CONCAT(u.primeiro_nome,' ',COALESCE(u.ultimo_nome,'')) AS nome
      FROM key_results k
      LEFT JOIN usuarios u ON u.id_user = k.id_user_criador
      WHERE k.id_kr = ?");
    $st->execute([$id]);
    $creator = $st->fetch() ?: $creator;

  } else { // orcamento
    $st = $pdo->prepare("
      SELECT o.id_user_criador,
             CONCAT(u.primeiro_nome,' ',COALESCE(u.ultimo_nome,'')) AS nome,
             u.email_corporativo AS email, u.telefone AS phone
      FROM orcamentos o
      LEFT JOIN usuarios u ON u.id_user = o.id_user_criador
      WHERE o.id_orcamento = ?");
    $st->execute([(int)$id]);
    $creator = $st->fetch() ?: $creator;
  }

  $titulo   = ($acao==='resubmit')
    ? "Reenvio para aprovação: {$module} {$id}"
    : ucfirst($status) . ": {$module} {$id}";

  $mensagem = ($acao==='resubmit')
    ? "O item {$module} {$id} foi reenviado para aprovação.\nObservação: ".($context['obs'] ?? '—')
    : "Seu item {$module} {$id} foi {$status}.\nObservação: ".($context['obs'] ?? '—');

  $url = "/OKR_system/views/aprovacao.php";

  // In-app para criador
  if (!empty($creator['id_user_criador']) || !empty($creator['id'])) {
    $uid = (int)($creator['id_user_criador'] ?? $creator['id']);
    notify_inapp($pdo, $uid, $titulo, nl2br(htmlentities($mensagem, ENT_QUOTES, 'UTF-8')), $url);
  }

  // Email
  if (!empty($creator['email'])) {
    notify_email($creator['email'], "[OKR] {$titulo}",
      "<p>{$mensagem}</p><p><a href='{$url}'>Abrir no OKR System</a></p>");
  }

  // WhatsApp (se telefone estiver em E.164 ex.: +5511988887777)
  if (!empty($creator['phone'])) {
    notify_whatsapp($creator['phone'], $mensagem . "\n" . rtrim('https://seu-dominio.com'.$url,'/'));
  }

  // Opcional: notificar aprovadores no caso de reenvio
  if ($acao === 'resubmit') {
    $res = $pdo->query("SELECT id_user FROM aprovadores WHERE habilitado=1")->fetchAll();
    foreach ($res as $r) {
      notify_inapp($pdo, (int)$r['id_user'], "Reenvio recebido: {$module} {$id}", "Há um item reenviado aguardando nova análise.", $url);
    }
  }
}
