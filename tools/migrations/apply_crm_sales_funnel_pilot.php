<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../../auth/crm_db.php';

$sampleSize = 80;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--sample=')) {
        $sampleSize = max(50, min(100, (int)substr($arg, 9)));
    }
}

function crm_pilot_json(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function crm_pilot_upsert_setting(PDO $pdo, string $key, string $value, string $type, string $description): void
{
    $st = $pdo->prepare("
        INSERT INTO crm_settings (setting_key, setting_value, value_type, description)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          setting_value = VALUES(setting_value),
          value_type = VALUES(value_type),
          description = VALUES(description),
          updated_at = NOW()
    ");
    $st->execute([$key, $value, $type, $description]);
}

function crm_pilot_segment(PDO $pdo, string $name, string $entityType, string $description, array $filters, bool $dynamic): int
{
    $st = $pdo->prepare("SELECT id_segment FROM crm_segments WHERE segment_name = ? AND entity_type = ? LIMIT 1");
    $st->execute([$name, $entityType]);
    $id = $st->fetchColumn();
    if ($id) {
        $upd = $pdo->prepare("
            UPDATE crm_segments
               SET description = ?, filters_json = ?, is_dynamic = ?, updated_at = NOW()
             WHERE id_segment = ?
        ");
        $upd->execute([$description, crm_pilot_json($filters), $dynamic ? 1 : 0, (int)$id]);
        return (int)$id;
    }

    $ins = $pdo->prepare("
        INSERT INTO crm_segments (segment_name, description, entity_type, filters_json, is_dynamic)
        VALUES (?, ?, ?, ?, ?)
    ");
    $ins->execute([$name, $description, $entityType, crm_pilot_json($filters), $dynamic ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}

function crm_pilot_replace_members(PDO $pdo, int $segmentId, string $entityType, array $members): int
{
    $del = $pdo->prepare("DELETE FROM crm_segment_members WHERE id_segment = ?");
    $del->execute([$segmentId]);

    $ins = $pdo->prepare("
        INSERT IGNORE INTO crm_segment_members (id_segment, entity_type, id_entity, score_at_add)
        VALUES (?, ?, ?, ?)
    ");

    $inserted = 0;
    foreach ($members as $member) {
        $ins->execute([
            $segmentId,
            $entityType,
            (int)$member['id'],
            $member['score'] !== null ? (float)$member['score'] : null,
        ]);
        $inserted += $ins->rowCount();
    }

    return $inserted;
}

function crm_pilot_stage_id(PDO $pdo, string $stageKey): int
{
    $st = $pdo->prepare("SELECT id_stage FROM crm_pipeline_stages WHERE stage_key = ? LIMIT 1");
    $st->execute([$stageKey]);
    $id = $st->fetchColumn();
    if (!$id) {
        throw new RuntimeException("Pipeline stage not found: {$stageKey}");
    }
    return (int)$id;
}

function crm_pilot_campaign(PDO $pdo, int $segmentId): int
{
    $name = 'Piloto LinkedIn - Diagnóstico de Planejamento Estratégico';
    $template = [
        'offer' => 'Diagnóstico de Maturidade em Planejamento Estratégico',
        'channel' => 'linkedin',
        'cadence' => [
            ['day' => 1, 'type' => 'linkedin_message', 'goal' => 'conectar contexto e pedir permissão para enviar diagnóstico'],
            ['day' => 3, 'type' => 'linkedin_follow_up', 'goal' => 'reforçar dor de execução estratégica, metas e indicadores'],
            ['day' => 7, 'type' => 'linkedin_follow_up', 'goal' => 'convidar para conversa de 30 minutos'],
            ['day' => 14, 'type' => 'content_nurture', 'goal' => 'enviar insight ou material de nutrição'],
        ],
        'message_1' => 'Olá, [Nome]. Vi sua atuação em [Empresa] e achei que fazia sentido conectar. Tenho trabalhado com empresas que querem organizar planejamento estratégico, metas e indicadores de gestão. Posso te enviar um diagnóstico rápido sobre maturidade em gestão estratégica?',
        'message_2' => 'A ideia é simples: entender como hoje vocês conectam estratégia, metas, indicadores e rotina de acompanhamento. Normalmente em 30 minutos já conseguimos identificar gargalos e oportunidades.',
    ];

    $st = $pdo->prepare("SELECT id_campaign FROM crm_campaigns WHERE campaign_name = ? LIMIT 1");
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if ($id) {
        $upd = $pdo->prepare("
            UPDATE crm_campaigns
               SET campaign_type = 'linkedin_outreach',
                   objective = ?,
                   status = 'active',
                   id_segment = ?,
                   template_json = ?,
                   updated_at = NOW()
             WHERE id_campaign = ?
        ");
        $upd->execute([
            'Validar abordagem consultiva para diagnóstico de maturidade em planejamento estratégico com 50 a 100 leads priorizados.',
            $segmentId,
            crm_pilot_json($template),
            (int)$id,
        ]);
        return (int)$id;
    }

    $ins = $pdo->prepare("
        INSERT INTO crm_campaigns
          (campaign_name, campaign_type, objective, status, id_segment, start_at, template_json)
        VALUES
          (?, 'linkedin_outreach', ?, 'active', ?, NOW(), ?)
    ");
    $ins->execute([
        $name,
        'Validar abordagem consultiva para diagnóstico de maturidade em planejamento estratégico com 50 a 100 leads priorizados.',
        $segmentId,
        crm_pilot_json($template),
    ]);
    return (int)$pdo->lastInsertId();
}

function crm_pilot_candidate_score(array $row): float
{
    $score = (float)$row['lead_score'];
    $score += match ((string)$row['seniority']) {
        'owner', 'c_level' => 30,
        'director', 'head' => 24,
        'manager' => 18,
        'coordinator' => 10,
        default => 0,
    };
    $score += match ((string)$row['department']) {
        'strategy' => 24,
        'finance', 'operations', 'it_data', 'commercial_marketing' => 16,
        'hr', 'procurement' => 6,
        default => 0,
    };
    if ((int)$row['has_conversation'] === 1) {
        $score += 18;
    }
    if ((int)$row['has_email'] === 1) {
        $score += 6;
    }
    if ((int)$row['account_contacts'] >= 2) {
        $score += min(12, (int)$row['account_contacts'] * 2);
    }
    if (!empty($row['connected_on']) && strtotime((string)$row['connected_on']) > strtotime('-18 months')) {
        $score += 8;
    }
    return round($score, 2);
}

function crm_pilot_select_sample(PDO $pdo, int $sampleSize): array
{
    $rows = $pdo->query("
        SELECT c.id_contact,
               c.full_name,
               c.current_account_id,
               c.current_company_name,
               c.current_position,
               c.seniority,
               c.department,
               c.contact_status,
               c.lead_score,
               c.connected_on,
               COALESCE(a.company_root_key, CONCAT('contact-', c.id_contact)) AS company_root_key,
               (
                 SELECT COUNT(*)
                   FROM crm_contacts cx
                  WHERE cx.current_account_id = c.current_account_id
                    AND c.current_account_id IS NOT NULL
               ) AS account_contacts,
               EXISTS(
                 SELECT 1 FROM crm_contact_channels ch
                  WHERE ch.id_contact = c.id_contact AND ch.channel_type = 'email'
                  LIMIT 1
               ) AS has_email,
               EXISTS(
                 SELECT 1 FROM crm_conversations cv
                  WHERE cv.id_contact = c.id_contact
                  LIMIT 1
               ) AS has_conversation
          FROM crm_contacts c
          LEFT JOIN crm_accounts a ON a.id_account = c.current_account_id
         WHERE c.contact_status NOT IN ('do_not_contact', 'not_fit', 'converted')
           AND (c.current_company_name IS NOT NULL AND c.current_company_name <> '')
         LIMIT 1000
    ")->fetchAll();

    foreach ($rows as &$row) {
        $row['pilot_score'] = crm_pilot_candidate_score($row);
    }
    unset($row);

    usort($rows, static fn(array $a, array $b): int => ($b['pilot_score'] <=> $a['pilot_score']) ?: ((int)$b['id_contact'] <=> (int)$a['id_contact']));

    $selected = [];
    $perCompany = [];
    foreach ($rows as $row) {
        $root = (string)($row['company_root_key'] ?: 'contact-' . $row['id_contact']);
        if (($perCompany[$root] ?? 0) >= 2) {
            continue;
        }
        $selected[] = $row;
        $perCompany[$root] = ($perCompany[$root] ?? 0) + 1;
        if (count($selected) >= $sampleSize) {
            return $selected;
        }
    }

    $selectedIds = array_fill_keys(array_map(static fn(array $r): int => (int)$r['id_contact'], $selected), true);
    foreach ($rows as $row) {
        if (isset($selectedIds[(int)$row['id_contact']])) {
            continue;
        }
        $selected[] = $row;
        if (count($selected) >= $sampleSize) {
            break;
        }
    }

    return $selected;
}

function crm_pilot_insert_campaign_members(PDO $pdo, int $campaignId, array $sample): int
{
    $ins = $pdo->prepare("
        INSERT INTO crm_campaign_members
          (id_campaign, id_contact, id_account, status, step_number, next_step_at, result_notes)
        VALUES
          (?, ?, ?, 'queued', 1, DATE_ADD(NOW(), INTERVAL ? DAY), ?)
        ON DUPLICATE KEY UPDATE
          id_account = VALUES(id_account),
          next_step_at = COALESCE(crm_campaign_members.next_step_at, VALUES(next_step_at)),
          result_notes = VALUES(result_notes),
          updated_at = NOW()
    ");

    $affected = 0;
    foreach ($sample as $idx => $row) {
        $ins->execute([
            $campaignId,
            (int)$row['id_contact'],
            $row['current_account_id'] ? (int)$row['current_account_id'] : null,
            1 + ($idx % 10),
            'Amostra piloto priorizada para diagnóstico de planejamento estratégico. Score piloto: ' . $row['pilot_score'],
        ]);
        $affected += $ins->rowCount();
    }
    return $affected;
}

function crm_pilot_insert_tasks(PDO $pdo, int $campaignId, array $sample): int
{
    $exists = $pdo->prepare("
        SELECT id_task
          FROM crm_tasks
         WHERE id_campaign = ?
           AND id_contact = ?
           AND title = ?
         LIMIT 1
    ");
    $ins = $pdo->prepare("
        INSERT INTO crm_tasks
          (title, description, status, priority, due_at, id_contact, id_account, id_campaign)
        VALUES
          (?, ?, 'open', ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?, ?, ?)
    ");

    $created = 0;
    foreach ($sample as $idx => $row) {
        $title = 'Abordagem inicial LinkedIn - Diagnóstico Estratégico';
        $exists->execute([$campaignId, (int)$row['id_contact'], $title]);
        if ($exists->fetchColumn()) {
            continue;
        }

        $priority = ((float)$row['pilot_score'] >= 95) ? 'high' : 'medium';
        $description = sprintf(
            "Contato: %s\nEmpresa: %s\nCargo: %s\nObjetivo: validar interesse em diagnóstico de maturidade em planejamento estratégico.\nMensagem sugerida: Olá, [Nome]. Vi sua atuação em [Empresa] e achei que fazia sentido conectar. Tenho trabalhado com empresas que querem organizar planejamento estratégico, metas e indicadores de gestão. Posso te enviar um diagnóstico rápido sobre maturidade em gestão estratégica?",
            (string)$row['full_name'],
            (string)$row['current_company_name'],
            (string)$row['current_position']
        );

        $ins->execute([
            $title,
            $description,
            $priority,
            1 + ($idx % 10),
            (int)$row['id_contact'],
            $row['current_account_id'] ? (int)$row['current_account_id'] : null,
            $campaignId,
        ]);
        $created += $ins->rowCount();
    }
    return $created;
}

function crm_pilot_insert_opportunities(PDO $pdo, int $stageId, array $sample): int
{
    $exists = $pdo->prepare("
        SELECT id_opportunity
          FROM crm_opportunities
         WHERE id_primary_contact = ?
           AND title = ?
         LIMIT 1
    ");
    $ins = $pdo->prepare("
        INSERT INTO crm_opportunities
          (id_account, id_primary_contact, id_stage, title, description, estimated_value, probability, source_type, status)
        VALUES
          (?, ?, ?, ?, ?, 0.00, 10, 'linkedin', 'open')
    ");

    $created = 0;
    foreach ($sample as $row) {
        $title = 'Diagnóstico de Planejamento Estratégico - ' . (string)$row['full_name'];
        $exists->execute([(int)$row['id_contact'], $title]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $description = 'Oportunidade piloto criada para testar abordagem consultiva de diagnóstico de maturidade em planejamento estratégico.';
        $ins->execute([
            $row['current_account_id'] ? (int)$row['current_account_id'] : null,
            (int)$row['id_contact'],
            $stageId,
            $title,
            $description,
        ]);
        $created += $ins->rowCount();
    }
    return $created;
}

$pdo = crm_db();

crm_pilot_upsert_setting(
    $pdo,
    'crm_primary_offer',
    'Diagnóstico de Maturidade em Planejamento Estratégico',
    'string',
    'Oferta inicial usada para ativação comercial da base LinkedIn.'
);
crm_pilot_upsert_setting(
    $pdo,
    'crm_secondary_offers',
    crm_pilot_json([
        'Workshop Executivo de OKRs e Indicadores',
        'Implantação de Planejamento Estratégico + OKRs',
        'Painel de Gestão Estratégica com BI',
    ]),
    'json',
    'Ofertas secundárias para evolução após diagnóstico.'
);
crm_pilot_upsert_setting(
    $pdo,
    'crm_linkedin_pilot_cadence',
    crm_pilot_json([
        ['day' => 1, 'action' => 'mensagem inicial contextual'],
        ['day' => 3, 'action' => 'follow-up com dor de gestão estratégica'],
        ['day' => 7, 'action' => 'convite para conversa de 30 minutos'],
        ['day' => 14, 'action' => 'nutrição com insight ou conteúdo'],
    ]),
    'json',
    'Cadência recomendada para campanha piloto LinkedIn.'
);

$icpRows = $pdo->query("
    SELECT c.id_contact AS id, c.lead_score AS score
      FROM crm_contacts c
     WHERE c.contact_status NOT IN ('do_not_contact', 'not_fit', 'converted')
       AND (
            c.seniority IN ('owner','c_level','director','head','manager')
         OR c.department IN ('strategy','finance','operations','it_data','commercial_marketing')
       )
")->fetchAll();

$relationshipRows = $pdo->query("
    SELECT DISTINCT c.id_contact AS id, c.lead_score AS score
      FROM crm_contacts c
      JOIN crm_conversations cv ON cv.id_contact = c.id_contact
     WHERE c.contact_status NOT IN ('do_not_contact', 'not_fit', 'converted')
")->fetchAll();

$multiAccountRows = $pdo->query("
    SELECT a.id_account AS id, a.icp_fit_score AS score
      FROM crm_accounts a
      JOIN crm_contacts c ON c.current_account_id = a.id_account
     GROUP BY a.id_account, a.icp_fit_score
    HAVING COUNT(c.id_contact) >= 2
")->fetchAll();

$icpSegmentId = crm_pilot_segment(
    $pdo,
    'ICP - Decisores e áreas com fit estratégico',
    'contact',
    'Contatos com senioridade decisora ou departamentos aderentes a planejamento, indicadores, BI e gestão.',
    ['seniority' => ['owner','c_level','director','head','manager'], 'department' => ['strategy','finance','operations','it_data','commercial_marketing']],
    true
);
$relationshipSegmentId = crm_pilot_segment(
    $pdo,
    'Relacionamento LinkedIn - histórico de mensagens',
    'contact',
    'Contatos com conversa importada do LinkedIn, priorizados como relacionamento mais quente.',
    ['has_conversation' => true],
    true
);
$multiAccountSegmentId = crm_pilot_segment(
    $pdo,
    'Empresas com múltiplos contatos',
    'account',
    'Contas com dois ou mais contatos na base, boas candidatas para estratégia por conta.',
    ['min_contacts' => 2],
    true
);

$icpInserted = crm_pilot_replace_members($pdo, $icpSegmentId, 'contact', $icpRows);
$relationshipInserted = crm_pilot_replace_members($pdo, $relationshipSegmentId, 'contact', $relationshipRows);
$multiAccountInserted = crm_pilot_replace_members($pdo, $multiAccountSegmentId, 'account', $multiAccountRows);

$sample = crm_pilot_select_sample($pdo, $sampleSize);
$sampleMembers = array_map(static fn(array $row): array => [
    'id' => (int)$row['id_contact'],
    'score' => (float)$row['pilot_score'],
], $sample);

$pilotSegmentId = crm_pilot_segment(
    $pdo,
    'Piloto 80 leads - Diagnóstico Estratégico',
    'contact',
    'Amostra inicial priorizada para validar abordagem do Diagnóstico de Maturidade em Planejamento Estratégico.',
    ['sample_size' => $sampleSize, 'selection' => 'pilot_score_desc_with_company_diversity'],
    false
);
$pilotInserted = crm_pilot_replace_members($pdo, $pilotSegmentId, 'contact', $sampleMembers);

$campaignId = crm_pilot_campaign($pdo, $pilotSegmentId);
$campaignMembers = crm_pilot_insert_campaign_members($pdo, $campaignId, $sample);
$newStageId = crm_pilot_stage_id($pdo, 'new');
$tasksCreated = crm_pilot_insert_tasks($pdo, $campaignId, $sample);
$opportunitiesCreated = crm_pilot_insert_opportunities($pdo, $newStageId, $sample);

$updStatus = $pdo->prepare("
    UPDATE crm_contacts
       SET contact_status = 'qualified',
           next_action_at = COALESCE(next_action_at, DATE_ADD(NOW(), INTERVAL 1 DAY))
     WHERE id_contact = ?
       AND contact_status IN ('new', 'to_research')
");
$qualified = 0;
foreach ($sample as $row) {
    $updStatus->execute([(int)$row['id_contact']]);
    $qualified += $updStatus->rowCount();
}

$summary = [
    'database' => CRM_DB_NAME,
    'offer' => 'Diagnóstico de Maturidade em Planejamento Estratégico',
    'sample_size' => count($sample),
    'segments' => [
        'icp_contacts' => $icpInserted,
        'relationship_contacts' => $relationshipInserted,
        'multi_contact_accounts' => $multiAccountInserted,
        'pilot_contacts' => $pilotInserted,
    ],
    'campaign_id' => $campaignId,
    'campaign_members_affected' => $campaignMembers,
    'tasks_created' => $tasksCreated,
    'opportunities_created' => $opportunitiesCreated,
    'contacts_qualified' => $qualified,
    'top_sample' => array_map(static fn(array $row): array => [
        'contact' => $row['full_name'],
        'company' => $row['current_company_name'],
        'seniority' => $row['seniority'],
        'department' => $row['department'],
        'pilot_score' => $row['pilot_score'],
    ], array_slice($sample, 0, 10)),
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
