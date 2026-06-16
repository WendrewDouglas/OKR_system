<?php
declare(strict_types=1);

// =============================================================
// Bootstrap do módulo LP_IA.
// Carrega o config.php da raiz do OKR_system (segredos via .env), define
// constantes do módulo e expõe os helpers. NÃO altera nada do OKR/CRM.
//
// Estrutura: OKR_system/LP/lp-ia/includes/bootstrap.php
//   dirname(__DIR__, 3) => OKR_system
// =============================================================

if (!defined('LP_IA_BOOTSTRAPPED')) {
    define('LP_IA_BOOTSTRAPPED', true);

    $root       = dirname(__DIR__, 3); // .../OKR_system
    $configPath = $root . '/auth/config.php';

    if (!is_file($configPath)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['error' => 'server_error', 'message' => 'Config não encontrado.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    require_once $configPath; // define DB_*, LP_DB_*, SMTP_*, CAPTCHA_*, etc.

    // Slug da landing servida por este módulo.
    if (!defined('LP_IA_SLUG')) {
        define('LP_IA_SLUG', 'ia-financeiro');
    }

    // Versão do texto de consentimento (LGPD). Incrementar ao alterar o texto.
    if (!defined('LP_IA_CONSENT_VERSION')) {
        define('LP_IA_CONSENT_VERSION', '1.0');
    }

    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/security.php';
}
