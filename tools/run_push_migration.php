<?php
// tools/run_push_migration.php — Executa migrations do modulo push
require_once __DIR__ . '/../auth/config.php';

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

echo "=== Push Tables ===\n";

$tables = [
  "push_devices" => "CREATE TABLE IF NOT EXISTS push_devices (
    id_device BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_user INT(11) NOT NULL,
    id_company INT(10) UNSIGNED DEFAULT NULL,
    platform ENUM('android','ios','web') NOT NULL DEFAULT 'android',
    push_provider VARCHAR(20) NOT NULL DEFAULT 'fcm',
    token TEXT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    app_version VARCHAR(30) DEFAULT NULL,
    os_version VARCHAR(30) DEFAULT NULL,
    device_model VARCHAR(80) DEFAULT NULL,
    locale VARCHAR(10) DEFAULT NULL,
    timezone VARCHAR(60) DEFAULT NULL,
    notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME DEFAULT NULL,
    last_token_refresh_at DATETIME DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_device),
    UNIQUE KEY uq_push_devices_token_hash (token_hash),
    KEY idx_push_devices_user (id_user),
    KEY idx_push_devices_company (id_company)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

  "push_assets" => "CREATE TABLE IF NOT EXISTS push_assets (
    id_asset BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(50) NOT NULL,
    ext VARCHAR(10) NOT NULL,
    path VARCHAR(500) NOT NULL,
    public_url VARCHAR(500) DEFAULT NULL,
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256_hash CHAR(64) NOT NULL,
    created_by INT(11) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_asset)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

  "push_segments" => "CREATE TABLE IF NOT EXISTS push_segments (
    id_segment BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(120) NOT NULL,
    descricao VARCHAR(500) DEFAULT NULL,
    filters_json LONGTEXT NOT NULL,
    created_by INT(11) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_segment)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

  "push_campaigns" => "CREATE TABLE IF NOT EXISTS push_campaigns (
    id_campaign BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome_interno VARCHAR(200) NOT NULL,
    canal ENUM('push','inbox','push_inbox') NOT NULL DEFAULT 'push',
    categoria VARCHAR(60) DEFAULT NULL,
    titulo VARCHAR(200) NOT NULL,
    descricao TEXT NOT NULL,
    image_asset_id BIGINT UNSIGNED DEFAULT NULL,
    route VARCHAR(200) DEFAULT NULL,
    url_web VARCHAR(500) DEFAULT NULL,
    priority ENUM('normal','high') NOT NULL DEFAULT 'normal',
    status ENUM('draft','scheduled','sending','sent','error','cancelled') NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME DEFAULT NULL,
    timezone VARCHAR(60) NOT NULL DEFAULT 'America/Sao_Paulo',
    is_recurring TINYINT(1) NOT NULL DEFAULT 0,
    recurrence_rule VARCHAR(200) DEFAULT NULL,
    next_recurrence_at DATETIME DEFAULT NULL,
    prompt_ia TEXT DEFAULT NULL,
    audience_estimate INT UNSIGNED DEFAULT NULL,
    filters_json LONGTEXT DEFAULT NULL,
    id_segment BIGINT UNSIGNED DEFAULT NULL,
    created_by INT(11) NOT NULL,
    updated_by INT(11) DEFAULT NULL,
    approved_by INT(11) DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_campaign),
    KEY idx_push_campaigns_status (status),
    KEY idx_push_campaigns_scheduled (status, scheduled_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

  "push_campaign_recipients" => "CREATE TABLE IF NOT EXISTS push_campaign_recipients (
    id_recipient BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_campaign BIGINT UNSIGNED NOT NULL,
    id_user INT(11) NOT NULL,
    id_device BIGINT UNSIGNED DEFAULT NULL,
    id_company INT(10) UNSIGNED DEFAULT NULL,
    status_envio ENUM('pending','sent','delivered','opened','clicked','failed','skipped') NOT NULL DEFAULT 'pending',
    provider_message_id VARCHAR(200) DEFAULT NULL,
    error_code VARCHAR(60) DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,
    opened_at DATETIME DEFAULT NULL,
    clicked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_recipient),
    KEY idx_push_recip_campaign (id_campaign, status_envio),
    KEY idx_push_recip_user (id_user)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

  "push_campaign_runs" => "CREATE TABLE IF NOT EXISTS push_campaign_runs (
    id_run BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_campaign BIGINT UNSIGNED NOT NULL,
    run_type ENUM('immediate','scheduled','recurring','test') NOT NULL DEFAULT 'immediate',
    status ENUM('pending','running','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    total_target INT UNSIGNED NOT NULL DEFAULT 0,
    total_sent INT UNSIGNED NOT NULL DEFAULT 0,
    total_failed INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    log_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_run),
    KEY idx_push_runs_campaign (id_campaign, status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

  "push_delivery_events" => "CREATE TABLE IF NOT EXISTS push_delivery_events (
    id_event BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_campaign BIGINT UNSIGNED NOT NULL,
    id_recipient BIGINT UNSIGNED DEFAULT NULL,
    event_type ENUM('sent','delivered','opened','clicked','dismissed','failed') NOT NULL,
    event_payload_json TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_event),
    KEY idx_push_events_campaign (id_campaign, event_type)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

  "push_ai_suggestions" => "CREATE TABLE IF NOT EXISTS push_ai_suggestions (
    id_suggestion BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_campaign BIGINT UNSIGNED DEFAULT NULL,
    prompt TEXT NOT NULL,
    response_json LONGTEXT NOT NULL,
    selected_option TEXT DEFAULT NULL,
    created_by INT(11) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_suggestion),
    KEY idx_push_ai_campaign (id_campaign)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $name => $sql) {
  try {
    $pdo->exec($sql);
    echo "OK: $name\n";
  } catch (Exception $e) {
    echo "ERR $name: " . $e->getMessage() . "\n";
  }
}

echo "\n=== Responsible ID columns ===\n";

// Add id_user_dono to objetivos
try {
  $cols = $pdo->query("SHOW COLUMNS FROM objetivos LIKE 'id_user_dono'")->fetchAll();
  if (empty($cols)) {
    $pdo->exec("ALTER TABLE objetivos ADD COLUMN id_user_dono INT(11) DEFAULT NULL AFTER dono");
    echo "OK: objetivos.id_user_dono added\n";
    $pdo->exec("ALTER TABLE objetivos ADD KEY idx_objetivos_user_dono (id_user_dono)");
    echo "OK: index added\n";
  } else {
    echo "SKIP: objetivos.id_user_dono already exists\n";
  }
} catch (Exception $e) { echo "WARN: " . $e->getMessage() . "\n"; }

// Add id_user_responsavel to key_results
try {
  $cols = $pdo->query("SHOW COLUMNS FROM key_results LIKE 'id_user_responsavel'")->fetchAll();
  if (empty($cols)) {
    $pdo->exec("ALTER TABLE key_results ADD COLUMN id_user_responsavel INT(11) DEFAULT NULL AFTER responsavel");
    echo "OK: key_results.id_user_responsavel added\n";
    $pdo->exec("ALTER TABLE key_results ADD KEY idx_kr_user_responsavel (id_user_responsavel)");
    echo "OK: index added\n";
  } else {
    echo "SKIP: key_results.id_user_responsavel already exists\n";
  }
} catch (Exception $e) { echo "WARN: " . $e->getMessage() . "\n"; }

// Auto-fill where possible
echo "\n=== Auto-fill responsible IDs ===\n";
try {
  $r1 = $pdo->exec("UPDATE objetivos o JOIN usuarios u ON u.id_user = o.id_user_criador SET o.id_user_dono = u.id_user WHERE o.id_user_dono IS NULL AND o.id_user_criador IS NOT NULL");
  echo "objetivos.id_user_dono filled: $r1 rows\n";
} catch (Exception $e) { echo "WARN: " . $e->getMessage() . "\n"; }

try {
  $r2 = $pdo->exec("UPDATE key_results k JOIN usuarios u ON u.id_user = k.id_user_criador SET k.id_user_responsavel = u.id_user WHERE k.id_user_responsavel IS NULL AND k.id_user_criador IS NOT NULL");
  echo "key_results.id_user_responsavel filled: $r2 rows\n";
} catch (Exception $e) { echo "WARN: " . $e->getMessage() . "\n"; }

// Create uploads/push dir
$dir = dirname(__DIR__) . '/uploads/push';
if (!is_dir($dir)) { mkdir($dir, 0755, true); echo "\nOK: uploads/push/ created\n"; }

// Verify
echo "\n=== Verification ===\n";
$tables = $pdo->query("SHOW TABLES LIKE 'push_%'")->fetchAll(PDO::FETCH_COLUMN);
echo "Push tables: " . implode(', ', $tables) . "\n";
echo "Total: " . count($tables) . " tables\n";
