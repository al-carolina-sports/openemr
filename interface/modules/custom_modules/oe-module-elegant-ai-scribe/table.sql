--
-- Annie's Elegant AI Scribe
--
-- oe_module_eas_settings  module settings (JSON values; Deepgram key only as CryptoGen ciphertext; AWS keys never stored)
-- oe_module_eas_sessions  one recording/transcription/draft per clinician + encounter
-- form_elegant_ai_scribe  the saved encounter form ("AI Scribe Note")
-- registry row            makes "AI Scribe Note" available in the encounter's Clinical forms menu
--

#IfNotTable oe_module_eas_settings
CREATE TABLE `oe_module_eas_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` MEDIUMTEXT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB COMMENT='Elegant AI Scribe settings';
#EndIf

#IfNotTable oe_module_eas_sessions
CREATE TABLE `oe_module_eas_sessions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(32) NOT NULL,
    `user_id` BIGINT NOT NULL,
    `pid` BIGINT NOT NULL,
    `encounter` BIGINT NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'new' COMMENT 'new, uploaded, transcribing, transcribed, drafted, saved, failed',
    `audio_path` VARCHAR(255) NULL,
    `audio_mime` VARCHAR(50) NULL,
    `audio_seconds` INT NULL,
    `transcriber` VARCHAR(30) NULL,
    `generator` VARCHAR(30) NULL,
    `job_name` VARCHAR(200) NULL,
    `transcript` MEDIUMTEXT NULL,
    `note_json` MEDIUMTEXT NULL,
    `error` VARCHAR(255) NULL,
    `form_id` BIGINT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY `pid_encounter` (`pid`, `encounter`)
) ENGINE=InnoDB COMMENT='Elegant AI Scribe recording sessions';
#EndIf

#IfNotTable form_elegant_ai_scribe
CREATE TABLE `form_elegant_ai_scribe` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `date` DATETIME DEFAULT NULL,
    `pid` BIGINT DEFAULT NULL,
    `user` VARCHAR(255) DEFAULT NULL,
    `groupname` VARCHAR(255) DEFAULT NULL,
    `authorized` TINYINT DEFAULT NULL,
    `activity` TINYINT DEFAULT NULL,
    `template` VARCHAR(30) DEFAULT NULL COMMENT 'hp (History & Physical; v0.3 new_patient) or soap',
    `chief_complaint` MEDIUMTEXT,
    `hpi` MEDIUMTEXT,
    `review_of_systems` MEDIUMTEXT,
    `physical_exam` MEDIUMTEXT,
    `imaging` MEDIUMTEXT,
    `subjective` MEDIUMTEXT,
    `objective` MEDIUMTEXT,
    `assessment` MEDIUMTEXT,
    `plan` MEDIUMTEXT,
    `transcript` MEDIUMTEXT,
    `ai_providers` VARCHAR(100) DEFAULT NULL COMMENT 'transcriber/generator used for the draft',
    `session_uuid` CHAR(32) DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB COMMENT='AI Scribe Note encounter form';
#EndIf

-- v0.3.0: note templates (installs from v0.1/v0.2)
#IfMissingColumn form_elegant_ai_scribe template
ALTER TABLE `form_elegant_ai_scribe` ADD `template` VARCHAR(30) DEFAULT NULL COMMENT 'new_patient or soap' AFTER `activity`,
    ADD `chief_complaint` MEDIUMTEXT AFTER `template`, ADD `hpi` MEDIUMTEXT AFTER `chief_complaint`,
    ADD `review_of_systems` MEDIUMTEXT AFTER `hpi`, ADD `physical_exam` MEDIUMTEXT AFTER `review_of_systems`,
    ADD `imaging` MEDIUMTEXT AFTER `physical_exam`;
#EndIf

#IfNotRow registry directory elegant_ai_scribe
INSERT INTO `registry` (`name`, `state`, `directory`, `sql_run`, `unpackaged`, `date`, `priority`, `category`, `nickname`, `patient_encounter`, `therapy_group_encounter`, `aco_spec`)
VALUES ('AI Scribe Note - History & Physical', 1, 'elegant_ai_scribe', 1, 1, NOW(), 0, 'Clinical', 'AI Scribe Note - History & Physical', 1, 0, 'encounters|notes');
#EndIf

-- v0.4.0: separate History & Physical and SOAP forms (same columns, one table per form directory)
UPDATE `registry` SET `name` = 'AI Scribe Note - History & Physical', `nickname` = 'AI Scribe Note - History & Physical'
    WHERE `directory` = 'elegant_ai_scribe' AND `name` = 'AI Scribe Note';
UPDATE `forms` SET `form_name` = 'AI Scribe Note - History & Physical' WHERE `formdir` = 'elegant_ai_scribe' AND `form_name` = 'AI Scribe Note';

#IfNotTable form_elegant_ai_scribe_soap
CREATE TABLE `form_elegant_ai_scribe_soap` LIKE `form_elegant_ai_scribe`;
#EndIf

#IfNotRow registry directory elegant_ai_scribe_soap
INSERT INTO `registry` (`name`, `state`, `directory`, `sql_run`, `unpackaged`, `date`, `priority`, `category`, `nickname`, `patient_encounter`, `therapy_group_encounter`, `aco_spec`)
VALUES ('AI Scribe Note - SOAP', 1, 'elegant_ai_scribe_soap', 1, 1, NOW(), 0, 'Clinical', 'AI Scribe Note - SOAP', 1, 0, 'encounters|notes');
#EndIf
