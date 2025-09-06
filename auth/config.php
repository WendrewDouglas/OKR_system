<?php
// /OKR_system/auth/config.php
declare(strict_types=1);

// Timezone e log (se já faz em bootstrap_logging.php, pode omitir aqui)
date_default_timezone_set('America/Sao_Paulo');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error_log');

// ==== Banco ====
define('DB_HOST', 'localhost');
define('DB_NAME', 'planni40_okr');
define('DB_USER', 'planni40_wendrew');
define('DB_PASS', 'V064tt=QJr]P');

// Opções PDO padrão (seguras)
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    // Opcional: garanta charset em conexões antigas.
    // PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
];

// ==== WhatsApp Cloud API (se/quando usar) ====
define('WHATSAPP_TOKEN',    getenv('WHATSAPP_TOKEN')    ?: '');
define('WHATSAPP_PHONE_ID', getenv('WHATSAPP_PHONE_ID') ?: '');

// ==== SMTP (Titan recomendado) ====
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.titan.email');
define('SMTP_USER', getenv('SMTP_USER') ?: 'contato@planningbi.com.br');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'Doug8405!');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587)); // força inteiro

// Opcional: ajuda a padronizar remetente no wrapper
define('SMTP_FROM',      'contato@planningbi.com.br');
define('SMTP_FROM_NAME', 'OKR System');
