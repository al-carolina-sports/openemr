-- Run manually only if the Module Manager could not drop the tables on unregister.
DROP TABLE IF EXISTS `oe_module_ecs_view_members`;
DROP TABLE IF EXISTS `oe_module_ecs_views`;
DROP TABLE IF EXISTS `oe_module_ecs_settings`;
DELETE FROM `user_settings` WHERE `setting_label` = 'oe_module_ecs_last_view';
DELETE FROM `user_settings` WHERE `setting_label` IN ('oe_module_ecs_hide_sat', 'oe_module_ecs_hide_sun');
