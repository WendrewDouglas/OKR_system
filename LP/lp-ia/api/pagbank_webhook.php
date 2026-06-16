<?php
declare(strict_types=1);

// =============================================================
// Webhook PagBank — recebe notificações de pagamento e dispara:
//   1) e-mail de confirmação para o COMPRADOR;
//   2) alerta interno para o organizador.
//
// Segurança:
//   - exige ?key=<LP_WEBHOOK_SECRET> na URL (1ª barreira);
//   - se LP_PAGBANK_TOKEN estiver definido, valida o header
//     x-authenticity-token = SHA256("{token}-{corpo_bruto}").
//   - idempotente por charge_id (não reenvia e-mail em redelivery).
//
// Responde sempre HTTP 200 quando a requisição é autêntica, para o
// PagBank não ficar reenviando indefinidamente.
//
// URL de produção:
//   https://planningbi.com.br/OKR_system/LP/lp-ia/api/pagbank_webhook.php?key=SECRET
// =============================================================

require_once dirname(__DIR__) . '/includes/bootstrap.php';

/* --- corpo bruto (necessário para validar a assinatura) -------------- */
$raw = file_get_contents('php://input') ?: '';

/* --- 1ª barreira: segredo na URL ------------------------------------- */
$secret = getenv('LP_WEBHOOK_SECRET');
$key    = $_GET['key'] ?? '';
if (!is_string($secret) || $secret === '' || !hash_equals($secret, (string) $key)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- 2ª barreira: assinatura (quando há token) ----------------------- */
$token = getenv('LP_PAGBANK_TOKEN');
if (is_string($token) && $token !== '') {
    $sig = $_SERVER['HTTP_X_AUTHENTICITY_TOKEN'] ?? '';
    $expected = hash('sha256', $token . '-' . $raw);
    if (!is_string($sig) || $sig === '' || !hash_equals($expected, $sig)) {
        error_log('[LP_IA][webhook] assinatura invalida.');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid_signature'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    error_log('[LP_IA][webhook] LP_PAGBANK_TOKEN ausente: validando apenas pelo segredo de URL.');
}

/* --- parse do payload ------------------------------------------------ */
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(200); // 200 para não gerar retries de algo que não entendemos
    echo json_encode(['ok' => true, 'ignored' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $landingId = lp_landing_id(LP_IA_SLUG);
} catch (\Throwable $e) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => 'landing_unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$orderId  = isset($data['id']) ? (string) $data['id'] : null;
$refId    = isset($data['reference_id']) ? (string) $data['reference_id'] : null;
$customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
$custName = isset($customer['name']) ? mb_substr((string) $customer['name'], 0, 180) : null;
$custMail = isset($customer['email']) ? mb_substr((string) $customer['email'], 0, 190) : null;
$custTax  = isset($customer['tax_id']) ? mb_substr((string) $customer['tax_id'], 0, 20) : null;

// Seleciona a cobrança relevante (prioriza uma PAID).
$charges = is_array($data['charges'] ?? null) ? $data['charges'] : [];
$charge  = null;
foreach ($charges as $c) {
    if (is_array($c) && strtoupper((string) ($c['status'] ?? '')) === 'PAID') { $charge = $c; break; }
}
if ($charge === null && !empty($charges) && is_array($charges[0])) {
    $charge = $charges[0];
}

if ($charge === null) {
    // Pode ser uma notificação de checkout sem cobrança ainda.
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => 'no_charge'], JSON_UNESCAPED_UNICODE);
    exit;
}

$chargeId = isset($charge['id']) ? (string) $charge['id'] : null;
$status   = strtoupper((string) ($charge['status'] ?? 'UNKNOWN'));
$amount   = (int) ($charge['amount']['value'] ?? 0);
$paidAt   = isset($charge['paid_at']) ? date('Y-m-d H:i:s', strtotime((string) $charge['paid_at'])) : null;

$pdo = lp_db();

/* --- registra/atualiza o pagamento (idempotente) -------------------- */
$existing = null;
if ($chargeId !== null) {
    $st = $pdo->prepare('SELECT id, status, buyer_notified_at, admin_notified_at, lead_id FROM lp_payments WHERE charge_id = ? LIMIT 1');
    $st->execute([$chargeId]);
    $existing = $st->fetch() ?: null;
}

// Tenta casar com um lead pelo e-mail (melhor esforço).
$leadId = $existing['lead_id'] ?? null;
$leadInfo = null;
if ($custMail) {
    $ls = $pdo->prepare('SELECT id, coupon_code, cidade, utm_source, utm_campaign FROM lp_leads WHERE landing_id = ? AND email = ? ORDER BY created_at DESC LIMIT 1');
    $ls->execute([$landingId, $custMail]);
    $leadInfo = $ls->fetch() ?: null;
    if ($leadInfo && !$leadId) { $leadId = (int) $leadInfo['id']; }
}

if ($existing) {
    $up = $pdo->prepare('UPDATE lp_payments SET status=?, amount_cents=?, paid_at=COALESCE(?, paid_at), customer_name=COALESCE(?,customer_name), customer_email=COALESCE(?,customer_email), customer_tax_id=COALESCE(?,customer_tax_id), order_id=COALESCE(?,order_id), reference_id=COALESCE(?,reference_id), lead_id=COALESCE(?,lead_id), raw_json=? WHERE id=?');
    $up->execute([$status, $amount ?: null, $paidAt, $custName, $custMail, $custTax, $orderId, $refId, $leadId, $raw, $existing['id']]);
    $paymentId = (int) $existing['id'];
} else {
    $in = $pdo->prepare('INSERT INTO lp_payments (landing_id, lead_id, provider, order_id, charge_id, reference_id, status, amount_cents, customer_name, customer_email, customer_tax_id, raw_json, paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $in->execute([$landingId, $leadId, 'pagbank', $orderId, $chargeId, $refId, $status, $amount ?: null, $custName, $custMail, $custTax, $raw, $paidAt]);
    $paymentId = (int) $pdo->lastInsertId();
}

lp_log_event($landingId, 'checkout_click', [ // reaproveita tabela de eventos como trilha
    'lead_id'  => $leadId,
    'metadata' => ['webhook' => true, 'status' => $status, 'charge_id' => $chargeId, 'amount_cents' => $amount],
]);

/* --- só dispara e-mails quando PAGO e ainda não notificado ---------- */
$alreadyNotified = $existing && !empty($existing['buyer_notified_at']);
if ($status === 'PAID' && !$alreadyNotified) {
    lp_pagbank_send_emails($landingId, $paymentId, [
        'name'   => $custName,
        'email'  => $custMail,
        'amount' => $amount,
        'lead'   => $leadInfo,
    ]);
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'status' => $status, 'payment_id' => $paymentId], JSON_UNESCAPED_UNICODE);
exit;

/* ==================================================================== */

function lp_pagbank_send_emails(int $landingId, int $paymentId, array $p): void
{
    $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $title = 'IA Aplicada ao Dia a Dia Financeiro';

    $date  = trim((string) lp_setting($landingId, 'training_date', ''));
    $time  = trim((string) lp_setting($landingId, 'training_time', ''));
    $local = trim((string) lp_setting($landingId, 'training_location', ''));
    $whenWhere = array_filter([$date, $time, $local], static fn($v) => $v !== '');

    $pdo = lp_db();
    $buyerOk = false;

    /* 1) Confirmação para o COMPRADOR */
    if (!empty($p['email'])) {
        $info = '';
        if (!empty($whenWhere)) {
            $info = '<p style="margin:8px 0"><strong>Quando/onde:</strong> ' . $esc(implode(' • ', $whenWhere)) . '</p>';
        }
        $html = '<div style="font-family:Arial,sans-serif;color:#212529;line-height:1.6">'
            . '<h2 style="color:#1e8e3e">Pagamento confirmado! ✅</h2>'
            . '<p>Olá, ' . $esc($p['name'] ?: 'participante') . '.</p>'
            . '<p>Recebemos a confirmação do seu pagamento e sua <strong>inscrição no treinamento '
            . '"' . $esc($title) . '" está garantida</strong>.</p>'
            . $info
            . '<p>Em breve enviaremos os detalhes finais e as orientações de acesso. '
            . 'Guarde este e-mail como comprovante de inscrição.</p>'
            . '<hr style="border:none;border-top:1px solid #eee">'
            . '<p style="font-size:12px;color:#6c757d">Treinamento presencial e prático. '
            . 'Iniciativa independente da PlanningBI.</p>'
            . '</div>';
        $buyerOk = lp_send_mail($p['email'], 'Inscrição confirmada — ' . $title, $html);
    }

    /* 2) Alerta interno */
    $adminOk = false;
    $to = lp_internal_notify_email();
    if ($to !== '') {
        $lead = is_array($p['lead'] ?? null) ? $p['lead'] : [];
        $rows = [
            'Comprador' => $p['name'] ?: '-',
            'E-mail'    => $p['email'] ?: '-',
            'Valor'     => $p['amount'] ? lp_money_br((int) $p['amount']) : '-',
            'Cupom (lead)' => $lead['coupon_code'] ?? '-',
            'Cidade (lead)' => $lead['cidade'] ?? '-',
            'Origem (lead)' => $lead['utm_source'] ?? '-',
            'Lead casado?' => !empty($lead) ? 'sim' : 'não (pagamento sem lead correspondente)',
        ];
        $body = '<div style="font-family:Arial,sans-serif;color:#212529">'
            . '<h3>💰 Venda confirmada — ' . $esc($title) . '</h3>'
            . '<table cellpadding="6" style="border-collapse:collapse">';
        foreach ($rows as $k => $v) {
            $body .= '<tr><td style="border:1px solid #eee"><strong>' . $esc($k)
                . '</strong></td><td style="border:1px solid #eee">' . $esc($v) . '</td></tr>';
        }
        $body .= '</table></div>';
        $adminOk = lp_send_mail($to, '[LP_IA] 💰 Venda confirmada: ' . ($p['name'] ?: $p['email']), $body);
    }

    // Marca como notificado (mesmo se um dos e-mails falhar, evitamos spam em redelivery).
    $pdo->prepare('UPDATE lp_payments SET buyer_notified_at = ?, admin_notified_at = ? WHERE id = ?')
        ->execute([
            $buyerOk ? date('Y-m-d H:i:s') : null,
            $adminOk ? date('Y-m-d H:i:s') : null,
            $paymentId,
        ]);

    if (!$buyerOk && !$adminOk) {
        error_log('[LP_IA][webhook] nenhum e-mail enviado (pagamento ' . $paymentId . ').');
    }
}
