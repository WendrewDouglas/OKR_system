<?php
// config.php
// Ajuste estes valores conforme seu ambiente
define('DB_HOST', 'localhost');
define('DB_NAME', 'planni40_okr');
define('DB_USER', 'planni40_wendrew');
define('DB_PASS', 'V064tt=QJr]P');

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// WhatsApp Cloud API
define('WHATSAPP_TOKEN',    getenv('WHATSAPP_TOKEN') ?: '');
define('WHATSAPP_PHONE_ID', getenv('WHATSAPP_PHONE_ID') ?: '');

// SMTP (se for implementar PHPMailer)
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
