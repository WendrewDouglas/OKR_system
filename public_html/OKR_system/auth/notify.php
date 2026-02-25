<?php
// auth/notify.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

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

// ==========================================
// Notificação de novo item pendente para aprovadores
// ==========================================

/**
 * Gera linhas <tr> de detalhes conforme o módulo.
 */
function build_detail_rows(string $module, array $details): string {
    $extras = $details['extras'] ?? [];
    $rows = '';

    $addRow = function(string $label, ?string $value) use (&$rows) {
        if ($value === null || $value === '') return;
        $v = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $l = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $rows .= "<tr><td style=\"padding:8px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600;width:40%;\">{$l}</td>"
                . "<td style=\"padding:8px 12px;border-bottom:1px solid #e5e7eb;color:#1f2937;\">{$v}</td></tr>";
    };

    switch ($module) {
        case 'objetivo':
            $addRow('Tipo', 'Objetivo');
            $addRow('Criador', $details['criador_nome'] ?? null);
            $addRow('Ciclo', $extras['tipo_ciclo'] ?? null);
            $addRow('Período', $extras['ciclo'] ?? null);
            $addRow('Prazo', $extras['dt_prazo'] ?? null);
            break;

        case 'kr':
            $addRow('Tipo', 'Key Result');
            $addRow('Criador', $details['criador_nome'] ?? null);
            $addRow('Objetivo vinculado', $extras['id_objetivo'] ?? null);
            $addRow('Baseline', isset($extras['baseline']) ? (string)$extras['baseline'] : null);
            $addRow('Meta', isset($extras['meta']) ? (string)$extras['meta'] : null);
            $addRow('Unidade', $extras['unidade_medida'] ?? null);
            $addRow('Período', ($extras['data_inicio'] ?? '') . ' a ' . ($extras['data_fim'] ?? ''));
            break;

        case 'orcamento':
            $addRow('Tipo', 'Orçamento');
            $addRow('Criador', $details['criador_nome'] ?? null);
            $addRow('Iniciativa', $extras['id_iniciativa'] ?? null);
            $addRow('Valor', isset($extras['valor']) ? 'R$ ' . number_format((float)$extras['valor'], 2, ',', '.') : null);
            $addRow('Justificativa', $extras['justificativa'] ?? null);
            break;
    }

    return $rows;
}

/**
 * Monta o HTML completo do email de aprovação pendente.
 */
function build_approval_email_html(string $module, array $details, string $approverName): string {
    $colors = [
        'objetivo'  => '#0c4a6e',
        'kr'        => '#065f46',
        'orcamento' => '#92400e',
    ];
    $labels = [
        'objetivo'  => 'Objetivo',
        'kr'        => 'Key Result',
        'orcamento' => 'Orçamento',
    ];

    $accent     = $colors[$module] ?? '#0c4a6e';
    $label      = $labels[$module] ?? ucfirst($module);
    $descricao  = htmlspecialchars($details['descricao'] ?? '', ENT_QUOTES, 'UTF-8');
    $criador    = htmlspecialchars($details['criador_nome'] ?? '', ENT_QUOTES, 'UTF-8');
    $dataHora   = date('d/m/Y \à\s H:i');
    $saudacao   = htmlspecialchars($approverName, ENT_QUOTES, 'UTF-8');
    $detailRows = build_detail_rows($module, $details);
    $ctaUrl     = 'https://planningbi.com.br/OKR_system/views/aprovacao.php';
    $logoUrl    = 'https://planningbi.com.br/wp-content/uploads/2025/07/logo-horizonta-brancfa-sem-fundol-1024x267.png';

    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
<tr><td align="center" style="padding:24px 16px;">

<!-- Container 600px -->
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

  <!-- Header gradient -->
  <tr>
    <td style="background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);padding:32px 40px;text-align:center;">
      <img src="{$logoUrl}" alt="Planning BI" width="180" style="max-width:180px;height:auto;margin-bottom:16px;display:block;margin-left:auto;margin-right:auto;">
      <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
        <tr>
          <td style="background-color:{$accent};color:#ffffff;font-size:13px;font-weight:700;padding:6px 18px;border-radius:20px;letter-spacing:0.5px;">
            NOVA APROVAÇÃO PENDENTE
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Body -->
  <tr>
    <td style="padding:32px 40px;">

      <!-- Saudação -->
      <p style="margin:0 0 4px;font-size:18px;font-weight:700;color:#1f2937;">Olá, {$saudacao}!</p>
      <p style="margin:0 0 24px;font-size:13px;color:#9ca3af;">{$dataHora}</p>

      <p style="margin:0 0 20px;font-size:15px;color:#374151;line-height:1.6;">
        Um novo <strong>{$label}</strong> foi submetido e aguarda sua análise e aprovação.
      </p>

      <!-- Card destaque -->
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
        <tr>
          <td style="border-left:4px solid {$accent};background-color:#f8fafc;padding:16px 20px;border-radius:0 8px 8px 0;">
            <p style="margin:0 0 6px;font-size:16px;font-weight:700;color:#0f172a;">{$descricao}</p>
            <p style="margin:0;font-size:13px;color:#6b7280;">Criado por <strong>{$criador}</strong></p>
          </td>
        </tr>
      </table>

      <!-- Tabela de detalhes -->
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:28px;font-size:14px;">
        <tr>
          <td colspan="2" style="padding:10px 12px;background-color:#f9fafb;font-weight:700;color:#374151;font-size:13px;text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid #e5e7eb;">
            Detalhes
          </td>
        </tr>
        {$detailRows}
      </table>

      <!-- CTA -->
      <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
        <tr>
          <td style="background-color:{$accent};border-radius:8px;">
            <a href="{$ctaUrl}" target="_blank" style="display:inline-block;padding:14px 36px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;letter-spacing:0.3px;">
              Revisar e Aprovar
            </a>
          </td>
        </tr>
      </table>

    </td>
  </tr>

  <!-- Footer -->
  <tr>
    <td style="padding:20px 40px;background-color:#f9fafb;border-top:1px solid #e5e7eb;text-align:center;">
      <p style="margin:0;font-size:12px;color:#9ca3af;">
        Enviado automaticamente pelo <strong>OKR System</strong> · Planning BI
      </p>
    </td>
  </tr>

</table>
<!-- /Container -->

</td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Notifica todos os aprovadores ativos sobre um novo item pendente.
 * Non-blocking: falhas não impedem o fluxo principal.
 */
function notify_approvers_new_item(PDO $pdo, string $module, string $itemId, array $details): void {
    try {
        $companyId = (int)($details['company_id'] ?? 0);
        if ($companyId <= 0) return;

        $labels = ['objetivo' => 'Objetivo', 'kr' => 'Key Result', 'orcamento' => 'Orçamento'];
        $label  = $labels[$module] ?? ucfirst($module);
        $desc   = $details['descricao'] ?? '';

        // Busca aprovadores habilitados da mesma empresa com email
        $st = $pdo->prepare("
            SELECT a.id_user,
                   u.email_corporativo,
                   CONCAT(u.primeiro_nome, ' ', COALESCE(u.ultimo_nome, '')) AS nome
            FROM aprovadores a
            JOIN usuarios u ON u.id_user = a.id_user
            WHERE a.habilitado = 1
              AND u.id_company = :cid
              AND u.email_corporativo IS NOT NULL
              AND u.email_corporativo != ''
        ");
        $st->execute([':cid' => $companyId]);
        $aprovadores = $st->fetchAll(PDO::FETCH_ASSOC);

        if (empty($aprovadores)) return;

        $url = '/OKR_system/views/aprovacao.php';
        $subject = "[OKR] Nova aprovação pendente: {$label}";

        foreach ($aprovadores as $apr) {
            try {
                // Notificação in-app
                notify_inapp(
                    $pdo,
                    (int)$apr['id_user'],
                    "Novo {$label} pendente",
                    htmlspecialchars("O {$label} \"{$desc}\" foi criado e aguarda sua aprovação.", ENT_QUOTES, 'UTF-8'),
                    $url
                );

                // Email via sendTransactionalMail (PHPMailer + SMTP)
                $email = trim((string)($apr['email_corporativo'] ?? ''));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $html = build_approval_email_html($module, $details, trim((string)$apr['nome']));
                    sendTransactionalMail($email, $subject, $html);
                }
            } catch (Throwable $inner) {
                error_log("notify_approvers_new_item: falha ao notificar aprovador {$apr['id_user']}: " . $inner->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log("notify_approvers_new_item: erro global: " . $e->getMessage());
    }
}
