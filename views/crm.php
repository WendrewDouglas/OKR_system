<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../auth/functions.php';
require_once __DIR__ . '/../auth/acl.php';
require_once __DIR__ . '/../auth/crm_db.php';

if (!isset($_SESSION['user_id'])) {
  header('Location: /OKR_system/views/login.php');
  exit;
}

$views = [
  'overview'   => ['title' => 'Visão Geral', 'icon' => 'fa-chart-line', 'subtitle' => 'Leitura executiva da base LinkedIn e prioridades de prospecção.'],
  'leads'      => ['title' => 'Leads', 'icon' => 'fa-filter-circle-dollar', 'subtitle' => 'Pessoas priorizadas por score, senioridade e aderência à consultoria.'],
  'companies'  => ['title' => 'Empresas', 'icon' => 'fa-building', 'subtitle' => 'Contas identificadas a partir de conexões, cargos e histórico do LinkedIn.'],
  'contacts'   => ['title' => 'Contatos', 'icon' => 'fa-address-book', 'subtitle' => 'Base de relacionamento com canais disponíveis e contexto profissional.'],
  'pipeline'   => ['title' => 'Funil', 'icon' => 'fa-diagram-project', 'subtitle' => 'Etapas comerciais para transformar relacionamento em oportunidade.'],
  'activities' => ['title' => 'Atividades', 'icon' => 'fa-list-check', 'subtitle' => 'Registro futuro de abordagens, reuniões, follow-ups e próximos passos.'],
  'segments'   => ['title' => 'Segmentos', 'icon' => 'fa-layer-group', 'subtitle' => 'Agrupamentos para campanhas e listas de abordagem.'],
  'campaigns'  => ['title' => 'Campanhas', 'icon' => 'fa-bullhorn', 'subtitle' => 'Sequências de prospecção e acompanhamento comercial.'],
  'imports'    => ['title' => 'Importações', 'icon' => 'fa-file-import', 'subtitle' => 'Auditoria dos arquivos LinkedIn importados para o CRM.'],
  'settings'   => ['title' => 'Configurações', 'icon' => 'fa-sliders', 'subtitle' => 'Parâmetros atuais do módulo CRM.'],
];

$view = (string)($_GET['view'] ?? 'overview');
if (!isset($views[$view])) {
  $view = 'overview';
}

function crm_h(?string $value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function crm_int(PDO $pdo, string $sql, array $params = []): int {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (int)$st->fetchColumn();
}

function crm_rows(PDO $pdo, string $sql, array $params = []): array {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}

function crm_count_table(PDO $pdo, string $table): int {
  static $allowed = [
    'crm_accounts' => true, 'crm_contacts' => true, 'crm_contact_channels' => true,
    'crm_conversations' => true, 'crm_messages' => true, 'crm_import_batches' => true,
    'crm_opportunities' => true, 'crm_activities' => true, 'crm_campaigns' => true,
    'crm_segments' => true, 'crm_tasks' => true,
  ];
  if (!isset($allowed[$table])) return 0;
  return (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

function crm_number(int|float $value): string {
  return number_format((float)$value, 0, ',', '.');
}

function crm_money(float $value): string {
  return 'R$ ' . number_format($value, 2, ',', '.');
}

function crm_label(string $type, ?string $value): string {
  $value = (string)$value;
  $maps = [
    'status' => [
      'new' => 'Novo', 'to_research' => 'Pesquisar', 'qualified' => 'Qualificado',
      'approached' => 'Abordado', 'responded' => 'Respondeu', 'meeting_scheduled' => 'Reunião',
      'opportunity' => 'Oportunidade', 'nurturing' => 'Nutrição', 'not_fit' => 'Sem fit',
      'do_not_contact' => 'Não contatar', 'converted' => 'Convertido',
    ],
    'seniority' => [
      'owner' => 'Sócio/Proprietário', 'c_level' => 'C-Level', 'director' => 'Diretor',
      'head' => 'Head', 'manager' => 'Gerente', 'coordinator' => 'Coordenador',
      'specialist' => 'Especialista', 'analyst' => 'Analista', 'assistant' => 'Assistente',
      'student' => 'Estudante', 'unknown' => 'Não classificado',
    ],
    'department' => [
      'executive' => 'Executivo', 'strategy' => 'Estratégia', 'it_data' => 'TI/Dados',
      'finance' => 'Finanças', 'operations' => 'Operações',
      'commercial_marketing' => 'Comercial/Marketing', 'hr' => 'RH', 'legal' => 'Jurídico',
      'procurement' => 'Compras', 'education' => 'Educação', 'health' => 'Saúde',
      'other' => 'Outros', 'unknown' => 'Não classificado',
    ],
    'account_status' => [
      'new' => 'Nova', 'target' => 'Alvo', 'researching' => 'Pesquisa',
      'qualified' => 'Qualificada', 'disqualified' => 'Descartada', 'customer' => 'Cliente',
      'partner' => 'Parceira', 'inactive' => 'Inativa',
    ],
    'priority' => ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta', 'critical' => 'Crítica'],
  ];
  return $maps[$type][$value] ?? ($value !== '' ? $value : '-');
}

$dbError = null;
$metrics = [];
$topLeads = $recentImports = $statusRows = $seniorityRows = $departmentRows = [];
$leadRows = $companyRows = $contactRows = $pipelineRows = $activityRows = [];
$taskRows = [];
$segmentRows = $campaignRows = $importRows = $settingsRows = [];

try {
  $crm = crm_db();
  $metrics = [
    'contacts' => crm_count_table($crm, 'crm_contacts'),
    'accounts' => crm_count_table($crm, 'crm_accounts'),
    'channels' => crm_count_table($crm, 'crm_contact_channels'),
    'conversations' => crm_count_table($crm, 'crm_conversations'),
    'messages' => crm_count_table($crm, 'crm_messages'),
    'imports' => crm_count_table($crm, 'crm_import_batches'),
    'opportunities' => crm_count_table($crm, 'crm_opportunities'),
    'activities' => crm_count_table($crm, 'crm_activities'),
  ];
  $metrics['email_channels'] = crm_int($crm, "SELECT COUNT(*) FROM crm_contact_channels WHERE channel_type = 'email'");
  $metrics['linkedin_channels'] = crm_int($crm, "SELECT COUNT(*) FROM crm_contact_channels WHERE channel_type = 'linkedin'");
  $metrics['qualified_pool'] = crm_int($crm, "
    SELECT COUNT(*) FROM crm_contacts
     WHERE seniority IN ('owner','c_level','director','head','manager')
        OR department IN ('executive','strategy','it_data','finance','operations')
  ");

  $topLeads = crm_rows($crm, "
    SELECT c.id_contact, c.full_name, c.current_company_name, c.current_position,
           c.seniority, c.department, c.contact_status, c.lead_score, c.connected_on,
           EXISTS(
             SELECT 1 FROM crm_contact_channels ch
              WHERE ch.id_contact = c.id_contact AND ch.channel_type = 'email'
              LIMIT 1
           ) AS has_email
      FROM crm_contacts c
     ORDER BY c.lead_score DESC, c.connected_on DESC, c.id_contact DESC
     LIMIT 12
  ");
  $recentImports = crm_rows($crm, "
    SELECT source_name, original_filename, status, total_rows, processed_rows, finished_at, created_at
      FROM crm_import_batches
     ORDER BY created_at DESC
     LIMIT 8
  ");
  $statusRows = crm_rows($crm, "
    SELECT contact_status AS label, COUNT(*) AS total
      FROM crm_contacts
     GROUP BY contact_status
     ORDER BY total DESC
  ");
  $seniorityRows = crm_rows($crm, "
    SELECT seniority AS label, COUNT(*) AS total
      FROM crm_contacts
     GROUP BY seniority
     ORDER BY total DESC
     LIMIT 8
  ");
  $departmentRows = crm_rows($crm, "
    SELECT department AS label, COUNT(*) AS total
      FROM crm_contacts
     GROUP BY department
     ORDER BY total DESC
     LIMIT 8
  ");

  if ($view === 'leads') {
    $q = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $where = [];
    $params = [];
    if ($q !== '') {
      $where[] = "(c.full_name LIKE :q OR c.current_company_name LIKE :q OR c.current_position LIKE :q)";
      $params[':q'] = '%' . $q . '%';
    }
    if ($status !== '') {
      $where[] = "c.contact_status = :status";
      $params[':status'] = $status;
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $leadRows = crm_rows($crm, "
      SELECT c.id_contact, c.full_name, c.current_company_name, c.current_position,
             c.seniority, c.department, c.contact_status, c.lead_score, c.connected_on,
             EXISTS(
               SELECT 1 FROM crm_contact_channels ch
                WHERE ch.id_contact = c.id_contact AND ch.channel_type = 'email'
                LIMIT 1
             ) AS has_email
        FROM crm_contacts c
        {$sqlWhere}
       ORDER BY c.lead_score DESC, c.connected_on DESC, c.id_contact DESC
       LIMIT 120
    ", $params);
  }

  if ($view === 'companies') {
    $companyRows = crm_rows($crm, "
      SELECT a.id_account, a.account_name, a.account_status, a.priority, a.icp_fit_score,
             a.industry, a.city, a.state, a.domain,
             COUNT(c.id_contact) AS contacts_count
        FROM crm_accounts a
        LEFT JOIN crm_contacts c ON c.current_account_id = a.id_account
       GROUP BY a.id_account, a.account_name, a.account_status, a.priority, a.icp_fit_score,
                a.industry, a.city, a.state, a.domain
       ORDER BY a.icp_fit_score DESC, contacts_count DESC, a.account_name ASC
       LIMIT 120
    ");
  }

  if ($view === 'contacts') {
    $contactRows = crm_rows($crm, "
      SELECT c.id_contact, c.full_name, c.current_company_name, c.current_position,
             c.seniority, c.department, c.contact_status, c.relationship_strength,
             c.last_interaction_at, c.next_action_at, COUNT(ch.id_channel) AS channels_count
        FROM crm_contacts c
        LEFT JOIN crm_contact_channels ch ON ch.id_contact = c.id_contact
       GROUP BY c.id_contact, c.full_name, c.current_company_name, c.current_position,
                c.seniority, c.department, c.contact_status, c.relationship_strength,
                c.last_interaction_at, c.next_action_at
       ORDER BY c.updated_at DESC, c.id_contact DESC
       LIMIT 120
    ");
  }

  if ($view === 'pipeline') {
    $pipelineRows = crm_rows($crm, "
      SELECT s.stage_name, s.stage_key, s.sort_order, s.is_won, s.is_lost,
             COUNT(o.id_opportunity) AS total,
             COALESCE(SUM(o.estimated_value), 0) AS value_total
        FROM crm_pipeline_stages s
        LEFT JOIN crm_opportunities o ON o.id_stage = s.id_stage AND o.status = 'open'
       WHERE s.is_active = 1
       GROUP BY s.id_stage, s.stage_name, s.stage_key, s.sort_order, s.is_won, s.is_lost
       ORDER BY s.sort_order ASC
    ");
  }

  if ($view === 'activities') {
    $activityRows = crm_rows($crm, "
      SELECT a.activity_type, a.direction, a.subject, a.activity_at, a.due_at, a.completed_at,
             c.full_name, ac.account_name
        FROM crm_activities a
        LEFT JOIN crm_contacts c ON c.id_contact = a.id_contact
        LEFT JOIN crm_accounts ac ON ac.id_account = a.id_account
       ORDER BY COALESCE(a.due_at, a.activity_at) DESC
       LIMIT 120
    ");
    $taskRows = crm_rows($crm, "
      SELECT t.title, t.status, t.priority, t.due_at, t.completed_at,
             c.full_name, ac.account_name, ca.campaign_name
        FROM crm_tasks t
        LEFT JOIN crm_contacts c ON c.id_contact = t.id_contact
        LEFT JOIN crm_accounts ac ON ac.id_account = t.id_account
        LEFT JOIN crm_campaigns ca ON ca.id_campaign = t.id_campaign
       ORDER BY
             CASE t.status
               WHEN 'open' THEN 1
               WHEN 'in_progress' THEN 2
               WHEN 'done' THEN 3
               ELSE 4
             END,
             COALESCE(t.due_at, t.created_at) ASC
       LIMIT 120
    ");
  }

  if ($view === 'segments') {
    $segmentRows = crm_rows($crm, "
      SELECT s.segment_name, s.entity_type, s.description, s.is_dynamic, s.created_at,
             COUNT(sm.id_segment_member) AS members_count
        FROM crm_segments s
        LEFT JOIN crm_segment_members sm ON sm.id_segment = s.id_segment
       GROUP BY s.id_segment, s.segment_name, s.entity_type, s.description, s.is_dynamic, s.created_at
       ORDER BY s.created_at DESC
       LIMIT 120
    ");
  }

  if ($view === 'campaigns') {
    $campaignRows = crm_rows($crm, "
      SELECT c.campaign_name, c.campaign_type, c.objective, c.status, c.start_at, c.end_at,
             s.segment_name, COUNT(cm.id_campaign_member) AS members_count
        FROM crm_campaigns c
        LEFT JOIN crm_segments s ON s.id_segment = c.id_segment
        LEFT JOIN crm_campaign_members cm ON cm.id_campaign = c.id_campaign
       GROUP BY c.id_campaign, c.campaign_name, c.campaign_type, c.objective, c.status,
                c.start_at, c.end_at, s.segment_name
       ORDER BY c.created_at DESC
       LIMIT 120
    ");
  }

  if ($view === 'imports') {
    $importRows = crm_rows($crm, "
      SELECT id_import_batch, source_type, source_name, original_filename, status,
             total_rows, processed_rows, inserted_rows, updated_rows, skipped_rows,
             error_rows, started_at, finished_at, created_at
        FROM crm_import_batches
       ORDER BY created_at DESC, id_import_batch DESC
       LIMIT 160
    ");
  }

  if ($view === 'settings') {
    $settingsRows = crm_rows($crm, "
      SELECT setting_key, setting_value, value_type, description, updated_at, created_at
        FROM crm_settings
       ORDER BY setting_key ASC
    ");
  }
} catch (Throwable $e) {
  $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CRM - OKR System</title>

<link rel="stylesheet" href="/OKR_system/assets/css/base.css">
<link rel="stylesheet" href="/OKR_system/assets/css/components.css">
<link rel="stylesheet" href="/OKR_system/assets/css/layout.css">
<link rel="stylesheet" href="/OKR_system/assets/css/theme.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous"/>

<style>
body {
  background: #0f141b;
}
.content {
  background: #0f141b;
  min-height: 100vh;
}
.crm-main {
  padding: 1.5rem;
  margin-right: var(--chat-w, 0);
  color: #eef4f8;
  background:
    linear-gradient(180deg, rgba(20,184,166,.08), rgba(15,20,27,0) 220px),
    #0f141b;
  min-height: calc(100vh - 60px);
}
.crm-shell {
  max-width: 1440px;
  margin: 0 auto;
}
.crm-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}
.crm-title-wrap {
  display: flex;
  align-items: center;
  gap: .8rem;
}
.crm-title-icon {
  width: 42px;
  height: 42px;
  display: grid;
  place-items: center;
  border: 1px solid rgba(20,184,166,.36);
  border-radius: 8px;
  background: rgba(20,184,166,.12);
  color: #5eead4;
}
.crm-title {
  margin: 0;
  font-size: 1.45rem;
  font-weight: 850;
  color: #f8fafc;
}
.crm-subtitle {
  margin: .15rem 0 0;
  color: #aeb8c6;
  font-size: .86rem;
}
.crm-badge {
  display: inline-flex;
  align-items: center;
  gap: .48rem;
  padding: .54rem .78rem;
  border: 1px solid rgba(255,255,255,.2);
  border-radius: 8px;
  background: #0a66c2;
  color: #ffffff;
  box-shadow: 0 10px 24px rgba(10,102,194,.28);
  font-size: .78rem;
  font-weight: 850;
  white-space: nowrap;
}
.crm-badge i { font-size: .95rem; }
.crm-nav {
  display: flex;
  gap: .45rem;
  overflow-x: auto;
  padding-bottom: .35rem;
  margin-bottom: 1rem;
}
.crm-nav a {
  display: inline-flex;
  align-items: center;
  gap: .42rem;
  min-height: 34px;
  padding: .45rem .65rem;
  border: 1px solid var(--border, #222733);
  border-radius: 8px;
  color: var(--muted, #a6adbb);
  background: rgba(255,255,255,.025);
  text-decoration: none;
  font-size: .78rem;
  font-weight: 750;
  white-space: nowrap;
}
.crm-nav a.active {
  border-color: rgba(20,184,166,.55);
  color: #06201d;
  background: #14b8a6;
}
.crm-kpis {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: .75rem;
  margin-bottom: 1rem;
}
.crm-kpi, .crm-panel {
  border: 1px solid var(--border, #222733);
  border-radius: 8px;
  background: var(--card, #1a1f2b);
  box-shadow: var(--shadow, 0 10px 30px rgba(0,0,0,.18));
}
.crm-kpi {
  padding: .9rem;
}
.crm-kpi-label {
  display: flex;
  align-items: center;
  gap: .45rem;
  color: var(--muted, #a6adbb);
  font-size: .72rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0;
}
.crm-kpi-value {
  margin-top: .35rem;
  color: var(--text, #eaeef6);
  font-size: 1.45rem;
  font-weight: 900;
}
.crm-grid {
  display: grid;
  grid-template-columns: 1.35fr .85fr;
  gap: .9rem;
}
.crm-grid .crm-panel:first-child,
.crm-grid .crm-panel:nth-child(4) {
  grid-column: 1 / -1;
}
.crm-panel {
  overflow: hidden;
}
.crm-panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .75rem;
  padding: .85rem 1rem;
  border-bottom: 1px solid var(--border, #222733);
}
.crm-panel-title {
  display: flex;
  align-items: center;
  gap: .45rem;
  font-size: .9rem;
  font-weight: 850;
  color: #eef4f8;
}
.crm-panel-title i { color: #5eead4; }
.crm-panel-body { padding: 1rem; }
.crm-table-wrap {
  width: 100%;
  overflow-x: visible;
}
.crm-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
}
.crm-table th, .crm-table td {
  padding: .48rem .5rem;
  border-bottom: 1px solid rgba(255,255,255,.07);
  text-align: left;
  vertical-align: top;
  font-size: .72rem;
  line-height: 1.28;
  overflow: hidden;
  text-overflow: ellipsis;
  word-break: break-word;
}
.crm-table th {
  color: #94a3b8;
  font-size: .62rem;
  text-transform: uppercase;
  letter-spacing: 0;
  font-weight: 850;
}
.crm-table td {
  color: #e5edf4;
}
.crm-table strong {
  font-size: .74rem;
  line-height: 1.22;
}
.crm-muted {
  color: #9aa7b6;
  font-size: .66rem;
  line-height: 1.22;
  margin-top: .08rem;
}
.crm-pill {
  display: inline-flex;
  align-items: center;
  min-height: 19px;
  padding: .16rem .34rem;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.045);
  color: #e5edf4;
  font-size: .64rem;
  font-weight: 750;
  line-height: 1.12;
  white-space: normal;
  max-width: 100%;
  margin: 0 .12rem .12rem 0;
}
.crm-pill.teal { border-color: rgba(20,184,166,.35); color: #99f6e4; background: rgba(20,184,166,.09); }
.crm-pill.gold { border-color: rgba(241,196,15,.35); color: #fde68a; background: rgba(241,196,15,.09); }
.crm-score {
  font-weight: 900;
  color: #99f6e4;
  font-size: .76rem;
}
.crm-bars {
  display: grid;
  gap: .65rem;
}
.crm-bar-row {
  display: grid;
  grid-template-columns: minmax(120px, .9fr) 1fr auto;
  align-items: center;
  gap: .6rem;
}
.crm-bar-label {
  color: var(--text, #eaeef6);
  font-size: .78rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.crm-bar-track {
  height: 8px;
  border-radius: 999px;
  background: rgba(255,255,255,.08);
  overflow: hidden;
}
.crm-bar-fill {
  height: 100%;
  border-radius: inherit;
  background: #14b8a6;
}
.crm-bar-total {
  color: var(--muted, #a6adbb);
  font-size: .74rem;
  font-weight: 800;
}
.crm-filter {
  display: flex;
  flex-wrap: wrap;
  gap: .55rem;
  margin-bottom: 1rem;
}
.crm-input, .crm-select {
  min-height: 36px;
  border: 1px solid var(--border, #222733);
  border-radius: 8px;
  background: #0e131a;
  color: var(--text, #eaeef6);
  padding: .45rem .6rem;
  font-size: .82rem;
}
.crm-input { min-width: min(360px, 100%); }
.crm-btn {
  min-height: 36px;
  display: inline-flex;
  align-items: center;
  gap: .42rem;
  border: 1px solid rgba(20,184,166,.55);
  border-radius: 8px;
  background: #14b8a6;
  color: #06201d;
  padding: .45rem .72rem;
  font-size: .8rem;
  font-weight: 850;
  cursor: pointer;
  text-decoration: none;
}
.crm-empty {
  padding: 1.5rem;
  color: var(--muted, #a6adbb);
  text-align: center;
  border: 1px dashed rgba(255,255,255,.14);
  border-radius: 8px;
  background: rgba(255,255,255,.025);
}
.crm-error {
  padding: 1rem;
  border: 1px solid rgba(239,68,68,.35);
  border-radius: 8px;
  background: rgba(239,68,68,.08);
  color: #fecaca;
}
.crm-stage-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: .75rem;
}
.crm-stage {
  min-height: 110px;
  border: 1px solid rgba(255,255,255,.09);
  border-radius: 8px;
  padding: .8rem;
  background: rgba(255,255,255,.025);
}
.crm-stage-name {
  font-weight: 850;
  font-size: .85rem;
}
.crm-stage-meta {
  display: flex;
  justify-content: space-between;
  gap: .5rem;
  margin-top: .7rem;
  color: var(--muted, #a6adbb);
  font-size: .78rem;
}
@media (max-width: 1100px) {
  .crm-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .crm-grid, .crm-stage-grid { grid-template-columns: 1fr; }
}
@media (max-width: 720px) {
  .crm-main { padding: 1rem; }
  .crm-head { flex-direction: column; }
  .crm-kpis { grid-template-columns: 1fr; }
  .crm-title { font-size: 1.2rem; }
}
</style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<div class="content">
  <?php include __DIR__ . '/partials/header.php'; ?>

  <main class="crm-main">
    <div class="crm-shell">
      <div class="crm-head">
        <div class="crm-title-wrap">
          <div class="crm-title-icon"><i class="fa-solid <?= crm_h($views[$view]['icon']) ?>"></i></div>
          <div>
            <h1 class="crm-title">CRM Comercial · <?= crm_h($views[$view]['title']) ?></h1>
            <p class="crm-subtitle"><?= crm_h($views[$view]['subtitle']) ?></p>
          </div>
        </div>
        <span class="crm-badge"><i class="fa-brands fa-linkedin"></i> Base LinkedIn importada</span>
      </div>

      <nav class="crm-nav" aria-label="Navegação CRM">
        <?php foreach ($views as $key => $item): ?>
          <a class="<?= $view === $key ? 'active' : '' ?>" href="/OKR_system/views/crm.php<?= $key === 'overview' ? '' : '?view=' . urlencode($key) ?>">
            <i class="fa-solid <?= crm_h($item['icon']) ?>"></i><?= crm_h($item['title']) ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <?php if ($dbError): ?>
        <div class="crm-error">
          <strong>Falha ao conectar no CRM.</strong>
          <div class="crm-muted"><?= crm_h($dbError) ?></div>
        </div>
      <?php else: ?>

      <section class="crm-kpis" aria-label="Indicadores CRM">
        <div class="crm-kpi">
          <div class="crm-kpi-label"><i class="fa-solid fa-address-book"></i> Contatos</div>
          <div class="crm-kpi-value"><?= crm_number($metrics['contacts'] ?? 0) ?></div>
        </div>
        <div class="crm-kpi">
          <div class="crm-kpi-label"><i class="fa-solid fa-building"></i> Empresas</div>
          <div class="crm-kpi-value"><?= crm_number($metrics['accounts'] ?? 0) ?></div>
        </div>
        <div class="crm-kpi">
          <div class="crm-kpi-label"><i class="fa-solid fa-bullseye"></i> Pool ICP</div>
          <div class="crm-kpi-value"><?= crm_number($metrics['qualified_pool'] ?? 0) ?></div>
        </div>
        <div class="crm-kpi">
          <div class="crm-kpi-label"><i class="fa-solid fa-envelope"></i> E-mails</div>
          <div class="crm-kpi-value"><?= crm_number($metrics['email_channels'] ?? 0) ?></div>
        </div>
      </section>

      <?php if ($view === 'overview'): ?>
        <div class="crm-grid">
          <section class="crm-panel">
            <div class="crm-panel-head">
              <div class="crm-panel-title"><i class="fa-solid fa-ranking-star"></i> Leads Prioritários</div>
              <a class="crm-btn" href="/OKR_system/views/crm.php?view=leads"><i class="fa-solid fa-arrow-right"></i> Ver leads</a>
            </div>
            <div class="crm-table-wrap">
              <table class="crm-table">
                <thead><tr><th>Contato</th><th>Empresa</th><th>Perfil</th><th>Status</th><th>Score</th></tr></thead>
                <tbody>
                <?php foreach ($topLeads as $row): ?>
                  <tr>
                    <td>
                      <strong><?= crm_h($row['full_name']) ?></strong>
                      <div class="crm-muted"><?= crm_h($row['current_position']) ?></div>
                    </td>
                    <td><?= crm_h($row['current_company_name'] ?: '-') ?></td>
                    <td>
                      <span class="crm-pill teal"><?= crm_h(crm_label('seniority', $row['seniority'])) ?></span>
                      <span class="crm-pill"><?= crm_h(crm_label('department', $row['department'])) ?></span>
                    </td>
                    <td><span class="crm-pill gold"><?= crm_h(crm_label('status', $row['contact_status'])) ?></span></td>
                    <td><span class="crm-score"><?= crm_h((string)$row['lead_score']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>

          <section class="crm-panel">
            <div class="crm-panel-head">
              <div class="crm-panel-title"><i class="fa-solid fa-chart-simple"></i> Senioridade</div>
            </div>
            <div class="crm-panel-body">
              <div class="crm-bars">
                <?php $maxSeniority = max(1, ...array_map(fn($r) => (int)$r['total'], $seniorityRows)); ?>
                <?php foreach ($seniorityRows as $row): ?>
                  <div class="crm-bar-row">
                    <div class="crm-bar-label"><?= crm_h(crm_label('seniority', $row['label'])) ?></div>
                    <div class="crm-bar-track"><div class="crm-bar-fill" style="width:<?= max(4, ((int)$row['total'] / $maxSeniority) * 100) ?>%"></div></div>
                    <div class="crm-bar-total"><?= crm_number((int)$row['total']) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </section>

          <section class="crm-panel">
            <div class="crm-panel-head">
              <div class="crm-panel-title"><i class="fa-solid fa-sitemap"></i> Departamentos</div>
            </div>
            <div class="crm-panel-body">
              <div class="crm-bars">
                <?php $maxDepartment = max(1, ...array_map(fn($r) => (int)$r['total'], $departmentRows)); ?>
                <?php foreach ($departmentRows as $row): ?>
                  <div class="crm-bar-row">
                    <div class="crm-bar-label"><?= crm_h(crm_label('department', $row['label'])) ?></div>
                    <div class="crm-bar-track"><div class="crm-bar-fill" style="width:<?= max(4, ((int)$row['total'] / $maxDepartment) * 100) ?>%"></div></div>
                    <div class="crm-bar-total"><?= crm_number((int)$row['total']) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </section>

          <section class="crm-panel">
            <div class="crm-panel-head">
              <div class="crm-panel-title"><i class="fa-solid fa-clock-rotate-left"></i> Importações Recentes</div>
            </div>
            <div class="crm-table-wrap">
              <table class="crm-table">
                <thead><tr><th>Arquivo</th><th>Status</th><th>Linhas</th><th>Data</th></tr></thead>
                <tbody>
                <?php foreach ($recentImports as $row): ?>
                  <tr>
                    <td><?= crm_h($row['original_filename'] ?: $row['source_name']) ?></td>
                    <td><span class="crm-pill teal"><?= crm_h($row['status']) ?></span></td>
                    <td><?= crm_number((int)$row['processed_rows']) ?> / <?= crm_number((int)$row['total_rows']) ?></td>
                    <td><?= crm_h($row['finished_at'] ?: $row['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      <?php endif; ?>

      <?php if ($view === 'leads'): ?>
        <section class="crm-panel">
          <div class="crm-panel-head">
            <div class="crm-panel-title"><i class="fa-solid fa-filter-circle-dollar"></i> Leads para Prospecção</div>
          </div>
          <div class="crm-panel-body">
            <form class="crm-filter" method="get">
              <input type="hidden" name="view" value="leads">
              <input class="crm-input" type="search" name="q" value="<?= crm_h((string)($_GET['q'] ?? '')) ?>" placeholder="Buscar por nome, empresa ou cargo">
              <select class="crm-select" name="status">
                <option value="">Todos os status</option>
                <?php foreach (['new','to_research','qualified','approached','responded','meeting_scheduled','opportunity','nurturing'] as $status): ?>
                  <option value="<?= crm_h($status) ?>" <?= (($_GET['status'] ?? '') === $status) ? 'selected' : '' ?>><?= crm_h(crm_label('status', $status)) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="crm-btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
            </form>
          </div>
          <div class="crm-table-wrap">
            <table class="crm-table">
              <thead><tr><th>Lead</th><th>Empresa</th><th>Classificação</th><th>Status</th><th>Canais</th><th>Score</th></tr></thead>
              <tbody>
              <?php foreach ($leadRows as $row): ?>
                <tr>
                  <td><strong><?= crm_h($row['full_name']) ?></strong><div class="crm-muted"><?= crm_h($row['current_position']) ?></div></td>
                  <td><?= crm_h($row['current_company_name'] ?: '-') ?></td>
                  <td><span class="crm-pill teal"><?= crm_h(crm_label('seniority', $row['seniority'])) ?></span> <span class="crm-pill"><?= crm_h(crm_label('department', $row['department'])) ?></span></td>
                  <td><span class="crm-pill gold"><?= crm_h(crm_label('status', $row['contact_status'])) ?></span></td>
                  <td><?= ((int)$row['has_email'] === 1) ? '<span class="crm-pill teal">LinkedIn + e-mail</span>' : '<span class="crm-pill">LinkedIn</span>' ?></td>
                  <td><span class="crm-score"><?= crm_h((string)$row['lead_score']) ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($view === 'companies'): ?>
        <section class="crm-panel">
          <div class="crm-panel-head"><div class="crm-panel-title"><i class="fa-solid fa-building"></i> Empresas</div></div>
          <div class="crm-table-wrap">
            <table class="crm-table">
              <thead><tr><th>Empresa</th><th>Status</th><th>Prioridade</th><th>Fit</th><th>Contatos</th><th>Local</th></tr></thead>
              <tbody>
              <?php foreach ($companyRows as $row): ?>
                <tr>
                  <td><strong><?= crm_h($row['account_name']) ?></strong><div class="crm-muted"><?= crm_h($row['domain'] ?: $row['industry'] ?: '-') ?></div></td>
                  <td><span class="crm-pill gold"><?= crm_h(crm_label('account_status', $row['account_status'])) ?></span></td>
                  <td><span class="crm-pill"><?= crm_h(crm_label('priority', $row['priority'])) ?></span></td>
                  <td><span class="crm-score"><?= crm_h((string)$row['icp_fit_score']) ?></span></td>
                  <td><?= crm_number((int)$row['contacts_count']) ?></td>
                  <td><?= crm_h(trim((string)$row['city'] . ' ' . (string)$row['state']) ?: '-') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($view === 'contacts'): ?>
        <section class="crm-panel">
          <div class="crm-panel-head"><div class="crm-panel-title"><i class="fa-solid fa-address-book"></i> Contatos</div></div>
          <div class="crm-table-wrap">
            <table class="crm-table">
              <thead><tr><th>Contato</th><th>Empresa</th><th>Perfil</th><th>Status</th><th>Canais</th><th>Próxima ação</th></tr></thead>
              <tbody>
              <?php foreach ($contactRows as $row): ?>
                <tr>
                  <td><strong><?= crm_h($row['full_name']) ?></strong><div class="crm-muted"><?= crm_h($row['current_position']) ?></div></td>
                  <td><?= crm_h($row['current_company_name'] ?: '-') ?></td>
                  <td><span class="crm-pill teal"><?= crm_h(crm_label('seniority', $row['seniority'])) ?></span> <span class="crm-pill"><?= crm_h(crm_label('department', $row['department'])) ?></span></td>
                  <td><span class="crm-pill gold"><?= crm_h(crm_label('status', $row['contact_status'])) ?></span></td>
                  <td><?= crm_number((int)$row['channels_count']) ?></td>
                  <td><?= crm_h($row['next_action_at'] ?: '-') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($view === 'pipeline'): ?>
        <section class="crm-panel">
          <div class="crm-panel-head"><div class="crm-panel-title"><i class="fa-solid fa-diagram-project"></i> Funil Comercial</div></div>
          <div class="crm-panel-body">
            <div class="crm-stage-grid">
              <?php foreach ($pipelineRows as $row): ?>
                <div class="crm-stage">
                  <div class="crm-stage-name"><?= crm_h($row['stage_name']) ?></div>
                  <div class="crm-muted"><?= crm_h($row['stage_key']) ?></div>
                  <div class="crm-stage-meta"><span><?= crm_number((int)$row['total']) ?> oportunidades</span><span><?= crm_money((float)$row['value_total']) ?></span></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($view === 'activities'): ?>
        <section class="crm-panel" style="margin-bottom:.9rem">
          <div class="crm-panel-head"><div class="crm-panel-title"><i class="fa-solid fa-list-check"></i> Tarefas do Funil</div></div>
          <?php if (!$taskRows): ?>
            <div class="crm-panel-body"><div class="crm-empty">Ainda não há tarefas abertas para campanhas ou oportunidades.</div></div>
          <?php else: ?>
            <div class="crm-table-wrap">
              <table class="crm-table">
                <thead><tr><th>Tarefa</th><th>Contato</th><th>Empresa</th><th>Campanha</th><th>Status</th><th>Prazo</th></tr></thead>
                <tbody>
                <?php foreach ($taskRows as $row): ?>
                  <tr>
                    <td><strong><?= crm_h($row['title']) ?></strong><div class="crm-muted"><?= crm_h(crm_label('priority', $row['priority'])) ?></div></td>
                    <td><?= crm_h($row['full_name'] ?: '-') ?></td>
                    <td><?= crm_h($row['account_name'] ?: '-') ?></td>
                    <td><?= crm_h($row['campaign_name'] ?: '-') ?></td>
                    <td><span class="crm-pill teal"><?= crm_h($row['status']) ?></span></td>
                    <td><?= crm_h($row['due_at'] ?: '-') ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <section class="crm-panel">
          <div class="crm-panel-head"><div class="crm-panel-title"><i class="fa-solid fa-clock-rotate-left"></i> Histórico de Atividades</div></div>
          <?php if (!$activityRows): ?>
            <div class="crm-panel-body"><div class="crm-empty">Ainda não há atividades comerciais registradas. A próxima etapa será criar ações de abordagem e follow-up.</div></div>
          <?php else: ?>
            <div class="crm-table-wrap">
              <table class="crm-table">
                <thead><tr><th>Tipo</th><th>Assunto</th><th>Contato</th><th>Empresa</th><th>Data</th><th>Prazo</th></tr></thead>
                <tbody>
                <?php foreach ($activityRows as $row): ?>
                  <tr>
                    <td><span class="crm-pill teal"><?= crm_h($row['activity_type']) ?></span></td>
                    <td><strong><?= crm_h($row['subject'] ?: '-') ?></strong><div class="crm-muted"><?= crm_h($row['direction']) ?></div></td>
                    <td><?= crm_h($row['full_name'] ?: '-') ?></td>
                    <td><?= crm_h($row['account_name'] ?: '-') ?></td>
                    <td><?= crm_h($row['activity_at']) ?></td>
                    <td><?= crm_h($row['due_at'] ?: '-') ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($view === 'segments'): ?>
        <section class="crm-panel">
          <div class="crm-panel-head"><div class="crm-panel-title"><i class="fa-solid fa-layer-group"></i> Segmentos</div></div>
          <?php if (!$segmentRows): ?>
            <div class="crm-panel-body"><div class="crm-empty">Ainda não há segmentos salvos. Vamos criar segmentos ICP depois de validar os critérios comerciais.</div></div>
          <?php else: ?>
            <div class="crm-table-wrap">
              <table class="crm-table">
                <thead><tr><th>Segmento</th><th>Tipo</th><th>Dinâmico</th><th>Membros</th><th>Descrição</th></tr></thead>
                <tbody>
                <?php foreach ($segmentRows as $row): ?>
                  <tr>
                    <td><strong><?= crm_h($row['segment_name']) ?></strong></td>
                    <td><span class="crm-pill teal"><?= crm_h($row['entity_type']) ?></span></td>
                    <td><?= ((int)$row['is_dynamic'] === 1) ? 'Sim' : 'Não' ?></td>
                    <td><?= crm_number((int)$row['members_count']) ?></td>
                    <td><?= crm_h($row['description'] ?: '-') ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($view === 'campaigns'): ?>
        <section class="crm-panel">
          <div class="crm-panel-head"><div class="crm-panel-title"><i class="fa-solid fa-bullhorn"></i> Campanhas</div></div>
          <?php if (!$campaignRows): ?>
            <div class="crm-panel-body"><div class="crm-empty">Ainda não há campanhas cadastradas. Esta área deve receber sequências LinkedIn/e-mail após definirmos as abordagens.</div></div>
          <?php else: ?>
            <div class="crm-table-wrap">
              <table class="crm-table">
                <thead><tr><th>Campanha</th><th>Tipo</th><th>Status</th><th>Segmento</th><th>Membros</th><th>Período</th></tr></thead>
                <tbody>
                <?php foreach ($campaignRows as $row): ?>
                  <tr>
                    <td><strong><?= crm_h($row['campaign_name']) ?></strong><div class="crm-muted"><?= crm_h($row['objective'] ?: '-') ?></div></td>
                    <td><span class="crm-pill teal"><?= crm_h($row['campaign_type']) ?></span></td>
                    <td><span class="crm-pill gold"><?= crm_h($row['status']) ?></span></td>
                    <td><?= crm_h($row['segment_name'] ?: '-') ?></td>
                    <td><?= crm_number((int)$row['members_count']) ?></td>
                    <td><?= crm_h(($row['start_at'] ?: '-') . ' / ' . ($row['end_at'] ?: '-')) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($view === 'imports'): ?>
        <section class="crm-panel">
          <div class="crm-panel-head"><div class="crm-panel-title"><i class="fa-solid fa-file-import"></i> Histórico de Importações</div></div>
          <div class="crm-table-wrap">
            <table class="crm-table">
              <thead><tr><th>ID</th><th>Arquivo</th><th>Status</th><th>Processadas</th><th>Inseridas</th><th>Atualizadas</th><th>Finalizado em</th></tr></thead>
              <tbody>
              <?php foreach ($importRows as $row): ?>
                <tr>
                  <td>#<?= crm_number((int)$row['id_import_batch']) ?></td>
                  <td><strong><?= crm_h($row['original_filename'] ?: $row['source_name']) ?></strong><div class="crm-muted"><?= crm_h($row['source_type']) ?></div></td>
                  <td><span class="crm-pill teal"><?= crm_h($row['status']) ?></span></td>
                  <td><?= crm_number((int)$row['processed_rows']) ?> / <?= crm_number((int)$row['total_rows']) ?></td>
                  <td><?= crm_number((int)$row['inserted_rows']) ?></td>
                  <td><?= crm_number((int)$row['updated_rows']) ?></td>
                  <td><?= crm_h($row['finished_at'] ?: '-') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($view === 'settings'): ?>
        <section class="crm-panel">
          <div class="crm-panel-head"><div class="crm-panel-title"><i class="fa-solid fa-sliders"></i> Configurações CRM</div></div>
          <div class="crm-table-wrap">
            <table class="crm-table">
              <thead><tr><th>Chave</th><th>Valor</th><th>Tipo</th><th>Descrição</th></tr></thead>
              <tbody>
              <?php foreach ($settingsRows as $row): ?>
                <tr>
                  <td><strong><?= crm_h($row['setting_key']) ?></strong></td>
                  <td><?= crm_h(mb_strimwidth((string)$row['setting_value'], 0, 90, '...')) ?></td>
                  <td><span class="crm-pill"><?= crm_h($row['value_type']) ?></span></td>
                  <td><?= crm_h($row['description']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <?php endif; ?>
    </div>
  </main>

  <?php include __DIR__ . '/partials/chat.php'; ?>
  <?php include __DIR__ . '/partials/tutorial.php'; ?>
</div>
</body>
</html>
