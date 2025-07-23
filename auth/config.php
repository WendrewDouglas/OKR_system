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
