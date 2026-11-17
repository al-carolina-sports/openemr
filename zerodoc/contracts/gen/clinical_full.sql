-- ---------------------------------------------------------------------------
-- ZeroDoc clinical schema -- generated from contracts/schema.sql
-- Contract version 1.5.0.  61 tables, all prefixed `zdoc_`.
--
-- IDEMPOTENCY: every CREATE TABLE is wrapped in #IfNotTable / #EndIf; the
-- one added column is wrapped in #IfMissingColumn / #EndIf; every seed row
-- is wrapped in #IfNotRow / #EndIf except where noted at that statement.
-- Directives start in column 0, are case-sensitive, and terminate in #EndIf.
--
-- NO FOREIGN KEY targets a core OpenEMR table. Core is related to by value
-- only: pid, encounter, forms.form_id, users.id -- matched to core's own
-- signed types so joins keep their indexes. FKs BETWEEN zdoc_ tables are
-- declared inline; tables are emitted parent-before-child so no ALTER is
-- needed and no statement depends on another file's order.
--
-- `zdoc_schema_version` and `form_zdoc_encounter` are NOT created here --
-- the scaffold owns both, above this marker.
-- ---------------------------------------------------------------------------

--
-- The scaffold's form table gets one ZeroDoc column: the link to our own
-- encounter extension row. #IfMissingColumn takes EXACTLY two arguments.
--
#IfMissingColumn form_zdoc_encounter encounter_ext_id
ALTER TABLE `form_zdoc_encounter`
  ADD COLUMN `encounter_ext_id` BIGINT UNSIGNED NULL
  COMMENT 'points at zdoc_encounter_ext.id; the only ZeroDoc column on this bridge table',
  ADD KEY `ix_form_zdoc_encounter_ext` (`encounter_ext_id`);
#EndIf

#IfNotTable zdoc_audit_event
CREATE TABLE `zdoc_audit_event` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `occurred_at`     DATETIME(3)     NOT NULL,
  `actor_user_id`   BIGINT              NULL COMMENT 'core users.id; NULL = system',
  `actor_role`      VARCHAR(64)         NULL COMMENT 'role string at time of action',
  `patient_pid`     BIGINT              NULL COMMENT 'core patient id',
  `encounter_id`    BIGINT              NULL COMMENT 'core encounter id',
  `object_table`    VARCHAR(64)     NOT NULL COMMENT 'zdoc_* table name',
  `object_id`       BIGINT UNSIGNED     NULL,
  `action`          ENUM('create','update','delete','apply','undo','review',
                         'attest','attest_void','sign','amend','void',
                         'dismiss_warning','dispatch','code','deny','export'
                        )            NOT NULL,
  `summary`         VARCHAR(255)        NULL COMMENT 'human-readable one-liner',
  `before_json`     JSON                NULL,
  `after_json`      JSON                NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_audit_encounter` (`encounter_id`,`occurred_at`),
  KEY `ix_zdoc_audit_patient`   (`patient_pid`,`occurred_at`),
  KEY `ix_zdoc_audit_object`    (`object_table`,`object_id`),
  KEY `ix_zdoc_audit_actor`     (`actor_user_id`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_application
CREATE TABLE `zdoc_application` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL COMMENT 'core encounter id',
  `application_type` ENUM('template','saved_finding','diagnosis_set',
                          'encounter_plan','order_set','carry_forward',
                          'all_normal','ai_draft'
                         )           NOT NULL,
  `source_id`       BIGINT UNSIGNED     NULL COMMENT 'id of the applied object in its own table',
  `section_key`     VARCHAR(32)         NULL COMMENT 'zdoc_encounter_section.section_key value',
  `applied_by`      BIGINT          NOT NULL COMMENT 'core users.id',
  `applied_at`      DATETIME(3)     NOT NULL,
  `preview_json`    JSON                NULL COMMENT 'the previewApplication payload that was shown and committed',
  `skipped_json`    JSON                NULL COMMENT 'templates skipped by sex/age filter, with reasons (06 §1.5)',
  `is_undone`       TINYINT(1)      NOT NULL DEFAULT 0,
  `undone_by`       BIGINT              NULL,
  `undone_at`       DATETIME(3)         NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_application_encounter` (`encounter_id`,`applied_at`),
  KEY `ix_zdoc_application_source`    (`application_type`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_usage_stat
CREATE TABLE `zdoc_usage_stat` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         BIGINT          NOT NULL COMMENT 'core users.id; the ranking is per-user',
  `object_type`     ENUM('template','saved_finding','diagnosis_set','plan_option',
                         'plan_group','order_set','order_type','procedure_type',
                         'macro','diagnosis_code','encounter_plan'
                        )            NOT NULL,
  `object_id`       BIGINT UNSIGNED     NULL COMMENT 'NULL when object_key carries the identity',
  `object_key`      VARCHAR(64)         NULL COMMENT 'for code-identified objects, e.g. an ICD-10 code',
  `scope_key`       VARCHAR(64)         NULL COMMENT 'optional narrowing, e.g. plan_group_id for plan_option ranking',
  `use_count`       INT UNSIGNED    NOT NULL DEFAULT 0,
  `last_used_at`    DATETIME            NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_usage_stat` (`user_id`,`object_type`,`object_id`,`object_key`,`scope_key`),
  KEY `ix_zdoc_usage_stat_rank` (`user_id`,`object_type`,`scope_key`,`use_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_warning_dismissal
CREATE TABLE `zdoc_warning_dismissal` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `warning_source`  ENUM('consistency_rule','section_policy','order_set_validation',
                         'required_note_element','bundling_edit','other'
                        )            NOT NULL,
  `warning_ref`     VARCHAR(128)    NOT NULL COMMENT 'rule id, element key, or edit pair id',
  `dismissed_by`    BIGINT          NOT NULL,
  `dismissed_at`    DATETIME(3)     NOT NULL,
  `reason`          TEXT            NOT NULL COMMENT 'mandatory; no blank dismissals',
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_warning_dismissal_enc` (`encounter_id`,`warning_source`,`warning_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_vocabulary
CREATE TABLE `zdoc_vocabulary` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `vocab_key`       VARCHAR(64)     NOT NULL COMMENT 'stable machine key, e.g. response_to_treatment',
  `name`            VARCHAR(128)    NOT NULL,
  `description`     TEXT                NULL,
  `value_kind`      ENUM('nominal','ordinal','integer_range')
                                    NOT NULL DEFAULT 'nominal'
                      COMMENT 'integer_range: items carry numeric_value and label; e.g. severity_scale 0..10',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_vocabulary_key` (`vocab_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_vocabulary_item
CREATE TABLE `zdoc_vocabulary_item` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `vocabulary_id`   INT UNSIGNED    NOT NULL,
  `item_key`        VARCHAR(64)     NOT NULL COMMENT 'stable machine key within the vocabulary',
  `label`           VARCHAR(255)    NOT NULL COMMENT 'rendered text',
  `polarity`        ENUM('positive','negative','neutral')
                                    NOT NULL DEFAULT 'neutral'
                      COMMENT 'STRUCTURAL role, declared and never inferred from wording. positive = asserted presence; negative = asserted ABSENCE ("no swelling"); neutral = descriptor, including a NORMAL descriptor ("normal alignment"). See CONTRACTS.md §3.8',
  `is_normal`       TINYINT(1)      NOT NULL DEFAULT 0
                      COMMENT 'CLINICAL normality, independent of polarity. Drives colour and the Normal / All Normal controls. A normal descriptor is polarity=neutral AND is_normal=1',
  `numeric_value`   DECIMAL(10,3)       NULL COMMENT 'set for ordinal/integer_range vocabularies',
  `sort_order`      INT             NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_vocabulary_item` (`vocabulary_id`,`item_key`),
  KEY `ix_zdoc_vocabulary_item_sort` (`vocabulary_id`,`sort_order`),
  CONSTRAINT `fk_zdoc_vocab_item_vocab`
    FOREIGN KEY (`vocabulary_id`) REFERENCES `zdoc_vocabulary` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_template
CREATE TABLE `zdoc_template` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(32)         NULL COMMENT 'optional catalog number; UNIQUE when present',
  `name`            VARCHAR(191)    NOT NULL,
  `specialty`       VARCHAR(64)         NULL,
  `body_region`     VARCHAR(64)         NULL,
  `section_key`     ENUM('reason','chief_complaint','vitals','allergies','medications',
                         'problems','family_hx','social_hx','surgical_hx',
                         'past_medical_hx','screening','hpi','ros','pe','imaging',
                         'assessment_plan','orders','procedures','time_spent',
                         'patient_instructions','discussion_notes','follow_up'
                        )            NOT NULL
                      COMMENT 'which section this template may be applied to',
  `owner_scope`     ENUM('system','practice','provider')
                                    NOT NULL DEFAULT 'practice',
  `owner_user_id`   BIGINT              NULL COMMENT 'required when owner_scope = provider',
  `applies_to_sex`  ENUM('any','male','female')
                                    NOT NULL DEFAULT 'any'
                      COMMENT 'sex filter applied at application time (06 §1.5)',
  `applies_to_age_min` TINYINT UNSIGNED NULL COMMENT 'inclusive, years; NULL = no lower bound',
  `applies_to_age_max` TINYINT UNSIGNED NULL COMMENT 'inclusive, years; NULL = no upper bound',
  `default_render_mode` ENUM('list','paragraph') NOT NULL DEFAULT 'list',
  `default_reported_by` ENUM('patient','family','caregiver','interpreter','staff','provider','records')
                                    NOT NULL DEFAULT 'patient'
                      COMMENT '06 §3.3 Reported by selector, default patient',
  `is_procedure_note` TINYINT(1)    NOT NULL DEFAULT 0
                      COMMENT '13 §13.3.2 — procedure notes use this same engine',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1
                      COMMENT 'retirement preserves historical notes (06 §3.8)',
  `retired_at`      DATETIME            NULL,
  `last_used_at`    DATETIME            NULL COMMENT 'unused-in-N-months report (06 §3.8)',
  `created_by`      BIGINT              NULL,
  `created_at`      DATETIME        NOT NULL,
  `updated_by`      BIGINT              NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_template_code` (`code`),
  KEY `ix_zdoc_template_section` (`section_key`,`is_active`,`name`),
  KEY `ix_zdoc_template_owner`   (`owner_scope`,`owner_user_id`),
  KEY `ix_zdoc_template_name`    (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_template_group
CREATE TABLE `zdoc_template_group` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id`     BIGINT UNSIGNED NOT NULL,
  `group_key`       VARCHAR(64)     NOT NULL COMMENT 'stable key within the template, e.g. lumbar_spine',
  `label`           VARCHAR(191)    NOT NULL
                      COMMENT 'the outer heading, e.g. "Lumbar Spine". Rendered as "Lumbar Spine: <element prefix>: <values>"',
  `sort_order`      INT             NOT NULL DEFAULT 0
                      COMMENT 'group order in the rendered note; independent of element sort_order',
  `render_mode`     ENUM('inherit','list','paragraph')
                                    NOT NULL DEFAULT 'inherit'
                      COMMENT 'inherit = use the section render_mode. Lets one region render as prose inside a list-mode section',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_template_group_key` (`template_id`,`group_key`),
  KEY `ix_zdoc_template_group_sort` (`template_id`,`sort_order`),
  CONSTRAINT `fk_zdoc_tpl_group_template`
    FOREIGN KEY (`template_id`) REFERENCES `zdoc_template` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_template_element
CREATE TABLE `zdoc_template_element` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id`     BIGINT UNSIGNED NOT NULL,
  `template_group_id` BIGINT UNSIGNED   NULL
                      COMMENT 'the outer region heading this element sits under. NULL = ungrouped, rendered at template level (06 §5.6)',
  `element_key`     VARCHAR(64)     NOT NULL COMMENT 'stable key within the template, e.g. severity',
  `label`           VARCHAR(191)    NOT NULL COMMENT 'e.g. "Severity"',
  `render_prefix`   VARCHAR(191)        NULL COMMENT 'e.g. "Sensation on the Right:" (06 §1.4)',
  `render_stem`     VARCHAR(191)        NULL
                      COMMENT 'shared stem used by paragraph-mode stem collapsing (06 §5.3), e.g. "tenderness of"',
  `sort_order`      INT             NOT NULL DEFAULT 0,
  `allows_multi`    TINYINT(1)      NOT NULL DEFAULT 1,
  `vocabulary_id`   INT UNSIGNED        NULL
                      COMMENT 'when set, options derive from the shared vocabulary (06 Appendix B MUST)',
  `laterality_role` ENUM('none','right','left','bilateral','parameter')
                                    NOT NULL DEFAULT 'none'
                      COMMENT 'right/left mark paired elements for the symmetry linter (06 §3.6); parameter = side chosen at entry',
  `pair_key`        VARCHAR(64)         NULL
                      COMMENT 'two elements sharing a pair_key with laterality_role right/left MUST have identical option sets (06 §3.6, test 8)',
  `carries_forward` TINYINT(1)      NOT NULL DEFAULT 1
                      COMMENT '08 §12.2 — FALSE for current severity, pain score, time-indexed findings',
  `allows_note`     TINYINT(1)      NOT NULL DEFAULT 1 COMMENT 'per-element NOTE row (06 §3.3)',
  `is_required`     TINYINT(1)      NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_template_element_key` (`template_id`,`element_key`),
  KEY `ix_zdoc_template_element_sort` (`template_id`,`sort_order`),
  KEY `ix_zdoc_template_element_pair` (`template_id`,`pair_key`,`laterality_role`),
  KEY `ix_zdoc_template_element_vocab` (`vocabulary_id`),
  KEY `ix_zdoc_template_element_group` (`template_group_id`,`sort_order`),
  CONSTRAINT `fk_zdoc_tpl_element_template`
    FOREIGN KEY (`template_id`) REFERENCES `zdoc_template` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_tpl_element_group`
    FOREIGN KEY (`template_group_id`) REFERENCES `zdoc_template_group` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_tpl_element_vocab`
    FOREIGN KEY (`vocabulary_id`) REFERENCES `zdoc_vocabulary` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_template_option
CREATE TABLE `zdoc_template_option` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `element_id`      BIGINT UNSIGNED NOT NULL,
  `option_key`      VARCHAR(64)     NOT NULL COMMENT 'stable key within the element',
  `label`           VARCHAR(255)    NOT NULL COMMENT 'e.g. "worst pain __/10"',
  `polarity`        ENUM('positive','negative','neutral')
                                    NOT NULL DEFAULT 'neutral'
                      COMMENT 'STRUCTURAL role. Drives paragraph grouping and STEM COLLAPSING. positive = asserted presence ("tenderness"); negative = asserted ABSENCE ("no swelling"); neutral = descriptor, INCLUDING a normal descriptor ("normal alignment"). Tagging a descriptor negative makes it collapse into the preceding negated clause and renders wrongly. DECLARED, never inferred from wording. Authoring rule: CONTRACTS.md §3.8',
  `sort_order`      INT             NOT NULL DEFAULT 0
                      COMMENT 'authoritative render order (06 §5.5)',
  `is_normal`       TINYINT(1)      NOT NULL DEFAULT 0
                      COMMENT 'CLINICAL normality, independent of polarity. Selected by the element Normal / All Normal controls (06 §3.3) AND combined with polarity to derive colour (06 §5.4). A normal descriptor is polarity=neutral AND is_normal=1',
  `vocabulary_item_id` INT UNSIGNED     NULL
                      COMMENT 'set when this option is a vocabulary item rather than a free-standing chip',
  `value_slots`     JSON                NULL
                      COMMENT 'e.g. [{"type":"numeric","max":10,"suffix":"/10"}] (06 §1.4)',
  `excludes_option_ids` JSON            NULL
                      COMMENT 'mutual exclusion within the element (06 §1.4)',
  `allows_laterality` TINYINT(1)    NOT NULL DEFAULT 0,
  `allows_anatomic_level` TINYINT(1) NOT NULL DEFAULT 0,
  `anatomic_level_vocabulary_id` INT UNSIGNED NULL
                      COMMENT 'e.g. lumbar_levels or cervical_levels (06 §3.5)',
  `is_free_text`    TINYINT(1)      NOT NULL DEFAULT 0
                      COMMENT 'option captures value_text instead of a fixed label',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_template_option_key` (`element_id`,`option_key`),
  KEY `ix_zdoc_template_option_sort` (`element_id`,`sort_order`),
  KEY `ix_zdoc_template_option_vocab_item` (`vocabulary_item_id`),
  KEY `ix_zdoc_template_option_level_vocab` (`anatomic_level_vocabulary_id`),
  CONSTRAINT `fk_zdoc_tpl_option_element`
    FOREIGN KEY (`element_id`) REFERENCES `zdoc_template_element` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_tpl_option_vocab_item`
    FOREIGN KEY (`vocabulary_item_id`) REFERENCES `zdoc_vocabulary_item` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_tpl_option_level_vocab`
    FOREIGN KEY (`anatomic_level_vocabulary_id`) REFERENCES `zdoc_vocabulary` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_saved_finding
CREATE TABLE `zdoc_saved_finding` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(32)         NULL COMMENT 'optional catalog number; UNIQUE when present',
  `name`            VARCHAR(191)    NOT NULL
                      COMMENT 'laterality MUST NOT appear in the name (06 §3.4)',
  `section_key`     ENUM('reason','chief_complaint','vitals','allergies','medications',
                         'problems','family_hx','social_hx','surgical_hx',
                         'past_medical_hx','screening','hpi','ros','pe','imaging',
                         'assessment_plan','orders','procedures','time_spent',
                         'patient_instructions','discussion_notes','follow_up'
                        )            NOT NULL,
  `laterality`      ENUM('right','left','bilateral','n/a','parameter')
                                    NOT NULL DEFAULT 'n/a'
                      COMMENT 'parameter = side chosen when applied; makes missing mirrors structurally impossible (06 §3.4)',
  `specialty`       VARCHAR(64)         NULL,
  `body_region`     VARCHAR(64)         NULL,
  `owner_scope`     ENUM('system','practice','provider')
                                    NOT NULL DEFAULT 'practice',
  `owner_user_id`   BIGINT              NULL,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `retired_at`      DATETIME            NULL,
  `last_used_at`    DATETIME            NULL,
  `created_by`      BIGINT              NULL,
  `created_at`      DATETIME        NOT NULL,
  `updated_by`      BIGINT              NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_saved_finding_code` (`code`),
  UNIQUE KEY `uq_zdoc_saved_finding_identity` (`section_key`,`name`,`laterality`),
  KEY `ix_zdoc_saved_finding_section` (`section_key`,`is_active`,`name`),
  KEY `ix_zdoc_saved_finding_owner`   (`owner_scope`,`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_saved_finding_template
CREATE TABLE `zdoc_saved_finding_template` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `saved_finding_id` BIGINT UNSIGNED NOT NULL,
  `template_id`     BIGINT UNSIGNED NOT NULL,
  `sort_order`      INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_sf_template` (`saved_finding_id`,`template_id`),
  KEY `ix_zdoc_sf_template_sort` (`saved_finding_id`,`sort_order`),
  KEY `ix_zdoc_sf_template_tpl`  (`template_id`),
  CONSTRAINT `fk_zdoc_sf_template_sf`
    FOREIGN KEY (`saved_finding_id`) REFERENCES `zdoc_saved_finding` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_sf_template_tpl`
    FOREIGN KEY (`template_id`) REFERENCES `zdoc_template` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_saved_finding_preset
CREATE TABLE `zdoc_saved_finding_preset` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `saved_finding_id` BIGINT UNSIGNED NOT NULL,
  `element_id`      BIGINT UNSIGNED NOT NULL,
  `option_id`       BIGINT UNSIGNED     NULL COMMENT 'NULL when the preset only supplies a value',
  `value_text`      TEXT                NULL,
  `value_numeric`   DECIMAL(12,4)       NULL,
  `value_unit`      VARCHAR(32)         NULL,
  `laterality`      ENUM('right','left','bilateral','n/a','inherit')
                                    NOT NULL DEFAULT 'inherit'
                      COMMENT 'inherit = take the saved finding''s laterality / applied parameter',
  `anatomic_level`  VARCHAR(16)         NULL COMMENT 'e.g. L4, S1, T12',
  `sort_order`      INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_sf_preset_sf`      (`saved_finding_id`,`sort_order`),
  KEY `ix_zdoc_sf_preset_element` (`element_id`),
  KEY `ix_zdoc_sf_preset_option`  (`option_id`),
  CONSTRAINT `fk_zdoc_sf_preset_sf`
    FOREIGN KEY (`saved_finding_id`) REFERENCES `zdoc_saved_finding` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_sf_preset_element`
    FOREIGN KEY (`element_id`) REFERENCES `zdoc_template_element` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_sf_preset_option`
    FOREIGN KEY (`option_id`) REFERENCES `zdoc_template_option` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_macro
CREATE TABLE `zdoc_macro` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(64)     NOT NULL
                      COMMENT 'the inline trigger; `.name` expands it',
  `scope`           ENUM('system','practice','personal')
                                    NOT NULL DEFAULT 'practice',
  `owner_user_id`   BIGINT              NULL
                      COMMENT 'required when scope = personal; NULL otherwise',
  `body`            TEXT            NOT NULL
                      COMMENT 'may contain tab stops and @VARIABLES@ and may nest other macros',
  `hotkey`          VARCHAR(16)         NULL COMMENT 'Ctrl+1 .. Ctrl+9',
  `description`     VARCHAR(255)        NULL,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `last_used_at`    DATETIME            NULL,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_macro_scope_name` (`scope`,`owner_user_id`,`name`)
    COMMENT 'name collisions ACROSS scopes are legal: personal > practice > system (08 §12.5.2)',
  UNIQUE KEY `uq_zdoc_macro_hotkey` (`owner_user_id`,`hotkey`),
  KEY `ix_zdoc_macro_name` (`name`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_reason_for_visit
CREATE TABLE `zdoc_reason_for_visit` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(32)         NULL,
  `name`            VARCHAR(191)    NOT NULL,
  `body_region`     VARCHAR(64)         NULL,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `sort_order`      INT             NOT NULL DEFAULT 0,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_rfv_code` (`code`),
  UNIQUE KEY `uq_zdoc_rfv_name` (`name`)
    COMMENT 'new-vs-established is encounter_type, NEVER a duplicate reason (06 §2.1)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_encounter_plan
CREATE TABLE `zdoc_encounter_plan` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reason_for_visit_id` BIGINT UNSIGNED NULL
                      COMMENT 'NULL = selectable independently of a reason',
  `code`            VARCHAR(32)         NULL,
  `name`            VARCHAR(191)    NOT NULL,
  `encounter_type`  ENUM('any','new','follow_up','procedure','telehealth')
                                    NOT NULL DEFAULT 'any',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_encounter_plan_code` (`code`),
  KEY `ix_zdoc_encounter_plan_rfv` (`reason_for_visit_id`,`is_active`),
  CONSTRAINT `fk_zdoc_encounter_plan_rfv`
    FOREIGN KEY (`reason_for_visit_id`) REFERENCES `zdoc_reason_for_visit` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_encounter_plan_item
CREATE TABLE `zdoc_encounter_plan_item` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_plan_id` BIGINT UNSIGNED NOT NULL,
  `item_type`       ENUM('hpi_template','hpi_saved_finding','ros_saved_finding',
                         'pe_template','pe_saved_finding','diagnosis_set',
                         'order_set','education_set'
                        )            NOT NULL,
  `reference_id`    BIGINT UNSIGNED NOT NULL
                      COMMENT 'polymorphic; target table is implied by item_type. No FK — see OPEN-QUESTIONS Q12',
  `sort_order`      INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_encounter_plan_item` (`encounter_plan_id`,`sort_order`),
  KEY `ix_zdoc_encounter_plan_item_ref` (`item_type`,`reference_id`),
  CONSTRAINT `fk_zdoc_encounter_plan_item_plan`
    FOREIGN KEY (`encounter_plan_id`) REFERENCES `zdoc_encounter_plan` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_encounter_ext
CREATE TABLE `zdoc_encounter_ext` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL
                      COMMENT 'core encounter id. NO FK (see OPEN-QUESTIONS Q1)',
  `patient_pid`     BIGINT          NOT NULL COMMENT 'core patient id, denormalised for query',
  `core_form_id`    BIGINT              NULL
                      COMMENT 'core forms.id for the single zdoc_encounter form row. A HANDLE for core navigation, not a store. NO FK. See the section header above',
  `facility_id`     INT                 NULL COMMENT 'core facility id',
  `provider_user_id` BIGINT             NULL COMMENT 'attributed clinician, core users.id',
  `encounter_type`  ENUM('new','follow_up','procedure','telehealth')
                                    NOT NULL DEFAULT 'follow_up',
  `reason_for_visit_id` BIGINT UNSIGNED NULL,
  `chief_complaint` TEXT                NULL,
  `service_date`    DATE            NOT NULL,
  `service_time`    TIME                NULL COMMENT 'supports has_content_before_service_time reporting (06 §6.6)',

  -- four-state machine, 06 §6.1. There is no prepped / checked_in /
  -- with_provider. Four values, not six stages.
  `zdoc_status`      ENUM('open','signed','amended','voided')
                                    NOT NULL DEFAULT 'open',
  `signed_at`       DATETIME(3)         NULL,
  `signed_by`       BIGINT              NULL,
  `voided_at`       DATETIME(3)         NULL,
  `voided_by`       BIGINT              NULL,
  `void_reason`     TEXT                NULL,
  `amends_encounter_id` BIGINT          NULL
                      COMMENT 'set on an addendum encounter; points at the original (06 §6.5)',
  `cosign_required` TINYINT(1)      NOT NULL DEFAULT 0,
  `cosigned_by`     BIGINT              NULL,
  `cosigned_at`     DATETIME(3)         NULL,

  -- assignment is ROUTING, NOT STATE (07 §11.2.2). It locks nothing and
  -- its absence blocks nothing.
  `assigned_to`     BIGINT              NULL,
  `assigned_by`     BIGINT              NULL,
  `assigned_at`     DATETIME(3)         NULL,
  `assignment_note` TEXT                NULL,
  `returned_at`     DATETIME(3)         NULL,
  `return_reason`   TEXT                NULL,

  -- prep notes kept as plain internal notes; NOT a separate mode (06 §2.2)
  `note_to_intake`  TEXT                NULL,
  `note_to_exam`    TEXT                NULL,

  -- modifier-25 determination, made by the provider at signing (13 §13.6.1)
  `em_with_procedure_same_day` TINYINT(1) NULL
                      COMMENT 'NULL = question not applicable / not yet asked',
  `modifier_25_asserted` TINYINT(1)       NULL
                      COMMENT 'provider answer: E/M significant and separately identifiable',
  `modifier_25_rationale` TEXT            NULL,
  `modifier_25_answered_by` BIGINT          NULL,
  `modifier_25_answered_at` DATETIME(3)     NULL,

  `rendered_note_text` LONGTEXT         NULL
                      COMMENT 'immutable snapshot frozen at signing (06 §6.5)',
  `rendered_note_hash` CHAR(64)         NULL,

  `version`         INT UNSIGNED    NOT NULL DEFAULT 1
                      COMMENT 'optimistic concurrency at encounter level',
  `created_by`      BIGINT              NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  `updated_by`      BIGINT              NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_encounter_ext_encounter` (`encounter_id`),
  KEY `ix_zdoc_encounter_ext_patient`  (`patient_pid`,`service_date`),
  KEY `ix_zdoc_encounter_ext_status`   (`zdoc_status`,`service_date`),
  KEY `ix_zdoc_encounter_ext_assigned` (`assigned_to`,`zdoc_status`),
  KEY `ix_zdoc_encounter_ext_provider` (`provider_user_id`,`service_date`),
  KEY `ix_zdoc_encounter_ext_amends`   (`amends_encounter_id`),
  CONSTRAINT `fk_zdoc_encounter_ext_rfv`
    FOREIGN KEY (`reason_for_visit_id`) REFERENCES `zdoc_reason_for_visit` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_encounter_section
CREATE TABLE `zdoc_encounter_section` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL COMMENT 'core encounter id',
  `section_key`     ENUM('reason','chief_complaint','vitals','allergies','medications',
                         'problems','family_hx','social_hx','surgical_hx',
                         'past_medical_hx','screening','hpi','ros','pe','imaging',
                         'assessment_plan','orders','procedures','time_spent',
                         'patient_instructions','discussion_notes','follow_up'
                        )            NOT NULL,
  `render_mode`     ENUM('list','paragraph')
                                    NOT NULL DEFAULT 'list'
                      COMMENT 'defaults: hpi=list, ros=paragraph, pe=list (06 §5.1)',
  `is_complete`     TINYINT(1)      NOT NULL DEFAULT 0
                      COMMENT 'ADVISORY CHECKMARK ONLY, NEVER A GATE (06 §1.2)',
  `note_text`       TEXT                NULL COMMENT 'free-text row; every section has one',
  `note_visibility` ENUM('everyone','internal')
                                    NOT NULL DEFAULT 'everyone'
                      COMMENT 'internal notes excluded from patient-facing and ROI renderings, and the exclusion is logged (06 §6.4)',
  `reported_by`     ENUM('patient','family','caregiver','interpreter','staff','provider','records')
                                    NOT NULL DEFAULT 'patient'
                      COMMENT 'set from the template header Reported by selector (06 §3.3); renders in the section heading',

  -- section heading display (06 §5.6: "HPI — Pain Management L-spine (reported by patient)")
  -- Both are SNAPSHOTS taken when a template is applied, so a heading
  -- renders identically years later even if the template is renamed or
  -- retired. Not derived at render time.
  `applied_template_ids` JSON           NULL
                      COMMENT 'ordered list of zdoc_template.id applied to this section, in application order',
  `applied_template_label` VARCHAR(255) NULL
                      COMMENT 'the heading suffix as it should render, e.g. "Pain Management L-spine" or "CSS Lumbar Spine + Wrist" when a section carries more than one template',

  -- carry-forward (08 §12.2)
  `carried_from_encounter_id` BIGINT          NULL,
  `carried_at`      DATETIME(3)         NULL,

  -- review, history-type sections (07 §11.2.3, §11.4)
  `reviewed_by`     BIGINT              NULL,
  `reviewed_at`     DATETIME(3)         NULL,
  `review_action`   ENUM('accepted','supplemented','corrected')
                                        NULL COMMENT 'NULL = not yet reviewed',

  -- attestation, PE and A/P only (07 §11.2.3, §11.5)
  `attested_by`     BIGINT              NULL,
  `attested_at`     DATETIME(3)         NULL,
  `attested_count`  INT UNSIGNED        NULL
                      COMMENT 'number of pre_populated findings adopted by the attestation',
  `attestation_text` TEXT               NULL
                      COMMENT 'the practice-configured wording in force at attestation time (07 §11.7)',
  `attestation_voided_at` DATETIME(3)   NULL
                      COMMENT 'set when pre_populated content was added after attestation (07 §11.10)',
  `attestation_void_reason` VARCHAR(255) NULL,

  `version`         INT UNSIGNED    NOT NULL DEFAULT 1
                      COMMENT 'section-level optimistic concurrency (07 §11.10). A save against a stale version is REJECTED, never merged silently',
  `created_by`      BIGINT              NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  `updated_by`      BIGINT              NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_encounter_section` (`encounter_id`,`section_key`),
  KEY `ix_zdoc_encounter_section_review` (`encounter_id`,`reviewed_at`),
  KEY `ix_zdoc_encounter_section_attest` (`encounter_id`,`attested_at`),
  KEY `ix_zdoc_encounter_section_carried` (`carried_from_encounter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_section_attribution
CREATE TABLE `zdoc_section_attribution` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_section_id` BIGINT UNSIGNED NOT NULL,
  `user_id`         BIGINT          NOT NULL COMMENT 'core users.id',
  `role_at_time`    VARCHAR(64)     NOT NULL
                      COMMENT 'role string captured at first write, e.g. "CMA", "MD". Snapshot, not a live lookup',
  `credential_at_time` VARCHAR(64)      NULL
                      COMMENT 'display credential for the documentation block, e.g. "Maria Reyes, CMA"',
  `first_write_at`  DATETIME(3)     NOT NULL,
  `last_write_at`   DATETIME(3)     NOT NULL,
  `write_count`     INT UNSIGNED    NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_section_attribution` (`encounter_section_id`,`user_id`),
  KEY `ix_zdoc_section_attribution_user` (`user_id`,`last_write_at`),
  CONSTRAINT `fk_zdoc_section_attribution_section`
    FOREIGN KEY (`encounter_section_id`) REFERENCES `zdoc_encounter_section` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_section_policy
CREATE TABLE `zdoc_section_policy` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `section_key`     ENUM('reason','chief_complaint','vitals','allergies','medications',
                         'problems','family_hx','social_hx','surgical_hx',
                         'past_medical_hx','screening','hpi','ros','pe','imaging',
                         'assessment_plan','orders','procedures','time_spent',
                         'patient_instructions','discussion_notes','follow_up'
                        )            NOT NULL,
  `ma_may_prepopulate` TINYINT(1)   NOT NULL DEFAULT 1,
  `ma_may_attest`   TINYINT(1)      NOT NULL DEFAULT 0,
  `requires_provider_review` TINYINT(1) NOT NULL DEFAULT 0,
  `requires_provider_attestation` TINYINT(1) NOT NULL DEFAULT 0,
  `on_violation`    ENUM('warn','block')
                                    NOT NULL DEFAULT 'block',
  `attestation_text` TEXT               NULL
                      COMMENT 'practice-configurable wording; compliance artifact owned by the practice (07 §11.7)',
  `updated_by`      BIGINT              NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_section_policy_key` (`section_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_problem
CREATE TABLE `zdoc_problem` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_pid`     BIGINT          NOT NULL,
  `core_list_id`    BIGINT              NULL
                      COMMENT 'optional link to a core lists row, read-only. NO FK',
  `diagnosis_code`  VARCHAR(16)     NOT NULL COMMENT 'ICD-10-CM',
  `code_system`     ENUM('ICD10','ICD9','SNOMED','other')
                                    NOT NULL DEFAULT 'ICD10',
  `display_name`    VARCHAR(255)    NOT NULL,
  `body_system`     VARCHAR(64)         NULL
                      COMMENT 'auto-grouping, e.g. "Cardiac and Vasculature" (06 §2.8)',
  `laterality`      ENUM('right','left','bilateral','n/a')
                                    NOT NULL DEFAULT 'n/a'
                      COMMENT 'STRUCTURED FIELD, never text appended to the name (06 §2.8)',
  `anatomic_level`  VARCHAR(16)         NULL,
  `status`          ENUM('active','resolved','inactive','entered_in_error')
                                    NOT NULL DEFAULT 'active',
  `onset_date`      DATE                NULL,
  `resolved_date`   DATE                NULL,
  `source`          ENUM('provider','patient','external_feed','derived')
                                    NOT NULL DEFAULT 'provider'
                      COMMENT 'derived = offered by a rule, e.g. BMI 30.1 -> E66.811 (06 §2.4)',
  `source_name`     VARCHAR(191)        NULL,
  `created_by`      BIGINT              NULL,
  `created_at`      DATETIME        NOT NULL,
  `updated_by`      BIGINT              NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_problem_patient` (`patient_pid`,`status`),
  KEY `ix_zdoc_problem_code`    (`diagnosis_code`),
  KEY `ix_zdoc_problem_system`  (`patient_pid`,`body_system`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_problem_thread
CREATE TABLE `zdoc_problem_thread` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_pid`     BIGINT          NOT NULL,
  `problem_id`      BIGINT UNSIGNED     NULL
                      COMMENT 'nullable so a thread can open before the problem is coded. See OPEN-QUESTIONS Q8',
  `diagnosis_code`  VARCHAR(16)         NULL COMMENT 'current working code',
  `display_name`    VARCHAR(255)    NOT NULL COMMENT 'thread header, e.g. "Lumbar radiculopathy, right"',
  `laterality`      ENUM('right','left','bilateral','n/a')
                                    NOT NULL DEFAULT 'n/a',
  `anatomic_level`  VARCHAR(16)         NULL,
  `opened_encounter_id` BIGINT          NOT NULL,
  `closed_encounter_id` BIGINT              NULL,
  `status`          ENUM('active','resolved','inactive')
                                    NOT NULL DEFAULT 'active',
  `carries_forward` TINYINT(1)      NOT NULL DEFAULT 1
                      COMMENT 'thread-level carry switch; element-level switch also applies (13 §13.9 WP-09)',
  `sort_order`      INT             NOT NULL DEFAULT 0
                      COMMENT 'threads render in A/P order (13 §13.2.2)',
  `created_by`      BIGINT              NULL,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_problem_thread_patient` (`patient_pid`,`status`),
  KEY `ix_zdoc_problem_thread_problem` (`problem_id`),
  KEY `ix_zdoc_problem_thread_opened`  (`opened_encounter_id`),
  CONSTRAINT `fk_zdoc_problem_thread_problem`
    FOREIGN KEY (`problem_id`) REFERENCES `zdoc_problem` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_thread_entry
CREATE TABLE `zdoc_thread_entry` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_id`       BIGINT UNSIGNED NOT NULL,
  `encounter_id`    BIGINT          NOT NULL,
  `encounter_section_id` BIGINT UNSIGNED NOT NULL
                      COMMENT 'the HPI (or other) section that contains this entry',
  `template_id`     BIGINT UNSIGNED     NULL,
  `is_interval_update` TINYINT(1)   NOT NULL DEFAULT 0
                      COMMENT 'what changed since last visit. ALWAYS NEW, NEVER CARRIED (13 §13.9 WP-09)',
  `interval_text`   TEXT                NULL,
  `sort_order`      INT             NOT NULL DEFAULT 0,
  `created_by`      BIGINT          NOT NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_thread_entry_thread`    (`thread_id`,`encounter_id`),
  KEY `ix_zdoc_thread_entry_encounter` (`encounter_id`,`sort_order`),
  KEY `ix_zdoc_thread_entry_section`   (`encounter_section_id`),
  KEY `ix_zdoc_thread_entry_template`  (`template_id`),
  CONSTRAINT `fk_zdoc_thread_entry_thread`
    FOREIGN KEY (`thread_id`) REFERENCES `zdoc_problem_thread` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_thread_entry_section`
    FOREIGN KEY (`encounter_section_id`) REFERENCES `zdoc_encounter_section` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_thread_entry_template`
    FOREIGN KEY (`template_id`) REFERENCES `zdoc_template` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_finding
CREATE TABLE `zdoc_finding` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_section_id` BIGINT UNSIGNED NOT NULL
                      COMMENT 'always set, even for thread findings, so section-scoped queries stay simple',
  `thread_entry_id` BIGINT UNSIGNED     NULL
                      COMMENT 'set for HPI thread findings (13 §13.2.1)',
  `template_id`     BIGINT UNSIGNED     NULL COMMENT 'which template emitted it',
  `element_id`      BIGINT UNSIGNED NOT NULL,
  `option_id`       BIGINT UNSIGNED     NULL COMMENT 'NULL for pure free-entry / value-only findings',
  `value_text`      TEXT                NULL COMMENT 'free-entry options',
  `value_numeric`   DECIMAL(12,4)       NULL COMMENT '"__/10", "__ %" slots',
  `value_unit`      VARCHAR(32)         NULL,
  `laterality`      ENUM('right','left','bilateral','n/a')
                                    NOT NULL DEFAULT 'n/a',
  `anatomic_level`  VARCHAR(16)         NULL COMMENT 'e.g. L4, S1, T12 (06 §3.5)',
  `polarity`        ENUM('positive','negative','neutral')
                                    NOT NULL DEFAULT 'neutral'
                      COMMENT 'COPIED from zdoc_template_option.polarity at creation; NEVER guessed from wording (06 §1.3). Structural role, per CONTRACTS.md §3.8',
  `is_normal`       TINYINT(1)      NOT NULL DEFAULT 0
                      COMMENT 'copied from zdoc_template_option.is_normal at creation. Clinical normality, for colour; see CONTRACTS.md §3.8',
  `sort_order`      INT             NOT NULL DEFAULT 0,

  -- provenance (07 §11.2.1). `ai_generated` is declared now so WP-14
  -- needs no ALTER later; it is NEVER auto-attested (09 WP-14).
  `provenance`      ENUM('pre_populated','attested','ai_generated')
                                    NOT NULL DEFAULT 'pre_populated',
  `placed_by`       BIGINT          NOT NULL COMMENT 'who selected the template / typed the value',
  `placed_at`       DATETIME(3)     NOT NULL,
  `attested_by`     BIGINT              NULL,
  `attested_at`     DATETIME(3)         NULL,
  `modified_from`   TEXT                NULL COMMENT 'prior rendered value, if the provider changed it',

  -- carry-forward provenance (08 §12.2)
  `source_encounter_id` BIGINT          NULL
                      COMMENT 'set when carried forward; drives "Carried from 11/02/2026 (13 days)"',
  `source_finding_id`   BIGINT UNSIGNED NULL,

  `application_id`  BIGINT UNSIGNED     NULL
                      COMMENT 'the zdoc_application batch this finding belongs to; backs atomic undo',
  `reported_by`     ENUM('patient','family','caregiver','interpreter','staff','provider','records')
                                        NULL COMMENT 'NULL = inherit the section value',
  `note_text`       TEXT                NULL COMMENT 'per-finding note, distinct from the element NOTE row',
  `created_at`      DATETIME(3)     NOT NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_finding_section`    (`encounter_section_id`,`sort_order`),
  KEY `ix_zdoc_finding_thread`     (`thread_entry_id`,`sort_order`),
  KEY `ix_zdoc_finding_element`    (`element_id`),
  KEY `ix_zdoc_finding_option`     (`option_id`),
  KEY `ix_zdoc_finding_template`   (`template_id`),
  KEY `ix_zdoc_finding_provenance` (`encounter_section_id`,`provenance`),
  KEY `ix_zdoc_finding_application` (`application_id`),
  KEY `ix_zdoc_finding_source`     (`source_encounter_id`),
  CONSTRAINT `fk_zdoc_finding_section`
    FOREIGN KEY (`encounter_section_id`) REFERENCES `zdoc_encounter_section` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_finding_thread_entry`
    FOREIGN KEY (`thread_entry_id`) REFERENCES `zdoc_thread_entry` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_finding_element`
    FOREIGN KEY (`element_id`) REFERENCES `zdoc_template_element` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_finding_option`
    FOREIGN KEY (`option_id`) REFERENCES `zdoc_template_option` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_finding_template`
    FOREIGN KEY (`template_id`) REFERENCES `zdoc_template` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_finding_application`
    FOREIGN KEY (`application_id`) REFERENCES `zdoc_application` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_vital_reading
CREATE TABLE `zdoc_vital_reading` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `patient_pid`     BIGINT          NOT NULL,
  `recorded_at`     DATETIME(3)     NOT NULL COMMENT 'PER-READING timestamp, surfaced in the note',
  `recorded_by`     BIGINT          NOT NULL,
  `sequence_no`     INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT 'display order within the encounter',

  `height_in`       DECIMAL(5,2)        NULL,
  `weight_lb`       DECIMAL(6,2)        NULL,
  `bmi`             DECIMAL(5,2)        NULL COMMENT 'DERIVED AND STORED (06 §2.4)',
  `bmi_class_code`  VARCHAR(16)         NULL
                      COMMENT 'specific obesity class offered to the problem list, e.g. E66.811 — never generic E66.9',
  `bp_systolic`     SMALLINT UNSIGNED   NULL,
  `bp_diastolic`    SMALLINT UNSIGNED   NULL,
  `bp_position`     ENUM('sitting','standing','supine','unknown')
                                        NULL,
  `bp_site`         ENUM('left_arm','right_arm','left_leg','right_leg','other','unknown')
                                        NULL,
  `pulse`           SMALLINT UNSIGNED   NULL,
  `resp_rate`       SMALLINT UNSIGNED   NULL,
  `temp_f`          DECIMAL(4,1)        NULL,
  `temp_route`      ENUM('oral','tympanic','temporal','axillary','rectal','other','unknown')
                                        NULL,
  `o2_sat`          TINYINT UNSIGNED    NULL,
  `o2_delivery`     ENUM('room_air','nasal_cannula','mask','other','unknown')
                                        NULL,
  `pain_score`      TINYINT UNSIGNED    NULL COMMENT '0..10; belongs here, not only in HPI (06 §2.4)',

  `not_performed`   TINYINT(1)      NOT NULL DEFAULT 0
                      COMMENT 'FIRST-CLASS STATE, distinct from NULL (06 §2.4)',
  `not_performed_reason` TEXT           NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_vital_reading_encounter` (`encounter_id`,`recorded_at`),
  KEY `ix_zdoc_vital_reading_patient`   (`patient_pid`,`recorded_at`),
  CONSTRAINT `ck_zdoc_vital_pain_score` CHECK (`pain_score` IS NULL OR `pain_score` <= 10),
  CONSTRAINT `ck_zdoc_vital_o2_sat`     CHECK (`o2_sat` IS NULL OR `o2_sat` <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_allergy
CREATE TABLE `zdoc_allergy` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_pid`     BIGINT          NOT NULL,
  `substance_code`  VARCHAR(32)         NULL COMMENT 'RxNorm / UNII / local',
  `substance_code_system` VARCHAR(32)   NULL,
  `substance_text`  VARCHAR(255)    NOT NULL,
  `allergy_type`    ENUM('drug','food','environmental','other')
                                    NOT NULL DEFAULT 'drug',
  `reaction`        VARCHAR(255)        NULL,
  `severity`        ENUM('mild','moderate','severe','life_threatening','unknown')
                                    NOT NULL DEFAULT 'unknown',
  `onset_date`      DATE                NULL,
  `status`          ENUM('active','inactive','entered_in_error')
                                    NOT NULL DEFAULT 'active',
  `source`          ENUM('provider','patient','external_feed')
                                    NOT NULL DEFAULT 'provider',
  `source_name`     VARCHAR(191)        NULL
                      COMMENT 'e.g. the sending facility; DISPLAYED ON THE ROW (06 §2.5)',
  `source_received_at` DATETIME(3)      NULL COMMENT 'receipt timestamp; DISPLAYED ON THE ROW',
  `reconciled_by`   BIGINT              NULL,
  `reconciled_at`   DATETIME(3)         NULL,
  `created_by`      BIGINT              NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  `updated_by`      BIGINT              NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_allergy_patient` (`patient_pid`,`status`),
  KEY `ix_zdoc_allergy_source`  (`source`,`source_received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_patient_allergy_assertion
CREATE TABLE `zdoc_patient_allergy_assertion` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_pid`     BIGINT          NOT NULL,
  `nkda`            TINYINT(1)      NOT NULL DEFAULT 0,
  `asserted_by`     BIGINT UNSIGNED     NULL,
  `asserted_at`     DATETIME(3)         NULL,
  `auto_cleared_at` DATETIME(3)         NULL
                      COMMENT 'set when an incoming external allergy auto-cleared the flag (06 §2.5)',
  `auto_cleared_reason` VARCHAR(255)    NULL,
  `last_reviewed_by` BIGINT             NULL
                      COMMENT 'a review is an EVENT, not a checkbox (06 §2.5)',
  `last_reviewed_at` DATETIME(3)        NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_allergy_assertion_patient` (`patient_pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_external_source
CREATE TABLE `zdoc_external_source` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `source_key`      VARCHAR(64)     NOT NULL,
  `display_name`    VARCHAR(191)    NOT NULL,
  `domain`          ENUM('medication','allergy','problem','immunization','lab','imaging','other')
                                    NOT NULL,
  `is_configured`   TINYINT(1)      NOT NULL DEFAULT 0,
  `health_status`   ENUM('ok','degraded','unavailable','misconfigured','never_contacted')
                                    NOT NULL DEFAULT 'never_contacted',
  `health_message`  VARCHAR(255)        NULL
                      COMMENT 'shown verbatim in the reconciliation rail when health_status != ok',
  `last_success_at` DATETIME(3)         NULL,
  `last_attempt_at` DATETIME(3)         NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_external_source_key` (`source_key`,`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_medication
CREATE TABLE `zdoc_medication` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_pid`     BIGINT          NOT NULL,
  `drug_code`       VARCHAR(32)         NULL COMMENT 'RxNorm',
  `drug_name`       VARCHAR(255)    NOT NULL
                      COMMENT 'stored with TALLman lettering where applicable, e.g. metFORMIN (06 §2.6)',
  `is_tallman`      TINYINT(1)      NOT NULL DEFAULT 0,
  `strength`        VARCHAR(64)         NULL,
  `form`            VARCHAR(64)         NULL,
  `sig_quantity`    DECIMAL(10,3)       NULL,
  `sig_frequency`   VARCHAR(64)         NULL COMMENT 'e.g. BID',
  `sig_route`       VARCHAR(32)         NULL COMMENT 'e.g. PO',
  `sig_duration_days` SMALLINT UNSIGNED NULL,
  `sig_free_text`   VARCHAR(255)        NULL COMMENT 'only when structured sig cannot express it',
  `start_date`      DATE                NULL,
  `end_date`        DATE                NULL,
  `status`          ENUM('active','historical','discontinued','entered_in_error')
                                    NOT NULL DEFAULT 'active',
  `source`          ENUM('provider','patient','external_feed')
                                    NOT NULL DEFAULT 'provider',
  `external_source_id` INT UNSIGNED     NULL,
  `source_received_at` DATETIME(3)      NULL,
  `reconciliation_state` ENUM('incoming','accepted','dismissed')
                                    NOT NULL DEFAULT 'accepted'
                      COMMENT 'drives the Incoming / Dismissed tabs of the reconciliation rail (06 §2.6)',
  `dismissed_by`    BIGINT              NULL,
  `dismissed_at`    DATETIME(3)         NULL,
  `created_by`      BIGINT              NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_medication_patient` (`patient_pid`,`status`),
  KEY `ix_zdoc_medication_recon`   (`patient_pid`,`reconciliation_state`),
  KEY `ix_zdoc_medication_source`  (`external_source_id`),
  CONSTRAINT `fk_zdoc_medication_ext_source`
    FOREIGN KEY (`external_source_id`) REFERENCES `zdoc_external_source` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_section_flag
CREATE TABLE `zdoc_section_flag` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `section_key`     VARCHAR(32)     NOT NULL,
  `rule_name`       VARCHAR(128)    NOT NULL COMMENT 'named in the flag text (06 §2.7)',
  `flag_text`       VARCHAR(255)    NOT NULL,
  `severity`        ENUM('advisory','warning','error')
                                    NOT NULL DEFAULT 'advisory',
  `is_cleared`      TINYINT(1)      NOT NULL DEFAULT 0,
  `cleared_by`      BIGINT              NULL,
  `cleared_at`      DATETIME(3)         NULL,
  `clear_assertion` VARCHAR(64)         NULL COMMENT 'e.g. nkda, no_medications_reported',
  `created_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_section_flag_encounter` (`encounter_id`,`section_key`,`is_cleared`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_diagnosis_set
CREATE TABLE `zdoc_diagnosis_set` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(32)     NOT NULL
                      COMMENT 'MUST be unique. No reuse across sets (07 §11.2.4, 06 §4.3, test 9)',
  `name`            VARCHAR(191)    NOT NULL
                      COMMENT 'laterality MUST NOT appear here; use laterality_param',
  `specialty`       VARCHAR(64)         NULL,
  `body_region`     VARCHAR(64)         NULL,
  `owner_scope`     ENUM('system','practice','provider')
                                    NOT NULL DEFAULT 'practice',
  `owner_user_id`   BIGINT              NULL,
  `presentation_mode` ENUM('insert_all','pick_list')
                                    NOT NULL DEFAULT 'pick_list'
                      COMMENT 'pick_list narrows the code universe to the candidates; insert_all for deterministic encounters (08 §12.3.2)',
  `laterality_param` TINYINT(1)     NOT NULL DEFAULT 0
                      COMMENT 'TRUE = one set with a side selector replaces the L/R duplicate pair (08 §12.3.2)',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `last_used_at`    DATETIME            NULL,
  `created_by`      BIGINT              NULL,
  `created_at`      DATETIME        NOT NULL,
  `updated_by`      BIGINT              NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_diagnosis_set_code` (`code`),
  KEY `ix_zdoc_diagnosis_set_active` (`is_active`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_order_set
CREATE TABLE `zdoc_order_set` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(32)     NOT NULL
                      COMMENT 'MUST be unique and MUST be present. Every set is numbered (06 §4.3, test 9)',
  `name`            VARCHAR(191)    NOT NULL
                      COMMENT 'laterality and level come from the items, NOT from the name',
  `body_region`     VARCHAR(64)         NULL,
  `laterality_param` TINYINT(1)     NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `last_used_at`    DATETIME            NULL,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_order_set_code` (`code`),
  KEY `ix_zdoc_order_set_active` (`is_active`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_plan_group
CREATE TABLE `zdoc_plan_group` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(32)         NULL,
  `name`            VARCHAR(191)    NOT NULL COMMENT 'e.g. "lumbar radiculopathy"',
  `body_region`     VARCHAR(64)         NULL,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_plan_group_code` (`code`),
  UNIQUE KEY `uq_zdoc_plan_group_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_diagnosis_set_item
CREATE TABLE `zdoc_diagnosis_set_item` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `diagnosis_set_id` BIGINT UNSIGNED NOT NULL,
  `icd10_code`      VARCHAR(16)     NOT NULL,
  `display_name`    VARCHAR(255)    NOT NULL,
  `laterality`      ENUM('right','left','bilateral','n/a','inherit')
                                    NOT NULL DEFAULT 'inherit'
                      COMMENT 'inherit = take the set-level laterality parameter',
  `anatomic_level`  VARCHAR(16)         NULL,
  `default_plan_text` TEXT              NULL COMMENT 'editable starting narrative',
  `default_selected` TINYINT(1)     NOT NULL DEFAULT 0
                      COMMENT 'FALSE = offered, one click to include, not yet in the note (test 31)',
  `sort_order`      INT             NOT NULL DEFAULT 0,
  `plan_group_id`   BIGINT UNSIGNED     NULL
                      COMMENT 'wires the candidate to its plan tree ("plans ready") — FK added in Section 14',
  `linked_order_set_id` BIGINT UNSIGNED NULL,
  `linked_education_ids` JSON           NULL,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_dx_set_item` (`diagnosis_set_id`,`icd10_code`,`laterality`),
  KEY `ix_zdoc_dx_set_item_sort` (`diagnosis_set_id`,`sort_order`),
  KEY `ix_zdoc_dx_set_item_code` (`icd10_code`),
  KEY `ix_zdoc_dx_set_item_plan_group` (`plan_group_id`),
  KEY `ix_zdoc_dx_set_item_order_set`  (`linked_order_set_id`),
  CONSTRAINT `fk_zdoc_dx_set_item_set`
    FOREIGN KEY (`diagnosis_set_id`) REFERENCES `zdoc_diagnosis_set` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_dx_set_item_plan_group`
    FOREIGN KEY (`plan_group_id`) REFERENCES `zdoc_plan_group` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_dx_set_item_order_set`
    FOREIGN KEY (`linked_order_set_id`) REFERENCES `zdoc_order_set` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_plan_group_code
CREATE TABLE `zdoc_plan_group_code` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_group_id`   BIGINT UNSIGNED NOT NULL,
  `icd10_code`      VARCHAR(16)     NOT NULL COMMENT 'M54.16 and M54.17 both point at one group',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_plan_group_code` (`icd10_code`)
    COMMENT 'a code resolves to exactly ONE plan group — this is what prevents the M54.16/M54.17 drift',
  KEY `ix_zdoc_plan_group_code_group` (`plan_group_id`),
  CONSTRAINT `fk_zdoc_plan_group_code_group`
    FOREIGN KEY (`plan_group_id`) REFERENCES `zdoc_plan_group` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_consent_type
CREATE TABLE `zdoc_consent_type` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(32)         NULL,
  `name`            VARCHAR(191)    NOT NULL,
  `body_text`       LONGTEXT            NULL COMMENT 'may reference a macro by name',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_consent_type_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_payer_auth_rule
CREATE TABLE `zdoc_payer_auth_rule` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(191)    NOT NULL,
  `payer_class`     VARCHAR(64)         NULL COMMENT 'e.g. medicare, commercial, self_pay',
  `payer_name`      VARCHAR(191)        NULL,
  `cpt_pattern`     VARCHAR(64)         NULL COMMENT 'CPT or prefix pattern the rule applies to',
  `requires_auth`   TINYINT(1)      NOT NULL DEFAULT 1,
  `notes`           TEXT                NULL,
  `effective_from`  DATE            NOT NULL,
  `effective_to`    DATE                NULL COMMENT 'NULL = currently in force',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_payer_auth_rule_lookup` (`payer_class`,`cpt_pattern`,`effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_receiver
CREATE TABLE `zdoc_receiver` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receiver_type`   ENUM('in_house_facility','external_practice','imaging_center',
                         'pt_clinic','surgeon','pharmacy','lab','other'
                        )            NOT NULL,
  `core_facility_id` INT                NULL
                      COMMENT 'set for in_house_facility; links to a core facility id. NO FK',
  `name`            VARCHAR(191)    NOT NULL,
  `npi`             VARCHAR(16)         NULL,
  `address_line1`   VARCHAR(191)        NULL,
  `address_line2`   VARCHAR(191)        NULL,
  `city`            VARCHAR(96)         NULL,
  `state`           VARCHAR(8)          NULL,
  `postal_code`     VARCHAR(16)         NULL,
  `latitude`        DECIMAL(9,6)        NULL,
  `longitude`       DECIMAL(9,6)        NULL,
  `phone`           VARCHAR(32)         NULL,
  `fax`             VARCHAR(32)         NULL,
  `email`           VARCHAR(191)        NULL,
  `direct_address`  VARCHAR(191)        NULL,
  `is_in_house`     TINYINT(1)      NOT NULL DEFAULT 0
                      COMMENT 'ranks first in the directory, always (06 §4.5)',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_receiver_name`   (`name`),
  KEY `ix_zdoc_receiver_inhouse` (`is_in_house`,`is_active`),
  KEY `ix_zdoc_receiver_geo`    (`postal_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_order_type
CREATE TABLE `zdoc_order_type` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(32)         NULL,
  `name`            VARCHAR(191)    NOT NULL,
  `cpt_code`        VARCHAR(16)         NULL,
  `item_category`   ENUM('procedure','imaging','lab','referral','medication',
                         'dme','therapy','other'
                        )            NOT NULL DEFAULT 'other',
  `default_destination` ENUM('front_desk','scheduler','imaging','pt','surgery',
                             'pharmacy','in_house_procedure','external_provider'
                            )        NOT NULL,
  `default_method`  ENUM('worklist','fax','direct_message','email','print','erx')
                                    NOT NULL,
  `default_recipient_id` BIGINT UNSIGNED NULL COMMENT 'fk -> zdoc_receiver',
  `requires_auth_rule_id` BIGINT UNSIGNED NULL,

  -- PAYER CLASS — a spec gap, not a detail.
  -- The practice runs multiple entities (insurance and cash-pay) through
  -- one OpenEMR, with a separate cash-pay system owning its own tables.
  -- Without this field, a cash-pay or cosmetic procedure planned in the
  -- A/P would project an INSURANCE claim line instead of routing to the
  -- cash-pay system. Signing routes by this value. See CONTRACTS.md
  -- §2.18.1 and OPEN-QUESTIONS.md Q36.
  `payer_class`     ENUM('insurance','cash_pay','either')
                                    NOT NULL DEFAULT 'insurance'
                      COMMENT 'insurance = project a zdoc_claim_line; cash_pay = route to the cash-pay system, NEVER a claim line; either = resolve per encounter from patient coverage',
  `cashpay_catalog_code` VARCHAR(64)    NULL
                      COMMENT 'catalog item key in the cash-pay system, used when payer_class routes there. A reference only — ZeroDoc never reads that system''s tables directly, only its services',

  `is_education`    TINYINT(1)      NOT NULL DEFAULT 0
                      COMMENT 'MUST be 0. Education is never an order type (06 §4.6). Kept only so the importer can reject it loudly',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_order_type_code` (`code`),
  KEY `ix_zdoc_order_type_active` (`is_active`,`name`),
  KEY `ix_zdoc_order_type_recipient` (`default_recipient_id`),
  KEY `ix_zdoc_order_type_auth` (`requires_auth_rule_id`),
  CONSTRAINT `fk_zdoc_order_type_recipient`
    FOREIGN KEY (`default_recipient_id`) REFERENCES `zdoc_receiver` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_order_type_auth_rule`
    FOREIGN KEY (`requires_auth_rule_id`) REFERENCES `zdoc_payer_auth_rule` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_procedure_type
CREATE TABLE `zdoc_procedure_type` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(32)     NOT NULL COMMENT 'MUST be unique',
  `name`            VARCHAR(191)    NOT NULL
                      COMMENT 'e.g. "interlaminar ESI" — level and side are PARAMETERS, not part of the name',
  `specialty`       VARCHAR(64)         NULL,
  `body_region`     VARCHAR(64)         NULL,
  `default_cpt`     JSON                NULL COMMENT 'may be multiple; sourced from the practice''s own fee schedule ONLY (13 §13.3.2)',
  `default_modifiers` JSON              NULL COMMENT 'laterality- and guidance-dependent (13 §13.6)',
  `parameters`      JSON                NULL COMMENT 'level, side, approach, guidance — drive the note template''s variable slots',
  `required_note_elements` JSON         NULL
                      COMMENT 'e.g. ["consent","timeout","laterality","level","guidance","agent_volume","patient_response","complications"]. SIGNING IS BLOCKED IF ANY IS ABSENT (13 §13.3.3)',
  `requires_consent` TINYINT(1)     NOT NULL DEFAULT 0,
  `payer_class`     ENUM('insurance','cash_pay','either')
                                    NOT NULL DEFAULT 'insurance'
                      COMMENT 'same routing dimension as zdoc_order_type.payer_class. A cosmetic or cash procedure must not project an insurance claim line',
  `cashpay_catalog_code` VARCHAR(64)    NULL,
  `note_template_id` BIGINT UNSIGNED    NULL COMMENT 'zdoc_template with is_procedure_note = 1',
  `linked_order_type_id` BIGINT UNSIGNED NULL,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `last_used_at`    DATETIME            NULL,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_procedure_type_code` (`code`),
  KEY `ix_zdoc_procedure_type_active` (`is_active`,`name`),
  KEY `ix_zdoc_procedure_type_tpl`    (`note_template_id`),
  KEY `ix_zdoc_procedure_type_order`  (`linked_order_type_id`),
  CONSTRAINT `fk_zdoc_procedure_type_template`
    FOREIGN KEY (`note_template_id`) REFERENCES `zdoc_template` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_procedure_type_order_type`
    FOREIGN KEY (`linked_order_type_id`) REFERENCES `zdoc_order_type` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_plan_option
CREATE TABLE `zdoc_plan_option` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_group_id`   BIGINT UNSIGNED NOT NULL,
  `label`           VARCHAR(191)    NOT NULL COMMENT 'e.g. "Epidural steroid injection"',
  `sort_order`      INT             NOT NULL DEFAULT 0
                      COMMENT 'baseline order; the UI re-ranks by the provider''s own usage (zdoc_usage_stat)',
  `narrative_template` TEXT             NULL
                      COMMENT 'may reference a macro by name, e.g. ".esirisks" (08 §12.5.4)',
  `creates_order_type_id` BIGINT UNSIGNED NULL,
  `creates_procedure_type_id` BIGINT UNSIGNED NULL
                      COMMENT '13 §13.9 WP-07: a plan option that creates a procedure populates zdoc_encounter_procedure, not only an order',
  `sets_followup`   TINYINT(1)      NOT NULL DEFAULT 0,
  `followup_interval` VARCHAR(32)       NULL COMMENT 'e.g. "4 wks"',
  `queues_consent_id` BIGINT UNSIGNED   NULL,
  `queues_education_ids` JSON           NULL,
  `parameters`      JSON                NULL
                      COMMENT 'e.g. [{"key":"level","options":["L3-L4","L4-L5","L5-S1"],"required":true}] — inline dropdowns, NEVER separate near-identical options',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_plan_option_group` (`plan_group_id`,`sort_order`),
  KEY `ix_zdoc_plan_option_order_type` (`creates_order_type_id`),
  KEY `ix_zdoc_plan_option_proc_type`  (`creates_procedure_type_id`),
  KEY `ix_zdoc_plan_option_consent`    (`queues_consent_id`),
  CONSTRAINT `fk_zdoc_plan_option_group`
    FOREIGN KEY (`plan_group_id`) REFERENCES `zdoc_plan_group` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_plan_option_order_type`
    FOREIGN KEY (`creates_order_type_id`) REFERENCES `zdoc_order_type` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_plan_option_procedure_type`
    FOREIGN KEY (`creates_procedure_type_id`) REFERENCES `zdoc_procedure_type` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_plan_option_consent_type`
    FOREIGN KEY (`queues_consent_id`) REFERENCES `zdoc_consent_type` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_encounter_consent
CREATE TABLE `zdoc_encounter_consent` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `consent_type_id` BIGINT UNSIGNED NOT NULL,
  `status`          ENUM('queued','presented','obtained','declined','void')
                                    NOT NULL DEFAULT 'queued',
  `obtained_by`     BIGINT              NULL,
  `obtained_at`     DATETIME(3)         NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_encounter_consent_enc` (`encounter_id`,`status`),
  CONSTRAINT `fk_zdoc_encounter_consent_type`
    FOREIGN KEY (`consent_type_id`) REFERENCES `zdoc_consent_type` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_education_item
CREATE TABLE `zdoc_education_item` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(32)         NULL,
  `title`           VARCHAR(255)    NOT NULL,
  `body_region`     VARCHAR(64)         NULL,
  `delivery`        ENUM('print','portal','email','verbal')
                                    NOT NULL DEFAULT 'print',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_education_item_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_encounter_education
CREATE TABLE `zdoc_encounter_education` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `education_item_id` BIGINT UNSIGNED NOT NULL,
  `attached_by_diagnosis_code` VARCHAR(16) NULL
                      COMMENT 'auto-attachment by diagnosis is fine and useful; it just is not an order',
  `status`          ENUM('queued','acknowledged','provided','declined')
                                    NOT NULL DEFAULT 'queued',
  `acknowledged_by` BIGINT              NULL,
  `acknowledged_at` DATETIME(3)         NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_encounter_education` (`encounter_id`,`education_item_id`),
  KEY `ix_zdoc_encounter_education_status` (`encounter_id`,`status`),
  CONSTRAINT `fk_zdoc_encounter_education_item`
    FOREIGN KEY (`education_item_id`) REFERENCES `zdoc_education_item` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_order_set_item
CREATE TABLE `zdoc_order_set_item` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_set_id`    BIGINT UNSIGNED NOT NULL,
  `item_type`       ENUM('diagnosis','procedure','imaging','lab','referral',
                         'education','consent','medication'
                        )            NOT NULL,
  `reference_id`    BIGINT UNSIGNED     NULL
                      COMMENT 'polymorphic by item_type; NULL when reference_code carries the identity',
  `reference_code`  VARCHAR(32)         NULL COMMENT 'ICD-10 for diagnosis items, CPT otherwise',
  `display_name`    VARCHAR(255)        NULL,
  `laterality`      ENUM('right','left','bilateral','n/a','inherit')
                                    NOT NULL DEFAULT 'inherit',
  `anatomic_level`  VARCHAR(16)         NULL,
  `default_quantity` DECIMAL(10,3)      NULL,
  `sort_order`      INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_order_set_item_set` (`order_set_id`,`sort_order`),
  KEY `ix_zdoc_order_set_item_ref` (`item_type`,`reference_id`),
  CONSTRAINT `fk_zdoc_order_set_item_set`
    FOREIGN KEY (`order_set_id`) REFERENCES `zdoc_order_set` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_encounter_diagnosis
CREATE TABLE `zdoc_encounter_diagnosis` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `encounter_section_id` BIGINT UNSIGNED NOT NULL COMMENT 'the assessment_plan section',
  `sort_order`      INT             NOT NULL DEFAULT 0
                      COMMENT 'A/P ORDER. Drives claim line order and diagnosis pointers (06 §7.2, 13 §13.5)',
  `icd10_code`      VARCHAR(16)     NOT NULL,
  `display_name`    VARCHAR(255)    NOT NULL,
  `laterality`      ENUM('right','left','bilateral','n/a')
                                    NOT NULL DEFAULT 'n/a',
  `anatomic_level`  VARCHAR(16)         NULL,
  `is_primary`      TINYINT(1)      NOT NULL DEFAULT 0,
  `plan_text`       LONGTEXT            NULL COMMENT 'NO CHARACTER CAP (06 §4.1)',
  `plan_group_id`   BIGINT UNSIGNED     NULL COMMENT 'resolved from zdoc_plan_group_code at entry',
  `problem_id`      BIGINT UNSIGNED     NULL COMMENT 'set when pushed from the problem list (06 §2.8, test 18)',
  `thread_id`       BIGINT UNSIGNED     NULL COMMENT 'diagnosis <-> thread binding (13 §13.9 WP-07)',
  `diagnosis_set_id` BIGINT UNSIGNED    NULL COMMENT 'the set that placed it, if any',
  `diagnosis_set_item_id` BIGINT UNSIGNED NULL,
  `provenance`      ENUM('pre_populated','attested','ai_generated')
                                    NOT NULL DEFAULT 'pre_populated',
  `placed_by`       BIGINT          NOT NULL,
  `placed_at`       DATETIME(3)     NOT NULL,
  `attested_by`     BIGINT              NULL,
  `attested_at`     DATETIME(3)         NULL,
  `modified_from`   TEXT                NULL,
  `application_id`  BIGINT UNSIGNED     NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_encounter_diagnosis` (`encounter_id`,`icd10_code`,`laterality`),
  KEY `ix_zdoc_enc_dx_order`   (`encounter_id`,`sort_order`),
  KEY `ix_zdoc_enc_dx_section` (`encounter_section_id`),
  KEY `ix_zdoc_enc_dx_group`   (`plan_group_id`),
  KEY `ix_zdoc_enc_dx_problem` (`problem_id`),
  KEY `ix_zdoc_enc_dx_thread`  (`thread_id`),
  KEY `ix_zdoc_enc_dx_set`     (`diagnosis_set_id`),
  CONSTRAINT `fk_zdoc_enc_dx_section`
    FOREIGN KEY (`encounter_section_id`) REFERENCES `zdoc_encounter_section` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_enc_dx_plan_group`
    FOREIGN KEY (`plan_group_id`) REFERENCES `zdoc_plan_group` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_enc_dx_problem`
    FOREIGN KEY (`problem_id`) REFERENCES `zdoc_problem` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_enc_dx_thread`
    FOREIGN KEY (`thread_id`) REFERENCES `zdoc_problem_thread` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_enc_dx_set`
    FOREIGN KEY (`diagnosis_set_id`) REFERENCES `zdoc_diagnosis_set` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_enc_dx_application`
    FOREIGN KEY (`application_id`) REFERENCES `zdoc_application` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_order
CREATE TABLE `zdoc_order` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `patient_pid`     BIGINT          NOT NULL,
  `order_type_id`   BIGINT UNSIGNED NOT NULL,
  `encounter_diagnosis_id` BIGINT UNSIGNED NULL
                      COMMENT 'the A/P diagnosis this order hangs under; FK added in Section 17',
  `plan_option_id`  BIGINT UNSIGNED     NULL COMMENT 'set when a plan option created it',
  `order_set_id`    BIGINT UNSIGNED     NULL,
  `laterality`      ENUM('right','left','bilateral','n/a')
                                    NOT NULL DEFAULT 'n/a',
  `anatomic_level`  VARCHAR(16)         NULL,
  `parameters`      JSON                NULL COMMENT 'resolved values for the plan option / order type parameters',
  `quantity`        DECIMAL(10,3)       NULL,

  -- 06 §4.4 procedure-order field set
  `performing_provider_id` BIGINT          NULL COMMENT 'default = encounter provider',
  `assistant_required` TINYINT(1)   NOT NULL DEFAULT 0,
  `followup_appointment` VARCHAR(64)     NULL,
  `anesthesia`      VARCHAR(128)        NULL,
  `anesthesia_notes` TEXT               NULL,
  `facility_id`     INT                 NULL COMMENT 'default = encounter facility',
  `send_date`       DATE                NULL,
  `is_stat`         TINYINT(1)      NOT NULL DEFAULT 0,
  `tickler_date`    DATE                NULL,
  `receiver_id`     BIGINT UNSIGNED     NULL COMMENT 'default = order type default_recipient_id',
  `documentation_only` TINYINT(1)   NOT NULL DEFAULT 0,
  `include_summary_of_care` TINYINT(1) NOT NULL DEFAULT 0,
  `cc_recipients`   JSON                NULL,
  `attachments`     JSON                NULL,
  `note_to_facility` TEXT               NULL,
  `internal_note`   TEXT                NULL,
  `authorization_number` VARCHAR(64)    NULL,
  `insurance_referral`   VARCHAR(64)    NULL,
  `relevant_diagnosis_ids` JSON         NULL,
  `prescribed_medications` JSON         NULL,
  `labs`            JSON                NULL,

  `status`          ENUM('draft','signed','deferred','cancelled','completed')
                                    NOT NULL DEFAULT 'draft'
                      COMMENT 'signing requires all orders signed OR EXPLICITLY DEFERRED (06 §6.3 item 6)',
  `deferred_reason` VARCHAR(255)        NULL,
  `signed_by`       BIGINT              NULL,
  `signed_at`       DATETIME(3)         NULL,
  `created_by`      BIGINT          NOT NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_order_encounter` (`encounter_id`,`status`),
  KEY `ix_zdoc_order_patient`   (`patient_pid`),
  KEY `ix_zdoc_order_type`      (`order_type_id`),
  KEY `ix_zdoc_order_dx`        (`encounter_diagnosis_id`),
  KEY `ix_zdoc_order_plan_option` (`plan_option_id`),
  CONSTRAINT `fk_zdoc_order_type`
    FOREIGN KEY (`order_type_id`) REFERENCES `zdoc_order_type` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_order_plan_option`
    FOREIGN KEY (`plan_option_id`) REFERENCES `zdoc_plan_option` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_order_order_set`
    FOREIGN KEY (`order_set_id`) REFERENCES `zdoc_order_set` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_order_receiver`
    FOREIGN KEY (`receiver_id`) REFERENCES `zdoc_receiver` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_order_encounter_diagnosis`
    FOREIGN KEY (`encounter_diagnosis_id`) REFERENCES `zdoc_encounter_diagnosis` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_dispatch_task
CREATE TABLE `zdoc_dispatch_task` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `patient_pid`     BIGINT          NOT NULL,
  `order_id`        BIGINT UNSIGNED     NULL
                      COMMENT 'NULL for non-order dispatches, e.g. a handout print packet',
  `destination`     ENUM('front_desk','scheduler','imaging','pt','surgery',
                         'pharmacy','in_house_procedure','external_provider','patient'
                        )            NOT NULL,
  `method`          ENUM('worklist','fax','direct_message','email','print','erx')
                                    NOT NULL,
  `receiver_id`     BIGINT UNSIGNED     NULL,
  `status`          ENUM('new','working','scheduled','sent','failed','done')
                                    NOT NULL DEFAULT 'new',
  `assigned_role`   VARCHAR(64)         NULL,
  `assigned_user_id` BIGINT             NULL,
  -- AUTHORIZATION: READ the existing register, do not duplicate it.
  -- Another module owns the practice's register of HELD authorizations
  -- (auth number, CPT, date window, units). ZeroDoc owns the RULE
  -- (zdoc_payer_auth_rule) and the per-task STATUS below. It must not
  -- create a second register. These columns cache what was read, with
  -- the time it was read, so a disabled module degrades to "unknown"
  -- rather than to a wrong answer. See CONTRACTS.md §2.18.2.
  `auth_status`     ENUM('not_required','required','requested','approved','denied','unknown')
                                    NOT NULL DEFAULT 'unknown',
  `auth_number`     VARCHAR(64)         NULL
                      COMMENT 'CACHED from the external authorization register; that register is authoritative',
  `auth_source`     VARCHAR(64)         NULL
                      COMMENT 'which external register answered, e.g. prior_authorizations. NULL = nothing consulted',
  `auth_units_remaining` INT            NULL
                      COMMENT 'DERIVED at read time as init_units - unitsUsed. Never trusted from a stored remaining-units column, which the owning module writes once and never recomputes',
  `auth_checked_at` DATETIME(3)         NULL
                      COMMENT 'when the register was last consulted. A stale value is shown with its age, never as current',

  `packet_json`     JSON                NULL
                      COMMENT 'pre-assembled packet manifest: order, demographics, insurance, relevant reports',
  `attempt_count`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `failure_reason`  TEXT                NULL COMMENT 'populated on status = failed; task renders red',

  -- EXTERNAL DELIVERY: reference the fax/SMS system, stay independent.
  -- ZeroDoc does NOT implement faxing and does not own a send queue.
  -- It stores the external job reference and MIRRORS status. The
  -- external row may be absent entirely (some vendors bypass the queue)
  -- or soft-deleted, while this clinical task still needs to exist and
  -- still needs a truthful status. `status` above is ZeroDoc's own and
  -- is authoritative for the task; the columns below are evidence.
  `external_job_id` VARCHAR(128)        NULL COMMENT 'the delivery system''s job id, when it issued one',
  `external_job_source` VARCHAR(64)     NULL COMMENT 'which delivery system and vendor path',
  `external_status` VARCHAR(32)         NULL COMMENT 'last status read from that system, verbatim, unmapped',
  `external_status_at` DATETIME(3)      NULL,
  `external_status_reliable` TINYINT(1) NOT NULL DEFAULT 0
                      COMMENT '0 = the vendor path does not report delivery status. A task whose status cannot be confirmed MUST NOT display as delivered',
  `scheduled_for`   DATETIME            NULL COMMENT 'loop closure: scheduling status returns to the chart',
  `created_at`      DATETIME(3)     NOT NULL,
  `completed_at`    DATETIME(3)         NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_dispatch_task_queue` (`destination`,`status`,`created_at`),
  KEY `ix_zdoc_dispatch_task_enc`   (`encounter_id`),
  KEY `ix_zdoc_dispatch_task_order` (`order_id`),
  KEY `ix_zdoc_dispatch_task_open`  (`status`,`created_at`)
    COMMENT 'backs the dispatched-but-never-completed report (08 §12.4)',
  CONSTRAINT `fk_zdoc_dispatch_task_order`
    FOREIGN KEY (`order_id`) REFERENCES `zdoc_order` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_dispatch_task_receiver`
    FOREIGN KEY (`receiver_id`) REFERENCES `zdoc_receiver` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_encounter_procedure
CREATE TABLE `zdoc_encounter_procedure` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `patient_pid`     BIGINT          NOT NULL,
  `procedure_type_id` BIGINT UNSIGNED NOT NULL,
  `encounter_section_id` BIGINT UNSIGNED NULL COMMENT 'the procedures section holding its findings',
  `order_id`        BIGINT UNSIGNED     NULL,
  `laterality`      ENUM('right','left','bilateral','n/a')
                                    NOT NULL DEFAULT 'n/a',
  `anatomic_level`  VARCHAR(64)         NULL COMMENT 'may be multi-level, e.g. "L2,L3,L4,L5"',
  `approach`        VARCHAR(64)         NULL COMMENT 'e.g. interlaminar, transforaminal',
  `guidance`        ENUM('none','fluoroscopy','ultrasound','ct','landmark','other')
                                    NOT NULL DEFAULT 'none',
  `performed_by`    BIGINT              NULL,
  `assisted_by`     BIGINT              NULL,
  `performed_at`    DATETIME(3)         NULL,
  `consent_obtained_at` DATETIME(3)     NULL,
  `timeout_completed_at` DATETIME(3)    NULL,
  `medications`     JSON                NULL COMMENT 'agent, concentration, volume',
  `contrast_used`   VARCHAR(128)        NULL,
  `fluoro_time`     DECIMAL(6,2)        NULL COMMENT 'minutes',
  `complications`   TEXT                NULL,
  `patient_response` TEXT               NULL,
  `procedure_note_text` LONGTEXT        NULL COMMENT 'rendered from the parameterised template',
  `suggested_cpt`   JSON                NULL,
  `suggested_modifiers` JSON            NULL,
  `confirmed_cpt`   JSON                NULL COMMENT 'BILLER-confirmed. The system suggests; the biller confirms (13 §13.4.1)',
  `confirmed_modifiers` JSON            NULL,
  `linked_diagnosis_ids` JSON           NULL COMMENT 'zdoc_encounter_diagnosis ids, in pointer order',
  `required_elements_ok` TINYINT(1)     NULL
                      COMMENT 'result of the required-element check at signing; shown to the biller as evidence',
  `required_elements_missing` JSON      NULL,
  `payer_class`     ENUM('insurance','cash_pay','either')
                                    NOT NULL DEFAULT 'insurance'
                      COMMENT 'RESOLVED at documentation time from the procedure type, overridable. Decides whether signing projects a claim line or routes to the cash-pay system',
  `cashpay_document_ref` VARCHAR(64)    NULL
                      COMMENT 'identifier of the quote/invoice created in the cash-pay system, returned by its service. A reference only; ZeroDoc never writes that system''s tables',
  `billing_status`  ENUM('pending','coded','billed','paid','denied')
                                    NOT NULL DEFAULT 'pending',
  `created_by`      BIGINT          NOT NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_enc_proc_encounter` (`encounter_id`),
  KEY `ix_zdoc_enc_proc_patient`   (`patient_pid`,`performed_at`),
  KEY `ix_zdoc_enc_proc_type`      (`procedure_type_id`),
  KEY `ix_zdoc_enc_proc_billing`   (`billing_status`,`created_at`),
  KEY `ix_zdoc_enc_proc_order`     (`order_id`),
  CONSTRAINT `fk_zdoc_enc_proc_type`
    FOREIGN KEY (`procedure_type_id`) REFERENCES `zdoc_procedure_type` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_enc_proc_section`
    FOREIGN KEY (`encounter_section_id`) REFERENCES `zdoc_encounter_section` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_enc_proc_order`
    FOREIGN KEY (`order_id`) REFERENCES `zdoc_order` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_encounter_plan_selection
CREATE TABLE `zdoc_encounter_plan_selection` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_diagnosis_id` BIGINT UNSIGNED NOT NULL,
  `plan_option_id`  BIGINT UNSIGNED NOT NULL,
  `parameter_values` JSON               NULL COMMENT 'e.g. {"level":"L4-L5","side":"right","guidance":"fluoro"}',
  `rendered_narrative` TEXT             NULL COMMENT 'narrative after macro and parameter expansion',
  `created_order_id` BIGINT UNSIGNED    NULL,
  `created_procedure_id` BIGINT UNSIGNED NULL COMMENT 'FK added after zdoc_encounter_procedure exists',
  `created_consent_id` BIGINT UNSIGNED  NULL,
  `set_followup_interval` VARCHAR(32)   NULL,
  `selected_by`     BIGINT          NOT NULL,
  `selected_at`     DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_enc_plan_selection` (`encounter_diagnosis_id`,`plan_option_id`),
  KEY `ix_zdoc_enc_plan_selection_option` (`plan_option_id`),
  CONSTRAINT `fk_zdoc_enc_plan_sel_dx`
    FOREIGN KEY (`encounter_diagnosis_id`) REFERENCES `zdoc_encounter_diagnosis` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_enc_plan_sel_option`
    FOREIGN KEY (`plan_option_id`) REFERENCES `zdoc_plan_option` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_enc_plan_sel_order`
    FOREIGN KEY (`created_order_id`) REFERENCES `zdoc_order` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_enc_plan_sel_procedure`
    FOREIGN KEY (`created_procedure_id`) REFERENCES `zdoc_encounter_procedure` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_enc_plan_sel_consent`
    FOREIGN KEY (`created_consent_id`) REFERENCES `zdoc_encounter_consent` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_encounter_time
CREATE TABLE `zdoc_encounter_time` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `user_id`         BIGINT          NOT NULL COMMENT 'whose time this is',
  `role_at_time`    VARCHAR(64)     NOT NULL,
  `minutes`         SMALLINT UNSIGNED   NULL
                      COMMENT 'NULLABLE SO A PARTIAL ENTRY IS RETAINED, NOT DISCARDED (06 §4.7, test 11)',
  `attribution`     ENUM('em','em_telehealth','bh','bh_telehealth','clinical_staff')
                                        NULL
                      COMMENT 'NULLABLE for the same reason. Validation reports the gap; the number survives',
  `is_complete`     TINYINT(1) GENERATED ALWAYS AS
                      (CASE WHEN `minutes` IS NOT NULL AND `attribution` IS NOT NULL
                            THEN 1 ELSE 0 END) STORED
                      COMMENT 'advisory; a partial row is legal storage and an illegal basis for coding',
  `note`            VARCHAR(255)        NULL,
  `recorded_by`     BIGINT          NOT NULL,
  `recorded_at`     DATETIME(3)     NOT NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_encounter_time_enc`  (`encounter_id`,`attribution`),
  KEY `ix_zdoc_encounter_time_user` (`encounter_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_billing_task
CREATE TABLE `zdoc_billing_task` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `patient_pid`     BIGINT          NOT NULL,
  `procedure_id`    BIGINT UNSIGNED     NULL COMMENT 'zdoc_encounter_procedure.id; NULL for em_coding / review tasks',
  `task_type`       ENUM('procedure_coding','em_coding','review')
                                    NOT NULL,
  `status`          ENUM('new','working','coded','submitted','denied','closed')
                                    NOT NULL DEFAULT 'new',
  `assigned_role`   VARCHAR(64)         NULL,
  `assigned_user_id` BIGINT             NULL,
  `suggested_cpt`   JSON                NULL,
  `suggested_modifiers` JSON            NULL,
  `confirmed_cpt`   JSON                NULL,
  `confirmed_modifiers` JSON            NULL,
  `diagnosis_pointers` JSON             NULL
                      COMMENT 'derived from the A/P, editable; max 4 per service line (13 §13.5)',
  `prior_encounter_diagnoses` JSON      NULL
                      COMMENT 'the picked prior-encounter codes supporting medical necessity (13 §13.4.2)',
  `auth_status`     ENUM('not_required','required','requested','approved','denied','unknown')
                                    NOT NULL DEFAULT 'unknown'
                      COMMENT 'carried from the dispatch task (13 §13.4.1)',
  `modifier_25_asserted` TINYINT(1)     NULL COMMENT 'the provider''s own determination, carried from zdoc_encounter_ext',
  `modifier_25_rationale` TEXT          NULL,
  `required_elements_ok` TINYINT(1)     NULL COMMENT 'shown as evidence that the check passed at signing',
  `bundling_warnings` JSON              NULL COMMENT 'NCCI-style conflicts. WARNINGS ONLY, never auto-applied (13 §13.6.2)',
  `biller_note`     TEXT                NULL,
  `denial_reason`   TEXT                NULL,
  `denial_code`     VARCHAR(32)         NULL,
  `denial_section_key` VARCHAR(32)      NULL
                      COMMENT 'set when the denial is documentation-related; deep-links to that note section (13 §13.7)',
  `reopened_count`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`      DATETIME(3)     NOT NULL,
  `completed_at`    DATETIME(3)         NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_billing_task_queue` (`status`,`task_type`,`created_at`),
  KEY `ix_zdoc_billing_task_enc`   (`encounter_id`),
  KEY `ix_zdoc_billing_task_proc`  (`procedure_id`),
  KEY `ix_zdoc_billing_task_uncoded` (`status`,`created_at`)
    COMMENT 'backs the signed-but-never-coded report (13 §13.7)',
  CONSTRAINT `fk_zdoc_billing_task_procedure`
    FOREIGN KEY (`procedure_id`) REFERENCES `zdoc_encounter_procedure` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_billing_code_revision
CREATE TABLE `zdoc_billing_code_revision` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `billing_task_id` BIGINT UNSIGNED NOT NULL,
  `field`           ENUM('cpt','modifiers','diagnosis_pointers','units')
                                    NOT NULL,
  `suggested_value` JSON                NULL,
  `confirmed_value` JSON                NULL,
  `changed_by`      BIGINT          NOT NULL,
  `changed_at`      DATETIME(3)     NOT NULL,
  `reason`          VARCHAR(255)        NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_billing_code_revision_task` (`billing_task_id`,`changed_at`),
  CONSTRAINT `fk_zdoc_billing_code_revision_task`
    FOREIGN KEY (`billing_task_id`) REFERENCES `zdoc_billing_task` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_em_time_code_rule
CREATE TABLE `zdoc_em_time_code_rule` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_status`  ENUM('new','established')
                                    NOT NULL,
  `payer_class`     VARCHAR(64)     NOT NULL
                      COMMENT 'e.g. medicare, non_medicare. Free string so new classes need no ALTER',
  `minutes_min`     SMALLINT UNSIGNED NOT NULL,
  `minutes_max`     SMALLINT UNSIGNED     NULL COMMENT 'NULL = open-ended upper bound',
  `base_code`       VARCHAR(16)     NOT NULL,
  `addon_code`      VARCHAR(16)         NULL COMMENT 'e.g. 99417 or G2212',
  `addon_per_minutes` SMALLINT UNSIGNED  NULL COMMENT 'e.g. 15',
  `rule_label`      VARCHAR(191)    NOT NULL COMMENT 'displayed beside the suggestion',
  `effective_from`  DATE            NOT NULL,
  `effective_to`    DATE                NULL,
  `source_citation` VARCHAR(255)        NULL,
  `created_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_em_time_rule_lookup`
    (`patient_status`,`payer_class`,`effective_from`,`minutes_min`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_claim
CREATE TABLE `zdoc_claim` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `patient_pid`     BIGINT          NOT NULL,
  `status`          ENUM('draft','ready','submitted','paid','denied')
                                    NOT NULL DEFAULT 'draft'
                      COMMENT 'revenue-cycle gaps hold the claim in draft and NEVER touch zdoc_encounter_ext.zdoc_status (06 §7.5)',
  `rendering_provider_id` BIGINT          NULL,
  `supervising_provider_id` BIGINT          NULL,
  `facility_id`     INT                 NULL,
  `place_of_service` VARCHAR(8)         NULL,
  `payer_class`     VARCHAR(64)         NULL COMMENT 'e.g. medicare, commercial, self_pay',
  `patient_status`  ENUM('new','established')
                                        NULL
                      COMMENT 'DERIVED FROM ENCOUNTER HISTORY, never defaulted to new (06 §7.3a, test 13)',
  `patient_status_derivation` VARCHAR(255) NULL COMMENT 'displayed derivation',
  `patient_status_override_reason` TEXT     NULL COMMENT 'required when overriding the derivation',
  `em_guideline_mode` ENUM('mdm','time_based')
                                        NULL,
  `em_mdm_problems` ENUM('n/a','minimal','low','moderate','high') NULL
                      COMMENT 'MDM axis: number/complexity of problems (06 §7.3)',
  `em_mdm_data`     ENUM('n/a','minimal','low','moderate','high') NULL,
  `em_mdm_risk`     ENUM('n/a','minimal','low','moderate','high') NULL,
  `em_total_minutes` SMALLINT UNSIGNED  NULL
                      COMMENT 'READ FROM zdoc_encounter_time PROVIDER ROWS. Never re-entered (06 §7.3b, test 12)',
  `em_excluded_staff_minutes` SMALLINT UNSIGNED NULL COMMENT 'displayed, never added (07 §11.8)',
  `em_time_user_id` BIGINT              NULL COMMENT 'whose time the calculator used; displayed',
  `em_applied_rule_id` BIGINT UNSIGNED  NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  `submitted_at`    DATETIME(3)         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_claim_encounter` (`encounter_id`),
  KEY `ix_zdoc_claim_status` (`status`,`created_at`),
  KEY `ix_zdoc_claim_patient` (`patient_pid`),
  CONSTRAINT `fk_zdoc_claim_em_rule`
    FOREIGN KEY (`em_applied_rule_id`) REFERENCES `zdoc_em_time_code_rule` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_claim_line
CREATE TABLE `zdoc_claim_line` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `claim_id`        BIGINT UNSIGNED NOT NULL,
  `line_no`         SMALLINT UNSIGNED NOT NULL,
  `procedure_code`  VARCHAR(16)     NOT NULL COMMENT 'CPT/HCPCS',
  `description`     VARCHAR(255)        NULL,
  `modifiers`       JSON                NULL,
  `units`           DECIMAL(10,3)   NOT NULL DEFAULT 1,
  `diagnosis_pointers` JSON             NULL
                      COMMENT 'up to four per line (13 §13.5); ALL A/P diagnoses carry over, in A/P order (06 §7.2)',
  `derived_pointers` JSON               NULL COMMENT 'the derived default, retained alongside any biller edit',
  `billable`        TINYINT(1)      NOT NULL DEFAULT 1,
  `source`          ENUM('em_calculator','signed_order','encounter_procedure','manual')
                                    NOT NULL,
  `source_order_id` BIGINT UNSIGNED     NULL,
  `source_procedure_id` BIGINT UNSIGNED NULL,
  `charge_amount`   DECIMAL(12,2)       NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  `updated_at`      DATETIME(3)     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_claim_line_no` (`claim_id`,`line_no`),
  KEY `ix_zdoc_claim_line_claim` (`claim_id`),
  KEY `ix_zdoc_claim_line_order` (`source_order_id`),
  KEY `ix_zdoc_claim_line_proc`  (`source_procedure_id`),
  CONSTRAINT `fk_zdoc_claim_line_claim`
    FOREIGN KEY (`claim_id`) REFERENCES `zdoc_claim` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_claim_line_order`
    FOREIGN KEY (`source_order_id`) REFERENCES `zdoc_order` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_claim_line_procedure`
    FOREIGN KEY (`source_procedure_id`) REFERENCES `zdoc_encounter_procedure` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_bundling_edit
CREATE TABLE `zdoc_bundling_edit` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_a`          VARCHAR(16)     NOT NULL,
  `code_b`          VARCHAR(16)     NOT NULL,
  `edit_type`       ENUM('mutually_exclusive','column1_column2','mue','other')
                                    NOT NULL,
  `modifier_allowed` TINYINT(1)     NOT NULL DEFAULT 0,
  `message`         VARCHAR(255)    NOT NULL,
  `effective_from`  DATE            NOT NULL,
  `effective_to`    DATE                NULL,
  `source_citation` VARCHAR(255)        NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_bundling_edit` (`code_a`,`code_b`,`effective_from`),
  KEY `ix_zdoc_bundling_edit_lookup` (`code_a`,`effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_revenue_cycle_task
CREATE TABLE `zdoc_revenue_cycle_task` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `patient_pid`     BIGINT          NOT NULL,
  `claim_id`        BIGINT UNSIGNED     NULL,
  `task_type`       ENUM('registration_gap','eligibility','assignment_of_benefits',
                         'authorization','demographic_mismatch','other'
                        )            NOT NULL,
  `detail`          VARCHAR(255)    NOT NULL,
  `status`          ENUM('new','working','resolved','waived')
                                    NOT NULL DEFAULT 'new',
  `assigned_role`   VARCHAR(64)     NOT NULL DEFAULT 'front_office',
  `assigned_user_id` BIGINT             NULL,
  `created_at`      DATETIME(3)     NOT NULL,
  `completed_at`    DATETIME(3)         NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_rc_task_queue` (`status`,`task_type`,`created_at`),
  KEY `ix_zdoc_rc_task_enc`   (`encounter_id`),
  CONSTRAINT `fk_zdoc_rc_task_claim`
    FOREIGN KEY (`claim_id`) REFERENCES `zdoc_claim` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_consistency_rule
CREATE TABLE `zdoc_consistency_rule` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_key`        VARCHAR(64)     NOT NULL COMMENT 'stable key, referenced by dismissals and tests',
  `name`            VARCHAR(191)    NOT NULL,
  `severity`        ENUM('error','warning')
                                    NOT NULL
                      COMMENT 'errors BLOCK signing; warnings are shown at sign-off and dismissible WITH A REASON',
  `when_jsonb`      JSON            NOT NULL
                      COMMENT 'e.g. {"section":"hpi","element":"Associated Symptoms","option":"numbness"}',
  `then_conflicts_jsonb` JSON       NOT NULL
                      COMMENT 'e.g. {"section":"ros","element":"Neurological","option":"no numbness"}',
  `message`         VARCHAR(255)    NOT NULL,
  `evaluate_on_change` TINYINT(1)   NOT NULL DEFAULT 1,
  `evaluate_at_signoff` TINYINT(1)  NOT NULL DEFAULT 1,
  `is_system_rule`  TINYINT(1)      NOT NULL DEFAULT 0
                      COMMENT '1 = seeded, cannot be deleted. The NKDA rule is a system rule',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zdoc_consistency_rule_key` (`rule_key`),
  KEY `ix_zdoc_consistency_rule_active` (`is_active`,`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf

#IfNotTable zdoc_consistency_violation
CREATE TABLE `zdoc_consistency_violation` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `encounter_id`    BIGINT          NOT NULL,
  `rule_id`         BIGINT UNSIGNED NOT NULL,
  `severity`        ENUM('error','warning')
                                    NOT NULL,
  `message`         VARCHAR(255)    NOT NULL,
  `deep_link_section_key` VARCHAR(32)   NULL,
  `deep_link_finding_id` BIGINT UNSIGNED NULL,
  `status`          ENUM('open','resolved','dismissed')
                                    NOT NULL DEFAULT 'open',
  `dismissal_id`    BIGINT UNSIGNED     NULL,
  `detected_at`     DATETIME(3)     NOT NULL,
  `resolved_at`     DATETIME(3)         NULL,
  PRIMARY KEY (`id`),
  KEY `ix_zdoc_consistency_violation_enc` (`encounter_id`,`status`,`severity`),
  KEY `ix_zdoc_consistency_violation_rule` (`rule_id`),
  CONSTRAINT `fk_zdoc_consistency_violation_rule`
    FOREIGN KEY (`rule_id`) REFERENCES `zdoc_consistency_rule` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zdoc_consistency_violation_dismissal`
    FOREIGN KEY (`dismissal_id`) REFERENCES `zdoc_warning_dismissal` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
#EndIf


-- ---------------------------------------------------------------------------
-- SEED 1 of 4 -- section authoring policy (22 rows, one per section_key)
-- Shipped defaults. CONFIG, not code: a practice wanting MAs out of the A/P
-- flips one row. Reviewed before go-live -- see OPEN-QUESTIONS.md Q11.
-- ---------------------------------------------------------------------------

#IfNotRow zdoc_section_policy section_key reason
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('reason',               1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key chief_complaint
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('chief_complaint',      1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key vitals
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('vitals',               1,1,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key allergies
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('allergies',            1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key medications
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('medications',          1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key problems
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('problems',             1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key family_hx
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('family_hx',            1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key social_hx
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('social_hx',            1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key surgical_hx
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('surgical_hx',          1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key past_medical_hx
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('past_medical_hx',      1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key screening
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('screening',            1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key hpi
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('hpi',                  1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key ros
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('ros',                  1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key imaging
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('imaging',              1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key pe
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('pe',                   1,0,1,1,'block',
     'I personally performed this examination. Findings not modified above are correct as documented.',
     NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key assessment_plan
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('assessment_plan',      1,0,1,1,'block',
     'The assessment and plan are mine. I performed the medical decision-making for this visit.',
     NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key procedures
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('procedures',           1,0,1,1,'block',
     'I personally performed this procedure as documented.',
     NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key orders
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('orders',               1,0,0,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key time_spent
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('time_spent',           1,1,0,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key patient_instructions
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('patient_instructions', 1,0,0,0,'warn', NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key discussion_notes
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('discussion_notes',     1,0,0,0,'warn', NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key follow_up
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('follow_up',            1,0,0,0,'warn', NULL,NOW());
#EndIf


-- ---------------------------------------------------------------------------
-- SEED 2 of 4 -- consistency rules (6 rows)
-- `is_system_rule = 1` marks rules that cannot be deleted. The NKDA rule is
-- one of them: it is the hard stop that blocks signing.
-- The when/then payloads are ILLUSTRATIVE SHAPES, not a frozen grammar --
-- WP-10 owns the resolver and proposes the grammar. `rule_key` IS stable and
-- is what the acceptance tests reference. See OPEN-QUESTIONS.md Q15.
-- ---------------------------------------------------------------------------

#IfNotRow zdoc_consistency_rule rule_key nkda_vs_active_allergy
INSERT INTO `zdoc_consistency_rule`
  (`rule_key`,`name`,`severity`,`when_jsonb`,`then_conflicts_jsonb`,`message`, `evaluate_on_change`,`evaluate_at_signoff`,`is_system_rule`,`is_active`, `created_at`,`updated_at`)
VALUES ('nkda_vs_active_allergy',
   'NKDA asserted alongside an active allergy',
   'error',
   '{"assertion":"nkda","equals":true}',
   '{"exists":{"table":"zdoc_allergy","status":"active"}}',
   'No-known-drug-allergies is asserted while this patient has an active allergy on file. Resolve before signing.',
   1,1,1,1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_consistency_rule rule_key hpi_numbness_vs_ros_no_numbness
INSERT INTO `zdoc_consistency_rule`
  (`rule_key`,`name`,`severity`,`when_jsonb`,`then_conflicts_jsonb`,`message`, `evaluate_on_change`,`evaluate_at_signoff`,`is_system_rule`,`is_active`, `created_at`,`updated_at`)
VALUES ('hpi_numbness_vs_ros_no_numbness',
   'HPI numbness or radiation vs ROS "no numbness" / "no shooting pain"',
   'error',
   '{"section":"hpi","element_label":"Associated Symptoms","option_label_any":["numbness","radiation"]}',
   '{"section":"ros","element_label":"Neurological","option_label_any":["no numbness","no shooting pain"]}',
   'The history documents numbness or radiation while the review of systems denies it.',
   1,1,1,1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_consistency_rule rule_key hpi_weakness_vs_no_weakness
INSERT INTO `zdoc_consistency_rule`
  (`rule_key`,`name`,`severity`,`when_jsonb`,`then_conflicts_jsonb`,`message`, `evaluate_on_change`,`evaluate_at_signoff`,`is_system_rule`,`is_active`, `created_at`,`updated_at`)
VALUES ('hpi_weakness_vs_no_weakness',
   'HPI weakness vs ROS/PE "no weakness" or normal motor strength',
   'error',
   '{"section":"hpi","element_label":"Associated Symptoms","option_label_any":["weakness"]}',
   '{"section_any":["ros","pe"],"option_label_any":["no weakness","5/5"]}',
   'The history documents weakness while the review of systems or exam denies it.',
   1,1,1,1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_consistency_rule rule_key template_sex_age_mismatch
INSERT INTO `zdoc_consistency_rule`
  (`rule_key`,`name`,`severity`,`when_jsonb`,`then_conflicts_jsonb`,`message`, `evaluate_on_change`,`evaluate_at_signoff`,`is_system_rule`,`is_active`, `created_at`,`updated_at`)
VALUES ('template_sex_age_mismatch',
   'Template applied that does not match the patient sex or age',
   'error',
   '{"event":"template_applied"}',
   '{"check":"template_demographic_match"}',
   'A template was applied whose sex or age range does not match this patient.',
   1,1,1,1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_consistency_rule rule_key diagnosis_without_supporting_content
INSERT INTO `zdoc_consistency_rule`
  (`rule_key`,`name`,`severity`,`when_jsonb`,`then_conflicts_jsonb`,`message`, `evaluate_on_change`,`evaluate_at_signoff`,`is_system_rule`,`is_active`, `created_at`,`updated_at`)
VALUES ('diagnosis_without_supporting_content',
   'A/P diagnosis with no corresponding PE or HPI content',
   'warning',
   '{"section":"assessment_plan","each":"diagnosis"}',
   '{"absent_support_in":["hpi","pe"]}',
   'This assessment has no supporting history or examination content documented.',
   0,1,1,1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_consistency_rule rule_key active_problem_absent_from_ap
INSERT INTO `zdoc_consistency_rule`
  (`rule_key`,`name`,`severity`,`when_jsonb`,`then_conflicts_jsonb`,`message`, `evaluate_on_change`,`evaluate_at_signoff`,`is_system_rule`,`is_active`, `created_at`,`updated_at`)
VALUES ('active_problem_absent_from_ap',
   'Active problem absent from the A/P',
   'warning',
   '{"exists":{"table":"zdoc_problem","status":"active"}}',
   '{"absent_in":{"table":"zdoc_encounter_diagnosis"}}',
   'An active problem on the problem list does not appear in this assessment and plan.',
   0,1,1,1,NOW(),NOW());
#EndIf


-- ---------------------------------------------------------------------------
-- SEED 3 of 4 -- shared vocabulary headers (12 rows, doc 06 Appendix B)
-- ---------------------------------------------------------------------------

#IfNotRow zdoc_vocabulary vocab_key response_to_treatment
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('response_to_treatment','Response to treatment',
   'Shared response scale. Every element asking how a prior treatment worked MUST use this, so no element is missing a step (06 §3.2). All items are descriptor answers: polarity neutral.',
   'ordinal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key laterality
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('laterality','Laterality',
   'Structural side attribute. Laterality is NEVER part of a name (06 §3.4). Mirrors are structurally impossible to omit.',
   'nominal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key severity_scale
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('severity_scale','Severity scale (0-10)',
   'Integer numeric rating scale, 0 through 10. Used for current pain, worst pain and any other 0-10 slot. 0 is an asserted absence.',
   'integer_range',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key frequency
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('frequency','Frequency',
   'Temporal pattern of a symptom. Descriptors of a symptom asserted elsewhere: polarity neutral.',
   'nominal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key onset
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('onset','Onset',
   'How the symptom began. Descriptors: polarity neutral.',
   'nominal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key pain_quality
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('pain_quality','Pain quality',
   'Descriptive character of pain. These ASSERT the presence of a quality, so polarity positive.',
   'nominal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key reflex_grade
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('reflex_grade','Reflex grade',
   'Deep tendon reflex grading. 2+ is the normal value and is a descriptor (neutral, is_normal = 1), not an asserted absence.',
   'ordinal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key motor_grade
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('motor_grade','Motor strength grade',
   'Manual muscle testing, 0/5 through 5/5. 5/5 is the normal value and is a descriptor (neutral, is_normal = 1).',
   'ordinal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key sensation
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('sensation','Sensation',
   'Sensory examination result. "normal" is a descriptor (neutral, is_normal = 1); decreased, absent and hyperesthetic assert a finding.',
   'ordinal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key special_test_result
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('special_test_result','Special test result',
   'Provocative test outcome. "negative" IS an asserted absence. "not performed" is a first-class value, distinct from an absent finding, and asserts nothing about the patient.',
   'nominal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key lumbar_levels
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('lumbar_levels','Lumbar and thoracolumbar levels',
   'Anatomical level qualifier for the lumbar region, T12 through S2 (06 §3.5). Referenced by zdoc_template_option.anatomic_level_vocabulary_id.',
   'ordinal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key cervical_levels
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('cervical_levels','Cervical levels',
   'Anatomical level qualifier for the cervical region, C2 through C8 (06 §3.5).',
   'ordinal',1,NOW(),NOW());
#EndIf


-- ---------------------------------------------------------------------------
-- SEED 4 of 4 -- shared vocabulary items (68 rows across the 12 vocabularies)
--
-- DOCUMENTED EXCEPTION TO THE #IfNotRow RULE, and the only one in this file.
-- These rows are keyed by (vocabulary_id, item_key), where vocabulary_id is a
-- surrogate not known when this file is written -- each statement resolves it
-- by joining on vocab_key. #IfNotRow and #IfNotRow2D both need literal values,
-- so neither can express the guard.
--
-- Instead these statements are NATURALLY REPEATABLE, which the conventions
-- document permits explicitly: each is INSERT ... SELECT ... ON DUPLICATE KEY
-- UPDATE against the UNIQUE key uq_zdoc_vocabulary_item (vocabulary_id,
-- item_key). Re-running inserts nothing twice and refreshes label, polarity,
-- is_normal, numeric_value and sort_order -- which is the intended behaviour,
-- since item ids must stay stable for zdoc_template_option to reference them.
--
-- See OPEN-QUESTIONS.md Q42 if the CI idempotency test is textual rather than
-- behavioural, in which case these need restructuring rather than exempting.
--
-- POLARITY / is_normal authoring rule: CONTRACTS.md §3.8. polarity is the
-- STRUCTURAL role (a normal descriptor is `neutral`, only an asserted absence
-- is `negative`); is_normal is clinical normality. Do not conflate them.
-- ---------------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, t.`polarity`, t.`is_normal`, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'aggravated_symptoms' AS item_key, 'aggravated symptoms' AS label, 'neutral' AS polarity, 0 AS is_normal, -1 AS numeric_value, 10 AS sort_order
  UNION ALL SELECT 'did_not_help',         'did not help',         'neutral', 0, 0, 20
  UNION ALL SELECT 'helped_a_little',      'helped a little',      'neutral', 0, 1, 30
  UNION ALL SELECT 'helped_temporarily',   'helped temporarily',   'neutral', 0, 2, 40
  UNION ALL SELECT 'helped_significantly', 'helped significantly', 'neutral', 0, 3, 50
) t
WHERE v.`vocab_key` = 'response_to_treatment'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, 'neutral', 0, NULL, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'right' AS item_key, 'right' AS label, 10 AS sort_order
  UNION ALL SELECT 'left',      'left',      20
  UNION ALL SELECT 'bilateral', 'bilateral', 30
  UNION ALL SELECT 'n/a',       'n/a',       40
) t
WHERE v.`vocab_key` = 'laterality'
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `polarity`   = VALUES(`polarity`),
  `is_normal`  = VALUES(`is_normal`),
  `sort_order` = VALUES(`sort_order`),
  `is_active`  = 1;

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, t.`polarity`, t.`is_normal`, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT '0' AS item_key, '0/10' AS label, 'negative' AS polarity, 1 AS is_normal, 0 AS numeric_value, 0 AS sort_order
  UNION ALL SELECT '1',  '1/10',  'positive', 0,  1,  10
  UNION ALL SELECT '2',  '2/10',  'positive', 0,  2,  20
  UNION ALL SELECT '3',  '3/10',  'positive', 0,  3,  30
  UNION ALL SELECT '4',  '4/10',  'positive', 0,  4,  40
  UNION ALL SELECT '5',  '5/10',  'positive', 0,  5,  50
  UNION ALL SELECT '6',  '6/10',  'positive', 0,  6,  60
  UNION ALL SELECT '7',  '7/10',  'positive', 0,  7,  70
  UNION ALL SELECT '8',  '8/10',  'positive', 0,  8,  80
  UNION ALL SELECT '9',  '9/10',  'positive', 0,  9,  90
  UNION ALL SELECT '10', '10/10', 'positive', 0, 10, 100
) t
WHERE v.`vocab_key` = 'severity_scale'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, 'neutral', 0, NULL, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'constant' AS item_key, 'constant' AS label, 10 AS sort_order
  UNION ALL SELECT 'intermittent', 'intermittent', 20
  UNION ALL SELECT 'nocturnal',    'nocturnal',    30
) t
WHERE v.`vocab_key` = 'frequency'
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `polarity`   = VALUES(`polarity`),
  `is_normal`  = VALUES(`is_normal`),
  `sort_order` = VALUES(`sort_order`),
  `is_active`  = 1;

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, 'neutral', 0, NULL, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'sudden' AS item_key, 'sudden' AS label, 10 AS sort_order
  UNION ALL SELECT 'gradual', 'gradual', 20
  UNION ALL SELECT 'chronic', 'chronic', 30
) t
WHERE v.`vocab_key` = 'onset'
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `polarity`   = VALUES(`polarity`),
  `is_normal`  = VALUES(`is_normal`),
  `sort_order` = VALUES(`sort_order`),
  `is_active`  = 1;

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, 'positive', 0, NULL, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'throbbing' AS item_key, 'throbbing' AS label, 10 AS sort_order
  UNION ALL SELECT 'tightness', 'tightness', 20
  UNION ALL SELECT 'numbness',  'numbness',  30
  UNION ALL SELECT 'burning',   'burning',   40
  UNION ALL SELECT 'aching',    'aching',    50
  UNION ALL SELECT 'cramping',  'cramping',  60
  UNION ALL SELECT 'sharp',     'sharp',     70
  UNION ALL SELECT 'dull',      'dull',      80
  UNION ALL SELECT 'tingling',  'tingling',  90
) t
WHERE v.`vocab_key` = 'pain_quality'
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `polarity`   = VALUES(`polarity`),
  `is_normal`  = VALUES(`is_normal`),
  `sort_order` = VALUES(`sort_order`),
  `is_active`  = 1;

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, t.`polarity`, t.`is_normal`, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'absent' AS item_key, 'absent' AS label, 'positive' AS polarity, 0 AS is_normal, 0 AS numeric_value, 10 AS sort_order
  UNION ALL SELECT '1plus', '1+', 'positive', 0, 1, 20
  UNION ALL SELECT '2plus', '2+', 'neutral',  1, 2, 30
  UNION ALL SELECT '3plus', '3+', 'positive', 0, 3, 40
  UNION ALL SELECT '4plus', '4+', 'positive', 0, 4, 50
) t
WHERE v.`vocab_key` = 'reflex_grade'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, t.`polarity`, t.`is_normal`, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT '0_5' AS item_key, '0/5' AS label, 'positive' AS polarity, 0 AS is_normal, 0 AS numeric_value, 10 AS sort_order
  UNION ALL SELECT '1_5', '1/5', 'positive', 0, 1, 20
  UNION ALL SELECT '2_5', '2/5', 'positive', 0, 2, 30
  UNION ALL SELECT '3_5', '3/5', 'positive', 0, 3, 40
  UNION ALL SELECT '4_5', '4/5', 'positive', 0, 4, 50
  UNION ALL SELECT '5_5', '5/5', 'neutral',  1, 5, 60
) t
WHERE v.`vocab_key` = 'motor_grade'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, t.`polarity`, t.`is_normal`, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'normal' AS item_key, 'normal' AS label, 'neutral' AS polarity, 1 AS is_normal, 0 AS numeric_value, 10 AS sort_order
  UNION ALL SELECT 'decreased',     'decreased',     'positive', 0, 1, 20
  UNION ALL SELECT 'absent',        'absent',        'positive', 0, 2, 30
  UNION ALL SELECT 'hyperesthetic', 'hyperesthetic', 'positive', 0, 3, 40
) t
WHERE v.`vocab_key` = 'sensation'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, t.`polarity`, t.`is_normal`, NULL, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'positive' AS item_key, 'positive' AS label, 'positive' AS polarity, 0 AS is_normal, 10 AS sort_order
  UNION ALL SELECT 'negative',      'negative',      'negative', 1, 20
  UNION ALL SELECT 'not_performed', 'not performed', 'neutral',  0, 30
) t
WHERE v.`vocab_key` = 'special_test_result'
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `polarity`   = VALUES(`polarity`),
  `is_normal`  = VALUES(`is_normal`),
  `sort_order` = VALUES(`sort_order`),
  `is_active`  = 1;

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, 'neutral', 0, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'T12' AS item_key, 'T12' AS label, 12 AS numeric_value, 10 AS sort_order
  UNION ALL SELECT 'L1', 'L1', 13, 20
  UNION ALL SELECT 'L2', 'L2', 14, 30
  UNION ALL SELECT 'L3', 'L3', 15, 40
  UNION ALL SELECT 'L4', 'L4', 16, 50
  UNION ALL SELECT 'L5', 'L5', 17, 60
  UNION ALL SELECT 'S1', 'S1', 18, 70
  UNION ALL SELECT 'S2', 'S2', 19, 80
) t
WHERE v.`vocab_key` = 'lumbar_levels'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, 'neutral', 0, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'C2' AS item_key, 'C2' AS label, 2 AS numeric_value, 10 AS sort_order
  UNION ALL SELECT 'C3', 'C3', 3, 20
  UNION ALL SELECT 'C4', 'C4', 4, 30
  UNION ALL SELECT 'C5', 'C5', 5, 40
  UNION ALL SELECT 'C6', 'C6', 6, 50
  UNION ALL SELECT 'C7', 'C7', 7, 60
  UNION ALL SELECT 'C8', 'C8', 8, 70
) t
WHERE v.`vocab_key` = 'cervical_levels'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;

-- ---------------------------------------------------------------------------
-- SCHEMA REVISION BOOKKEEPING
-- src/SchemaVersion.php reads this table and the admin status page displays it.
-- The scaffold owns the table and seeds the 'core' component; this records the
-- clinical component separately so the two advance independently.
-- The UPDATE is naturally idempotent -- it is a no-op once at this revision.
-- ---------------------------------------------------------------------------

#IfNotRow zdoc_schema_version component clinical
INSERT INTO `zdoc_schema_version` (`component`, `version`, `note`)
VALUES ('clinical', 1, 'contract 1.5.0: 61 tables, 12 vocabularies, 6 rules, 22 policies');
#EndIf

UPDATE `zdoc_schema_version`
   SET `version` = 1,
       `note` = 'contract 1.5.0: 61 tables, 12 vocabularies, 6 rules, 22 policies'
 WHERE `component` = 'clinical' AND `version` < 1;
