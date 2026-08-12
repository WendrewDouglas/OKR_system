<?php
declare(strict_types=1);

// =============================================================
// Bootstrap do módulo "Briefing CRM".
//
// Carrega auth/config.php (DB_*, SMTP_*, vendor/autoload) e
// auth/functions.php (sendTransactionalMail). functions.php só depende
// de config.php e define fallback próprio de app_log — dá para incluir
// daqui sem arrastar sessão nem estado do app OKR.
//
// Estrutura: OKR_system/LP/briefing-crm/includes/bootstrap.php
//   dirname(__DIR__, 3) => OKR_system
// =============================================================

if (!defined('BC_BOOTSTRAPPED')) {
    define('BC_BOOTSTRAPPED', true);

    $root = dirname(__DIR__, 3); // .../OKR_system

    foreach (['/auth/config.php', '/auth/functions.php'] as $rel) {
        $path = $root . $rel;
        if (!is_file($path)) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Configuração indisponível.']],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }
        require_once $path;
    }

    // ---- Identidade do formulário ----
    if (!defined('BC_FORM_SLUG_C')) {
        define('BC_FORM_SLUG_C', 'briefing-crm-kauana');
    }
    // Versão do texto de consentimento (LGPD). Incrementar ao alterar o texto.
    if (!defined('BC_CONSENT_VERSION')) {
        define('BC_CONSENT_VERSION', '1.0');
    }
    // Destinos do relatório, em ordem de preferência (lista separada por
    // vírgula, sobrescrevível pelo .env).
    //
    // O primeiro é EXTERNO de propósito: o exim da HostGator trata
    // planningbi.com.br como domínio local (`exim -bt` mostra
    // deliver_local_outside_jail -> 127.0.0.1), então tudo que o servidor
    // manda para lá é entregue nele mesmo e nunca chega ao Titan. O
    // @planningbi segue na lista para voltar a funcionar sozinho quando a
    // autenticação SMTP do Titan for corrigida — aí o envio passa pelo
    // Titan e não encosta mais no exim local.
    if (!defined('BC_OWNER_EMAILS')) {
        define('BC_OWNER_EMAILS', (string) env(
            'BRIEFING_OWNER_EMAILS',
            'wendrew.douglas@gmail.com,wendrew.gomes@planningbi.com.br'
        ));
    }
    // Endereço institucional mostrado a quem responde (Reply-To da cópia).
    // Responder para cá funciona normalmente: o problema de roteamento só
    // afeta o que o servidor ENVIA, não o que entra pelo MX do Titan.
    if (!defined('BC_CONTACT_EMAIL')) {
        define('BC_CONTACT_EMAIL', 'wendrew.gomes@planningbi.com.br');
    }
    if (!defined('BC_OWNER_NAME')) {
        define('BC_OWNER_NAME', 'Wendrew');
    }
    // URL pública — usada no e-mail de cópia e no link de retomada.
    if (!defined('BC_PUBLIC_URL')) {
        define('BC_PUBLIC_URL', 'https://planningbi.com.br/briefing_kauana/');
    }

    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/security.php';
    require_once __DIR__ . '/questions.php';
    require_once __DIR__ . '/mail.php';
}
