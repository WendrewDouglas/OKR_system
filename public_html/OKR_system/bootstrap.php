<?php
// bootstrap.php

declare(strict_types=1);

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;

// 1) Autoload do Composer
require __DIR__ . '/vendor/autoload.php';

// 2) Cria pasta de logs, se não existir
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// 3) Instancia o Logger
$logger = new Logger('openai');

// 4) Handler com rotação diária (7 dias de retenção)
$handler = new RotatingFileHandler(
    $logDir . '/openai.log', // caminho base dos arquivos
    7,                        // mantém 7 arquivos antes de apagar
    Logger::DEBUG             // nível mínimo: DEBUG
);

// 5) Formata cada linha como JSON
$handler->setFormatter(new JsonFormatter());

// 6) Associa o handler ao logger
$logger->pushHandler($handler);

// 7) Retorna o logger para uso em outros scripts
return $logger;
