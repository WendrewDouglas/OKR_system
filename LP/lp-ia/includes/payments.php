<?php
declare(strict_types=1);

// =============================================================
// Lógica compartilhada de pagamento (usada pelo webhook e pelo poller):
// registra/atualiza lp_payments (idempotente por charge_id), casa com lead
// por e-mail e — quando PAGO e ainda não notificado — envia os e-mails
// (confirmação ao comprador + alerta interno).
// =============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * @param array $p chaves: provider, order_id, charge_id, reference_id, status,
 *                 amount_cents, name, email, tax, raw
 * @return array{payment_id:int, notified:bool}
 */
function lp_process_payment(int $landingId, array $p): array
{
    $pdo = lp_db();
    $custMail = ($p['email'] ?? '') !== '' ? mb_substr((string) $p['email'], 0, 190) : null;
    $custName = ($p['name'] ?? '') !== '' ? mb_substr((string) $p['name'], 0, 180) : null;
    $chargeId = ($p['charge_id'] ?? '') !== '' ? (string) $p['charge_id'] : null;
    $status   = (string) ($p['status'] ?? 'UNKNOWN');
    $paidAt   = $status === 'PAID' ? date('Y-m-d H:i:s') : null;

    $existing = null;
    if ($chargeId !== null) {
        $st = $pdo->prepare('SELECT id, buyer_notified_at, admin_notified_at, lead_id FROM lp_payments WHERE charge_id = ? LIMIT 1');
        $st->execute([$chargeId]);
        $existing = $st->fetch() ?: null;
    }

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
            ->execute([$status, ($p['amount_cents'] ?? 0) ?: null, $paidAt, $custName, $custMail, $p['tax'] ?? null, ($p['order_id'] ?? '') ?: null, ($p['reference_id'] ?? '') ?: null, $leadId, $p['raw'] ?? null, $existing['id']]);
        $paymentId = (int) $existing['id'];
    } else {
        $pdo->prepare('INSERT INTO lp_payments (landing_id, lead_id, provider, order_id, charge_id, reference_id, status, amount_cents, customer_name, customer_email, customer_tax_id, raw_json, paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$landingId, $leadId, $p['provider'] ?? 'pagbank', ($p['order_id'] ?? '') ?: null, $chargeId, ($p['reference_id'] ?? '') ?: null, $status, ($p['amount_cents'] ?? 0) ?: null, $custName, $custMail, $p['tax'] ?? null, $p['raw'] ?? null, $paidAt]);
        $paymentId = (int) $pdo->lastInsertId();
    }

    $alreadyNotified = $existing && (!empty($existing['buyer_notified_at']) || !empty($existing['admin_notified_at']));
    $notified = false;
    if ($status === 'PAID' && !$alreadyNotified) {
        lp_pagbank_send_emails($landingId, $paymentId, [
            'name'   => $custName,
            'email'  => $custMail,
            'amount' => (int) ($p['amount_cents'] ?? 0),
            'lead'   => $leadInfo,
        ]);
        $notified = true;
    }

    return ['payment_id' => $paymentId, 'notified' => $notified];
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
        error_log('[LP_IA] nenhum e-mail enviado (pagamento ' . $paymentId . ').');
    }
}
