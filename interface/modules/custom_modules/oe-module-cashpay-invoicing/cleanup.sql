-- Removes every table of the Cash-Pay Invoicing module.
-- OpenEMR does not run this file. Run it by hand ONLY if all invoices,
-- payments and commission history kept by the module should be destroyed.
-- Take a database backup first.
DROP TABLE IF EXISTS `cpi_commission_line`, `cpi_commission_run`, `cpi_commission_rule_log`,
    `cpi_user_commission_template`, `cpi_commission_tier`, `cpi_commission_rule`, `cpi_commission_template`,
    `cpi_financial_note`, `cpi_gift_card_txn`, `cpi_gift_card`, `cpi_points_ledger`, `cpi_patient_rewards`,
    `cpi_cash_drawer`, `cpi_stripe_event`, `cpi_stripe_customer`, `cpi_payment_alloc`, `cpi_payment`,
    `cpi_line`, `cpi_invoice_lock_log`, `cpi_invoice`, `cpi_quote`, `cpi_payment_method`, `cpi_promo_discount`,
    `cpi_discount_reason`, `cpi_patient_membership`, `cpi_membership_plan_promo`, `cpi_membership_plan`,
    `cpi_package_item`, `cpi_package`, `cpi_stock_txn`, `cpi_stock_reason`, `cpi_stock`,
    `cpi_product_facility_price`, `cpi_product_vendor`, `cpi_product`, `cpi_product_category`, `cpi_vendor`,
    `cpi_manufacturer`, `cpi_procedure_product`, `cpi_procedure_fee`, `cpi_procedure`,
    `cpi_procedure_category`, `cpi_tax_type`, `cpi_settings`;
