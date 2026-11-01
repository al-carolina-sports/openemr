--
-- AI Assistant module (oe-module-ai-assistant)
--
-- All tables are prefixed mod_ai_assistant_ and live only in this module, so
-- OpenEMR upgrades never touch them. Core tables are referenced by id only
-- (users.id, patient_data.pid).
--
-- Chat text and tool results can contain patient information. They stay in
-- this database and are purged after the retention period in the settings
-- (30 days by default) by the "AI Assistant log purge" background service.
--

#IfNotTable mod_ai_assistant_settings
CREATE TABLE `mod_ai_assistant_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` MEDIUMTEXT NOT NULL COMMENT 'JSON-encoded value',
    `updated_by` INT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB COMMENT='AI Assistant settings (model id, limits, retention)';
#EndIf

-- One row per event: a user message, each tool call, the reply, or an error.
-- request_id groups the rows of one user message.
#IfNotTable mod_ai_assistant_log
CREATE TABLE `mod_ai_assistant_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `user_id` INT NOT NULL,
    `request_id` CHAR(36) NOT NULL,
    `role` VARCHAR(16) NOT NULL COMMENT 'user, tool, assistant, error',
    `content` MEDIUMTEXT NULL,
    `tool_name` VARCHAR(64) NULL,
    `tool_input` TEXT NULL COMMENT 'JSON',
    `tool_result_summary` TEXT NULL,
    `pid` BIGINT NULL COMMENT 'patient the tool call was about, when there was one',
    `model_id` VARCHAR(128) NULL,
    `input_tokens` INT NULL,
    `output_tokens` INT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_user_created` (`user_id`, `created_at`),
    KEY `idx_request` (`request_id`)
) ENGINE=InnoDB COMMENT='AI Assistant audit log';
#EndIf

-- The running conversation per user (Bedrock message format), so a chat
-- survives page reloads and never sits in the PHP session.
#IfNotTable mod_ai_assistant_conversation
CREATE TABLE `mod_ai_assistant_conversation` (
    `user_id` INT NOT NULL,
    `messages` MEDIUMTEXT NOT NULL COMMENT 'JSON list of Bedrock Converse messages',
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB COMMENT='AI Assistant current conversation per user';
#EndIf
