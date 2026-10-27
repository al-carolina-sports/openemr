# Phase 1 test script (catalog admin)

Test system: http://100.79.84.89:8080. Log in as an administrator.

## 1. Install

1. On the test server, check out branch `feature/oe-module-cashpay-invoicing` (or copy the
   `interface/modules/custom_modules/oe-module-cashpay-invoicing` folder into the same place).
2. **Modules > Manage Modules > Unregistered** tab: click **Register** next to *Cash-Pay Invoicing*.
3. On the **Registered** tab: click **Install**, then **Enable**.
   - Install creates the 44 `cpi_` tables and the default rows (taxes, payment methods,
     discount and stock reasons).
   - Enable adds the *Cash-Pay* access controls and gives them to Administrators,
     Accounting and Front Office.
4. Log out and back in so the new menu and permissions load.
5. **Admin > Cash-Pay Setup** should now be in the menu.

Expected: no errors on any step. If enable reports that the access controls could not be
registered, check the PHP error log and tell Claude.

## 2. Settings (Admin > Cash-Pay Setup > Cash-Pay Settings)

- [ ] Tax 1 shows *NC Sales Tax 6.7500%*, active. Tax 2 is inactive.
      Wake County (Raleigh) is 7.25%, Wilson and Nash counties are 6.75%. Set what the accountant wants.
- [ ] Turn on the credit card service charge at 3%, save. The example line says a $100.00 credit
      card payment carries **$3.00**.
- [ ] Turn the award points program on, set points per $1 and the value of one point, save, reload. Values stay.

## 3. Import the PatientNow catalog (Admin > Cash-Pay Setup > Import Catalog)

- [ ] Open **Items to check after import** and read the list (21 notes from the capture).
- [ ] Match each PatientNow office (Raleigh, Rocky Mount, Wilson) to an OpenEMR facility.
      Leave one as *skip* if it is not set up yet.
- [ ] Click **Import PatientNow catalog**. Expected: 309 procedures, 504 products, 37 packages,
      10 promotional discounts, 4 membership plans, plus categories and manufacturers.
- [ ] Reload the page: the button is gone and the page says the catalog is already loaded.

## 4. Procedures

- [ ] **Procedures** list shows 309 rows over 4 pages. Search "laser" and filter by category.
- [ ] Open *Pellet FEMALE single insertion*. Fields match PatientNow (price, category, tax and
      award flags).
- [ ] Add a price override for one provider at one office. Add a second for the office only.
      Saving the same provider/office again updates the row instead of adding one.
- [ ] Link a product the procedure uses (quantity 1). Remove it.
- [ ] Deactivate a procedure from the list, tick **Show inactive**, activate it again.
- [ ] Add a new procedure with a blank name or "abc" as the price. Both are refused with a message.

## 5. Products and stock

- [ ] **Products** list shows 504 rows. Filter by manufacturer.
- [ ] Open a product. The stock panel lists every active facility at 0.
- [ ] **Receive** 10 at one office, **Adjust** -2 with reason *Damaged*, **Physical count** 7.
      On hand ends at 7 and *Recent stock changes* shows three rows with your user name.
- [ ] Set a low-stock level of 8. The row turns yellow with a *Low* badge.
- [ ] Add the vendor *ZRT Laboratory* (created by the import) and make it the default.
- [ ] Add an office price for one facility.

## 6. Sales packages

- [ ] Open *Male Pellet 1yr Program (3 Pellets)*. Package price **$1,697.00**.
- [ ] Open *Full Back Laser Pkg 4 get 2 free*. Package price **$1,600.00**.
- [ ] Add a product line with the unit price left blank: the catalog price is used.
      Change the quantity on a line and click **Update**; the total changes. Remove the line.

## 7. Membership plans and promotional discounts

- [ ] **Membership Plans** shows Prescription Membership (101), Wellness, Regenerative Wellness, Core (1234).
- [ ] Core's **Member discounts** has the seven $37.25 device discounts ticked.
- [ ] Open the promo *Regenerative Wellness membership free VitD / NAD / B12*. Its scope lists
      Vit D3 50,000 inj, B12 Lipo Injection and NAD Injection. PatientNow cut this list off on
      screen, so add any others it should have.
- [ ] Open *PMF Device*. *Never apply to products* is ticked. Confirm with the front desk what it is for.

## 8. Price list and CSV round trip

- [ ] **Price List** shows procedures, products, packages and memberships by category. **Print** prints all three tabs.
- [ ] Click **Products CSV**. Open it in Excel, set `tax1` to 1 for a few products, save as CSV.
- [ ] **Import Catalog > CSV import**: pick Products and the file, leave *Save changes* unticked,
      click the button. It reports how many rows would be updated and changes nothing.
- [ ] Tick *Save changes* and load it again. The products now show Tax 1.
- [ ] Put "abc" in a price cell and load. The page names the row and column and changes nothing.

## 9. Permissions

- [ ] Log in as a Front Office user. *Price List* opens (view access). *Cash-Pay Setup* lists and
      settings say *Access denied*.
- [ ] As an Accounting user: same as Front Office for setup pages.

## Report back

Note anything that looks wrong, with the page and what you clicked. Phase 2 (quotes, invoices,
payments and Stripe) starts after this passes.

---

# Phase 2, part 1 test script (quotes and invoices)

## 1. Upgrade the module

1. Update the module folder on the test server to the latest `feature/oe-module-cashpay-invoicing`.
2. **Modules > Manage Modules > Registered**: Cash-Pay Invoicing now shows **Upgrade** (0.1.0 to 0.2.0). Click it.
   It adds two columns to the line table. Nothing else changes and no data is touched.
3. Log out and back in. Open a test patient: the chart's top menu has **Cash-Pay > Invoices / Quotes**.

## 2. Quote

- [ ] **Quotes > New quote**. Name it, pick office, provider and seller. Expiry defaults to 30 days out. Click **Create Quote**.
- [ ] Search "laser", pick a line, Add. Search a package name (e.g. "Arm Laser") and Add: it explodes into its lines at the package price.
- [ ] Add an **Anesthesia** fee of $400. The totals matrix shows Provider and Anesthesia rows.
- [ ] Edit a line: give it 10% off with no reason. It is refused. Pick a reason and save.
- [ ] **Print**: letterhead, lines, totals, quote footer text, signature and date lines.
- [ ] **PDF** opens the same as a PDF.
- [ ] **Email quote** (the patient needs an email, and Admin > Config > Notifications needs a sender email).
      The PDF says "For information only - not a contract and not a bill" and has no signature lines.
- [ ] Upload a scanned PDF as the signed copy. It appears in the patient's Documents under Patient Information,
      and the quote shows "Signed copy on file".
- [ ] **Create invoice**. You land on the new invoice with the same lines. The quote now shows **Open invoice**.

## 3. Pricing

- [ ] On a procedure with a provider override (Phase 1, step 4), add it to an invoice whose provider matches.
      The override price is used. Change the invoice provider, save, add it again: the standard price is used.
- [ ] Add a product with Tax 1 ticked: the Tax column shows 6.75% of the net (or your rate).
- [ ] Give a test patient a membership row is not possible from the screen yet (part 3). Members-only promos
      therefore do not apply yet; everyone-promos do. Make a 10% everyone-promo on one procedure and add that
      procedure: the discount and promo name appear on the line.

## 4. Immediate sale and stock

- [ ] New invoice, type **Immediate Sale**, office Raleigh. Add 2 of a product. On the product's Stock panel
      (Admin > Cash-Pay Setup > Products) Raleigh on-hand went down by 2 with a *sale* row.
- [ ] Remove the line. Stock goes back up with a *return* row.

## 5. Prepaid package

- [ ] New invoice, type **Prepaid Packages**. Add *B12 Package 5 for 4*. Lines show *Not received*; the
      invoice list shows the total as **Unearned**.
- [ ] **Receive** one line today with a provider. Unearned drops by that line. **Delivery > Edit received item**
      changes the date. **Clear received flag** puts it back to waiting.
- [ ] **Forfeit** a line, then **Undo forfeit**.

## 6. Lock, copy, delete

- [ ] **Lock** the invoice. The header is greyed out and items can't be added. Receiving still works.
- [ ] As a Front Office user, there is no Unlock button. As an administrator, **Unlock** asks for a reason;
      *Lock history* lists both actions with user and reason.
- [ ] **Copy** makes a new unlocked invoice dated today with the same lines.
- [ ] **Delete** the copy. Stock taken by its delivered lines goes back.

Payments are not in this part: balance due equals the total for now. Part 2 adds payments.
