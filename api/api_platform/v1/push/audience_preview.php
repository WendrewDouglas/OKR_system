<?php
// POST /push/audience/preview
declare(strict_types=1);
$auth = api_require_auth();
$pdo = api_db();
if (!api_is_admin_master($pdo, (int)$auth['sub'])) api_error('E_FORBIDDEN', 'Acesso restrito.', 403);

require_once dirname(__DIR__, 3) . '/../auth/push_helpers.php';

$in = api_input();
$filters = $in['filters'] ?? [];
if (!is_array($filters)) $filters = json_decode((string)$filters, true) ?: [];

$count = push_count_audience($filters, $pdo);
$users = push_list_audience($filters, $pdo, 50);

api_json(['ok' => true, 'count' => $count, 'users' => $users]);
