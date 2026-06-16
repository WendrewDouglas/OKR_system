<?php
declare(strict_types=1);

// =============================================================
// Webhook PagBank — confirma comprador + alerta interno.
// Trata DOIS formatos:
//   (A) MODERNO: JSON {charges[].status=PAID} (link criado via API),
//       validado por x-authenticity-token = SHA256("{LP_PAGBANK_TOKEN}-{corpo}").
//   (B) LEGADO: POST form notificationCode/notificationType (URL de
//       "Notificação de transações" do painel). Consulta a transação em
//       ws.pagseguro.uol.com.br com LP_PAGSEGURO_EMAIL + LP_PAGSEGURO_TOKEN.
//
// Barreira comum: ?key=<LP_WEBHOOK_SECRET> na URL.
// Idempotente por charge_id. Sempre responde 200 quando autêntico.
//
// URL: https://planningbi.com.br/OKR_system/LP/lp-ia/api/pagbank_webhook.php?key=SECRET
// =============================================================

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$raw = file_get_contents('php://input') ?: '';

/* --- barreira: segredo na URL --------------------------------------- */
$secret = getenv('LP_WEBHOOK_SECRET');
$key    = $_GET['key'] ?? '';
if (!is_string($secret) || $secret === '' || !hash_equals($secret, (string) $key)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $landingId = lp_landing_id(LP_IA_SLUG);
} catch (\Throwable $e) {
    lp_webhook_respond(200, ['ok' => true, 'ignored' => 'landing_unavailable']);
}

/* ==================================================================== */
/* (B) Fluxo LEGADO: notificationCode                                   */
/* ==================================================================== */
$notifCode = $_POST['notificationCode'] ?? null;
if (!$notifCode && $raw !== '' && stripos($raw, 'notificationCode=') !== false) {
    parse_str($raw, $parsed);
    $notifCode = $parsed['notificationCode'] ?? null;
}

if (is_string($notifCode) && $notifCode !== '') {
    $email = getenv('LP_PAGSEGURO_EMAIL');
    $tok   = getenv('LP_PAGSEGURO_TOKEN');
    if (!is_string($email) || $email === '' || !is_string($tok) || $tok === '') {
        error_log('[LP_IA][webhook] legado: LP_PAGSEGURO_EMAIL/TOKEN ausentes.');
        lp_webhook_respond(200, ['ok' => true, 'ignored' => 'pagseguro_creds_missing']);
    }

    $wsBase = rtrim((string) (getenv('LP_PAGSEGURO_WS') ?: 'https://ws.pagseguro.uol.com.br'), '/');
    $url = $wsBase . '/v3/transactions/notifications/' . rawurlencode($notifCode)
         . '?email=' . urlencode($email) . '&token=' . urlencode($tok);

    $xmlStr = lp_http_get($url);
    if ($xmlStr === null) {
        lp_webhook_respond(200, ['ok' => true, 'ignored' => 'consult_failed']);
    }

    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xmlStr);
    libxml_use_internal_errors($prev);
    if ($x === false) {
        lp_webhook_respond(200, ['ok' => true, 'ignored' => 'invalid_xml']);
    }

    $statusN = (int) ($x->status ?? 0);
    $isPaid  = in_array($statusN, [3, 4], true); // 3=Paga, 4=Disponível
    $chargeId = (string) ($x->code ?? $notifCode);
    $amount   = (int) round(((float) ($x->grossAmount ?? 0)) * 100);

    lp_process_payment($landingId, [
        'provider'     => 'pagseguro',
        'order_id'     => (string) ($x->code ?? ''),
        'charge_id'    => $chargeId,
        'reference_id' => (string) ($x->reference ?? ''),
        'status'       => $isPaid ? 'PAID' : ('STATUS_' . $statusN),
        'amount_cents' => $amount,
        'name'         => (string) ($x->sender->name ?? ''),
        'email'        => (string) ($x->sender->email ?? ''),
        'tax'          => null,
        'raw'          => $xmlStr,
    ]);
    lp_webhook_respond(200, ['ok' => true, 'status' => $isPaid ? 'PAID' : ('STATUS_' . $statusN)]);
}

/* ==================================================================== */
/* (A) Fluxo MODERNO: JSON com charges[]                                 */
/* ==================================================================== */
$token = getenv('LP_PAGBANK_TOKEN');
if (is_string($token) && $token !== '') {
    $sig = $_SERVER['HTTP_X_AUTHENTICITY_TOKEN'] ?? '';
    $expected = hash('sha256', $token . '-' . $raw);
    if (!is_string($sig) || $sig === '' || !hash_equals($expected, $sig)) {
        error_log('[LP_IA][webhook] assinatura moderna invalida.');
        lp_webhook_respond(401, ['ok' => false, 'error' => 'invalid_signature']);
    }
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    lp_webhook_respond(200, ['ok' => true, 'ignored' => 'unrecognized_payload']);
}

$customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
$charges  = is_array($data['charges'] ?? null) ? $data['charges'] : [];
$charge   = null;
foreach ($charges as $c) {
    if (is_array($c) && strtoupper((string) ($c['status'] ?? '')) === 'PAID') { $charge = $c; break; }
}
if ($charge === null && !empty($charges) && is_array($charges[0])) { $charge = $charges[0]; }
if ($charge === null) {
    lp_webhook_respond(200, ['ok' => true, 'ignored' => 'no_charge']);
}

$status = strtoupper((string) ($charge['status'] ?? 'UNKNOWN'));
lp_process_payment($landingId, [
    'provider'     => 'pagbank',
    'order_id'     => isset($data['id']) ? (string) $data['id'] : '',
    'charge_id'    => isset($charge['id']) ? (string) $charge['id'] : '',
    'reference_id' => isset($data['reference_id']) ? (string) $data['reference_id'] : '',
    'status'       => $status,
    'amount_cents' => (int) ($charge['amount']['value'] ?? 0),
    'name'         => isset($customer['name']) ? (string) $customer['name'] : '',
    'email'        => isset($customer['email']) ? (string) $customer['email'] : '',
    'tax'          => isset($customer['tax_id']) ? (string) $customer['tax_id'] : null,
    'raw'          => $raw,
]);
lp_webhook_respond(200, ['ok' => true, 'status' => $status]);

/* ==================================================================== */
/* Helpers                                                              */
/* ==================================================================== */

function lp_webhook_respond(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function lp_http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Accept: application/xml'],
        ]);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r === false || $code >= 400) {
            error_log('[LP_IA][webhook] consult HTTP ' . $code);
            return null;
        }
        return (string) $r;
    }
    $r = @file_get_contents($url);
    return $r === false ? null : $r;
}

/**
 * Registra/atualiza o pagamento (idempotente por charge_id), casa com lead por
 * e-mail e — se PAGO e ainda não notificado — dispara os e-mails.
 */
function lp_process_payment(int $landingId, array $p): void
{
    $pdo = lp_db();
    $custMail = $p['email'] !== '' ? mb_substr($p['email'], 0, 190) : null;
    $custName = $p['name'] !== '' ? mb_substr($p['name'], 0, 180) : null;
    $chargeId = $p['charge_id'] !== '' ? $p['charge_id'] : null;
    $status   = (string) $p['status'];
    $paidAt   = $status === 'PAID' ? date('Y-m-d H:i:s') : null;

    $existing = null;
    if ($chargeId !== null) {
        $st = $pdo->prepare('SELECT id, buyer_notified_at, admin_notified_at, lead_id FROM lp_payments WHERE charge_id = ? LIMIT 1');
        $st->execute([$chargeId]);
        $existing = $st->fetch() ?: null;
    }

    // casa com lead por e-mail (melhor esforço)
    $leadId = $existing['lead_id'] ?? null;
    $leadInfo = null;
    if ($custMail) {
        $ls = $pdo->prepare('SELECT id, coupon_code, cidade, utm_source, utm_campaign FROM lp_leads WHERE landing_id = ? AND email = ? ORDER BY created_at DESC LIMIT 1');
        $ls->execute([$landingId, $custMail]);
        $leadInfo = $ls->fetch() ?: null;
        if ($leadInfo && !$leadId) { $leadId = (int) $leadInfo['id']; }
    }

    if ($existing) {
        $pdo->prepare('UPDATE lp_payments SET status=?, amount_cents=?, paid_at=COALESCE(?,paid_at), customer_name=COALESCE(?,customer_name), customer_email=COALESCE(?,customer_email), customer_tax_id=COALESCE(?,customer_tax_id), order_id=COALESCE(?,order_id), reference_id=COALESCE(?,reference_id), lead_id=COALESCE(?,lead_id), raw_json=? WHERE id=?')
            ->execute([$status, $p['amount_cents'] ?: null, $paidAt, $custName, $custMail, $p['tax'], $p['order_id'] ?: null, $p['reference_id'] ?: null, $leadId, $p['raw'], $existing['id']]);
        $paymentId = (int) $existing['id'];
    } else {
        $pdo->prepare('INSERT INTO lp_payments (landing_id, lead_id, provider, order_id, charge_id, reference_id, status, amount_cents, customer_name, customer_email, customer_tax_id, raw_json, paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$landingId, $leadId, $p['provider'], $p['order_id'] ?: null, $chargeId, $p['reference_id'] ?: null, $status, $p['amount_cents'] ?: null, $custName, $custMail, $p['tax'], $p['raw'], $paidAt]);
        $paymentId = (int) $pdo->lastInsertId();
    }

    lp_log_event($landingId, 'checkout_click', [
        'lead_id'  => $leadId,
        'metadata' => ['webhook' => $p['provider'], 'status' => $status, 'charge_id' => $chargeId, 'amount_cents' => $p['amount_cents']],
    ]);

    $alreadyNotified = $existing && (!empty($existing['buyer_notified_at']) || !empty($existing['admin_notified_at']));
    if ($status === 'PAID' && !$alreadyNotified) {
        lp_pagbank_send_emails($landingId, $paymentId, [
            'name'   => $custName,
            'email'  => $custMail,
            'amount' => (int) $p['amount_cents'],
            'lead'   => $leadInfo,
        ]);
    }
}

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

    if (!empty($p['email'])) {
        $info = !empty($whenWhere)
            ? '<p style="margin:8px 0"><strong>Quando/onde:</strong> ' . $esc(implode(' • ', $whenWhere)) . '</p>'
            : '';
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
            . 'Iniciativa independente da PlanningBI.</p></div>';
        $buyerOk = lp_send_mail($p['email'], 'Inscrição confirmada — ' . $title, $html);
    }

    $adminOk = false;
    $to = lp_internal_notify_email();
    if ($to !== '') {
        $lead = is_array($p['lead'] ?? null) ? $p['lead'] : [];
        $rows = [
            'Comprador'     => $p['name'] ?: '-',
            'E-mail'        => $p['email'] ?: '-',
            'Valor'         => $p['amount'] ? lp_money_br((int) $p['amount']) : '-',
            'Cupom (lead)'  => $lead['coupon_code'] ?? '-',
            'Cidade (lead)' => $lead['cidade'] ?? '-',
            'Origem (lead)' => $lead['utm_source'] ?? '-',
            'Lead casado?'  => !empty($lead) ? 'sim' : 'não',
        ];
        $body = '<div style="font-family:Arial,sans-serif;color:#212529"><h3>💰 Venda confirmada — '
            . $esc($title) . '</h3><table cellpadding="6" style="border-collapse:collapse">';
        foreach ($rows as $k => $v) {
            $body .= '<tr><td style="border:1px solid #eee"><strong>' . $esc($k)
                . '</strong></td><td style="border:1px solid #eee">' . $esc($v) . '</td></tr>';
        }
        $body .= '</table></div>';
        $adminOk = lp_send_mail($to, '[LP_IA] 💰 Venda confirmada: ' . ($p['name'] ?: $p['email']), $body);
    }

    $pdo->prepare('UPDATE lp_payments SET buyer_notified_at=?, admin_notified_at=? WHERE id=?')
        ->execute([$buyerOk ? date('Y-m-d H:i:s') : null, $adminOk ? date('Y-m-d H:i:s') : null, $paymentId]);

    if (!$buyerOk && !$adminOk) {
        error_log('[LP_IA][webhook] nenhum e-mail enviado (pagamento ' . $paymentId . ').');
    }
}
