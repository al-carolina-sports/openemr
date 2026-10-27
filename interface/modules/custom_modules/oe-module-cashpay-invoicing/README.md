# oe-module-cashpay-invoicing

Cash-pay quoting, invoicing, packages, memberships, rewards, gift cards, inventory and
provider/seller commissions for OpenEMR 8.5, modeled on PatientNow and built for
Carolina Sports and Spine / BioFunctional Med / True Radiance MediSpa.

Everything lives in this module. No OpenEMR core file is edited; the module hooks in
through events (`MenuEvent`, `PatientMenuEvent`, script/style filters) the same way
`oe-module-appt-hovercard` and `oe-module-compact-theme` do, so OpenEMR upgrades do
not overwrite it.

**Cash-pay only.** There is no insurance invoice type. Insurance and Medicare billing
stay in OpenEMR's Fee Sheet / billing module, and no insurance data is migrated.

## Status

Proposal stage: `table.sql` (data model) and this file tree are up for approval.
No feature code is written yet.

## Decisions baked in

| Area | Decision |
| --- | --- |
| Taxes | Tax 1 and Tax 2 are two true taxes, set by admin, applied by item flag. |
| Card service charge | Separate setting (1% / 2% / 3%), credit cards only - never debit or prepaid. Own receipt line and report. |
| Card processor | Stripe (Checkout / Payment Links, Terminal readers, saved cards, refunds, webhooks). Saved cards are not migrated from PatientNow - patients re-enter them. |
| Commissions | Cash-pay only. Seller credited at sale (invoice date), provider at delivery (received date). Category scoping, tie-break, overlap warning, role templates, rule preview, audit log. Rates set by admin later. |
| Rewards | Admin turns the program on, enrolls patients, sets points per dollar paid and redemption value. |
| Quotes | No e-signature. Emailed "for information only"; printed for a hand signature; signed scan can be uploaded. |
| Balances | Not migrated yet. Tables accept opening balances later (`source='migration'`). |

## Proposed file tree

```
oe-module-cashpay-invoicing/
  info.txt                      Module name + version for Module Manager
  version.php                   Module version / database version
  composer.json                 PSR-4 autoload OpenEMR\Modules\CashpayInvoicing\ -> src/, stripe/stripe-php
  openemr.bootstrap.php         Registers the namespace and subscribes Bootstrap to events
  ModuleManagerListener.php     Registers ACL objects on install; drops cpi_ tables on unregister
  table.sql                     44 cpi_ tables (this proposal)
  cleanup.sql                   Drops all cpi_ tables
  README.md

  src/
    Bootstrap.php               Menu items (Admin > Cash-Pay Setup, Reports > Cash-Pay), patient tabs
    Acl.php                     ACO section 'cashpay': view, write, admin, commissions, refunds
    Money.php                   Integer-cents value object (no float math)
    Settings/                   SettingsRepository, Settings (card fee %, rewards, Stripe keys via CryptoGen)
    Catalog/                    Procedure, Product, Package, MembershipPlan, PromoDiscount repositories + CsvImporter
    Pricing/                    PriceResolver (fee overrides), PromoMatcher, TaxCalculator, CardFeeCalculator
    Billing/                    QuoteService, InvoiceService, LineService, PrepaidService (receive/forfeit), LockService
    Payments/                   PaymentService, AllocationService, RefundService, CashDrawerService
    Stripe/                     StripeClientFactory, CheckoutService, TerminalService, WebhookHandler, CustomerService
    Rewards/                    PointsService, GiftCardService
    Membership/                 MembershipService, MonthlyBillingJob (background service)
    Commission/                 RuleMatcher, CommissionEngine, RunService, TemplateService, RuleAuditLog
    Reports/                    One class per report, each returning rows for screen / CSV / PDF
    Inventory/                  StockService (receive, adjust, count, sale/procedure use)

  public/
    admin/                      Catalog, packages, memberships, discounts, payment methods, taxes, settings
    patient/                    Quotes, Invoices, Payments, Rewards, Financial Notes tabs
    reports/                    Report runner pages
    commissions/                Rule editor, templates, commission runs
    stripe/webhook.php          Stripe webhook endpoint (signature-verified)
    ajax/                       JSON endpoints (CSRF header, ACL checked)
    assets/                     css / js

  templates/                    Twig templates for all pages and printable quote/receipt PDFs

  sql/
    seed_defaults.sql           Tax types, discount reasons, payment methods, stock reasons
    seed_patientnow_catalog.sql Procedures, products, packages, memberships, promos from PatientNow

  tests/                        Isolated PHPUnit tests (no database): pricing, tax, card fee,
                                prepaid/unearned income, points, commission matching and tie-break
```

## Build phases (each one stops for testing on the test system)

1. **Catalog admin** - procedures, products, inventory, packages, memberships, discounts,
   payment methods, taxes, settings, and import of the PatientNow catalog.
2. **Quotes, invoices, payments** - patient tabs, pricing and taxes, prepaid packages,
   memberships, Stripe payments and card fee, rewards, gift cards, financial notes, cash drawer.
3. **Commissions** - rule editor with the improvements over PatientNow, engine, commission runs.
4. **Reports** - sales, commissions, unearned income, taxes and card fees, gift cards,
   memberships, inventory.
