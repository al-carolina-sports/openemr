-- Run manually only. Unregistering the module drops the settings and session
-- tables and disables the form; saved AI Scribe Notes (form_elegant_ai_scribe, form_elegant_ai_scribe_soap)
-- are clinical records and are never dropped automatically.
DROP TABLE IF EXISTS `oe_module_eas_sessions`;
DROP TABLE IF EXISTS `oe_module_eas_settings`;
UPDATE `registry` SET `state` = 0 WHERE `directory` IN ('elegant_ai_scribe', 'elegant_ai_scribe_soap');
