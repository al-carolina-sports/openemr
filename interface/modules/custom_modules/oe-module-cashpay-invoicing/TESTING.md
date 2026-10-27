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
