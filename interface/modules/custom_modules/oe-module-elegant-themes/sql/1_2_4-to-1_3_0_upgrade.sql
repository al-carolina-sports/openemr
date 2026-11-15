--
-- Upgrade 1.2.x -> 1.3.0
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
