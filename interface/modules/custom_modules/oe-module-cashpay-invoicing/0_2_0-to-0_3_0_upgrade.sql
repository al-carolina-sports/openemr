--
-- Cash-Pay Invoicing 0.2.0 -> 0.3.0 (phase 2 part 3: memberships and Stripe)
-- Run from Modules > Manage Modules > Upgrade.
--

#IfMissingColumn cpi_patient_membership facility_id
ALTER TABLE `cpi_patient_membership` ADD COLUMN `facility_id` INT NULL COMMENT 'income facility for the membership invoices' AFTER `next_bill_date`;
#EndIf

#IfMissingColumn cpi_patient_membership last_autopay_error
ALTER TABLE `cpi_patient_membership` ADD COLUMN `last_autopay_error` VARCHAR(255) NULL AFTER `facility_id`;
#EndIf

#IfMissingColumn cpi_payment processor_account
ALTER TABLE `cpi_payment` ADD COLUMN `processor_account` VARCHAR(40) NULL COMMENT 'Stripe account key the money went to' AFTER `processor`;
#EndIf

#IfMissingColumn cpi_stripe_customer account_key
ALTER TABLE `cpi_stripe_customer` ADD COLUMN `account_key` VARCHAR(40) NOT NULL DEFAULT 'default' COMMENT 'which Stripe account (one per income entity)' AFTER `pid`, DROP PRIMARY KEY, ADD PRIMARY KEY (`pid`, `account_key`);
#EndIf

#IfNotTable cpi_pay_request
CREATE TABLE `cpi_pay_request` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `pid` BIGINT NOT NULL,
    `purpose` VARCHAR(8) NOT NULL COMMENT 'payment | setup',
    `account_key` VARCHAR(40) NOT NULL,
    `checkout_session_id` VARCHAR(100) NOT NULL,
    `url` VARCHAR(1000) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `invoice_ids` VARCHAR(255) NULL COMMENT 'comma-separated invoice ids the payment is applied to',
    `facility_id` INT NULL,
    `status` VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open | paid | expired',
    `payment_id` BIGINT NULL,
    `sent_to` VARCHAR(150) NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_session` (`checkout_session_id`),
    KEY `idx_pid` (`pid`, `created_at`)
) ENGINE=InnoDB;
#EndIf
