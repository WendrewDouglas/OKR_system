<?php
// /views/api/okr_router.php
declare(strict_types=1);

/**
 * Router único para AJAX:
 * /views/api/okr_router.php?action=...
 */

require_once __DIR__ . '/../../app/bootstrap/init.php';

// Se você usa ACL por path, aplica aqui (opcional)
gate_by_path_if_available('/views/detalhe_okr.php');

// Exige login para qualquer endpoint do OKR
require_login_or_fail();

// action
$action = (string)($_GET['action'] ?? '');

// Mapa de endpoints permitidos (whitelist)
$map = [
  'list_status_iniciativa'     => __DIR__ . '/okr/list_status_iniciativa.php',
  'list_responsaveis_company'  => __DIR__ . '/okr/list_responsaveis_company.php',
];

if ($action === '' || !isset($map[$action])) {
  json_fail(400, 'Ação inválida.');
}

require $map[$action];