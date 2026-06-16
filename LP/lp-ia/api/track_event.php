<?php
declare(strict_types=1);

// POST: registra eventos de front (essencialmente page_view).
// Eventos sensíveis (cupom/checkout) são registrados server-side nos seus
// próprios endpoints; aqui aceitamos apenas um subconjunto seguro.
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lp_fail('method_not_allowed', 405);
}

$input = lp_input();

if (!lp_csrf_check($input['csrf'] ?? null)) {
    lp_fail('csrf_invalid', 419);
}

if (!lp_rate_limit('track', 60, 60)) {
    lp_fail('rate_limited', 429);
}

$allowed = ['page_view'];
$type = lp_str($input, 'event_type', 40);
if (!in_array($type, $allowed, true)) {
    lp_fail('event_not_allowed', 400);
}

try {
    $landingId = lp_landing_id(LP_IA_SLUG);
} catch (\Throwable $e) {
    lp_fail('landing_unavailable', 503);
}

lp_log_event($landingId, $type, [
    'utm_source'  => lp_clean_param($input['utm_source'] ?? null),
    'utm_medium'  => lp_clean_param($input['utm_medium'] ?? null),
    'utm_campaign' => lp_clean_param($input['utm_campaign'] ?? null),
    'referrer'    => lp_clean_param($input['referrer'] ?? null, 400) ?? lp_referrer(),
    'metadata'    => [
        'path' => lp_clean_param($input['path'] ?? null, 200),
    ],
]);

lp_ok();
