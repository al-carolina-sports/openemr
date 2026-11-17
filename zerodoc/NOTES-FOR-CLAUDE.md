# ZeroDoc: notes for the implementing agent (2026-11-16, America/New_York)

## 1. Alvin's decisions (binding)
1. **OpenEMR core lists are the source of truth.** Problems, allergies, and medications live in core `lists` (and `prescriptions` for meds). `zdoc_problem` is a 1:1 *extension* row keyed to the core `lists.id`; it never holds its own copy of the problem. Use the same pattern for allergies and meds (`zdoc_allergy_ext`/`zdoc_med_ext` or similar: 1:1 with the core row, ZeroDoc-only attributes only).
2. **Two documentation pathways, ZeroDoc or AI Scribe, chosen per encounter and mutually exclusive.** Choosing one blocks the other on that encounter, so there are never duplicate notes. Billing reads the **signed note of the chosen pathway**. AI Scribe feeding ZeroDoc (scribe output as ZeroDoc input) is a possible later option. Don't build it now, but don't design it out.
3. **Cash vs. insurance.** The provider picks it at planning time, and it defaults from the patient's coverage. The biller can override it. One encounter may carry **both** (some lines cash, some insurance), so payer class belongs at line/order level with an encounter-level default.
4. **The E&M table stays empty** until it is filled from current CPT/CMS sources plus the practice fee schedule. Ship no placeholder codes or amounts.

## 2. Deployment finding: DEPLOY §9 is wrong for this server
- Dev is Docker: `openemr-proto-openemr-1` (image openemr/openemr:8.5.0, digest sha256:927cbca9…, 8.5.0-dev, v_database 548) plus `openemr-proto-mysql-1` (MariaDB 11.4.13). The compose file is at /home/alvin/openemr-proto.
- **Only `sites/`, `/var/log`, and the DB are volumes.** `interface/modules/custom_modules/` lives in the container's **writable layer**. A container recreate (compose down/up or an image pull) wipes every custom module, including ZeroDoc, while the DB rows (registry, modules, ACL, 63 tables) survive. That leaves the site with orphan registrations.
- DEPLOY §9's update/rollback steps assume a host path. On this setup, deploying means `docker cp` plus chown apache, and a durable fix needs a bind mount for custom_modules (the "bind-mount migration"). **The migration is HELD and not approved yet.** Gate 0c (the update test) is also HELD.
- The image has no phpstan, phpunit, or openemr-cmd. There are leftover `/tmp/*-module-review*` dirs from an earlier review inside the container; they are not ours and I left them alone.

## 3. Validator drift and spec issues (from Gate 0 prep)
`validate-schema.out`: **8 errors** (DRIFT-COLUMN 5, DRIFT-CONSTRAINT 1, VOCAB-COL-MISSING 2) and **56 warnings** (FK-CHILD-UNINDEXED 14, GUARD-INSERT-NAT 26, DRIFT-COLUMN 5, TABLE-UNDOCUMENTED 5, GUARD-DML 1, …). Tests: renderer 52 pass / 0 fail; library-tools 88 pass / 0 fail.
- **schema.sql ≠ table.sql.** table.sql has 120 statements and schema.sql has 106, both with 63 CREATE TABLE. Columns drift between them. The FK constraints exist **only in schema.sql**, so the installed DB (built from table.sql) has no FKs. Pick one source and generate the other.
- `vocabularies.sql` uses the **stale `zdoc_schema_version` shape**, which causes the VOCAB-COL-MISSING errors.
- The contract says version **1.4.0** in one place and **1.5.0** in another. **5 tables are undocumented** in the contract.
- **Collation mix:** utf8mb4_general_ci and utf8mb4_unicode_ci tables coexist, so joins and comparisons across them can hit "illegal mix of collations." Standardise on one.
- `payer_class` is bound to **two different vocabularies** in different tables, which conflicts with decision 3. Use one vocabulary.
- **cleanup.sql bug:** a DELETE runs *after* the DROP of the table it reads from, and the ACL objects (`zerodoc` section, 6 ACOs, 14 group maps) are never removed.
- **Unregister:** the brief and the scaffold disagree on whether unregister drops tables. Decide this explicitly; my recommendation is to keep data on unregister and drop only in an explicit purge.
- The brief overstates what the CLI (`zfc-module`) skips. Gate 0 used the Modules UI only.
- There is no zerodoc git repo yet.
- Spec 06 §7.4 and §10 contradict each other.
- OPEN-QUESTIONS: Q2 (patient merge) is missing from the §0 table.
- The ACL seed gives the **Administrators group the Sign permission**. Admins can sign clinical notes, so confirm with Alvin.
- The artifact zip size doesn't match the handoff, and `SCHEMA-VALIDATION-REPORT.md` is referenced but missing.
- **Pre-existing, not ZeroDoc:** on encounter 488 the Eye Exam (eye_mag) forms 18 and 19 trigger AccessDeniedHelper ("headers already sent … forms.php:146", "Attempt to return raw file without retrieve key"). This truncates forms.php, so later forms on that encounter (including our ZeroDoc note, forms.id 20) aren't listed. The same error appears before the install (12:50 UTC = 7:50 AM ET). Use a different test encounter for visual checks, or fix eye_mag separately.

## 4. Gate 0a/0b results (dev, 2026-11-16)
**Backup:** `~/openemr-backups/20261116-084715-pre-zerodoc-gate0/` on functional-emr, containing:
- openemr-db.sql.gz (60M, gunzip -t OK)
- custom_modules.tar.gz (12 module dirs)
- image-digest.txt
- docker-diff.txt
- sites-docker-version.txt
- the counts-*.txt files

**Light rollback:** Modules UI Disable, then Unregister, then restore the custom_modules tar. The full rollback is a DB restore.

**Module:** 0.1.0, tarball sha256 4807999879c00dfd1564cfec9cfc1fec300fcc1b4232c3cb9f0adb08ede83953. I copied it in with `docker cp`, set owner apache, and set perms 755/644.

**0a, Install (Modules UI, ~8:48 AM ET):**
- 63 zdoc tables created
- `zdoc_schema_version`: core r1 and clinical r1
- registry id 40, `zdoc_encounter`, category Clinical, ACO encounters|notes
- seeds: 12 vocabularies, 68 vocabulary items, 6 consistency rules, 22 section policies
- `modules` mod_id 24, sql_run 1, sql_version 0.1.0

**0b, Enable:**
- mod_active 1
- ACL section `zerodoc` with 6 ACOs (view, document, sign, dispatch, setup, settings) and 14 group maps: Admin gets all 6; Physicians view/document/sign; Clinicians view/document/dispatch; Front Office view/dispatch.

**Functional:**
- "ZeroDoc Encounter Note" appears under the Clinical forms menu.
- The banner reads "ZeroDoc 0.1.0 · clinical schema r1 · core schema r1".
- A test note titled "ZeroDoc Gate0 (test data)" was saved for Bethany Ashworth (pid 159), encounter 488, as `form_zdoc_encounter` id 1 / forms id 20. It reopens correctly.
- Admin > ZeroDoc Workflow (status.php) shows the version, forms, schema, and the 8 neighbour modules.

**Injection scope:**
- zerodoc.js/css load only on the encounter forms page.
- They don't load on demographics, calendar, or main.php (0 custom_modules assets on main).
- The main page renders normally.

**Idempotency:** I re-ran install_sql twice via the UI's own `manage('24','install_sql')` AJAX. Both returned Success.
- Second run: Com_insert_select went from 40 to 52 (the seeds really re-executed), while Com_create_table and Com_alter_table stayed the same (the guards held).
- All 69 table and registry row counts were identical before and after.

**Disable/enable cycle (twice):**
- Disabled: registry.state 0, the form is gone from the Clinical menu (other forms still listed), no zerodoc.js, and module PHP is denied with **401** (core "Access to module path for disabled module is denied"; the brief expected 403). The ACL rows are kept.
- Re-enabled: state 1, the form is back, status.php returns 200, and ACL is still 6/14. Row counts are identical.

**Error log:** no zerodoc/zdoc warnings or fatals except the expected disabled-module denials. The rest is pre-existing noise:
- eye_mag raw-file access denied
- PatientMenuRole.php undefined pid/on_click
- Installer InstModule undefined mod_nick_name/mod_enc_menu (core, applies to every module)
- CSRF errors from stale tabs

**Final state:** installed and **enabled**. Gate 0c and the bind-mount migration are **HELD**.

**Not verified:** the Elegant Themes visual. Only `admin` has a theme pref (shadcn); the `grokbot` test user has none, so I could only verify that ZeroDoc adds nothing to main.php, not how the themed UI looks.

---

## Install summary — what Grok Bot did on dev (2026-11-16)

No new ZeroDoc code was written. Claude's scaffold `oe-module-zerodoc-encounter` 0.1.0 (from ZeroDoc-build-artifacts.zip in the project Drive folder) was installed as-is and tested. OpenEMR base code was not touched.

**Where the code is**
- Dev container (openemr-proto-openemr-1 on functional-emr, 100.79.84.89): `/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-zerodoc-encounter`
- Grok Bot's copy of the source: `/workspace/zerodoc/build/` (Grok Bot's box); test scripts in `/workspace/zerodoc/gate0/`
- Pre-install backup on VM: `~/openemr-backups/20261116-084715-pre-zerodoc-gate0` (DB dump, custom_modules tar, image digest)
- NOT in GitHub yet, and NOT on a bind mount — recreating the container would wipe it. Bind-mount migration is on hold pending Alvin's approval.

**What the install did**
- Registered and enabled the module via Admin > Modules > Manage Modules (UI, not CLI; the CLI path skips the listener).
- Created 63 `zdoc_` tables (empty; scaffolding for the full documentation-to-billing workflow).
- Added a `zerodoc` ACL section: 6 objects, 14 group grants.

**What it adds in OpenEMR**
- "ZeroDoc Encounter Note" form in an encounter's Clinical menu (save and reopen works).
- Admin > ZeroDoc Workflow page.
- `zerodoc` section under Admin > ACL.
- Loads only on the encounter forms page; no other pages changed.

**Tests passed**
- Test note "ZeroDoc Gate0 (test data)" (form id 1) saved on test patient Bethany Ashworth, encounter 488 "Layout test encounter (test data)"; reopens fine.
- Disable/re-enable and re-running install left data and registration intact.

**Known issues / holds**
- Encounter 488: two pre-existing Eye Exam forms throw "Access denied" and truncate the forms list, so the ZeroDoc note is not listed there. Predates the install; use a fresh encounter to view.
- Uninstall: spec and scaffold disagree on dropping tables; cleanup.sql ends in an error (see above).
- Themed UI not visually checked (tests ran as the `grokbot` login).
- Gate 0c (8.5.0 point update, v_database 555) and the bind-mount migration are on hold.
