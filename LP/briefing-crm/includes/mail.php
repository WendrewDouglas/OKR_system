<?php
declare(strict_types=1);

// =============================================================
// E-mails do módulo. Usa sendTransactionalMail() de auth/functions.php
// (SMTP Titan via contato@planningbi.com.br, com fallback mail() e
// auditoria .eml em auth/outbox/).
//
// HTML de e-mail é conservador de propósito: estilos inline, tabelas,
// sem flex/grid — é o que sobrevive no Gmail e no Outlook.
// =============================================================

/**
 * Renderiza as respostas agrupadas por bloco.
 * $answers: mapa question_key => valor (string|array|int|null).
 */
function bc_render_answers_html(array $answers, bool $showEmpty = false): string
{
    $questions = bc_questions();
    $meta      = bc_block_meta();
    $out       = '';

    foreach (bc_block_order() as $block) {
        $rows = '';
        foreach (bc_block_question_keys($block) as $qkey) {
            $q = $questions[$qkey];
            $v = $answers[$qkey] ?? null;

            if (is_array($v)) {
                $v = $v === [] ? '' : implode(' · ', $v);
            }
            $v = is_string($v) ? trim($v) : ($v === null ? '' : (string) $v);

            if ($v === '' && !$showEmpty) {
                continue;
            }

            $rows .= '<tr>'
                . '<td style="padding:10px 0 2px;font:600 13px/1.4 Segoe UI,Arial,sans-serif;color:#5E757E;">'
                . htmlspecialchars($q['question_text'], ENT_QUOTES, 'UTF-8')
                . '</td></tr>'
                . '<tr><td style="padding:0 0 12px;border-bottom:1px solid #E4EBED;'
                . 'font:400 15px/1.55 Georgia,serif;color:#101F28;white-space:pre-wrap;">'
                . ($v === ''
                    ? '<span style="color:#9AAAB1;font-style:italic;">— em branco —</span>'
                    : nl2br(htmlspecialchars($v, ENT_QUOTES, 'UTF-8')))
                . '</td></tr>';
        }

        if ($rows === '') {
            continue;
        }

        $title = $meta[$block]['title'] ?? $block;
        $out .= '<h3 style="margin:26px 0 4px;font:600 12px/1.4 Segoe UI,Arial,sans-serif;'
            . 'letter-spacing:.12em;text-transform:uppercase;color:#0B6B60;">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . $rows . '</table>';
    }

    return $out === ''
        ? '<p style="font:400 15px/1.6 Georgia,serif;color:#5E757E;">Nenhuma resposta preenchida.</p>'
        : $out;
}

function bc_email_shell(string $preheader, string $inner): string
{
    return '<!-- ' . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . ' -->'
        . '<div style="background:#F1F5F6;padding:24px 12px;">'
        . '<div style="max-width:640px;margin:0 auto;background:#FFFFFF;'
        . 'border:1px solid #D3DDE0;border-radius:3px;padding:28px 26px;">'
        . $inner
        . '<p style="margin:28px 0 0;padding-top:14px;border-top:1px solid #E4EBED;'
        . 'font:400 11px/1.5 Consolas,monospace;letter-spacing:.04em;color:#8C9DA4;">'
        . 'Briefing CRM &middot; enviado automaticamente por planningbi.com.br'
        . '</p></div></div>';
}

/**
 * Aviso para o dono do projeto, com o briefing inteiro.
 * Reply-To aponta para a respondente — responder o e-mail fala com ela.
 */
function bc_send_owner_notification(array $sess, array $answers): bool
{
    $nome  = (string) $sess['nome_informado'];
    $email = (string) $sess['email_informado'];
    $wpp   = (string) ($sess['whatsapp_informado'] ?? '');
    $esc   = (string) ($sess['escritorio'] ?? '');
    $papel = (string) ($sess['papel'] ?? '');

    $ident = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
        . 'style="margin:14px 0 4px;background:#F1F5F6;border-radius:3px;">';
    foreach ([
        'Nome'      => $nome,
        'Escritório' => $esc,
        'Papel'     => $papel,
        'E-mail'    => $email,
        'WhatsApp'  => $wpp,
    ] as $label => $value) {
        if (trim($value) === '') {
            continue;
        }
        $ident .= '<tr>'
            . '<td style="padding:7px 12px;font:400 11px/1.4 Consolas,monospace;letter-spacing:.06em;'
            . 'text-transform:uppercase;color:#5E757E;width:98px;vertical-align:top;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:7px 12px 7px 0;font:600 14px/1.45 Segoe UI,Arial,sans-serif;color:#101F28;">'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    $ident .= '</table>';

    $inner = '<p style="margin:0;font:400 11px/1.4 Consolas,monospace;letter-spacing:.12em;'
        . 'text-transform:uppercase;color:#0B6B60;">Briefing respondido</p>'
        . '<h1 style="margin:8px 0 0;font:650 24px/1.2 Segoe UI,Arial,sans-serif;color:#101F28;">'
        . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . ' preencheu o briefing do CRM</h1>'
        . $ident
        . bc_render_answers_html($answers, true)
        . '<p style="margin:24px 0 0;font:400 13px/1.6 Georgia,serif;color:#5E757E;">'
        . 'Concluído em ' . date('d/m/Y \à\s H:i') . '. '
        . 'Responder este e-mail fala direto com ' . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . '.</p>';

    $replyTo = bc_valid_email($email) ? $email : BC_OWNER_EMAIL;

    return sendTransactionalMail(
        BC_OWNER_EMAIL,
        'Briefing CRM respondido — ' . $nome . ($esc !== '' ? ' (' . $esc . ')' : ''),
        bc_email_shell('Briefing do CRM preenchido por ' . $nome, $inner),
        $replyTo,
        $nome !== '' ? $nome : 'Briefing CRM'
    );
}

/**
 * Cópia para a respondente — ela precisa disso para levar à reunião
 * com os sócios.
 */
function bc_send_respondent_copy(array $sess, array $answers): bool
{
    $nome  = (string) $sess['nome_informado'];
    $email = (string) $sess['email_informado'];
    if (!bc_valid_email($email)) {
        return false;
    }

    $primeiro = explode(' ', trim($nome))[0] ?: 'Olá';

    $inner = '<p style="margin:0;font:400 11px/1.4 Consolas,monospace;letter-spacing:.12em;'
        . 'text-transform:uppercase;color:#0B6B60;">Sua cópia</p>'
        . '<h1 style="margin:8px 0 14px;font:650 24px/1.2 Segoe UI,Arial,sans-serif;color:#101F28;">'
        . htmlspecialchars($primeiro, ENT_QUOTES, 'UTF-8') . ', recebi seu briefing</h1>'
        . '<p style="margin:0 0 6px;font:400 15px/1.62 Georgia,serif;color:#344A54;">'
        . 'Abaixo está tudo o que você respondeu, para você ter em mãos — dá para '
        . 'levar direto para a conversa com os sócios. Qualquer coisa que você '
        . 'quiser corrigir ou completar, me avisa que eu ajusto.</p>'
        . bc_render_answers_html($answers, false)
        . '<p style="margin:26px 0 0;font:400 14px/1.6 Georgia,serif;color:#344A54;">'
        . 'Vou ler com calma e te procuro com o desenho da primeira versão.</p>';

    return sendTransactionalMail(
        $email,
        'Sua cópia do briefing do CRM',
        bc_email_shell('Cópia das suas respostas do briefing', $inner),
        BC_OWNER_EMAIL,
        BC_OWNER_NAME
    );
}
