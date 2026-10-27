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

**Phase 1 (catalog admin) is built and tested.** Phase 2 is being built in three parts;
**parts 1 (quotes and invoices) and 2 (payments, points, gift cards, notes, cash drawer) are ready for testing**
(module version 0.2.0, run *Upgrade* in Module Manager). Phase 2 part 3 (Stripe and memberships) and phases 3
and 4 are not started.

Phase 1 adds **Admin > Cash-Pay Setup**:

- Catalog and Pricing home page with counts.
- Procedures, with price overrides by provider and/or office and the products each procedure uses.
- Products, with stock by office (adjust, receive, physical count, on-order and low-stock levels, history), vendors and office prices.
- Sales packages, with a line builder and the package price.
- Membership plans, with their member discounts.
- Promotional discounts, with a scope editor (invoice types, offices, procedures, procedure and product categories, products, vendors, manufacturers).
- Lists: categories, manufacturers, vendors, discount reasons, stock reasons, payment methods.
- Settings: Tax 1 and Tax 2, the credit card service charge (credit cards only, 0 to 3%), award points, quote and receipt text.
- Price List (printable) with CSV export, and CSV import for bulk edits of procedures and products.
- One-time import of the PatientNow catalog (`data/patientnow_catalog.json`).

Phase 2 part 1 adds a **Cash-Pay** menu to the patient chart with **Invoices** and **Quotes**:

- A shared editor: header (name, date, type, office, provider, seller, deposit; quotes also expiry, consult credit, active),
  a search box that adds procedures, products and packages (packages explode into their lines), fee lines for
  OR / anesthesia / recovery / assistant, per-line edits (quantity, price, discount in $ or %, reason, provider,
  seller, office, service date), Discount All, and the totals matrix.
- Pricing: provider + office fee, provider fee, office fee, then standard price; office prices for products;
  the best matching promotional discount (members-only promos need an active membership); Tax 1 and Tax 2 on the net.
- Immediate-sale, gift card and membership invoices are delivered when sold: stock comes out of the line's office
  (the product, or the products a procedure uses). Prepaid and surgery lines wait: Receive, Edit received,
  Clear received (stock goes back), Forfeit and Undo forfeit. The invoice list shows unearned income.
- Lock / unlock (unlock needs the refunds-and-unlock permission and a reason, kept in the lock history), copy,
  delete (only without payments), quote to invoice.
- Print and PDF for quotes and receipts; email a quote as a PDF marked "for information only"; printed quotes
  carry signature lines; upload the signed scan to the patient documents.

Phase 2 part 2 adds **Payments**, **Rewards** and **Financial Notes** to the patient menu, and **Gift Cards** and
**Cash Drawer** to Admin > Cash-Pay Setup:

- Apply patient payment or deposit (cash, check, card on another terminal, award points, gift card, financing),
  spread over open invoices; anything not applied is credit and can be applied later. Card payments ask for the
  card type and add the credit card service charge only for credit cards. Gratuity is recorded separately.
- Refunds (unapplied credit first, then newest invoices; card charge and earned points reversed in proportion;
  gift card value restored), write-offs and voids. Nothing is deleted. These need the refunds permission.
- Patient totals: balance due, credit on account, lifetime paid, patient since.
- Award points: admin enrolls the patient; points are earned on the eligible share of what is applied to invoices
  (no tax, no card charge, only items marked to earn); points can pay at the set value; admin adjustments need a reason.
- Gift cards: sold as a line on a Gift Cards invoice; the code is issued when that invoice is paid in full; lookup,
  history, recipient, total owed.
- Financial notes: one rich-text note per patient, cleaned before saving.
- Cash drawer per office per day: opening balance, cash in and out, adjustment, counted close, over/short; reopen
  needs the refunds permission.

Install and test steps are in [TESTING.md](TESTING.md).

### Data safety

- Uninstalling (unregistering) the module does **not** drop its tables, because they will hold invoices,
  payments and commission history. `cleanup.sql` removes them by hand if that is ever wanted.
- Records are deactivated, not deleted, so old invoices keep their items.
- The PatientNow import runs once, in one transaction, and refuses to run again.

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
  ModuleManagerListener.php     Registers the 'cashpay' ACL objects on enable; keeps data on unregister
  table.sql                     44 cpi_ tables plus default rows
  cleanup.sql                   Drops all cpi_ tables (manual use only)
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

    (default tax types, discount reasons, payment methods and stock reasons are #IfNotRow blocks at the end of table.sql)

  data/
    patientnow_catalog.json     Procedures, products, packages, memberships, promos captured from PatientNow

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
