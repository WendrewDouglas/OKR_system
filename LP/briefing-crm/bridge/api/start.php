<?php
declare(strict_types=1);

// PONTE — ~/public_html/briefing_kauana/api/start.php
// Delega para o endpoint versionado no repo. Não editar aqui.

$target = __DIR__ . '/../../OKR_system/LP/briefing-crm/api/start.php';

if (!is_file($target)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => ['code' => 'unavailable', 'message' => 'Serviço indisponível.']], JSON_UNESCAPED_UNICODE);
    exit;
}

require $target;
