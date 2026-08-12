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
    // Destino das respostas. Mesmo domínio do remetente SMTP
    // (contato@planningbi.com.br) para não cair em spam.
    if (!defined('BC_OWNER_EMAIL')) {
        define('BC_OWNER_EMAIL', 'wendrew.gomes@planningbi.com.br');
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
