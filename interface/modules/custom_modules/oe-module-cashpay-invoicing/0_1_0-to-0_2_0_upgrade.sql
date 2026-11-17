--
-- Cash-Pay Invoicing 0.1.0 -> 0.2.0 (phase 2: quotes and invoices)
-- Run from Modules > Manage Modules > Upgrade.
--

#IfMissingColumn cpi_line code
ALTER TABLE `cpi_line` ADD COLUMN `code` VARCHAR(20) NULL COMMENT 'CPT or catalog code printed on the line' AFTER `description`;
#EndIf

#IfMissingColumn cpi_line charge_category
ALTER TABLE `cpi_line` ADD COLUMN `charge_category` VARCHAR(12) NOT NULL DEFAULT 'provider' COMMENT 'provider | product | or | anesthesia | recovery | assistant | other' AFTER `code`;
#EndIf
