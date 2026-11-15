--
-- Appointment Hover Card module: settings table
--
-- One row per setting. Values are JSON-encoded so a single table holds
-- booleans, numbers, lists and the per-field configuration.
--

#IfNotTable oe_module_appt_hovercard_settings
CREATE TABLE `oe_module_appt_hovercard_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` MEDIUMTEXT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB COMMENT='Appointment Hover Card module settings';
#EndIf
