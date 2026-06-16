-- =============================================================
-- CRM Module - PlanningBI / OKR_system
-- Database target: planni40_crm
-- Compatible with MySQL 5.7+ / InnoDB / utf8mb4_unicode_ci
-- =============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_settings` (
  `setting_key`   VARCHAR(120) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `value_type`    ENUM('string','int','decimal','bool','json','date') NOT NULL DEFAULT 'string',
  `description`   VARCHAR(500) DEFAULT NULL,
  `updated_at`    DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_import_batches` (
  `id_import_batch` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_type`     ENUM('linkedin','manual','csv','api','other') NOT NULL DEFAULT 'linkedin',
  `source_name`     VARCHAR(120) DEFAULT NULL,
  `original_filename` VARCHAR(255) DEFAULT NULL,
  `file_sha256`     CHAR(64) DEFAULT NULL,
  `file_size_bytes` BIGINT UNSIGNED DEFAULT NULL,
  `imported_by`     INT(11) DEFAULT NULL COMMENT 'OKR_system usuarios.id_user when available',
  `status`          ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `total_rows`      INT UNSIGNED NOT NULL DEFAULT 0,
  `processed_rows`  INT UNSIGNED NOT NULL DEFAULT 0,
  `inserted_rows`   INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_rows`    INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_rows`    INT UNSIGNED NOT NULL DEFAULT 0,
  `error_rows`      INT UNSIGNED NOT NULL DEFAULT 0,
  `notes`           TEXT DEFAULT NULL,
  `metadata_json`   LONGTEXT DEFAULT NULL,
  `started_at`      DATETIME DEFAULT NULL,
  `finished_at`     DATETIME DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_import_batch`),
  KEY `idx_crm_import_batches_status` (`status`, `created_at`),
  KEY `idx_crm_import_batches_source` (`source_type`, `source_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_import_rows` (
  `id_import_row`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_import_batch` BIGINT UNSIGNED NOT NULL,
  `row_number`      INT UNSIGNED NOT NULL,
  `entity_hint`     ENUM('profile','connection','company','message','position','skill','recommendation','job','learning','event','ad_targeting','raw','other') NOT NULL DEFAULT 'raw',
  `raw_json`        LONGTEXT NOT NULL,
  `raw_text`        LONGTEXT DEFAULT NULL,
  `row_hash`        CHAR(64) DEFAULT NULL,
  `processing_status` ENUM('pending','processed','skipped','error') NOT NULL DEFAULT 'pending',
  `error_message`   VARCHAR(1000) DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_import_row`),
  UNIQUE KEY `uq_crm_import_rows_batch_row` (`id_import_batch`, `row_number`),
  KEY `idx_crm_import_rows_hash` (`row_hash`),
  KEY `idx_crm_import_rows_status` (`processing_status`, `entity_hint`),
  CONSTRAINT `fk_crm_import_rows_batch`
    FOREIGN KEY (`id_import_batch`) REFERENCES `crm_import_batches` (`id_import_batch`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_accounts` (
  `id_account`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_name`     VARCHAR(255) NOT NULL,
  `legal_name`       VARCHAR(255) DEFAULT NULL,
  `normalized_name`  VARCHAR(255) DEFAULT NULL,
  `linkedin_url`     VARCHAR(500) DEFAULT NULL,
  `linkedin_url_hash` CHAR(64) DEFAULT NULL,
  `website`          VARCHAR(500) DEFAULT NULL,
  `domain`           VARCHAR(190) DEFAULT NULL,
  `cnpj`             VARCHAR(20) DEFAULT NULL,
  `industry`         VARCHAR(160) DEFAULT NULL,
  `subindustry`      VARCHAR(160) DEFAULT NULL,
  `company_size_label` VARCHAR(80) DEFAULT NULL,
  `employee_count_min` INT UNSIGNED DEFAULT NULL,
  `employee_count_max` INT UNSIGNED DEFAULT NULL,
  `revenue_label`    VARCHAR(80) DEFAULT NULL,
  `city`             VARCHAR(120) DEFAULT NULL,
  `state`            VARCHAR(80) DEFAULT NULL,
  `country`          VARCHAR(80) DEFAULT 'Brasil',
  `region`           VARCHAR(120) DEFAULT NULL,
  `source_type`      ENUM('linkedin','manual','import','website','referral','other') NOT NULL DEFAULT 'linkedin',
  `source_confidence` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `account_status`  ENUM('new','target','researching','qualified','disqualified','customer','partner','inactive') NOT NULL DEFAULT 'new',
  `priority`         ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `icp_fit_score`    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `converted_id_company` INT UNSIGNED DEFAULT NULL COMMENT 'OKR_system company.id_company when converted',
  `owner_user_id`    INT(11) DEFAULT NULL COMMENT 'OKR_system usuarios.id_user owner',
  `last_interaction_at` DATETIME DEFAULT NULL,
  `next_action_at`   DATETIME DEFAULT NULL,
  `metadata_json`    LONGTEXT DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_account`),
  UNIQUE KEY `uq_crm_accounts_linkedin_hash` (`linkedin_url_hash`),
  UNIQUE KEY `uq_crm_accounts_domain` (`domain`),
  KEY `idx_crm_accounts_name` (`normalized_name`),
  KEY `idx_crm_accounts_status` (`account_status`, `priority`),
  KEY `idx_crm_accounts_score` (`icp_fit_score`),
  KEY `idx_crm_accounts_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_contacts` (
  `id_contact`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name`       VARCHAR(120) DEFAULT NULL,
  `last_name`        VARCHAR(160) DEFAULT NULL,
  `full_name`        VARCHAR(255) NOT NULL,
  `normalized_full_name` VARCHAR(255) DEFAULT NULL,
  `headline`         VARCHAR(500) DEFAULT NULL,
  `summary`          LONGTEXT DEFAULT NULL,
  `linkedin_url`     VARCHAR(500) DEFAULT NULL,
  `linkedin_url_hash` CHAR(64) DEFAULT NULL,
  `current_account_id` BIGINT UNSIGNED DEFAULT NULL,
  `current_company_name` VARCHAR(255) DEFAULT NULL,
  `current_position` VARCHAR(255) DEFAULT NULL,
  `seniority`        ENUM('owner','c_level','director','head','manager','coordinator','specialist','analyst','assistant','student','unknown') NOT NULL DEFAULT 'unknown',
  `department`       ENUM('executive','strategy','it_data','finance','operations','commercial_marketing','hr','legal','procurement','education','health','other','unknown') NOT NULL DEFAULT 'unknown',
  `location_city`    VARCHAR(120) DEFAULT NULL,
  `location_state`   VARCHAR(80) DEFAULT NULL,
  `location_country` VARCHAR(80) DEFAULT 'Brasil',
  `connected_on`     DATE DEFAULT NULL,
  `relationship_strength` ENUM('unknown','cold','warm','hot','trusted') NOT NULL DEFAULT 'unknown',
  `contact_status`   ENUM('new','to_research','qualified','approached','responded','meeting_scheduled','opportunity','nurturing','not_fit','do_not_contact','converted') NOT NULL DEFAULT 'new',
  `source_type`      ENUM('linkedin','manual','import','referral','website','other') NOT NULL DEFAULT 'linkedin',
  `lead_score`       DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `owner_user_id`    INT(11) DEFAULT NULL COMMENT 'OKR_system usuarios.id_user owner',
  `converted_id_user` INT(11) DEFAULT NULL COMMENT 'OKR_system usuarios.id_user when converted',
  `last_interaction_at` DATETIME DEFAULT NULL,
  `next_action_at`   DATETIME DEFAULT NULL,
  `metadata_json`    LONGTEXT DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_contact`),
  UNIQUE KEY `uq_crm_contacts_linkedin_hash` (`linkedin_url_hash`),
  KEY `idx_crm_contacts_account` (`current_account_id`),
  KEY `idx_crm_contacts_status` (`contact_status`, `seniority`, `department`),
  KEY `idx_crm_contacts_score` (`lead_score`),
  KEY `idx_crm_contacts_owner` (`owner_user_id`),
  CONSTRAINT `fk_crm_contacts_account`
    FOREIGN KEY (`current_account_id`) REFERENCES `crm_accounts` (`id_account`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_contact_channels` (
  `id_channel`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_contact`       BIGINT UNSIGNED NOT NULL,
  `channel_type`     ENUM('email','phone','whatsapp','linkedin','website','other') NOT NULL,
  `channel_value`    VARCHAR(500) NOT NULL,
  `channel_hash`     CHAR(64) DEFAULT NULL,
  `label`            VARCHAR(80) DEFAULT NULL,
  `is_primary`       TINYINT(1) NOT NULL DEFAULT 0,
  `verified_at`      DATETIME DEFAULT NULL,
  `consent_status`   ENUM('unknown','allowed','opted_out','invalid') NOT NULL DEFAULT 'unknown',
  `source_type`      ENUM('linkedin','manual','import','enrichment','other') NOT NULL DEFAULT 'linkedin',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_channel`),
  UNIQUE KEY `uq_crm_contact_channels_hash` (`id_contact`, `channel_type`, `channel_hash`),
  KEY `idx_crm_contact_channels_type` (`channel_type`, `consent_status`),
  CONSTRAINT `fk_crm_contact_channels_contact`
    FOREIGN KEY (`id_contact`) REFERENCES `crm_contacts` (`id_contact`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_contact_positions` (
  `id_position`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_contact`       BIGINT UNSIGNED NOT NULL,
  `id_account`       BIGINT UNSIGNED DEFAULT NULL,
  `company_name`     VARCHAR(255) DEFAULT NULL,
  `title`            VARCHAR(255) NOT NULL,
  `description`      LONGTEXT DEFAULT NULL,
  `location`         VARCHAR(255) DEFAULT NULL,
  `started_on`       DATE DEFAULT NULL,
  `finished_on`      DATE DEFAULT NULL,
  `is_current`       TINYINT(1) NOT NULL DEFAULT 0,
  `source_type`      ENUM('linkedin','manual','import','other') NOT NULL DEFAULT 'linkedin',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_position`),
  KEY `idx_crm_positions_contact` (`id_contact`, `is_current`),
  KEY `idx_crm_positions_account` (`id_account`),
  CONSTRAINT `fk_crm_positions_contact`
    FOREIGN KEY (`id_contact`) REFERENCES `crm_contacts` (`id_contact`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_positions_account`
    FOREIGN KEY (`id_account`) REFERENCES `crm_accounts` (`id_account`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_conversations` (
  `id_conversation`  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_type`      ENUM('linkedin','manual','email','whatsapp','phone','meeting','other') NOT NULL DEFAULT 'linkedin',
  `external_conversation_id` VARCHAR(190) DEFAULT NULL,
  `conversation_title` VARCHAR(500) DEFAULT NULL,
  `id_contact`       BIGINT UNSIGNED DEFAULT NULL,
  `id_account`       BIGINT UNSIGNED DEFAULT NULL,
  `direction`        ENUM('inbound','outbound','mixed','unknown') NOT NULL DEFAULT 'unknown',
  `folder`           VARCHAR(80) DEFAULT NULL,
  `started_at`       DATETIME DEFAULT NULL,
  `last_message_at`  DATETIME DEFAULT NULL,
  `message_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `metadata_json`    LONGTEXT DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_conversation`),
  UNIQUE KEY `uq_crm_conversations_external` (`source_type`, `external_conversation_id`),
  KEY `idx_crm_conversations_contact` (`id_contact`),
  KEY `idx_crm_conversations_account` (`id_account`),
  KEY `idx_crm_conversations_last` (`last_message_at`),
  CONSTRAINT `fk_crm_conversations_contact`
    FOREIGN KEY (`id_contact`) REFERENCES `crm_contacts` (`id_contact`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_conversations_account`
    FOREIGN KEY (`id_account`) REFERENCES `crm_accounts` (`id_account`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_messages` (
  `id_message`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_conversation`  BIGINT UNSIGNED NOT NULL,
  `id_contact`       BIGINT UNSIGNED DEFAULT NULL,
  `id_account`       BIGINT UNSIGNED DEFAULT NULL,
  `external_message_id` VARCHAR(190) DEFAULT NULL,
  `sender_name`      VARCHAR(255) DEFAULT NULL,
  `sender_profile_url` VARCHAR(500) DEFAULT NULL,
  `recipient_names`  TEXT DEFAULT NULL,
  `recipient_profile_urls` TEXT DEFAULT NULL,
  `direction`        ENUM('inbound','outbound','unknown') NOT NULL DEFAULT 'unknown',
  `subject`          VARCHAR(500) DEFAULT NULL,
  `content_html`     LONGTEXT DEFAULT NULL,
  `content_text`     LONGTEXT DEFAULT NULL,
  `sent_at`          DATETIME DEFAULT NULL,
  `folder`           VARCHAR(80) DEFAULT NULL,
  `attachments_json` LONGTEXT DEFAULT NULL,
  `is_draft`         TINYINT(1) NOT NULL DEFAULT 0,
  `content_hash`     CHAR(64) DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_message`),
  KEY `idx_crm_messages_conversation` (`id_conversation`, `sent_at`),
  KEY `idx_crm_messages_contact` (`id_contact`),
  KEY `idx_crm_messages_account` (`id_account`),
  KEY `idx_crm_messages_hash` (`content_hash`),
  CONSTRAINT `fk_crm_messages_conversation`
    FOREIGN KEY (`id_conversation`) REFERENCES `crm_conversations` (`id_conversation`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_messages_contact`
    FOREIGN KEY (`id_contact`) REFERENCES `crm_contacts` (`id_contact`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_messages_account`
    FOREIGN KEY (`id_account`) REFERENCES `crm_accounts` (`id_account`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_tags` (
  `id_tag`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tag_key`      VARCHAR(120) NOT NULL,
  `tag_name`     VARCHAR(160) NOT NULL,
  `tag_type`     ENUM('segment','icp','source','status','custom') NOT NULL DEFAULT 'custom',
  `color`        VARCHAR(20) DEFAULT NULL,
  `description`  VARCHAR(500) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_tag`),
  UNIQUE KEY `uq_crm_tags_key` (`tag_key`),
  KEY `idx_crm_tags_type` (`tag_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_tag_links` (
  `id_tag_link`  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_tag`       BIGINT UNSIGNED NOT NULL,
  `entity_type`  ENUM('account','contact','opportunity','campaign','conversation') NOT NULL,
  `id_entity`    BIGINT UNSIGNED NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_tag_link`),
  UNIQUE KEY `uq_crm_tag_links_entity` (`id_tag`, `entity_type`, `id_entity`),
  KEY `idx_crm_tag_links_lookup` (`entity_type`, `id_entity`),
  CONSTRAINT `fk_crm_tag_links_tag`
    FOREIGN KEY (`id_tag`) REFERENCES `crm_tags` (`id_tag`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_segments` (
  `id_segment`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `segment_name` VARCHAR(160) NOT NULL,
  `description`  VARCHAR(700) DEFAULT NULL,
  `entity_type`  ENUM('account','contact') NOT NULL DEFAULT 'contact',
  `filters_json` LONGTEXT NOT NULL,
  `is_dynamic`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`   INT(11) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_segment`),
  KEY `idx_crm_segments_entity` (`entity_type`, `is_dynamic`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_segment_members` (
  `id_segment_member` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_segment`        BIGINT UNSIGNED NOT NULL,
  `entity_type`       ENUM('account','contact') NOT NULL DEFAULT 'contact',
  `id_entity`         BIGINT UNSIGNED NOT NULL,
  `score_at_add`      DECIMAL(5,2) DEFAULT NULL,
  `added_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_segment_member`),
  UNIQUE KEY `uq_crm_segment_members_entity` (`id_segment`, `entity_type`, `id_entity`),
  KEY `idx_crm_segment_members_lookup` (`entity_type`, `id_entity`),
  CONSTRAINT `fk_crm_segment_members_segment`
    FOREIGN KEY (`id_segment`) REFERENCES `crm_segments` (`id_segment`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_lead_scores` (
  `id_score`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`   ENUM('account','contact') NOT NULL DEFAULT 'contact',
  `id_entity`     BIGINT UNSIGNED NOT NULL,
  `score_model_version` VARCHAR(40) NOT NULL DEFAULT 'v1',
  `total_score`   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `icp_score`     DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `engagement_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `relationship_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `timing_score`  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `reasons_json`  LONGTEXT DEFAULT NULL,
  `calculated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_score`),
  KEY `idx_crm_lead_scores_entity` (`entity_type`, `id_entity`, `calculated_at`),
  KEY `idx_crm_lead_scores_total` (`total_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_pipeline_stages` (
  `id_stage`    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stage_key`   VARCHAR(60) NOT NULL,
  `stage_name`  VARCHAR(120) NOT NULL,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_won`      TINYINT(1) NOT NULL DEFAULT 0,
  `is_lost`     TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_stage`),
  UNIQUE KEY `uq_crm_pipeline_stages_key` (`stage_key`),
  KEY `idx_crm_pipeline_stages_order` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_opportunities` (
  `id_opportunity` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_account`     BIGINT UNSIGNED DEFAULT NULL,
  `id_primary_contact` BIGINT UNSIGNED DEFAULT NULL,
  `id_stage`       SMALLINT UNSIGNED DEFAULT NULL,
  `title`          VARCHAR(255) NOT NULL,
  `description`    TEXT DEFAULT NULL,
  `estimated_value` DECIMAL(14,2) DEFAULT NULL,
  `currency`       CHAR(3) NOT NULL DEFAULT 'BRL',
  `probability`    TINYINT UNSIGNED DEFAULT NULL,
  `expected_close_date` DATE DEFAULT NULL,
  `source_type`    ENUM('linkedin','referral','website','manual','other') NOT NULL DEFAULT 'linkedin',
  `status`         ENUM('open','won','lost','archived') NOT NULL DEFAULT 'open',
  `loss_reason`    VARCHAR(500) DEFAULT NULL,
  `owner_user_id`  INT(11) DEFAULT NULL,
  `closed_at`      DATETIME DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_opportunity`),
  KEY `idx_crm_opportunities_account` (`id_account`),
  KEY `idx_crm_opportunities_contact` (`id_primary_contact`),
  KEY `idx_crm_opportunities_stage` (`id_stage`, `status`),
  KEY `idx_crm_opportunities_owner` (`owner_user_id`),
  CONSTRAINT `fk_crm_opportunities_account`
    FOREIGN KEY (`id_account`) REFERENCES `crm_accounts` (`id_account`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_opportunities_contact`
    FOREIGN KEY (`id_primary_contact`) REFERENCES `crm_contacts` (`id_contact`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_opportunities_stage`
    FOREIGN KEY (`id_stage`) REFERENCES `crm_pipeline_stages` (`id_stage`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_campaigns` (
  `id_campaign`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_name` VARCHAR(180) NOT NULL,
  `campaign_type` ENUM('linkedin_outreach','email','whatsapp','content','event','manual','other') NOT NULL DEFAULT 'linkedin_outreach',
  `objective`     VARCHAR(700) DEFAULT NULL,
  `status`        ENUM('draft','active','paused','completed','archived') NOT NULL DEFAULT 'draft',
  `id_segment`    BIGINT UNSIGNED DEFAULT NULL,
  `owner_user_id` INT(11) DEFAULT NULL,
  `start_at`      DATETIME DEFAULT NULL,
  `end_at`        DATETIME DEFAULT NULL,
  `template_json` LONGTEXT DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_campaign`),
  KEY `idx_crm_campaigns_status` (`status`, `campaign_type`),
  KEY `idx_crm_campaigns_segment` (`id_segment`),
  CONSTRAINT `fk_crm_campaigns_segment`
    FOREIGN KEY (`id_segment`) REFERENCES `crm_segments` (`id_segment`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_campaign_members` (
  `id_campaign_member` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_campaign`        BIGINT UNSIGNED NOT NULL,
  `id_contact`         BIGINT UNSIGNED DEFAULT NULL,
  `id_account`         BIGINT UNSIGNED DEFAULT NULL,
  `status`             ENUM('queued','contacted','responded','meeting','converted','skipped','unsubscribed','error') NOT NULL DEFAULT 'queued',
  `step_number`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `last_step_at`       DATETIME DEFAULT NULL,
  `next_step_at`       DATETIME DEFAULT NULL,
  `result_notes`       VARCHAR(1000) DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_campaign_member`),
  UNIQUE KEY `uq_crm_campaign_members_contact` (`id_campaign`, `id_contact`),
  KEY `idx_crm_campaign_members_account` (`id_account`),
  KEY `idx_crm_campaign_members_status` (`id_campaign`, `status`),
  CONSTRAINT `fk_crm_campaign_members_campaign`
    FOREIGN KEY (`id_campaign`) REFERENCES `crm_campaigns` (`id_campaign`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_campaign_members_contact`
    FOREIGN KEY (`id_contact`) REFERENCES `crm_contacts` (`id_contact`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_campaign_members_account`
    FOREIGN KEY (`id_account`) REFERENCES `crm_accounts` (`id_account`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_activities` (
  `id_activity`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_type`  ENUM('note','linkedin_message','email','whatsapp','call','meeting','task','import','content_engagement','proposal','other') NOT NULL DEFAULT 'note',
  `id_contact`     BIGINT UNSIGNED DEFAULT NULL,
  `id_account`     BIGINT UNSIGNED DEFAULT NULL,
  `id_opportunity` BIGINT UNSIGNED DEFAULT NULL,
  `id_campaign`    BIGINT UNSIGNED DEFAULT NULL,
  `direction`      ENUM('inbound','outbound','internal','unknown') NOT NULL DEFAULT 'unknown',
  `subject`        VARCHAR(500) DEFAULT NULL,
  `body`           LONGTEXT DEFAULT NULL,
  `activity_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `due_at`         DATETIME DEFAULT NULL,
  `completed_at`   DATETIME DEFAULT NULL,
  `outcome`        VARCHAR(500) DEFAULT NULL,
  `owner_user_id`  INT(11) DEFAULT NULL,
  `metadata_json`  LONGTEXT DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_activity`),
  KEY `idx_crm_activities_contact` (`id_contact`, `activity_at`),
  KEY `idx_crm_activities_account` (`id_account`, `activity_at`),
  KEY `idx_crm_activities_opportunity` (`id_opportunity`),
  KEY `idx_crm_activities_campaign` (`id_campaign`),
  KEY `idx_crm_activities_due` (`due_at`, `completed_at`),
  CONSTRAINT `fk_crm_activities_contact`
    FOREIGN KEY (`id_contact`) REFERENCES `crm_contacts` (`id_contact`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_activities_account`
    FOREIGN KEY (`id_account`) REFERENCES `crm_accounts` (`id_account`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_activities_opportunity`
    FOREIGN KEY (`id_opportunity`) REFERENCES `crm_opportunities` (`id_opportunity`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_activities_campaign`
    FOREIGN KEY (`id_campaign`) REFERENCES `crm_campaigns` (`id_campaign`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_tasks` (
  `id_task`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`         VARCHAR(255) NOT NULL,
  `description`   TEXT DEFAULT NULL,
  `status`        ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
  `priority`      ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `due_at`        DATETIME DEFAULT NULL,
  `completed_at`  DATETIME DEFAULT NULL,
  `id_contact`    BIGINT UNSIGNED DEFAULT NULL,
  `id_account`    BIGINT UNSIGNED DEFAULT NULL,
  `id_opportunity` BIGINT UNSIGNED DEFAULT NULL,
  `id_campaign`   BIGINT UNSIGNED DEFAULT NULL,
  `owner_user_id` INT(11) DEFAULT NULL,
  `created_by`    INT(11) DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_task`),
  KEY `idx_crm_tasks_status` (`status`, `due_at`),
  KEY `idx_crm_tasks_owner` (`owner_user_id`, `status`),
  KEY `idx_crm_tasks_contact` (`id_contact`),
  KEY `idx_crm_tasks_account` (`id_account`),
  CONSTRAINT `fk_crm_tasks_contact`
    FOREIGN KEY (`id_contact`) REFERENCES `crm_contacts` (`id_contact`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_tasks_account`
    FOREIGN KEY (`id_account`) REFERENCES `crm_accounts` (`id_account`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_tasks_opportunity`
    FOREIGN KEY (`id_opportunity`) REFERENCES `crm_opportunities` (`id_opportunity`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_crm_tasks_campaign`
    FOREIGN KEY (`id_campaign`) REFERENCES `crm_campaigns` (`id_campaign`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_consent_events` (
  `id_consent_event` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_contact`       BIGINT UNSIGNED DEFAULT NULL,
  `channel_type`     ENUM('email','phone','whatsapp','linkedin','website','other') DEFAULT NULL,
  `channel_value_hash` CHAR(64) DEFAULT NULL,
  `event_type`       ENUM('source_imported','legitimate_interest','opt_in','opt_out','delete_request','data_correction','do_not_contact','privacy_note') NOT NULL,
  `legal_basis`      ENUM('legitimate_interest','consent','contract','legal_obligation','public_data','unknown') NOT NULL DEFAULT 'unknown',
  `source_description` VARCHAR(700) DEFAULT NULL,
  `event_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`       INT(11) DEFAULT NULL,
  `metadata_json`    LONGTEXT DEFAULT NULL,
  PRIMARY KEY (`id_consent_event`),
  KEY `idx_crm_consent_contact` (`id_contact`, `event_at`),
  KEY `idx_crm_consent_type` (`event_type`, `legal_basis`),
  CONSTRAINT `fk_crm_consent_contact`
    FOREIGN KEY (`id_contact`) REFERENCES `crm_contacts` (`id_contact`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `crm_settings` (`setting_key`, `setting_value`, `value_type`, `description`) VALUES
  ('schema_version', '2026-06-16-001', 'string', 'Initial CRM schema version'),
  ('default_country', 'Brasil', 'string', 'Default country used during LinkedIn import'),
  ('lead_score_model_version', 'v1', 'string', 'Default lead scoring model');

INSERT IGNORE INTO `crm_pipeline_stages` (`stage_key`, `stage_name`, `sort_order`, `is_won`, `is_lost`, `is_active`) VALUES
  ('new', 'Novo lead', 10, 0, 0, 1),
  ('qualified', 'Qualificado', 20, 0, 0, 1),
  ('approached', 'Abordagem enviada', 30, 0, 0, 1),
  ('responded', 'Respondeu', 40, 0, 0, 1),
  ('meeting_scheduled', 'Reuniao marcada', 50, 0, 0, 1),
  ('diagnosis', 'Diagnostico', 60, 0, 0, 1),
  ('proposal', 'Proposta', 70, 0, 0, 1),
  ('won', 'Ganho', 90, 1, 0, 1),
  ('lost', 'Perdido', 100, 0, 1, 1);

INSERT IGNORE INTO `crm_tags` (`tag_key`, `tag_name`, `tag_type`, `color`, `description`) VALUES
  ('icp-ceo-industria', 'CEO/Diretor de industria', 'icp', '#2563eb', 'Decisores de empresas industriais ou operacionais'),
  ('icp-ti-bi-dados', 'TI, BI e Dados', 'icp', '#16a34a', 'Gestores e influenciadores de tecnologia, dados e BI'),
  ('icp-financeiro-controladoria', 'Financeiro e Controladoria', 'icp', '#9333ea', 'Perfis ligados a margem, custos, orcamento e controladoria'),
  ('icp-operacoes-comercial', 'Operacoes e Comercial', 'icp', '#f97316', 'Perfis ligados a operacao, vendas, forecast e produtividade'),
  ('source-linkedin-export', 'LinkedIn Export', 'source', '#0a66c2', 'Registro vindo da exportacao de dados do LinkedIn');
