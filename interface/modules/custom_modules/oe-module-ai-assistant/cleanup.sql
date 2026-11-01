-- Removes every table of the AI Assistant module, including its audit log.
-- OpenEMR does not run this file. Run it by hand only if the assistant's
-- chat history and audit log should be destroyed. Take a database backup first.
DROP TABLE IF EXISTS `mod_ai_assistant_conversation`, `mod_ai_assistant_log`, `mod_ai_assistant_settings`;
