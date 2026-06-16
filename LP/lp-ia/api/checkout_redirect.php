<?php
declare(strict_types=1);

// GET ?t=<lead_token> : registra o clique de checkout e redireciona ao PagBank.
// Só redireciona se: lead existe, consentimento aceito, checkout habilitado e
// o link PagBank correspondente estiver configurado. Caso contrário, exibe
// uma mensagem amigável (HTML).
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$token = isset($_GET['t']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['t']) : '';

try {
    $landingId = lp_landing_id(LP_IA_SLUG);
} catch (\Throwable $e) {
    lp_checkout_message('Indisponível no momento', 'A página de inscrição está temporariamente indisponível. Tente novamente em instantes.');
}

/* --- Resolve lead pelo token público --------------------------------- */
$lead = null;
if ($token !== '' && strlen($token) === 32) {
    $stmt = lp_db()->prepare(
        'SELECT id, coupon_code, consent FROM lp_leads
          WHERE lead_token = ? AND landing_id = ? LIMIT 1'
    );
    $stmt->execute([$token, $landingId]);
    $lead = $stmt->fetch() ?: null;
}

$leadId = $lead ? (int) $lead['id'] : null;

/* --- Determina o link conforme cupom do lead ------------------------- */
$useDiscount = false;
if ($lead && !empty($lead['coupon_code'])) {
    $useDiscount = lp_resolve_coupon($landingId, (string) $lead['coupon_code']) !== null;
}

$linkKey = $useDiscount ? 'pagbank_url_desconto' : 'pagbank_url_oficial';
$link    = trim((string) lp_setting($landingId, $linkKey, ''));

/* --- Guardas --------------------------------------------------------- */
$checkoutEnabled = lp_setting_bool($landingId, 'checkout_enabled', false);

$blockReason = null;
if (!$lead) {
    $blockReason = 'lead_not_found';
} elseif ((int) $lead['consent'] !== 1) {
    $blockReason = 'no_consent';
} elseif (!$checkoutEnabled) {
    $blockReason = 'checkout_disabled';
} elseif ($link === '' || !preg_match('#^https?://#i', $link)) {
    $blockReason = 'link_unconfigured';
}

if ($blockReason !== null) {
    lp_log_event($landingId, 'checkout_blocked', [
        'lead_id'     => $leadId,
        'coupon_code' => $lead['coupon_code'] ?? null,
        'metadata'    => ['reason' => $blockReason, 'link_key' => $linkKey],
    ]);

    if ($blockReason === 'lead_not_found') {
        lp_checkout_message(
            'Link inválido',
            'Não encontramos sua inscrição. Volte à página e preencha o formulário novamente.'
        );
    }
    lp_checkout_message(
        'Inscrição quase lá!',
        'O pagamento ainda não está disponível neste momento. Recebemos seus dados e entraremos '
        . 'em contato em breve com o link oficial para concluir sua inscrição.'
    );
}

/* --- Sucesso: registra clique e redireciona -------------------------- */
lp_log_event($landingId, 'checkout_click', [
    'lead_id'     => $leadId,
    'coupon_code' => $lead['coupon_code'] ?? null,
    'metadata'    => ['link_key' => $linkKey, 'discount' => $useDiscount],
]);

header('Location: ' . $link, true, 302);
exit;

/* -------------------------------------------------------------------- */

function lp_checkout_message(string $title, string $body): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $b = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="pt-br"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$t} — PlanningBI</title>
<style>
  body{margin:0;font-family:'Inter',Arial,sans-serif;background:#0e131a;color:#fff;
       display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}
  .card{max-width:480px;background:#1a212b;border-radius:16px;padding:32px;text-align:center;
        box-shadow:0 10px 40px rgba(0,0,0,.4)}
  h1{color:#FF5722;font-size:1.4rem;margin:0 0 12px}
  p{color:#cfd6e0;line-height:1.6;margin:0 0 24px}
  a{display:inline-block;background:#FF5722;color:#fff;text-decoration:none;
    padding:12px 22px;border-radius:10px;font-weight:600}
</style></head>
<body><div class="card"><h1>{$t}</h1><p>{$b}</p>
<a href="../public/index.php">Voltar para a página</a></div></body></html>
HTML;
    exit;
}
