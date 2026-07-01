<?php
declare(strict_types=1);

// =============================================================
// Bootstrap do módulo "Perspectivas de Gestão" (FMX).
// Carrega o config.php da raiz do OKR_system (segredos via .env),
// define constantes do módulo e expõe os helpers.
// GRAVA NO BANCO PRINCIPAL DO OKR (usa DB_*, não LP_DB_*).
//
// Estrutura: OKR_system/LP/perspectivas/includes/bootstrap.php
//   dirname(__DIR__, 3) => OKR_system
// =============================================================

if (!defined('PG_BOOTSTRAPPED')) {
    define('PG_BOOTSTRAPPED', true);

    $root       = dirname(__DIR__, 3); // .../OKR_system
    $configPath = $root . '/auth/config.php';

    if (!is_file($configPath)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Config não encontrado.']],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    require_once $configPath; // define DB_*, SMTP_*, CAPTCHA_*, etc.

    // Identidade do formulário servido por este módulo.
    if (!defined('PG_FORM_SLUG_C')) {
        define('PG_FORM_SLUG_C', 'perspectivas-gestao');
    }
    // Versão do texto de consentimento (LGPD). Incrementar ao alterar o texto.
    if (!defined('PG_CONSENT_VERSION')) {
        define('PG_CONSENT_VERSION', '1.0');
    }
    // Picker de animais: false = emoji (interim, sem custo); true = fotos em
    // assets/img/animais/<slug>.png. Virar true quando as imagens forem subidas.
    if (!defined('PG_ANIMAL_IMAGES')) {
        define('PG_ANIMAL_IMAGES', false);
    }

    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/security.php';
    require_once __DIR__ . '/questions.php';
    require_once __DIR__ . '/user_upsert.php';
}
