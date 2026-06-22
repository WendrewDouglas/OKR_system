<?php
declare(strict_types=1);

// POST: grava lead + prova de consentimento, dispara e-mails e devolve o
// token público para o checkout. Preço NUNCA vem do cliente.
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lp_fail('method_not_allowed', 405);
}

$input = lp_input();

/* --- Anti-abuso ------------------------------------------------------ */
if (!lp_csrf_check($input['csrf'] ?? null)) {
    lp_fail('csrf_invalid', 419);
}
if (lp_honeypot_tripped($input)) {
    // Responde como sucesso silencioso para não dar pistas ao bot.
    lp_ok(['lead_token' => null, 'silent' => true]);
}
if (!lp_rate_limit('lead', 5, 600)) {
    lp_fail('rate_limited', 429, ['message' => 'Muitas tentativas. Tente novamente em alguns minutos.']);
}
if (!lp_captcha_verify($input['captcha_token'] ?? ($input['g-recaptcha-response'] ?? null))) {
    lp_fail('captcha_failed', 400, ['message' => 'Não foi possível validar que você é humano. Recarregue a página e tente novamente.']);
}

try {
    $landingId = lp_landing_id(LP_IA_SLUG);
} catch (\Throwable $e) {
    lp_fail('landing_unavailable', 503);
}

/* --- Validação dos campos ------------------------------------------- */
$nome  = lp_str($input, 'nome', 180);
$email = lp_str($input, 'email', 190);
$wpRaw = lp_str($input, 'whatsapp', 40);
$cidade = lp_str($input, 'cidade', 120);
$area   = lp_str($input, 'area_atuacao', 120);
$couponCode = lp_str($input, 'coupon', 60);

$consent = filter_var($input['consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

$errors = [];
if (mb_strlen($nome) < 2) {
    $errors['nome'] = 'Informe seu nome completo.';
}
if (!lp_valid_email($email)) {
    $errors['email'] = 'Informe um e-mail válido.';
}
$whatsapp = lp_normalize_whatsapp($wpRaw);
if ($whatsapp === '') {
    $errors['whatsapp'] = 'Informe um WhatsApp válido com DDD.';
}
if (!$consent) {
    $errors['consent'] = 'É necessário aceitar os termos para continuar.';
}

if (!empty($errors)) {
    lp_fail('validation_error', 422, ['fields' => $errors, 'message' => 'Revise os campos destacados.']);
}

/* --- Cupom (revalida no servidor; ignora se inválido) --------------- */
$couponStored = null;
if ($couponCode !== '') {
    $coupon = lp_resolve_coupon($landingId, $couponCode);
    if ($coupon !== null) {
        $couponStored = $coupon['code'];
    }
}

/* --- UTM / origem ---------------------------------------------------- */
$utmSource   = lp_clean_param($input['utm_source'] ?? null);
$utmMedium   = lp_clean_param($input['utm_medium'] ?? null);
$utmCampaign = lp_clean_param($input['utm_campaign'] ?? null);
$referrer    = lp_clean_param($input['referrer'] ?? null, 400) ?? lp_referrer();

/* --- Persistência ---------------------------------------------------- */
$pdo = lp_db();
$token = lp_generate_token();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO lp_leads
            (landing_id, lead_token, nome, email, whatsapp, cidade, area_atuacao,
             coupon_code, utm_source, utm_medium, utm_campaign, referrer,
             consent, consent_version, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
    );
    $stmt->execute([
        $landingId, $token, $nome, $email, $whatsapp, ($cidade ?: null), ($area ?: null),
        $couponStored, $utmSource, $utmMedium, $utmCampaign, $referrer,
        LP_IA_CONSENT_VERSION, lp_client_ip(), lp_user_agent(),
    ]);
    $leadId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO lp_consents
            (lead_id, consent_text, consent_version, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $leadId, lp_consent_text(), LP_IA_CONSENT_VERSION, lp_client_ip(), lp_user_agent(),
    ]);

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[LP_IA] lead_submit falhou: ' . $e->getMessage());
    lp_fail('server_error', 500, ['message' => 'Não foi possível registrar agora. Tente novamente em instantes.']);
}

lp_log_event($landingId, 'lead_submit', [
    'lead_id'     => $leadId,
    'coupon_code' => $couponStored,
    'utm_source'  => $utmSource,
    'utm_medium'  => $utmMedium,
    'utm_campaign' => $utmCampaign,
    'referrer'    => $referrer,
]);

/* --- E-mails (não bloqueiam a resposta em caso de falha) ------------- */
lp_dispatch_lead_emails($landingId, [
    'nome'        => $nome,
    'email'       => $email,
    'whatsapp'    => $whatsapp,
    'cidade'      => $cidade,
    'area'        => $area,
    'coupon'      => $couponStored,
    'utm_source'  => $utmSource,
    'utm_campaign' => $utmCampaign,
]);

// O botão de pagamento fica em /public/; o endpoint está em /api/.
$checkoutUrl = '../api/checkout_redirect.php?t=' . urlencode($token);

lp_ok([
    'lead_token'   => $token,
    'checkout_url' => $checkoutUrl,
    'message'      => 'Recebemos seus dados! A inscrição será confirmada somente após o pagamento.',
]);

/* -------------------------------------------------------------------- */

function lp_dispatch_lead_emails(int $landingId, array $lead): void
{
    $trainingTitle = 'IA Aplicada ao Dia a Dia Financeiro';
    $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    // 1) Confirmação para o lead — sem prometer vaga garantida.
    $leadHtml = '<div style="font-family:Arial,sans-serif;color:#212529;line-height:1.6">'
        . '<h2 style="color:#FF5722">Recebemos sua inscrição de interesse!</h2>'
        . '<p>Olá, ' . $esc($lead['nome']) . '.</p>'
        . '<p>Recebemos seus dados para o treinamento <strong>' . $esc($trainingTitle) . '</strong>.</p>'
        . '<p><strong>Importante:</strong> sua inscrição só será <strong>confirmada após o pagamento</strong>. '
        . 'O envio deste formulário não garante vaga.</p>'
        . '<p>Em breve entraremos em contato com as instruções. Se você já realizou o pagamento, aguarde a confirmação.</p>'
        . '<hr style="border:none;border-top:1px solid #eee">'
        . '<p style="font-size:12px;color:#6c757d">Participação opcional. Iniciativa independente da PlanningBI. '
        . 'Não garante emprego nem aprovação em processo seletivo.</p>'
        . '</div>';
    lp_send_mail($lead['email'], 'Recebemos sua inscrição — ' . $trainingTitle, $leadHtml);

    // 2) Notificação interna.
    $to = lp_internal_notify_email();
    if ($to !== '') {
        $rows = [
            'Nome'      => $lead['nome'],
            'E-mail'    => $lead['email'],
            'WhatsApp'  => $lead['whatsapp'],
            'Cidade'    => $lead['cidade'] ?: '-',
            'Área'      => $lead['area'] ?: '-',
            'Cupom'     => $lead['coupon'] ?: '-',
            'UTM source' => $lead['utm_source'] ?: '-',
            'Campanha'  => $lead['utm_campaign'] ?: '-',
        ];
        $body = '<div style="font-family:Arial,sans-serif;color:#212529">'
            . '<h3>Novo lead — ' . $esc($trainingTitle) . '</h3><table cellpadding="6" style="border-collapse:collapse">';
        foreach ($rows as $k => $v) {
            $body .= '<tr><td style="border:1px solid #eee"><strong>' . $esc($k)
                . '</strong></td><td style="border:1px solid #eee">' . $esc($v) . '</td></tr>';
        }
        $body .= '</table></div>';
        lp_send_mail($to, '[LP_IA] Novo lead: ' . $lead['nome'], $body);
    }
}
