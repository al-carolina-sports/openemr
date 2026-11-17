--
-- Annie's Elegant Calendar Style: Custom Views and site settings
--
-- A custom view is a named set of calendar users. owner_user_id NULL means
-- the view is shared with everyone (only admins may change shared views).
-- Safe to run more than once (install and upgrade both run this file).
--

#IfNotTable oe_module_ecs_views
CREATE TABLE `oe_module_ecs_views` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `owner_user_id` BIGINT NULL DEFAULT NULL COMMENT 'users.id; NULL = shared',
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `owner_user_id` (`owner_user_id`)
) ENGINE=InnoDB COMMENT='Elegant Calendar Style custom views';
#EndIf

#IfNotTable oe_module_ecs_view_members
CREATE TABLE `oe_module_ecs_view_members` (
    `view_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`view_id`, `username`),
    CONSTRAINT `oe_module_ecs_view_members_view` FOREIGN KEY (`view_id`) REFERENCES `oe_module_ecs_views` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Elegant Calendar Style custom view members';
INSERT INTO `oe_module_ecs_views` (`name`, `owner_user_id`, `sort_order`) VALUES ('Providers', NULL, 10), ('Aestheticians', NULL, 20), ('All Users', NULL, 30);
INSERT INTO `oe_module_ecs_view_members` (`view_id`, `username`) SELECT v.`id`, u.`username` FROM `oe_module_ecs_views` v JOIN `users` u ON u.`username` IN ('aantony', 'jyap') WHERE v.`name` = 'Providers' AND v.`owner_user_id` IS NULL;
INSERT INTO `oe_module_ecs_view_members` (`view_id`, `username`) SELECT v.`id`, u.`username` FROM `oe_module_ecs_views` v JOIN `users` u ON u.`username` IN ('smaazouzi', 'ykim') WHERE v.`name` = 'Aestheticians' AND v.`owner_user_id` IS NULL;
INSERT INTO `oe_module_ecs_view_members` (`view_id`, `username`) SELECT v.`id`, u.`username` FROM `oe_module_ecs_views` v JOIN `users` u ON u.`username` IN ('aantony', 'jyap', 'smaazouzi', 'ykim') WHERE v.`name` = 'All Users' AND v.`owner_user_id` IS NULL;
#EndIf

-- v1.3.1: site settings (one row per key), e.g. the Hide Saturday / Hide
-- Sunday site defaults. Users' own choices live in user_settings.
#IfNotTable oe_module_ecs_settings
CREATE TABLE `oe_module_ecs_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` VARCHAR(255) NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB COMMENT='Elegant Calendar Style site settings';
INSERT INTO `oe_module_ecs_settings` (`setting_key`, `setting_value`) VALUES ('hide_saturday', '1'), ('hide_sunday', '1');
#EndIf
