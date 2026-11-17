--
-- Annie's Elegant Themes
--
-- oe_module_elegant_themes_prefs  one row per user (user_id = 0 is the site default).
-- theme = '' means "off" (OpenEMR's own theme is used unchanged).
--

#IfNotTable oe_module_elegant_themes_prefs
CREATE TABLE `oe_module_elegant_themes_prefs` (
    `user_id` BIGINT NOT NULL,
    `theme` VARCHAR(40) NOT NULL DEFAULT '',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB COMMENT='Elegant Themes per-user theme choice';
#EndIf

--
-- v1.3.0: oe_module_aet_recent  last 8 distinct patients opened per user (newest first by last_seen).
--
#IfNotTable oe_module_aet_recent
CREATE TABLE `oe_module_aet_recent` (
    `user_id` BIGINT NOT NULL,
    `pid` BIGINT NOT NULL,
    `last_seen` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`user_id`, `pid`),
    KEY `user_seen` (`user_id`, `last_seen`)
) ENGINE=InnoDB COMMENT='Elegant Themes recent patients per user';
#EndIf
