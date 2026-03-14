<?php
// GET /push/campaigns
declare(strict_types=1);
$auth = api_require_auth();
$pdo = api_db();
if (!api_is_admin_master($pdo, (int)$auth['sub'])) api_error('E_FORBIDDEN', 'Acesso restrito.', 403);

[$page, $perPage] = api_pagination_params();

$data = api_paginated($pdo,
  "SELECT pc.*, u.primeiro_nome AS criador_nome,
    (SELECT COUNT(*) FROM push_campaign_recipients r WHERE r.id_campaign=pc.id_campaign AND r.status_envio='sent') AS total_sent,
    (SELECT COUNT(*) FROM push_campaign_recipients r WHERE r.id_campaign=pc.id_campaign) AS total_recipients
   FROM push_campaigns pc LEFT JOIN usuarios u ON u.id_user=pc.created_by
   ORDER BY pc.created_at DESC",
  "SELECT COUNT(*) FROM push_campaigns",
  [], $page, $perPage
);

api_json($data);
