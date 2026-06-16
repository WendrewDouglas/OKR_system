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
require_once dirname(__DIR__) . '/includes/payments.php';

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
