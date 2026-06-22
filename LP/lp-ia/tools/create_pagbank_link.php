<?php
declare(strict_types=1);

// =============================================================
// CLI — cria um Link de Pagamento (Checkout) via API do PagBank,
// já com notification_urls/payment_notification_urls apontando para
// o nosso webhook (assim os webhooks disparam de forma confiável).
//
// Lê o token de LP_PAGBANK_TOKEN (.env) — não passe o token por argumento.
//
// Uso (no servidor, na raiz do OKR_system):
//   php LP/lp-ia/tools/create_pagbank_link.php [--apply] [--amount=14700] [--exp=2026-06-30]
//
//   --apply        Atualiza lp_settings (pagbank_url_oficial e _desconto) com o link criado.
//   --amount=NNN   Valor em centavos (default 14700 = R$ 147,00).
//   --exp=AAAA-MM-DD  Data de expiração do link (default 2026-06-30).
//   --env=prod|sandbox  Ambiente (default prod).
// =============================================================

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

require_once __DIR__ . '/../includes/bootstrap.php';

$opts = getopt('', ['apply', 'amount::', 'exp::', 'env::']);
$apply  = array_key_exists('apply', $opts);
$amount = isset($opts['amount']) ? (int) $opts['amount'] : 14700;
$exp    = isset($opts['exp']) ? (string) $opts['exp'] : '2026-06-30';
$env    = isset($opts['env']) ? (string) $opts['env'] : (getenv('LP_PAGBANK_ENV') ?: 'prod');

$token = getenv('LP_PAGBANK_TOKEN');
if (!is_string($token) || trim($token) === '') {
    fwrite(STDERR, "ERRO: LP_PAGBANK_TOKEN ausente no .env.\n");
    exit(1);
}
$token = trim($token);

$secret = getenv('LP_WEBHOOK_SECRET');
if (!is_string($secret) || $secret === '') {
    fwrite(STDERR, "ERRO: LP_WEBHOOK_SECRET ausente no .env.\n");
    exit(1);
}

$base = rtrim((string) (getenv('LP_PUBLIC_BASE') ?: 'https://planningbi.com.br/OKR_system/LP/lp-ia'), '/');
$webhook = $base . '/api/pagbank_webhook.php?key=' . rawurlencode($secret);

$endpoint = $env === 'sandbox'
    ? 'https://sandbox.api.pagseguro.com/checkouts'
    : 'https://api.pagseguro.com/checkouts';

$payload = [
    'reference_id'        => 'lpia-ia-financeiro',
    'expiration_date'     => $exp . 'T23:59:59-03:00',
    'customer_modifiable' => true,
    'items' => [[
        'reference_id' => 'ia-financeiro',
        'name'         => 'IA Aplicada ao Dia a Dia Financeiro — Presencial (4h)',
        'quantity'     => 1,
        'unit_amount'  => $amount,
    ]],
    'payment_methods' => [
        ['type' => 'CREDIT_CARD'],
        ['type' => 'DEBIT_CARD'],
        ['type' => 'PIX'],
        ['type' => 'BOLETO'],
    ],
    'payment_methods_configs' => [[
        'type' => 'CREDIT_CARD',
        'config_options' => [[ 'option' => 'INSTALLMENTS_LIMIT', 'value' => '18' ]],
    ]],
    'soft_descriptor'           => 'TREINAMENTO IA',
    'redirect_url'              => $base . '/public/index.php?pago=1',
    'notification_urls'         => [$webhook],
    'payment_notification_urls' => [$webhook],
];

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'accept: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT        => 25,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    fwrite(STDERR, "ERRO de rede: {$err}\n");
    exit(1);
}

echo "HTTP {$code}\n";
$data = json_decode($resp, true);

if ($code >= 400 || !is_array($data)) {
    fwrite(STDERR, "Falha ao criar checkout:\n{$resp}\n");
    exit(1);
}

// Procura o link de pagamento (rel = PAY) na resposta.
$payLink = null;
foreach (($data['links'] ?? []) as $l) {
    if (is_array($l) && strtoupper((string) ($l['rel'] ?? '')) === 'PAY') {
        $payLink = $l['href'] ?? null;
        break;
    }
}
$checkoutId = $data['id'] ?? '(sem id)';

echo "checkout_id: {$checkoutId}\n";
echo "pay_link   : " . ($payLink ?: '(NAO ENCONTRADO — ver resposta abaixo)') . "\n";
if (!$payLink) {
    echo $resp . "\n";
    exit(1);
}

if ($apply) {
    $pdo = lp_db();
    $lid = lp_landing_id(LP_IA_SLUG);
    $u = $pdo->prepare('UPDATE lp_settings SET setting_value=? WHERE landing_id=? AND setting_key=?');
    $u->execute([$payLink, $lid, 'pagbank_url_oficial']);
    $u->execute([$payLink, $lid, 'pagbank_url_desconto']);
    echo "lp_settings atualizado (oficial + desconto) com o novo link.\n";
}

echo "OK\n";
