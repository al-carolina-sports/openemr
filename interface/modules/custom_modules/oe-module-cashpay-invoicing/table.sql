--
-- Cash-Pay Invoicing & Commissions module (oe-module-cashpay-invoicing)
--
-- All tables are prefixed cpi_ and live only in this module, so OpenEMR
-- upgrades never touch them. Core tables are referenced by id only:
--   patient_data.pid, users.id (providers/sellers), facility.id.
-- Money is DECIMAL(12,2); rates/percentages are DECIMAL(7,4).
-- The module is cash-pay only: there is no insurance invoice type.
--

-- ---------------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------------
#IfNotTable cpi_settings
CREATE TABLE `cpi_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` MEDIUMTEXT NOT NULL COMMENT 'JSON-encoded value',
    `updated_by` INT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB COMMENT='Module settings: card service charge %, rewards program, points rate, etc.';
#EndIf

-- Tax 1 and Tax 2: two true taxes (e.g. NC sales tax). The credit card
-- service charge is NOT a tax and is configured in cpi_settings.
#IfNotTable cpi_tax_type
CREATE TABLE `cpi_tax_type` (
    `slot` TINYINT NOT NULL COMMENT '1 or 2',
    `name` VARCHAR(100) NOT NULL,
    `rate_pct` DECIMAL(7,4) NOT NULL DEFAULT 0,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`slot`)
) ENGINE=InnoDB;
#EndIf

-- ---------------------------------------------------------------------------
-- Catalog: procedures
-- ---------------------------------------------------------------------------
#IfNotTable cpi_procedure_category
CREATE TABLE `cpi_procedure_category` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_procedure
CREATE TABLE `cpi_procedure` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `category_id` INT NULL,
    `layman_terms` TEXT NULL,
    `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `expected_hours` DECIMAL(6,2) NOT NULL DEFAULT 0,
    `cpt_code` VARCHAR(20) NULL,
    `gl_code` VARCHAR(50) NULL,
    `barcode` VARCHAR(64) NULL,
    `tax1` TINYINT(1) NOT NULL DEFAULT 0,
    `tax2` TINYINT(1) NOT NULL DEFAULT 0,
    `award_points_eligible` TINYINT(1) NOT NULL DEFAULT 0,
    `allow_manual_desc` TINYINT(1) NOT NULL DEFAULT 0,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `source` VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual | patientnow_import',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_category` (`category_id`),
    KEY `idx_active_name` (`active`, `name`)
) ENGINE=InnoDB;
#EndIf

-- Price/hours override by provider and/or facility (most specific wins).
#IfNotTable cpi_procedure_fee
CREATE TABLE `cpi_procedure_fee` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `procedure_id` INT NOT NULL,
    `provider_id` INT NULL COMMENT 'users.id',
    `facility_id` INT NULL COMMENT 'facility.id',
    `price` DECIMAL(12,2) NOT NULL,
    `hours` DECIMAL(6,2) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_scope` (`procedure_id`, `provider_id`, `facility_id`)
) ENGINE=InnoDB;
#EndIf

-- Products consumed by a procedure (stock is decremented when the line is received).
#IfNotTable cpi_procedure_product
CREATE TABLE `cpi_procedure_product` (
    `procedure_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `qty` DECIMAL(10,3) NOT NULL DEFAULT 1,
    PRIMARY KEY (`procedure_id`, `product_id`)
) ENGINE=InnoDB;
#EndIf

-- ---------------------------------------------------------------------------
-- Catalog: products and inventory
-- ---------------------------------------------------------------------------
#IfNotTable cpi_manufacturer
CREATE TABLE `cpi_manufacturer` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_vendor
CREATE TABLE `cpi_vendor` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `contact` VARCHAR(150) NULL,
    `email` VARCHAR(150) NULL,
    `phone` VARCHAR(40) NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_product_category
CREATE TABLE `cpi_product_category` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_product
CREATE TABLE `cpi_product` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `style` VARCHAR(100) NULL,
    `catalog_no` VARCHAR(64) NULL,
    `barcode` VARCHAR(64) NULL,
    `manufacturer_id` INT NULL,
    `category_id` INT NULL,
    `cost_to_practice` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `price_to_patient` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `tax1` TINYINT(1) NOT NULL DEFAULT 0,
    `tax2` TINYINT(1) NOT NULL DEFAULT 0,
    `award_points_eligible` TINYINT(1) NOT NULL DEFAULT 0,
    `allow_manual_desc` TINYINT(1) NOT NULL DEFAULT 0,
    `no_print` TINYINT(1) NOT NULL DEFAULT 0,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `source` VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual | patientnow_import',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_barcode` (`barcode`),
    KEY `idx_category` (`category_id`),
    KEY `idx_manufacturer` (`manufacturer_id`),
    KEY `idx_active_name` (`active`, `name`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_product_vendor
CREATE TABLE `cpi_product_vendor` (
    `product_id` INT NOT NULL,
    `vendor_id` INT NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`product_id`, `vendor_id`)
) ENGINE=InnoDB;
#EndIf

-- Optional per-facility retail price (PatientNow had a few office-specific prices).
#IfNotTable cpi_product_facility_price
CREATE TABLE `cpi_product_facility_price` (
    `product_id` INT NOT NULL,
    `facility_id` INT NOT NULL,
    `price_to_patient` DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (`product_id`, `facility_id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_stock
CREATE TABLE `cpi_stock` (
    `product_id` INT NOT NULL,
    `facility_id` INT NOT NULL,
    `on_hand` DECIMAL(10,3) NOT NULL DEFAULT 0,
    `on_order` DECIMAL(10,3) NOT NULL DEFAULT 0,
    `low_stock_level` DECIMAL(10,3) NOT NULL DEFAULT 0,
    PRIMARY KEY (`product_id`, `facility_id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_stock_reason
CREATE TABLE `cpi_stock_reason` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_stock_txn
CREATE TABLE `cpi_stock_txn` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `product_id` INT NOT NULL,
    `facility_id` INT NOT NULL,
    `qty_delta` DECIMAL(10,3) NOT NULL,
    `kind` VARCHAR(20) NOT NULL COMMENT 'sale | receive_order | adjust | count | return | procedure_use',
    `reason_id` INT NULL,
    `line_id` BIGINT NULL COMMENT 'cpi_line.id when caused by a sale/delivery',
    `user_id` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_product_facility` (`product_id`, `facility_id`, `created_at`)
) ENGINE=InnoDB;
#EndIf

-- ---------------------------------------------------------------------------
-- Sales packages and memberships
-- ---------------------------------------------------------------------------
#IfNotTable cpi_package
CREATE TABLE `cpi_package` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `package_type` VARCHAR(20) NOT NULL DEFAULT 'prepaid' COMMENT 'prepaid | immediate',
    `income_facility_id` INT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `allow_trades` TINYINT(1) NOT NULL DEFAULT 0,
    `comments` TEXT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `source` VARCHAR(20) NOT NULL DEFAULT 'manual',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_package_item
CREATE TABLE `cpi_package_item` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `package_id` INT NOT NULL,
    `item_type` VARCHAR(20) NOT NULL COMMENT 'procedure | product',
    `item_id` INT NOT NULL,
    `qty` DECIMAL(10,3) NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `discount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_package` (`package_id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_membership_plan
CREATE TABLE `cpi_membership_plan` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `code` VARCHAR(10) NOT NULL COMMENT 'Badge shown beside the patient name',
    `annual_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `monthly_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `monthly_points` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `package_id` INT NULL COMMENT 'Package added each month when add_package_monthly',
    `add_package_monthly` TINYINT(1) NOT NULL DEFAULT 0,
    `auto_monthly_invoice` TINYINT(1) NOT NULL DEFAULT 0,
    `tax1` TINYINT(1) NOT NULL DEFAULT 0,
    `tax2` TINYINT(1) NOT NULL DEFAULT 0,
    `notes` TEXT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB;
#EndIf

-- A plan can carry several member-only discounts (PatientNow's Core plan had 7).
#IfNotTable cpi_membership_plan_promo
CREATE TABLE `cpi_membership_plan_promo` (
    `plan_id` INT NOT NULL,
    `promo_id` INT NOT NULL,
    PRIMARY KEY (`plan_id`, `promo_id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_patient_membership
CREATE TABLE `cpi_patient_membership` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `pid` BIGINT NOT NULL,
    `plan_id` INT NOT NULL,
    `billing_cycle` VARCHAR(10) NOT NULL DEFAULT 'monthly' COMMENT 'monthly | annual',
    `start_date` DATE NOT NULL,
    `end_date` DATE NULL,
    `status` VARCHAR(12) NOT NULL DEFAULT 'active' COMMENT 'active | frozen | cancelled',
    `autopay` TINYINT(1) NOT NULL DEFAULT 0,
    `next_bill_date` DATE NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pid` (`pid`),
    KEY `idx_billing` (`status`, `next_bill_date`)
) ENGINE=InnoDB;
#EndIf

-- ---------------------------------------------------------------------------
-- Discounts, payment methods
-- ---------------------------------------------------------------------------
#IfNotTable cpi_discount_reason
CREATE TABLE `cpi_discount_reason` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_promo_discount
CREATE TABLE `cpi_promo_discount` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `reason_id` INT NULL,
    `kind` VARCHAR(12) NOT NULL COMMENT 'percent | flat_each | flat_total',
    `amount` DECIMAL(12,4) NOT NULL DEFAULT 0,
    `members_only` TINYINT(1) NOT NULL DEFAULT 0,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `scope_json` TEXT NULL COMMENT 'invoice types, facilities, procedures, products, vendors, manufacturers, categories (empty = all)',
    `notes` TEXT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_payment_method
CREATE TABLE `cpi_payment_method` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `kind` VARCHAR(16) NOT NULL COMMENT 'cash | check | card | points | gift_card | financing | other',
    `is_refund_method` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB;
#EndIf

-- ---------------------------------------------------------------------------
-- Quotes, invoices, lines
-- ---------------------------------------------------------------------------
#IfNotTable cpi_quote
CREATE TABLE `cpi_quote` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `pid` BIGINT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `quote_date` DATE NOT NULL,
    `facility_id` INT NULL,
    `invoice_type` VARCHAR(16) NOT NULL DEFAULT 'immediate',
    `provider_id` INT NULL,
    `seller_id` INT NULL,
    `comments` TEXT NULL,
    `expires_on` DATE NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `consult_credit` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `deposit` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `emailed_at` DATETIME NULL,
    `printed_at` DATETIME NULL,
    `signed_scan_doc_id` INT NULL COMMENT 'documents.id of the hand-signed paper copy',
    `converted_invoice_id` INT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pid` (`pid`, `quote_date`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_invoice
CREATE TABLE `cpi_invoice` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `pid` BIGINT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `invoice_date` DATE NOT NULL,
    `invoice_type` VARCHAR(16) NOT NULL COMMENT 'immediate | prepaid | surgery | gift_card | membership',
    `income_facility_id` INT NULL,
    `provider_id` INT NULL,
    `seller_id` INT NULL,
    `comments` TEXT NULL,
    `deposit` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `source_quote_id` INT NULL,
    `membership_id` INT NULL,
    `locked` TINYINT(1) NOT NULL DEFAULT 0,
    `locked_by` INT NULL,
    `locked_at` DATETIME NULL,
    `voided` TINYINT(1) NOT NULL DEFAULT 0,
    `source` VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual | membership_job | migration',
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pid` (`pid`, `invoice_date`),
    KEY `idx_date_facility` (`invoice_date`, `income_facility_id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_invoice_lock_log
CREATE TABLE `cpi_invoice_lock_log` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `invoice_id` INT NOT NULL,
    `action` VARCHAR(10) NOT NULL COMMENT 'lock | unlock',
    `user_id` INT NULL,
    `reason` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_invoice` (`invoice_id`)
) ENGINE=InnoDB;
#EndIf

-- One table for quote and invoice lines.
#IfNotTable cpi_line
CREATE TABLE `cpi_line` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `parent_type` VARCHAR(8) NOT NULL COMMENT 'quote | invoice',
    `parent_id` INT NOT NULL,
    `item_type` VARCHAR(16) NOT NULL COMMENT 'procedure | product | gift_card | membership | other',
    `item_id` INT NULL,
    `package_id` INT NULL COMMENT 'set when the line came from exploding a package',
    `description` VARCHAR(255) NOT NULL,
    `qty` DECIMAL(10,3) NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'cost to practice snapshot, for margin commissions/COGS',
    `discount_amt` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `discount_reason_id` INT NULL,
    `promo_id` INT NULL,
    `net` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `tax1_amt` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `tax2_amt` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `award_points_eligible` TINYINT(1) NOT NULL DEFAULT 0,
    `service_date` DATE NULL,
    `received_date` DATE NULL COMMENT 'delivery date - prepaid lines are unearned until set',
    `received_by` INT NULL,
    `forfeited` TINYINT(1) NOT NULL DEFAULT 0,
    `provider_id` INT NULL,
    `seller_id` INT NULL,
    `service_facility_id` INT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_parent` (`parent_type`, `parent_id`),
    KEY `idx_provider_received` (`provider_id`, `received_date`),
    KEY `idx_seller` (`seller_id`)
) ENGINE=InnoDB;
#EndIf

-- ---------------------------------------------------------------------------
-- Payments (Stripe)
-- ---------------------------------------------------------------------------
#IfNotTable cpi_payment
CREATE TABLE `cpi_payment` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `pid` BIGINT NOT NULL,
    `pay_date` DATE NOT NULL,
    `pay_type` VARCHAR(12) NOT NULL COMMENT 'payment | deposit | refund | write_off',
    `method_id` INT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `gratuity` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `card_fee_pct` DECIMAL(7,4) NOT NULL DEFAULT 0 COMMENT 'credit card service charge rate at time of payment',
    `card_fee_amt` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'never charged on debit/prepaid cards',
    `processor` VARCHAR(16) NULL COMMENT 'stripe | none',
    `stripe_payment_intent` VARCHAR(64) NULL,
    `stripe_charge` VARCHAR(64) NULL,
    `stripe_refund` VARCHAR(64) NULL,
    `card_brand` VARCHAR(20) NULL,
    `card_last4` CHAR(4) NULL,
    `card_funding` VARCHAR(10) NULL COMMENT 'credit | debit | prepaid | unknown',
    `refund_of_payment_id` BIGINT NULL,
    `reference` VARCHAR(100) NULL,
    `comments` VARCHAR(255) NULL,
    `status` VARCHAR(10) NOT NULL DEFAULT 'posted' COMMENT 'pending | posted | void',
    `source` VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual | stripe_webhook | migration',
    `facility_id` INT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pid` (`pid`, `pay_date`),
    KEY `idx_stripe_pi` (`stripe_payment_intent`)
) ENGINE=InnoDB;
#EndIf

-- One payment can be spread across several invoices.
#IfNotTable cpi_payment_alloc
CREATE TABLE `cpi_payment_alloc` (
    `payment_id` BIGINT NOT NULL,
    `invoice_id` INT NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (`payment_id`, `invoice_id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_stripe_customer
CREATE TABLE `cpi_stripe_customer` (
    `pid` BIGINT NOT NULL,
    `stripe_customer_id` VARCHAR(64) NOT NULL,
    `default_payment_method` VARCHAR(64) NULL,
    `needs_card_on_file` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'set for patients who had a saved card in PatientNow',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`pid`),
    UNIQUE KEY `uq_customer` (`stripe_customer_id`)
) ENGINE=InnoDB;
#EndIf

-- Webhook idempotency.
#IfNotTable cpi_stripe_event
CREATE TABLE `cpi_stripe_event` (
    `event_id` VARCHAR(64) NOT NULL,
    `event_type` VARCHAR(64) NOT NULL,
    `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`event_id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_cash_drawer
CREATE TABLE `cpi_cash_drawer` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `facility_id` INT NOT NULL,
    `business_date` DATE NOT NULL,
    `opening_balance` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `closing_balance` DECIMAL(12,2) NULL,
    `adjustment` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `opened_by` INT NULL,
    `closed_by` INT NULL,
    `opened_at` DATETIME NULL,
    `closed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_facility_day` (`facility_id`, `business_date`)
) ENGINE=InnoDB;
#EndIf

-- ---------------------------------------------------------------------------
-- Rewards (award points) and gift cards
-- ---------------------------------------------------------------------------
#IfNotTable cpi_patient_rewards
CREATE TABLE `cpi_patient_rewards` (
    `pid` BIGINT NOT NULL,
    `enrolled` TINYINT(1) NOT NULL DEFAULT 0,
    `enrolled_by` INT NULL,
    `enrolled_at` DATETIME NULL,
    PRIMARY KEY (`pid`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_points_ledger
CREATE TABLE `cpi_points_ledger` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `pid` BIGINT NOT NULL,
    `txn_date` DATE NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `points` DECIMAL(12,2) NOT NULL COMMENT 'positive = earned, negative = redeemed/reversed',
    `dollar_value` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `source` VARCHAR(12) NOT NULL COMMENT 'manual | payment | membership | redeem | reverse | expire | migration',
    `payment_id` BIGINT NULL,
    `invoice_id` INT NULL,
    `reason` VARCHAR(255) NULL COMMENT 'required for manual adjustments',
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pid` (`pid`, `txn_date`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_gift_card
CREATE TABLE `cpi_gift_card` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(40) NOT NULL,
    `purchaser_pid` BIGINT NULL,
    `recipient_pid` BIGINT NULL,
    `recipient_name` VARCHAR(150) NULL,
    `initial_value` DECIMAL(12,2) NOT NULL,
    `balance` DECIMAL(12,2) NOT NULL,
    `issued_invoice_id` INT NULL,
    `issued_date` DATE NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `source` VARCHAR(20) NOT NULL DEFAULT 'sale' COMMENT 'sale | migration',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_gift_card_txn
CREATE TABLE `cpi_gift_card_txn` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `gift_card_id` INT NOT NULL,
    `txn_date` DATE NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL COMMENT 'positive = load/add funds, negative = redeem',
    `invoice_id` INT NULL,
    `payment_id` BIGINT NULL,
    `pid` BIGINT NULL,
    `action` VARCHAR(12) NOT NULL COMMENT 'issue | add_funds | redeem | refund | migration',
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_card` (`gift_card_id`)
) ENGINE=InnoDB;
#EndIf

-- ---------------------------------------------------------------------------
-- Financial notes
-- ---------------------------------------------------------------------------
#IfNotTable cpi_financial_note
CREATE TABLE `cpi_financial_note` (
    `pid` BIGINT NOT NULL,
    `body_html` MEDIUMTEXT NOT NULL,
    `updated_by` INT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`pid`)
) ENGINE=InnoDB;
#EndIf

-- ---------------------------------------------------------------------------
-- Commissions (cash-pay only)
-- ---------------------------------------------------------------------------
#IfNotTable cpi_commission_template
CREATE TABLE `cpi_commission_template` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `notes` TEXT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;
#EndIf

-- A rule belongs to a user OR to a template (exactly one of the two is set).
#IfNotTable cpi_commission_rule
CREATE TABLE `cpi_commission_rule` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NULL,
    `template_id` INT NULL,
    `role` VARCHAR(10) NOT NULL COMMENT 'seller | provider | manager',
    `scope_json` TEXT NULL COMMENT '{include:{...}, exclude:{...}} by invoice type, facility, procedure, procedure category, product, product category, manufacturer - empty = all',
    `basis` VARCHAR(8) NOT NULL DEFAULT 'net' COMMENT 'net | margin',
    `method` VARCHAR(8) NOT NULL COMMENT 'percent | dollar | sliding',
    `amount` DECIMAL(12,4) NOT NULL DEFAULT 0 COMMENT 'percent or dollars per unit',
    `priority` INT NOT NULL DEFAULT 100 COMMENT 'tie-break after specificity - lower wins',
    `start_date` DATE NOT NULL,
    `end_date` DATE NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`, `role`),
    KEY `idx_template` (`template_id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_commission_tier
CREATE TABLE `cpi_commission_tier` (
    `rule_id` INT NOT NULL,
    `range_start` DECIMAL(12,2) NOT NULL,
    `range_end` DECIMAL(12,2) NULL COMMENT 'NULL = no upper limit',
    `pct` DECIMAL(7,4) NOT NULL,
    PRIMARY KEY (`rule_id`, `range_start`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_user_commission_template
CREATE TABLE `cpi_user_commission_template` (
    `user_id` INT NOT NULL,
    `template_id` INT NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NULL,
    PRIMARY KEY (`user_id`, `template_id`, `start_date`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_commission_rule_log
CREATE TABLE `cpi_commission_rule_log` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `rule_id` INT NOT NULL,
    `changed_by` INT NULL,
    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `before_json` TEXT NULL,
    `after_json` TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_rule` (`rule_id`)
) ENGINE=InnoDB;
#EndIf

-- Finalized runs are frozen snapshots for payroll.
#IfNotTable cpi_commission_run
CREATE TABLE `cpi_commission_run` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `status` VARCHAR(10) NOT NULL DEFAULT 'draft' COMMENT 'draft | finalized',
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finalized_by` INT NULL,
    `finalized_at` DATETIME NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable cpi_commission_line
CREATE TABLE `cpi_commission_line` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `run_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `role` VARCHAR(10) NOT NULL,
    `line_id` BIGINT NOT NULL,
    `rule_id` INT NOT NULL COMMENT 'the rule that paid, shown on the report',
    `credit_date` DATE NOT NULL COMMENT 'invoice date (seller) or received date (provider)',
    `basis_amt` DECIMAL(12,2) NOT NULL,
    `commission_amt` DECIMAL(12,2) NOT NULL,
    `is_reversal` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_run_user` (`run_id`, `user_id`)
) ENGINE=InnoDB;
#EndIf
