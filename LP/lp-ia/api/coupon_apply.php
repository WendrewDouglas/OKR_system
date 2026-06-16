<?php
declare(strict_types=1);

// POST: valida cupom no servidor e devolve o valor especial.
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lp_fail('method_not_allowed', 405);
}

$input = lp_input();

if (!lp_csrf_check($input['csrf'] ?? null)) {
    lp_fail('csrf_invalid', 419);
}

if (!lp_rate_limit('coupon', 30, 60)) {
    lp_fail('rate_limited', 429, ['message' => 'Muitas tentativas. Aguarde um instante.']);
}

try {
    $landingId = lp_landing_id(LP_IA_SLUG);
} catch (\Throwable $e) {
    lp_fail('landing_unavailable', 503);
}

$code = lp_str($input, 'coupon', 60);

if ($code === '') {
    lp_fail('coupon_required', 400, ['message' => 'Digite um cupom.']);
}

$coupon = lp_resolve_coupon($landingId, $code);

if ($coupon === null) {
    lp_log_event($landingId, 'coupon_failed', [
        'coupon_code' => $code,
        'utm_source'  => lp_clean_param($input['utm_source'] ?? null),
        'utm_medium'  => lp_clean_param($input['utm_medium'] ?? null),
        'utm_campaign' => lp_clean_param($input['utm_campaign'] ?? null),
    ]);
    lp_fail('coupon_invalid', 200, [
        'valid'   => false,
        'message' => 'Cupom inválido ou expirado.',
    ]);
}

$priceCents = (int) $coupon['price_cents'];

lp_log_event($landingId, 'coupon_applied', [
    'coupon_code' => $coupon['code'],
    'utm_source'  => lp_clean_param($input['utm_source'] ?? null),
    'utm_medium'  => lp_clean_param($input['utm_medium'] ?? null),
    'utm_campaign' => lp_clean_param($input['utm_campaign'] ?? null),
    'metadata'    => ['price_cents' => $priceCents],
]);

lp_ok([
    'valid'           => true,
    'code'            => $coupon['code'],
    'price_cents'     => $priceCents,
    'price_formatted' => lp_money_br($priceCents),
    'label'           => $coupon['label'] ?: 'Valor especial liberado',
    'message'         => 'Cupom aplicado: valor especial liberado — ' . lp_money_br($priceCents),
    'btn_text'        => lp_setting($landingId, 'btn_text_desconto', 'Garantir minha vaga com desconto'),
]);
