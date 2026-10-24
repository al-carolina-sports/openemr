--
-- Compact Theme module: practice-wide defaults.
-- Rows in `globals` are needed so per-user overrides in user_settings apply.
-- INSERT IGNORE keeps any value an admin already saved.
--
INSERT IGNORE INTO `globals` (`gl_name`, `gl_index`, `gl_value`) VALUES ('cmt_theme', 0, 'paper');
INSERT IGNORE INTO `globals` (`gl_name`, `gl_index`, `gl_value`) VALUES ('cmt_density', 0, 'compact');
INSERT IGNORE INTO `globals` (`gl_name`, `gl_index`, `gl_value`) VALUES ('cmt_menu_style', 0, 'both');
INSERT IGNORE INTO `globals` (`gl_name`, `gl_index`, `gl_value`) VALUES ('cmt_accent', 0, '');
