# EXISTING-MODULES-SURVEY.md

Survey of `/home/claude/al-carolina-sports/openemr/interface/modules/custom_modules`
(branch `backup/dev-modules-2026-11-14`) against the ZeroDoc Clinical Encounter
Workflow specs (`06`, `07`, `08`, `10`).

**Produced:** 2026-11-16 · **Status:** authoritative for cross-module decisions

---

## Full table-ownership map (for the schema agent)

Every table created by any custom module, so ZeroDoc avoids collisions.
**`zdoc_` is free.** Taken prefixes:

| Prefix | Owner |
|---|---|
| `cpi_*` (48 tables) | cashpay-invoicing |
| `oe_module_eas_*`, `form_elegant_ai_scribe`, `form_elegant_ai_scribe_soap` | elegant-ai-scribe |
| `oe_module_ecs_*`, `oe_module_elegant_themes_prefs` | elegant-themes |
| `oe_module_appt_hovercard_settings`, `oe_module_aet_recent` | appt-hovercard / elegant-themes |
| `dashboard_context_*`, `user_dashboard_context*` | dashboard-context |
| `comlink_telehealth_*` | comlink-telehealth |
| `mod_dorn_*` | dorn |
| `ehi_export_job*` | ehi-exporter |
| `module_faxsms_credentials`, `oe_faxsms_queue` | faxsms |
| `module_prior_authorizations` | prior-authorizations |
| `weno_*` | weno |

**Critical for the schema agent:** ZeroDoc's specs name bare tables (`claim`,
`claim_line`, `order_type`, `dispatch_task`, `macro`, problem threads).
**Do not create unprefixed tables.** `claim` and `order_type` are generic enough
to collide with a future core or vendor module, and OpenEMR core already owns
`billing`, `claims`, `procedure_order`, `procedure_order_code`,
`procedure_type`, `lists`, `forms`, `form_encounter`, `registry`, `code_types`.
Prefix everything `zdoc_`.

---

## 1. oe-module-elegant-ai-scribe — closest overlap

Dictation-to-note pipeline. Clinician records audio in the encounter; module
uploads it, transcribes via a vendor STT service, hands the transcript to an LLM
with a per-template prompt, parses the prose output into named sections, saves
as a normal OpenEMR encounter form. Two templates: **History & Physical** (`hp`)
and **SOAP** (`soap`). Namespace `OpenEMR\Modules\ElegantAiScribe`. ~2,100 lines.

### Tables
- `oe_module_eas_settings` — key/value JSON settings
- `oe_module_eas_sessions` — one recording session per clinician+encounter:
  `uuid`, `user_id`, `pid`, `encounter`, `status`
  (`new|uploaded|transcribing|transcribed|drafted|saved|failed`), `audio_path`,
  `transcriber`, `generator`, `job_name`, `transcript`, `note_json`, `form_id`
- `form_elegant_ai_scribe`, `form_elegant_ai_scribe_soap` — **one MEDIUMTEXT
  blob per section**: `chief_complaint`, `hpi`, `review_of_systems`,
  `physical_exam`, `imaging`, `subjective`, `objective`, `assessment`, `plan`,
  plus `transcript`, `ai_providers`, `session_uuid`
- Two `registry` rows; rewrites matching `forms.form_name` rows on upgrade

### Provider interfaces

`TranscriberInterface` — **reusable as-is.** Audio in, text out; no note-shape
assumptions; working `MockTranscriber` for tests.
```php
public function id(): string;
public function start(string $audioPath, string $mime, string $sessionUuid): array;
public function poll(string $job): array;
public function cleanup(string $job): void;
```
Implementations: `DeepgramTranscriber`, `AwsTranscribeMedicalTranscriber`,
`MockTranscriber`.

`NoteGeneratorInterface` — **NOT reusable.**
```php
public function generate(string $transcript, string $prompt, array $sections): array;
// heading => prose
```
ZeroDoc's M9 requires schema-constrained generation: findings bound to
`template_element` ids with option values, not a heading→paragraph map. There is
no way to express that through this signature. **ZeroDoc needs its own
`FindingGeneratorInterface`.**

`ProviderFactory` — config-driven construction with Mock fallback. **Pattern
worth copying verbatim**; the class itself is coupled to `ElegantAiScribe\Settings`.

### Templates.php is not a template engine
97-line static registry of two hard-coded note shapes: template id → `label`,
`form_name`, `formdir`, `prompt_key`, flat `sections` array of
`column_key => 'HEADING'`. Nothing in common with ZeroDoc's template engine
(`06 §3`). **Name collision only — no reuse.**

### NoteParser.php is a prose de-serializer
66 lines. Parses model output as JSON, or builds a regex alternation of heading
names plus aliases (`HISTORY OF PRESENT ILLNESS→HPI`, `IMPRESSION→ASSESSMENT`)
and `preg_split`s on heading lines. **Splits text into text.** ZeroDoc never
needs this — its AI layer emits findings against known element ids. Value is as
a cautionary example of the prose round-trip ZeroDoc deliberately avoids.

### Core integration pattern — worth copying
`src/Bootstrap.php` is a model of event-only integration, no core files edited:
- `LoadEncounterFormFilterEvent::EVENT_NAME` → `locateForm()` redirects core's
  encounter-form loader to serve `new.php`/`view.php`/`report.php` from the
  module folder for its own `formdir`s. **ZeroDoc should use exactly this to
  host its encounter UI as a registry form.**
- `MenuEvent::MENU_UPDATE` → admin menu child under `admimg`,
  `acl_req = ['admin','super']`

`src/SessionRepository.php` — security pattern worth copying: every lookup binds
`uuid AND user_id AND pid AND encounter`; `update()` runs a column allow-list
before building SQL; `audioDir()` writes under `OE_SITE_DIR/documents/`;
`deleteAudio()` checks `realpath()` containment before `unlink`.

`public/api.php` — working JSON API: `POST ?action=start`, `?action=upload`,
`?action=transcribe`, `GET ?action=status`, `POST ?action=generate`.

### Secrets
`Settings.php`, `Aws/Credentials.php`, `ProviderFactory.php`, `settings.php`
handle vendor credentials. Per the module's own schema comment, STT key stored
only as CryptoGen ciphertext; cloud keys read from container environment, never
stored. **No credential values were read or printed.** ZeroDoc should reuse this
approach and must not introduce a second credential store.

### Recommendation: STAY SEPARATE, two narrow reuses — do not extend
1. Do not build ZeroDoc on top of it. Data model is one text blob per section.
2. Reuse `TranscriberInterface` + implementations, ideally **lifted into shared
   code** rather than a runtime dependency (which breaks when that module is
   disabled).
3. Copy the patterns: Bootstrap event wiring, ProviderFactory dispatch,
   SessionRepository binding + allow-list, api.php action router.
4. Write a new `FindingGeneratorInterface` returning structured findings keyed
   by element id, `provenance = ai_generated`, never auto-attested.
5. **Decide the coexistence story explicitly** — see R1.

---

## 2. oe-module-cashpay-invoicing

Largest custom module: ~110 source classes, 48 tables. Complete **cash-pay**
revenue system — catalog, price resolver with provider/office overrides and
promos, quotes and invoices, deferred delivery with inventory movement, payments
(cash, check, card, points, gift card, financing), refunds/write-offs/voids,
award points ledger, gift cards, per-office cash drawer with over/short,
memberships with autopay, card processor integration.

### Critical finding: it does not touch insurance billing
README is explicit: cash-pay only, no insurance invoice type, insurance and
Medicare billing stay in OpenEMR's Fee Sheet / billing module. Grep of all
`src/` for `billing`, `form_encounter`, `fee_sheet`, `code_types` — **zero
hits.** Writes only to `cpi_*` and the document store.

**Cleanest possible boundary:** ZeroDoc projects insurance claims; cashpay
collects cash. Disjoint halves of the revenue cycle.

### Services ZeroDoc should call
Namespace `OpenEMR\Modules\CashpayInvoicing`, well-factored and injectable:
- `Billing\DocumentService` — `create(DocumentKind, pid, HeaderInput, userId)`,
  `saveHeader()`, `convertQuote()`, `copy()`, `setLocked()`, `delete()`,
  `markQuote()`, `attachSignedScan()`
- `Billing\DocumentRepository`, `Billing\LineService`
- `Pricing\PriceResolver`, `Pricing\CatalogLookup`, `Pricing\PromoMatcher`
- `Inventory\StockService` — **relevant to ZeroDoc procedure documentation**;
  an in-house injection consumes supplies
- `Payments\PaymentService`, `Rewards\RewardsService`, `Membership\MembershipService`
- `Admin\*` — generic entity/CRUD/panel framework (`EntityDefinition`,
  `FieldDefinition`, `FieldType`, `CrudRepository`, `ListQuery`, `Panel`).
  **Best admin-screen framework in the codebase, and ZeroDoc needs many admin
  screens** — order types, templates, saved findings, plan options, diagnosis
  sets, macros, the time→code table.
- `Access.php` / `AclObject.php` / `AclRegistrar.php` — module-registered ACL
  objects. Copy this.

### Where the two connect
1. **Cash-pay procedures in the A/P** should produce a cashpay **quote**, not a
   projected insurance claim. `order_type` needs a payer-class dimension — R10.
2. **Price display at the point of care** via `PriceResolver`.
3. **Supply consumption** via `StockService` from procedure documentation.
4. `public/admin/worklist.php` already exists — naming collision, R4.

### Recommendation: INTEGRATE (call it), never duplicate
Reuse `Admin\*` as the pattern for config screens; call `PriceResolver` and
`StockService`; route cash-pay plan items to `DocumentService` instead of
`claim_line`; copy `Access`/`AclRegistrar`. Do not read or write `cpi_*`
directly — go through services. Keep claim projection inside `zdoc_*` and core
`billing`.

---

## 3. oe-module-prior-authorizations

Third-party (Sherwin Gaddis / Juggernaut, 2022), namespace
`Juggernaut\OpenEMR\Modules\PriorAuthModule`. One table, two controllers, three
pages. A manual register of authorizations the practice already holds.

### Table
`module_prior_authorizations`: `id`, `pid`, `auth_num VARCHAR(20)`,
`start_date`, `end_date`, `cpt TEXT`, `init_units INT`, `remaining_units INT`.
Note `cpt` is free-text, no encounter link, no payer link, no status enum, no
request/response tracking.

### Interface
```php
AuthorizationService::getUnitsUsed($authnum, $pid, $cpt, $start_date, $end_date): int
```
Sums `billing.units` by joining
`billing → forms → form_misc_billing_options → form_encounter`, filtered on
`fmbo.prior_auth_number` within the date window.

### What it does not do
No `payer_auth_rule`, no per-order-type auth requirement, no auth status on an
order, no "auth required (BCBS)" determination, no task creation, no submission
workflow. **A filing cabinet, not a rules engine.**

### Recommendation: ZeroDoc should DRIVE it, not duplicate — model must extend
- Do not create a second table of held authorizations.
- `payer_auth_rule` and `dispatch_task.auth_status` are new, belong in `zdoc_*`.
- At dispatch, **read** `module_prior_authorizations` to answer "do we hold an
  auth covering this CPT for this patient today, with units remaining?"
- **R6 caveat:** unit counting depends on `form_misc_billing_options.prior_auth_number`
  being populated, which ZeroDoc's claim projection does not write.
- `remaining_units` is written on insert and never recomputed on update — treat
  as unreliable; derive from `init_units - getUnitsUsed()`.
- Read through `AuthorizationService`, not raw SQL; degrade to "auth status
  unknown" if the module is disabled.

---

## 4. oe-module-faxsms

Upstream OpenEMR module (Jerry Padgett), namespace `OpenEMR\Modules\FaxSMS`.
Multi-vendor fax, SMS, email, voice: RingCentral, etherFAX, SignalWire, Twilio,
Clickatell, email channel, RingCentral softphone widget. Handles inbound faxes
(download media, store as document, auto-match patient by phone, assign),
outbound sending, appointment reminder batches.

### Tables
- `oe_faxsms_queue` — `job_id`, `account`, `status`
  (`queued|sent|delivered|received|failed|...`), `direction`, `site_id`,
  `patient_id`, `document_id`, `media_path`, `deleted`
- `module_faxsms_credentials` — encrypted, not inspected

**Caution:** `oe_faxsms_queue` is only reliably populated by the etherFAX path.
`SignalWireClient` and `FaxDocumentService` carry explicit comments that
SignalWire neither reads nor writes the queue. **Delivery status is
vendor-dependent.**

### Entry points
- `Service\ServiceFactory::create(string $moduleType, $serviceTypeKey): AppDispatch`
  — `'fax'|'sms'|'email'|'voice'`; throws `RuntimeException` when the service is off
- `Controller\FaxDocumentService` — `storeFaxDocument()`, `assignFaxToPatient()`,
  `getUnassignedFaxes()`, `getFaxDocument()`, `findPatientByPhone()`,
  `insertInboundFaxToQueue()`, `downloadAndStoreFromUrl()`
- `Service\FaxMailer::send()`, `Service\FaxUploadStaging`,
  `Service\CredentialsRepository`, `Events\NotificationEventListener`
- `Contracts\FaxChannelInterface::sendFax(): string|bool` — **thin, parameterless;
  concrete clients read arguments from request state**, awkward to call
  programmatically

### Recommendation: HAND OFF — ZeroDoc must not implement faxing
1. **Write an adapter, don't call clients directly.** ZeroDoc needs a
   `FaxDispatcher` that assembles its packet (order + demographics + insurance +
   imaging report) as a PDF in the document store, then invokes the vendor
   client with explicit arguments. Budget real work; not a one-liner.
2. **Delivery confirmation is the hard part** — see R7.
3. **Keep `zdoc_dispatch_task` independent of `oe_faxsms_queue`.** Store
   `job_id` as a reference; mirror status. A fax row can be absent (SignalWire)
   or soft-deleted while the clinical task still needs to exist.

---

## 5. oe-module-dashboard-context — UI pattern

Controls which patient-dashboard widgets are visible per care context (Primary
Care, Outpatient, Inpatient, Emergency, Specialty, Telehealth, Behavioral
Health, Pediatric, Geriatric). Users switch context and customise widget sets;
admins create contexts, assign in bulk, lock users, set role and facility
defaults, import/export, audit log.

**Tables:** `dashboard_context_definitions`, `dashboard_context_assignments`,
`dashboard_context_role_defaults`, `dashboard_context_audit_log`,
`user_dashboard_context`, `user_dashboard_context_config`.

**Why it matters:** this is the **reference implementation for role- and
context-driven UI configuration** — exactly the shape of ZeroDoc's
`section_policy` (M5). The three-tier resolution (explicit user assignment →
role default → facility default, with admin lock) is the right model. The
audit-log-every-change pattern applies directly to provenance and attestation.

**Recommendation: COPY THE PATTERN, no runtime interaction.** One integration
opportunity: if ZeroDoc adds a dashboard widget (active problem threads, open
dispatch tasks), register it so this module can show/hide it per context.

---

## 6. oe-module-appt-hovercard — UI + security pattern

Replaces the calendar's native appointment tooltip with a richer hover card.

**Table:** `oe_module_appt_hovercard_settings`. `ModuleManagerListener` drops the
table on unregister **because OpenEMR does not run `cleanup.sql` itself — a
detail ZeroDoc's installer must replicate.**

**Best-documented injection pattern in the repo, and the most security-conscious:**
- `Bootstrap` subscribes to `html.head.script.filter`, `html.head.style.filter`,
  `MenuEvent::MENU_UPDATE`. **Assets injected only on the specific page**, only
  on views enabled in settings (**checked server-side**), only for allowed roles.
  ZeroDoc needs exactly this to inject encounter UI assets without loading them
  site-wide.
- `public/config.js.php` hands the page settings plus a CSRF token;
  `public/ajax.php` requires session, CSRF token in `X-CSRF-Token` header, the
  relevant core ACL, and an allowed role, returning 403 otherwise. **Drops
  fields the role may not see on the server, not in JS.** Bound parameters only.
  One audit entry per patient per session.
- Graceful degradation: AJAX failure restores native tooltips; disabling the
  module restores stock behaviour.
- Supporting classes worth imitating: `AccessPolicy`, `RoleResolver`,
  `FieldCatalog`, `Settings` + `SettingsRepository`, `PhotoService`. Has real
  tests (`AccessAndSettingsTest`, `ViewGatingAndPhotoTest`).

**Recommendation: COPY THE PATTERN wholesale** for every ZeroDoc AJAX endpoint —
asset gating, CSRF, server-side field redaction, graceful degradation. Its
README notes PHPStan and the pre-commit suite were not run in the authoring
environment; **run `openemr-cmd phpstan` on ZeroDoc before merging.**

---

## 7. Remainder

| Module | What it is | Matters to ZeroDoc? |
|---|---|---|
| **comlink-telehealth** | Vendor telehealth v2.0.0 — appointment-bound video sessions, portal launch, provider settings (`comlink_telehealth_appointment_session`, `_auth`, `_person_settings`) | **Barely.** But place-of-service for claim projection differs for telehealth — POS derivation should check whether the appointment has a telehealth session |
| **dorn** | Diagnostic Ordering Result Network — lab ordering and results, per-lab routes, orderable-item compendium (`mod_dorn_routes`, `mod_dorn_compendium`, `mod_dorn_orderable_items`), result acknowledgement | **Yes — the one genuine order-dispatch overlap.** See R5 |
| **ehi-exporter** | ONC-compliance bulk EHI export, job-based (`ehi_export_job*`), with `src/TableDefinitions/` registry of exported tables | **Yes, one obligation.** ZeroDoc's clinical tables must be added or they are silently absent from exports. See R9 |
| **weno** | Weno eRx — e-prescribing, pharmacy directory, assigned pharmacies (`weno_pharmacy`, `weno_assigned_pharmacy`, `weno_download_log`) | **Yes, narrowly.** `order_type.default_method = erx` should dispatch to Weno. `weno_assigned_pharmacy` is the existing per-patient pharmacy — use as `default_recipient_id` |
| **elegant-calendar-style** | CSS only — shrinks Day/Week appointment text on `pnuserapi.php` | No interaction |
| **elegant-themes** | Eight per-user visual themes (`oe_module_ecs_*`, `oe_module_elegant_themes_prefs`, `oe_module_aet_recent`). *Not analysed — actively being edited* | ZeroDoc UI should use theme-neutral styling so it renders under all eight; confirm with the user |

---

## RISKS

**R1 — Two AI note generators for one encounter (highest risk).** AI Scribe
already puts "AI Scribe Note – H&P" and "– SOAP" in the encounter's Clinical
forms menu. ZeroDoc's M9 adds a second AI path to the same encounter. Nothing
prevents a clinician producing both, with contradictory content, two
attestations, and an ambiguous source for the claim. **Decide precedence before
M1, not at M9.** Either retire AI Scribe when ZeroDoc's note is live, or keep it
strictly as a dictation front-end that writes findings into ZeroDoc and never
creates its own form.

**R2 — "Template" means two incompatible things.** `ElegantAiScribe\Templates`
is a hard-coded two-entry registry of heading lists. ZeroDoc's is user-editable
structured elements with option sets, plus "saved findings". Same word, no
shared semantics. A developer told to "use the existing templates" will build
the wrong thing. Name ZeroDoc's objects unambiguously (`zdoc_template`,
`zdoc_saved_finding`) and state in the README that `Templates.php` is unrelated.

**R3 — The name "ZeroDoc" is already used inside cashpay-invoicing.** That
module ships `data/zerodoc_catalog.json`, `Import\ZerodocCatalogImporter`, an
admin action named `zerodoc`, and tests asserting on that catalog — the
practice's **previous** cash-pay system's exported catalog. Grepping `zerodoc`
returns both. **Choose a distinct code identifier** (namespace and table prefix)
and leave cashpay's importer alone.

**R4 — Two things called "the front-desk worklist".**
`cashpay-invoicing/public/admin/worklist.php` already shows memberships due and
cards needed, gated on `AclObject::Billing`. ZeroDoc's `dispatch_task` worklist
is a clinically-driven queue. Same name, same audience, different data,
different ACL. **Name ZeroDoc's queue distinctly** (e.g. "Order Queue"), and
consider cross-linking counts.

**R5 — Parallel lab-ordering paths.** If DORN is live, ZeroDoc could grow a
second lab-order path with a second orderable catalog alongside
`mod_dorn_orderable_items`, results landing in DORN's acknowledgement flow while
`dispatch_task` stays open forever — loop closure broken for the order type most
likely to be used. **Confirm whether DORN is in use**; if so, `order_type` needs
a `dorn` method and ZeroDoc must consume DORN's result acknowledgement.

**R6 — Prior-auth unit counting depends on a form ZeroDoc does not write.**
`getUnitsUsed()` counts `billing.units` only for encounters with a Misc Billing
Options form where `prior_auth_number` is populated. ZeroDoc writes
`zdoc_claim*`. Unless ZeroDoc also populates
`form_misc_billing_options.prior_auth_number`, every authorization reports zero
units used, staff believe units remain, and the practice delivers unauthorised
services. **Patient-facing financial risk, not cosmetic.** Also treat
`remaining_units` as stale by design.

**R7 — Fax delivery confirmation is weaker than M4 assumes.** "A failed fax
turns the task red" presumes a reliable status source. `oe_faxsms_queue.status`
is maintained only on etherFAX; SignalWire bypasses the queue; rows can be
soft-deleted under the task. If ZeroDoc trusts the queue, failed faxes show as
sent. **Own status in `zdoc_dispatch_task` and poll the vendor, or pin the
vendor choice.**

**R8 — Patient-menu contention.** prior-authorizations ships
`public/patient_menu/custom_patient_menu.json`, a **complete replacement**
patient menu. cashpay-invoicing adds items via `PatientMenuEvent::MENU_UPDATE`.
Two different mechanisms for one menu; the JSON replacement is the kind of thing
that silently drops event-added items. ZeroDoc must use `PatientMenuEvent`, and
someone should verify cashpay's items still appear with prior-auth enabled —
**this may already be broken today**, independent of ZeroDoc.

**R9 — EHI export blind spot.** Nothing in `ehi-exporter/src/TableDefinitions/`
knows about `zdoc_*`. Signed notes, findings and problem threads would be
omitted from patient EHI exports — an **ONC compliance failure that no ZeroDoc
test will surface.** Add ZeroDoc's clinical tables to the exporter's definitions
as part of M6 (signing), not later.

**R10 — Cash versus insurance routing at signature.** Nothing in `06 §7` or
`10 §13.4` distinguishes cash-pay plan items from insurance-billable ones, and
`order_type` has no payer-class field. A cosmetic or cash procedure planned in
the A/P would project an insurance claim line instead of creating a cashpay
quote. On a practice running three entities through one OpenEMR, **this will
happen in the first week.** Add a payer-class / financial-class dimension to
`order_type` and route at signing.

**R11 — Hard runtime dependencies on modules that can be disabled.** Every
module here is registered through Module Manager and can be disabled or
unregistered. If ZeroDoc calls AI Scribe's transcribers, cashpay's services,
prior-auth's service or faxsms's factory directly, each becomes a fatal error
waiting for an admin click. **Wrap every cross-module call in a capability check
with defined degraded behaviour**, and prefer lifting shared code over
depending on a neighbour at runtime.

**R12 — No collision where one might be feared.** Recorded explicitly so it is
not re-litigated: cashpay-invoicing does **not** read or write core `billing`,
`claims`, `form_encounter`, `fee_sheet` or `code_types`. ZeroDoc's claim
projection has the insurance side of the revenue cycle to itself. No `cpi_*`
table conflicts with anything ZeroDoc needs.
