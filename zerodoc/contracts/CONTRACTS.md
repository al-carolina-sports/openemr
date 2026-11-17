# CONTRACTS.md

**Product:** ZeroDoc Clinical Encounter Workflow
**Package:** WP-00a — integration contract
**Status:** FROZEN. This document is the authority every build package codes against.
**Companion files:** `schema.sql` (complete DDL), `vocabularies.sql` (shared vocabulary seed), `OPEN-QUESTIONS.md` (unresolved items and judgment calls)
**Specs this implements:** `06 - gateless-encounter-documentation-spec.md`, `07 - ma-authored-notes-and-diagnosis-sets.md`, `08 - build-plan-v1.md` Part A (§12), `10 - documentation-to-billing-integration.md` (§13), package briefs in `09 - work-packages-for-parallel-build.md`

---

## 0. The rule that makes parallel work possible

> **No agent may alter anything in this document.** Table names, column names, types, enum values, service signatures, route paths, event names and asset conventions are frozen. An agent that believes a contract is wrong **stops and raises it to the integration owner** rather than changing it locally.

Two agents independently "improving" a shared table is the single failure mode that turns parallel work into a rewrite. The full change protocol is §12.

### 0.0 Target environment — verified, not assumed

| Item | Value |
|---|---|
| OpenEMR | **8.5.0-dev** |
| PHP | **>= 8.3.0** |
| Database | **MariaDB** (container `openemr-proto-mysql-1`, client `mariadb`). The DDL targets MariaDB specifically and does not hedge for MySQL. |
| Deployment | Docker. Web root inside the web container: `/var/www/localhost/htdocs/openemr`. Custom modules: `interface/modules/custom_modules/`. |
| Reference source | Read-only clone at `/home/claude/al-carolina-sports/openemr`, branch `backup/dev-modules-2026-11-14`. **Read only — nothing in it is modified.** Core table structures referenced in §2.1 were read from its `sql/database.sql`. |
| Existing modules | ~12 custom modules owning 77 tables. `zdoc_` verified collision-free (§2.1.2). |
| Install mechanism | Plain idempotent SQL files — `table.sql`, `cleanup.sql`, `X_Y_Z-to-A_B_C_upgrade.sql`. No framework migrations. |

Both companion documents now exist and **this contract conforms to them**. Read both before starting a package; raise a change request if either contradicts this document:
- `/home/claude/build/module/EXISTING-MODULES-SURVEY.md` — table ownership across the 12 existing modules, the cross-module integration recommendations, and risks R1–R12.
- `/home/claude/build/module/MODULE-CONVENTIONS.md` — how this version declares and runs module install SQL, registers encounter forms, and dispatches events.

### 0.0.1 Cross-module rule — binding on every package

The practice runs 12 other custom modules. Several own capabilities ZeroDoc needs. **ZeroDoc integrates with them; it never duplicates them and never reads or writes their tables.**

| Capability | Owner | ZeroDoc's position |
|---|---|---|
| Cash-pay quoting, pricing, inventory, payments | the cash-pay module (48 tables) | **Call its services.** A `payer_class = 'cash_pay'` item routes there instead of projecting a claim line (§2.18.1). ZeroDoc keeps the insurance half of the revenue cycle; the two are disjoint. |
| The register of **held** authorizations | the prior-authorizations module | **Read it.** ZeroDoc owns the *rule* and the per-task *status*, never a second register (§2.18.2). |
| Fax, SMS, email, voice delivery | the fax/SMS module | **Hand off.** ZeroDoc assembles a packet and invokes it through an adapter. It owns no send queue (§2.18.3). |
| e-prescribing and the per-patient pharmacy | the eRx module | `default_method = 'erx'` dispatches there; its assigned-pharmacy record is the default recipient. |
| Lab ordering, compendium, result acknowledgement | the lab-ordering module | **If live, ZeroDoc must not grow a second lab path.** See OPEN-QUESTIONS Q38. |
| Bulk EHI export | the EHI exporter | ZeroDoc **must register its clinical tables** there or they are silently absent from patient exports (§2.24). |

**Every cross-module call is wrapped in a capability check with defined degraded behaviour.** All of these modules can be disabled from Module Manager, and a direct call to a disabled module is a fatal error waiting for an admin click. A package that calls another module and has not defined what happens when it is absent has not finished the work. Degraded behaviour must never be a *wrong* answer presented as a right one — an unavailable authorization register yields `auth_status = 'unknown'`, never `'not_required'`.

### 0.1 Standing conventions, repeated here because every package must honour them

| Convention | Rule |
|---|---|
| **No core file edits** | No OpenEMR core file is edited, ever. No core table is created, altered, dropped or constrained. Everything lives in the module. |
| **Table prefix** | Every table this module creates is prefixed `zdoc_`. No exceptions. |
| **Core references** | A relationship to a core OpenEMR object (patient, encounter, user, facility) is stored as a bare id column with **no foreign key**. See §2.1. |
| **Naming** | The product is "ZeroDoc Clinical Encounter Workflow". "Annie's Elegant Modules" is the umbrella workspace, not this product's name, and does not appear in code, UI strings or table names. |
| **No vendor attribution** | Design requirements are stated as requirements. No code, comment, commit message, UI string or document attributes a requirement to another vendor's product, or names one. This includes the prior practice-management system whose name the standing constraint forbids, and the EMR whose teardown was research input only. A CI grep enforces the former. |
| **No real patient data** | Synthetic patients only until the Wave 3 gate (WP-10 and WP-11 complete, full suite green). |
| **Branching** | Each package works on its own branch named for the package. |
| **Tests** | Every package ships its own tests. WP-90 owns the cross-package acceptance suite (52 tests: 20 in `06` §9, 20 in `07` §11.11, 12 in `10` §13.10). |

---

## 1. Design invariants these contracts encode

Every signature and every column below exists to serve one of these. An agent that finds a contract inconvenient should check whether the inconvenience is the point.

1. **One encounter object, one state.** No prep copy, no visit copy. Anything written is written to the encounter itself.
2. **No stage ordering.** Sections are navigation, not a state machine. Any section may be opened, edited and completed in any order, at any time, by anyone with the right.
3. **No role gate on data entry.** Who typed a value is an attribution question (recorded), not a permission question (enforced).
4. **Signing requires only clinical completeness.** Never registration, insurance, check-in, arrival status or location.
5. **Billing is derived, never a prerequisite.** The claim is a projection of the signed note. A missing code blocks the claim, never the note.
6. **Pre-population is not authorship.** A finding is either `pre_populated` (placed by a template selection, by anyone, including the provider) or `attested` (affirmed by the clinician who personally performed or verified it).
7. **Transparency before commit.** Anything that places content in bulk must be previewable with counts, and undoable as one atomic action.
8. **Attributes, never names.** Laterality, anatomic level, side and approach are structured fields. They never appear in the name of a template, saved finding, diagnosis set, order set or procedure type.
9. **Codes are keys.** Every catalog code (`zdoc_order_set.code`, `zdoc_diagnosis_set.code`, `zdoc_procedure_type.code`) is unique and enforced unique by the database.
10. **Rules and rates are data.** Consistency rules, time-to-code mappings, authorization rules and bundling edits are rows with effective dates, never code.

---

## 2. Table reference

`schema.sql` is the normative definition — exact types, nullability, defaults, foreign keys, indexes and inline comments. This section gives the purpose of each table, the columns that carry contractual meaning, and the gotchas. **Where this section and `schema.sql` appear to differ, `schema.sql` wins and the discrepancy is a contract defect to report.**

61 tables. Full column inventory: 850 columns.

### 2.1 How core OpenEMR objects are referenced

The module owns no core table and constrains none. Core id columns are declared with **the same type and signedness as the core column they hold**, read from `sql/database.sql` in the read-only clone — not assumed — so a join never forces a type conversion that defeats an index.

| Column name pattern | Holds | Core column | Type |
|---|---|---|---|
| `encounter_id`, `source_encounter_id`, `opened_encounter_id`, `closed_encounter_id`, `amends_encounter_id`, `carried_from_encounter_id` | core encounter id | `form_encounter.encounter` `bigint(20)` | `BIGINT` |
| `patient_pid` | core patient id | `patient_data.pid` `bigint(20)` | `BIGINT` |
| `*_user_id`, `placed_by`, `attested_by`, `signed_by`, `recorded_by`, `created_by`, `updated_by`, `assigned_to`, `assigned_by`, `performed_by`, `assisted_by`, `reviewed_by`, `dismissed_by`, `cleared_by`, `obtained_by`, `acknowledged_by`, `changed_by`, `selected_by`, and the provider id columns | core user id | `users.id` `bigint(20)` | `BIGINT` |
| `facility_id`, `core_facility_id` | core facility id | `facility.id` `int(11)` | `INT` |
| `core_list_id` | core `lists` row, read-only link | `lists.id` `bigint(20)` | `BIGINT` |
| `core_form_id` | core `forms` row, navigation handle | `forms.id` `bigint(20)` | `BIGINT` |

**Rules:**
- **No `FOREIGN KEY` is declared against any of these.** They are indexed, never constrained.
- These columns are **signed**, because core is signed. The module's **own** primary and foreign keys are `BIGINT UNSIGNED` — internal and consistent. Do not mix the two conventions.
- No package writes to a core table. Reading core data goes through the context accessor in §4.1, never a raw join written by a package. A package that needs a new core read asks the integration owner to add an accessor method.
- Referential integrity against core is the module's responsibility at the service layer, not the database's. A core record deleted out from under the module leaves an orphan row; the orphan-detection report is WP-90's.

### 2.1.1 Relationship to core `registry`, `forms` and `form_<dir>` — decided

In this OpenEMR version an encounter form is **three** things, not one:

1. one `registry` row per form **directory**, inserted by `table.sql`
2. a `form_<directory>` **data table** — core derives this name from the directory and enforces the convention
3. one `forms` row per saved **instance**, written by core's `addForm()`

**ZeroDoc registers one form directory and one form instance per encounter — not one per section.**

```
registry.directory          = 'zdoc_encounter'
registry.category           = 'Clinical'
registry.aco_spec           = 'encounters|notes'
registry.patient_encounter  = 1
forms.formdir               = 'zdoc_encounter'
forms.form_id               = form_zdoc_encounter.id
form_zdoc_encounter.encounter_ext_id → zdoc_encounter_ext.id
zdoc_encounter_ext.core_form_id      → forms.id      (reverse lookup)
```

The `registry` INSERT lives in `table.sql` guarded by `#IfNotRow registry directory zdoc_encounter` — **not in `schema.sql`**, because it is configuration, not schema. The module manager's enable and disable hooks toggle `registry.state`. Form files are reached through `LoadEncounterFormFilterEvent`, so **no file is placed in core's `/interface/forms/` tree**.

**Why one form and not one per section.** Per-section forms give every section its own core document, its own `deleted` flag and its own registry row — the per-section ad-hoc-form model the spec explicitly moves away from ("sections as a first-class registry rather than ad-hoc forms", 06 §10) — and distribute state across core rows the module does not own, breaking design invariant 1.

**Binding consequences:**
- `zdoc_encounter_section` carries **no** link to core `forms` or `registry`. Sections are module-owned rows. **Do not add one.**
- `forms.deleted` and `form_zdoc_encounter.activity` are **not** clinical states. `zdoc_encounter_ext.zdoc_status` is authoritative. No package reads them as a status or writes them as one.
- `form_zdoc_encounter` is a **thin bridge** carrying only the columns core requires of a form table (`id`, `date`, `pid`, `user`, `groupname`, `authorized`, `activity`) plus one pointer. **No clinical content lives in it, or anywhere else in core.**

See OPEN-QUESTIONS Q24 for the one unresolved risk.

### 2.1.2 Table naming and the collision check

**Every table this module creates is prefixed `zdoc_`, with exactly one forced exception.**

The specs name bare tables — `claim`, `claim_line`, `order_type`, `dispatch_task`, `macro`, `problem_thread`. **No unprefixed table is created.** Core already owns `billing`, `claims`, `procedure_order`, `procedure_order_code`, `procedure_type`, `lists`, `forms`, `form_encounter`, `registry` and `code_types`; `claim` and `order_type` are generic enough to collide with a future core or vendor table even where nothing collides today.

**The one exception: `form_zdoc_encounter`.** Core derives a form's data table name from its registry directory as `form_<directory>`, and core code and the existing modules enforce it. It remains inside ZeroDoc's own namespace (`form_zdoc_*`) and collides with nothing.

Verified against the survey's full ownership map — `zdoc_` and `form_zdoc_` are both free. Taken prefixes: `cpi_*` (48 tables), `oe_module_eas_*`, `form_elegant_ai_scribe*`, `oe_module_ecs_*`, `oe_module_elegant_themes_prefs`, `oe_module_appt_hovercard_settings`, `oe_module_aet_recent`, `dashboard_context_*`, `user_dashboard_context*`, `comlink_telehealth_*`, `mod_dorn_*`, `ehi_export_job*`, `module_faxsms_credentials`, `oe_faxsms_queue`, `module_prior_authorizations`, `weno_*`.

**Re-run the collision check before each install.** The map above is a point-in-time read of one branch.

### 2.1.3 Code identifier — avoid the pre-existing name

The cash-pay module contains a `zerodoc_catalog.json`, a `ZerodocCatalogImporter`, an admin action named `zerodoc`, and tests asserting on that catalog. **These refer to the practice's previous cash-pay system and are unrelated to this product.** Do not reference them, import from them, or collide with them.

**ZeroDoc's code identifier is `zdoc`**, everywhere: the `zdoc_` table prefix, the `zdoc_encounter` registry directory, the `ZdocClinicalEncounter` namespace, the `zdoc-` asset prefix, the `window.ZDoc` global, and the `zdoc.*` event names. "ZeroDoc Clinical Encounter Workflow" is the product name for humans — in prose, UI strings and documentation. It is not a code identifier. This keeps a grep for `zdoc` unambiguous.

### 2.2 Module infrastructure

| Table | Purpose | Contractual columns |
|---|---|---|
| `zdoc_schema_version` | Migration ledger the installer reads. | `migration` (unique), `applied_at`, `checksum` |
| `zdoc_audit_event` | Append-only module audit log. Every deletion of pre-populated content, every attestation, every sign/amend/void, every warning dismissal, every billing code change lands here. | `action`, `object_table`, `object_id`, `before_json`, `after_json` |
| `zdoc_application` | One row per bulk placement. **This is what makes `undoApplication` atomic.** Every finding and A/P diagnosis created by a bulk action carries its `application_id`. | `application_type`, `source_id`, `preview_json`, `skipped_json`, `is_undone` |
| `zdoc_usage_stat` | Per-user usage counts, the basis for "ranked by the provider's own usage". | `user_id`, `object_type`, `scope_key`, `use_count`, `last_used_at` |
| `zdoc_warning_dismissal` | A dismissed warning and its **mandatory** reason. | `warning_source`, `warning_ref`, `reason` (NOT NULL) |

`zdoc_audit_event` is written by **every** package. It is the one shared-write table, and the exception is deliberate: it is append-only, nothing reads it transactionally, and no row is ever updated. See §11.

### 2.3 Shared vocabularies

| Table | Purpose |
|---|---|
| `zdoc_vocabulary` | The twelve centrally defined option lists. Seeded by `vocabularies.sql`. |
| `zdoc_vocabulary_item` | Items, each with a **declared** polarity, an optional `numeric_value`, and a stable `sort_order`. |

**Hard rule:** an element needing one of these vocabularies references it via `zdoc_template_element.vocabulary_id`. It does not restate the options as its own `zdoc_template_option` rows. The WP-06 importer rejects an element whose option labels duplicate a vocabulary it did not reference. This is what prevents one element offering `helped significantly` while a sibling element in the same template does not.

### 2.4 Template library

| Table | Purpose | Gotchas |
|---|---|---|
| `zdoc_template` | Blank structure. Inserts blank elements. | `applies_to_sex` / `applies_to_age_min` / `applies_to_age_max` are enforced **at application time**, and a skip is reported, never silent. `is_active = 0` retires a template while preserving historical notes — **never delete**. `is_procedure_note` marks a procedure-note template; the engine is identical. |
| `zdoc_template_group` | **The outer grouping level inside a template.** `label` is the region heading; `sort_order` orders groups independently of elements; `render_mode` (`inherit` \| `list` \| `paragraph`) lets one region render as prose inside a list-mode section. A flat template has no rows here. | See §2.4.1. |
| `zdoc_template_element` | A row inside a template ("Severity"). `template_group_id` is nullable: NULL = ungrouped, rendered at template level. | `render_stem` feeds paragraph-mode stem collapsing. `pair_key` + `laterality_role` (`right`/`left`) mark paired elements; the symmetry validator **refuses to save** when the two option sets differ. `carries_forward = 0` for current severity, pain score and time-indexed findings. |
| `zdoc_template_option` | A chip. | `polarity` is **declared here and copied to the finding at creation** — never inferred from wording anywhere. `sort_order` is the authoritative render order. `value_slots` describes `__/10`, `__ %` style inputs. `excludes_option_ids` is mutual exclusion within the element. `is_normal = 1` is what `Normal` / `All Normal` selects. |

### 2.4.1 The template → group → element → option hierarchy

Doc 06 §5.6's physical exam nests element prefixes under region headings:

```
Lumbar Spine: Inspection: no induration, ecchymosis, or swelling; normal
  alignment. Bony Palpation: no tenderness of the spinous process, the facet
  joints, the sacroiliac joint(s), or the coccyx. Active ROM: no pain with motion.
```

`Lumbar Spine` is the **group**. `Inspection`, `Bony Palpation`, `Active ROM` are **elements** with `render_prefix`. §1.4's `template_element` had no field for that outer level; `zdoc_template_group` is it.

```
zdoc_template
  └── zdoc_template_group        (optional; region heading, own sort_order)
        └── zdoc_template_element  (render_prefix, render_stem)
              └── zdoc_template_option  (polarity, is_normal, sort_order)
```

**Why a table rather than a `group_label` column on the element** — the choice the coordinator left open, documented as asked:

A group needs a sort order independent of element sort order. Denormalised `group_label` / `group_sort_order` columns on every element make group ordering depend on a convention that cannot be constrained and let the same group acquire two spellings across its elements. A group is a real structural entity in the rendered note, with its own heading and potentially its own render mode, so it gets a row. The cost is one nullable FK and one small table; a flat template (HPI in the reference output is flat) carries no group rows and pays nothing.

**Rendering contract:** groups render in `zdoc_template_group.sort_order`; within a group, elements render in `zdoc_template_element.sort_order`; ungrouped elements render after all groups, in their own sort order. `render_mode = 'inherit'` means use the section's mode.

### 2.5 Saved findings

| Table | Purpose |
|---|---|
| `zdoc_saved_finding` | Template(s) **plus answers**. One click fills an entire section. `laterality` is an attribute with value `parameter` meaning "side chosen when applied" — so one entry serves right, left and bilateral. |
| `zdoc_saved_finding_template` | The templates a saved finding applies. **Every one MUST be disclosed with its element count before commit.** |
| `zdoc_saved_finding_preset` | The pre-selected answers. `laterality = 'inherit'` takes the saved finding's side or the applied parameter. |

### 2.6 Macros

`zdoc_macro` — scope `system | practice | personal`, **personal silently wins on name collision**. Expanded text is `attested` on insertion, never `pre_populated`: the provider actively invoked their own stored speech. Nesting is depth-capped. A `zdoc_plan_option.narrative_template` and a `zdoc_consent_type.body_text` may reference a macro by name, so consent language has one definition whichever path reaches it.

### 2.7 Reason for visit and encounter plans

| Table | Purpose |
|---|---|
| `zdoc_reason_for_visit` | Configurable reason list. `name` is unique: **new-vs-established is `encounter_type`, never a duplicate reason.** |
| `zdoc_encounter_plan` | A one-selection scaffold for a whole note. |
| `zdoc_encounter_plan_item` | Polymorphic item (`item_type` + `reference_id`). No FK — the target table is implied by `item_type`. Referential integrity is the service layer's. |

### 2.8 Encounter extension

`zdoc_encounter_ext` — one row per core encounter, `UNIQUE` on `encounter_id`. The module does not own the encounter; it owns this extension.

**`zdoc_status`** is the module's four-state machine: `open | signed | amended | voided`. That is the entire state machine. There is no `prepped`, no `checked_in`, no `with_provider`. Core's own encounter/billing flow is **ignored** and never read as a state.

> **Naming note:** the status column is `zdoc_status`, not `status`, so a query joining core and module rows is unambiguous. This column name is frozen.

Assignment columns (`assigned_to`, `assigned_by`, `assigned_at`, `assignment_note`, `returned_at`, `return_reason`) are **routing, not state**. They do not appear in `zdoc_status`, they lock nothing, and their absence blocks nothing. An unassigned note signs normally.

`note_to_intake` / `note_to_exam` keep the prep-note capability as plain internal notes. **No package builds a separate pre-charting mode around them.**

`modifier_25_*` columns hold the provider's own same-day determination, captured at signing and carried to the billing task.

`rendered_note_text` / `rendered_note_hash` are the immutable snapshot frozen at signing.

### 2.9 Sections, attribution, policy

| Table | Purpose | Gotchas |
|---|---|---|
| `zdoc_encounter_section` | One row per (encounter, section_key). | **Section heading** (06 §5.6 `HPI — Pain Management L-spine (reported by patient)`) renders from three columns on this row: `section_key`, `applied_template_label` and `reported_by`. `applied_template_ids` (JSON, ordered) and `applied_template_label` are **snapshots** taken when a template is applied, so a heading renders identically years later even if the template is renamed or retired; `applied_template_label` holds the heading suffix as it should read, including the multi-template form `CSS Lumbar Spine + Wrist`. `reported_by` is set from the template header `Reported by` selector (06 §3.3, default `patient`). `is_complete` is an **advisory checkmark only, never a gate**. `version` is section-level optimistic concurrency: a save against a stale version is **rejected with current content for merge**, never silently overwritten. `reviewed_*` and `attested_*` are **not** advisory — WP-11 enforces them. `attestation_voided_at` is set when pre-populated content is added after attestation. |
| `zdoc_section_attribution` | Who documented what. Replaces role-gated stages. `role_at_time` and `credential_at_time` are **snapshots**, not live lookups, so the documentation block renders correctly years later. |
| `zdoc_section_policy` | One row per `section_key`. Shipped defaults are in `schema.sql` §23. **Config, not code**: a practice that wants MAs out of the A/P flips one row. |

### 2.10 Problems and problem threads

| Table | Purpose |
|---|---|
| `zdoc_problem` | Module-owned problem list. `laterality` is a **structured field, never text appended to the name**. `body_system` drives auto-grouping. `source = 'derived'` marks a problem offered by a rule (a BMI-derived specific obesity class, not a generic code). See OPEN-QUESTIONS Q7 on the relationship to core `lists`. |
| `zdoc_problem_thread` | The HPI is not one block. It is a set of threads, each bound to a problem, each carrying forward independently. `carries_forward` is a thread-level switch; the element-level switch also applies and both must permit a carry. |
| `zdoc_thread_entry` | A thread's content at one encounter. `is_interval_update = 1` marks the what-changed-since-last-visit row, which is **always new and never carried**. |

A new problem opens a **new blank thread**. Nothing existing is disturbed. Threads render in A/P order.

### 2.11 Findings

`zdoc_finding` — the atomic unit. One row per selected chip or typed value.

- `encounter_section_id` is **always set**, including for thread findings, so section-scoped queries stay simple. `thread_entry_id` is additionally set for HPI thread findings.
- `polarity` is copied from the option definition at creation. Never guessed.
- `provenance` ∈ `pre_populated | attested | ai_generated`. `ai_generated` is declared now so WP-14 needs no schema change later; it is **never auto-attested**.
- `placed_by` / `placed_at` are NOT NULL. `attested_by` / `attested_at` are set on attestation or on direct-entry creation by a clinician with attestation authority.
- `modified_from` holds the prior rendered value when the provider changed a pre-populated finding.
- `source_encounter_id` / `source_finding_id` are set on carry-forward and drive the age display.
- `application_id` links the finding to its bulk-placement batch.

**Provenance rules, verbatim contract:**
1. A finding created by applying a template or saved finding is `pre_populated`, **regardless of who applied it, the provider included.** A provider clicking `All Normal` has not examined anything.
2. A finding created by direct entry (typing, selecting an individual chip) by a clinician with attestation authority is `attested` immediately.
3. Editing a `pre_populated` finding sets it `attested`, stamps `attested_by`/`attested_at`, and records the prior value in `modified_from`.
4. Bulk attestation flips remaining `pre_populated` findings in a section to `attested` under one explicit action.
5. Macro-expanded text is `attested` on insertion.
6. Carried-forward findings are `pre_populated` with `source_encounter_id` set. **No new provenance value** — carry-forward, MA-placed scaffolding and AI drafts are all scaffold until attested, and one control covers all three.

### 2.12 Vitals

`zdoc_vital_reading` — **multiple readings per encounter are required**, each with its own `recorded_at`, surfaced in the note. Do not stamp the group. `bmi` is derived **and stored**. `bmi_class_code` carries the specific class offered to the problem list. `pain_score` lives here, not only in HPI. `not_performed` + `not_performed_reason` is a **first-class state, distinct from NULL**.

### 2.13 Allergies — the critical safety control

| Table | Purpose |
|---|---|
| `zdoc_allergy` | `source`, `source_name` and `source_received_at` are **displayed on the row**, not hidden. |
| `zdoc_patient_allergy_assertion` | One row per patient. `nkda`, plus `auto_cleared_at` and `last_reviewed_*`. A review is an **event, not a checkbox**. |

**`nkda = 1` while any `zdoc_allergy.status = 'active'` exists is a HARD VALIDATION ERROR that blocks signing. Not a warning.** It is seeded as system consistency rule `nkda_vs_active_allergy` with `severity = 'error'` and `is_system_rule = 1`, which makes it undeletable. An incoming external allergy on a patient asserting NKDA auto-clears the flag and raises a reconciliation task.

### 2.14 Medications and external sources

| Table | Purpose |
|---|---|
| `zdoc_external_source` | `health_status` + `health_message`. **If a source is configured but non-functional the rail says so**, verbatim from `health_message`. It never shows an empty list that reads as "nothing to reconcile". |
| `zdoc_medication` | Structured sig by default. `is_tallman` marks look-alike drug names stored with TALLman lettering. `reconciliation_state` drives the `Incoming` / `Dismissed` tabs. |
| `zdoc_section_flag` | Advisory per-section regulatory flag. `rule_name` is **named in the flag text**. A flag is **never cleared by an assertion that contradicts stored data** — enforced by the consistency engine, not by the clearing UI. |

### 2.15 Diagnosis sets

| Table | Purpose |
|---|---|
| `zdoc_diagnosis_set` | `code` is **NOT NULL and UNIQUE**. `presentation_mode` ∈ `insert_all \| pick_list`. `laterality_param = 1` means one set with a side selector replaces what would otherwise be a left/right duplicate pair. |
| `zdoc_diagnosis_set_item` | `default_selected = 0` means **offered** — one click to include, not yet in the note. `plan_group_id` wires the candidate to its plan tree ("plans ready"). |

`pick_list` is the primary mode: the set narrows the ICD-10 universe to the handful actually used for a region, each already wired to its plan group. `insert_all` is for deterministic encounters. A set MAY mix both by setting `default_selected` on some items only.

### 2.16 Plan groups and plan options

| Table | Purpose |
|---|---|
| `zdoc_plan_group` | One group per clinical entity, e.g. "lumbar radiculopathy". |
| `zdoc_plan_group_code` | `icd10_code` is **globally UNIQUE**: a code resolves to exactly one plan group. This is the structural guarantee against two near-identical codes acquiring divergent plan lists. |
| `zdoc_plan_option` | **One click, up to four effects**: narrative, order creation (`creates_order_type_id`), follow-up (`sets_followup` + `followup_interval`), consent queue (`queues_consent_id`). Plus `creates_procedure_type_id`, which populates `zdoc_encounter_procedure`, not only an order. `parameters` holds inline dropdowns (level, side, contrast, protocol, interval) — **never separate near-identical options**. |

Plan options are attached to **groups, not codes**. Attaching options directly to codes duplicates the list and guarantees drift.

### 2.17 Consents, education, receivers, authorization rules

| Table | Purpose |
|---|---|
| `zdoc_consent_type`, `zdoc_encounter_consent` | Consent queued by a plan option, obtained at checkout. |
| `zdoc_education_item`, `zdoc_encounter_education` | **Education is not an order.** Separate queue, own `Acknowledge` action, `status` lifecycle of its own. It **MUST NOT** appear in the order-signing count. Auto-attachment by diagnosis is fine and useful; it just isn't an order. |
| `zdoc_receiver` | Directory. `is_in_house = 1` entries are **first-class and rank first, always**. `latitude`/`longitude` exist so distance can be **displayed**, making a wrong suggestion visibly wrong. Search origin defaults to the encounter's facility address — never a fixed vendor default. |
| `zdoc_payer_auth_rule` | Date-effective authorization rules. Data, not code. |

### 2.18 Orders and dispatch

| Table | Purpose |
|---|---|
| `zdoc_order_type` | `default_destination` and `default_method` are properties of the **type**, configured once. **The provider never chooses a destination or a method.** `is_education` exists only so the importer can reject an attempt to model a handout as an order type; it must always be 0. |
| `zdoc_order_set` | `code` is **NOT NULL and UNIQUE**. Every set is numbered. Laterality and level come from the items, not the name. |
| `zdoc_order_set_item` | Mixed item types. **Validation warns when a set's name implies a procedure and no `item_type = 'procedure'` row exists.** Diagnosis codes on a set must match problem-list specificity. |
| `zdoc_order` | An order instance. The full field set is surfaced **before** signing, with defaults **pre-filled from the encounter** (`performing_provider_id` = encounter provider, `facility_id` = encounter facility, `receiver_id` = order type default). `status` includes `deferred` with a `deferred_reason`, because signing requires every order **signed or explicitly deferred**. |
| `zdoc_dispatch_task` | Front desk receives **tasks, not documents**. `packet_json` is the pre-assembled packet manifest; staff click send. `status = 'failed'` + `failure_reason` is what **turns the task red**. `scheduled_for` closes the loop back to the chart. |

### 2.18.1 Payer class — routing at signature

**This closes a genuine gap in specs 06 §7 and 10 §13.4, which do not distinguish cash-pay plan items from insurance-billable ones.** The practice runs multiple entities — insurance and cash-pay — through one OpenEMR, with a separate cash-pay system. Without this dimension, a cosmetic or cash procedure planned in the A/P projects an **insurance claim line**, which on this practice would happen in the first week of use.

`payer_class` ∈ `insurance` | `cash_pay` | `either`, carried on three tables:

| Table | Role |
|---|---|
| `zdoc_order_type.payer_class` | the configured default for that kind of order |
| `zdoc_procedure_type.payer_class` | the configured default for that procedure |
| `zdoc_encounter_procedure.payer_class` | the **resolved** value for this instance, overridable at documentation time |

**Signing routes by the resolved value. This is binding on WP-11, WP-12 and WP-15:**

| Resolved value | At signing |
|---|---|
| `insurance` | project a `zdoc_claim_line` as specified in 06 §7.2 |
| `cash_pay` | **do not project a claim line.** Create a quote in the cash-pay system through its `DocumentService`, and record the returned reference in `zdoc_encounter_procedure.cashpay_document_ref` |
| `either` | resolve from the patient's coverage for this encounter; if it cannot be resolved, raise a `zdoc_revenue_cycle_task` and hold **both** paths rather than guessing |

`cashpay_catalog_code` / `cashpay_document_ref` are **references only**. ZeroDoc never reads or writes the cash-pay system's tables — only its services. A `cash_pay` item on a signed note whose cash-pay module is disabled raises a revenue-cycle task; it never silently becomes an insurance line.

See OPEN-QUESTIONS Q36 for what still needs deciding here.

### 2.18.2 Authorization — read the existing register, never duplicate it

Another module owns the practice's register of **held** authorizations (auth number, CPT, date window, units). **ZeroDoc owns the rule and the status; it does not own a register.**

| Owned by ZeroDoc | Owned elsewhere |
|---|---|
| `zdoc_payer_auth_rule` — *does this order type require an auth for this payer class?* | the register of authorizations the practice actually holds |
| `zdoc_dispatch_task.auth_status` — the per-task state | the auth numbers, CPTs, date windows and unit counts |

At dispatch, ZeroDoc **reads** the external register through its service — never raw SQL — to answer "do we hold an auth covering this CPT for this patient today, with units remaining?" The answer is cached on the task in `auth_source`, `auth_number`, `auth_units_remaining` and `auth_checked_at`.

**Two rules that matter:**
1. **`auth_units_remaining` is always derived** at read time as `init_units − unitsUsed`. The external register's own stored remaining-units column is written on insert and never recomputed on update, so it is unreliable by design. No package reads it.
2. **A stale or unavailable answer degrades to `unknown`, with its age shown.** Never to `not_required`. OPEN-QUESTIONS Q37 records a prerequisite that is currently unmet and carries patient-facing financial risk.

### 2.18.3 Delivery — hand off, mirror, stay independent

**ZeroDoc does not implement faxing and owns no send queue.** It assembles the packet and invokes the existing fax/SMS module through an adapter.

`zdoc_dispatch_task` carries `external_job_id`, `external_job_source`, `external_status`, `external_status_at` and `external_status_reliable`, and **remains independent of the external queue**: the external row may be absent entirely (some vendor paths bypass the queue), or soft-deleted, while the clinical task still exists and still needs a truthful status.

**`zdoc_dispatch_task.status` is ZeroDoc's own and is authoritative for the task.** The `external_*` columns are evidence, not the source of truth.

**`external_status_reliable = 0` means the vendor path does not report delivery status. A task whose delivery cannot be confirmed MUST NOT display as delivered.** 08 §12.4's "a failed fax turns the task red" presumes a reliable status source that does not exist on every vendor path — so the honest display is a third state, not a false green. See OPEN-QUESTIONS Q39.

### 2.19 Assessment and plan

| Table | Purpose |
|---|---|
| `zdoc_encounter_diagnosis` | The A/P. **`sort_order` is the A/P order and it is load-bearing**: claim line order and diagnosis pointers derive from it. `plan_text` is `LONGTEXT` with **no character cap**. Carries full provenance (`provenance`, `placed_by`, `attested_by`, `modified_from`, `application_id`) on the same rules as findings. `thread_id` is the diagnosis ↔ thread binding. |
| `zdoc_encounter_plan_selection` | Which plan options were ticked under a diagnosis, the parameter values chosen, and the four things each created. `rendered_narrative` is the narrative after macro and parameter expansion. |

Deleting a pre-populated diagnosis leaves **no residue in the note** and **is** recorded in `zdoc_audit_event`.

### 2.20 Time

`zdoc_encounter_time` — one row per (encounter, user, attribution). **Provider time and clinical-staff time are separate rows, never summed into one displayed total.**

- `minutes` and `attribution` are both **NULLABLE on purpose**: a partial entry must validate with an error **without discarding the number**. A silently lost time value is a documented data-loss path and this nullability is the fix.
- `is_complete` is a stored generated column, advisory only.
- The E&M calculator reads **only** rows where `attribution` is an E&M variant **and** `user_id` is the billing practitioner. Staff minutes are structurally unable to reach the code.
- The calculator displays whose time it used and the excluded total.

### 2.21 Procedures

| Table | Purpose |
|---|---|
| `zdoc_procedure_type` | `code` unique. `parameters` (level, side, approach, guidance) drive the note template's variable slots, so **one template serves every level and side**. `default_cpt` and `default_modifiers` come from **the practice's own fee schedule only** — populated by the content capture track, from no other source. `required_note_elements` is the denial-prevention list. |
| `zdoc_encounter_procedure` | The performed procedure. `suggested_cpt` vs `confirmed_cpt`: **the system suggests, the biller confirms. Codes are never auto-submitted.** `required_elements_ok` / `required_elements_missing` record the check result. |

**Signing is blocked if any `required_note_elements` entry is absent**, with the missing items listed and deep-linked. Typical set: consent, timeout, laterality, level(s), guidance modality, agent and volume, patient response, complications statement.

A procedure is an **add-on to the note**, not part of the base note. It may occur at a visit or as a standalone procedure encounter.

### 2.22 Billing

| Table | Purpose |
|---|---|
| `zdoc_billing_task` | Created at signing, worked on the same kind of queue as dispatch tasks. Carries suggested vs confirmed codes, derived pointers, the prior-encounter diagnosis picks, auth status carried from the dispatch task, the provider's own modifier-25 determination, the required-element evidence, and bundling warnings. `denial_*` columns reopen the task with its reason and, where documentation-related, a `denial_section_key` that **deep-links to the specific note section**. |
| `zdoc_billing_code_revision` | Every biller edit, with the original suggestion retained alongside. |
| `zdoc_claim` | One per encounter. `status` ∈ `draft \| ready \| submitted \| paid \| denied`. `patient_status` is **derived from encounter history, never defaulted to `new`**, with the derivation displayed and an override requiring a reason. `em_total_minutes` is **read from `zdoc_encounter_time`, never re-entered**; `em_excluded_staff_minutes` and `em_time_user_id` are displayed so two numbers never silently become one. |
| `zdoc_claim_line` | `diagnosis_pointers` holds up to four per line. `derived_pointers` retains the derived default alongside any biller edit. **All** A/P diagnoses carry over, in A/P order — not just the first. `source` records whether the line came from the E&M calculator, a signed order, a procedure or manual entry. |
| `zdoc_em_time_code_rule` | Versioned, date-effective, payer-class dimensioned time→code table. `rule_label` and `effective_from` are **displayed beside the suggestion**. Never hard-coded. `payer_class` is a free string so a new class needs no schema change. |
| `zdoc_bundling_edit` | Bundling conflicts as data with effective dates. Surfaced as **warnings on the billing task, never auto-applied**. |
| `zdoc_revenue_cycle_task` | Registration gaps, eligibility failures and missing authorizations. **They set `zdoc_claim.status = 'draft'` and block submission. They never touch `zdoc_encounter_ext.zdoc_status`.** |

**The spine:** `thread → problem → diagnosis → plan option → order → claim line`. Every claim line traces to the documentation supporting it, which is what makes pointers derivable rather than reconstructed.

### 2.22.1 EHI export registration — a required deliverable, tied to signing

The practice runs an ONC-compliance bulk EHI exporter that maintains a registry of exported tables in its `src/TableDefinitions/`. **Nothing in it knows about `zdoc_*`.**

Left as-is, a patient's signed notes, findings, problem threads, vitals, allergies, problems and procedures would be **silently absent from their EHI export**. That is an ONC compliance failure, and **no ZeroDoc test will surface it** — the module works perfectly while the obligation goes unmet.

**This is a deliverable of WP-11 (signing), in M6 — not a later cleanup.** Signing is the moment patient clinical data becomes a record, so it is the moment the export obligation attaches.

**Tables carrying patient clinical data, which MUST be registered with the exporter.** Keyed by `patient_pid` or reachable from it via `encounter_id`:

| Table | Reached by | Holds |
|---|---|---|
| `zdoc_encounter_ext` | `patient_pid`, `encounter_id` | the note: status, signature, rendered text |
| `form_zdoc_encounter` | `pid` | core form bridge |
| `zdoc_encounter_section` | `encounter_id` | section text, visibility, review and attestation |
| `zdoc_section_attribution` | section | who documented what |
| `zdoc_finding` | section | **every clinical finding** |
| `zdoc_thread_entry`, `zdoc_problem_thread` | `encounter_id` / `patient_pid` | HPI threads and interval updates |
| `zdoc_encounter_diagnosis` | `encounter_id` | the assessment |
| `zdoc_encounter_plan_selection` | diagnosis | the plan |
| `zdoc_vital_reading` | `patient_pid`, `encounter_id` | vitals |
| `zdoc_allergy`, `zdoc_patient_allergy_assertion` | `patient_pid` | allergies and the NKDA assertion |
| `zdoc_medication` | `patient_pid` | medications |
| `zdoc_problem` | `patient_pid` | problem list |
| `zdoc_order`, `zdoc_dispatch_task` | `encounter_id`, `patient_pid` | orders and their disposition |
| `zdoc_procedure_type` ref + `zdoc_encounter_procedure` | `encounter_id`, `patient_pid` | procedures performed |
| `zdoc_encounter_time` | `encounter_id` | time documented |
| `zdoc_encounter_consent`, `zdoc_encounter_education` | `encounter_id` | consents and education provided |
| `zdoc_claim`, `zdoc_claim_line`, `zdoc_billing_task` | `encounter_id`, `patient_pid` | billing — **confirm whether EHI scope includes these** |
| `zdoc_audit_event` | `patient_pid` | **confirm whether audit data is in EHI scope** |

**Not patient data, and not for export:** every library and configuration table — `zdoc_template*`, `zdoc_saved_finding*`, `zdoc_vocabulary*`, `zdoc_diagnosis_set*`, `zdoc_plan_*`, `zdoc_order_type`, `zdoc_order_set*`, `zdoc_macro`, `zdoc_section_policy`, `zdoc_consistency_rule`, `zdoc_em_time_code_rule`, `zdoc_bundling_edit`, `zdoc_receiver`, `zdoc_payer_auth_rule`, `zdoc_education_item`, `zdoc_consent_type`, `zdoc_external_source`, `zdoc_schema_version`, `zdoc_usage_stat`, `zdoc_application`.

**Borderline, needing a decision:** `zdoc_consistency_violation`, `zdoc_warning_dismissal`, `zdoc_section_flag` and `zdoc_billing_code_revision` are encounter-scoped and arguably part of the record. See OPEN-QUESTIONS Q40.

Note that `zdoc_encounter_section.note_visibility = 'internal'` content is excluded from release-of-information renderings (§4.3). **Whether an EHI export is an ROI rendering for that purpose is a question for the practice, not a technical default** — and it is in Q40.

### 2.23 Consistency rules

| Table | Purpose |
|---|---|
| `zdoc_consistency_rule` | Declarative. `when_jsonb` / `then_conflicts_jsonb` / `message` / `severity`. `is_system_rule = 1` marks seeded rules that cannot be deleted. Six rules are seeded in `schema.sql` §24. |
| `zdoc_consistency_violation` | Evaluation results, so the sign-off panel and the acceptance harness read the same rows. `deep_link_section_key` / `deep_link_finding_id` back the deep links. |

**Errors block signing. Warnings are shown at sign-off and dismissible with a mandatory reason** recorded in `zdoc_warning_dismissal`.

The `when`/`then` expression language is **not frozen by this document** — see OPEN-QUESTIONS Q15. WP-10 owns the resolver and proposes the grammar back to the integration owner; the seeded payloads are shapes, not a specification.

---

## 3. Enum register — every enum, every exact value, in one place

Values are exactly as written in `schema.sql`. **Nothing adds a value to any enum locally.** An enum change is a schema migration and goes through §12.

### 3.1 Identity and structure

| Enum | Table.column | Values |
|---|---|---|
| **section_key** | `zdoc_encounter_section.section_key`, `zdoc_template.section_key`, `zdoc_saved_finding.section_key`, `zdoc_section_policy.section_key` | `reason`, `chief_complaint`, `vitals`, `allergies`, `medications`, `problems`, `family_hx`, `social_hx`, `surgical_hx`, `past_medical_hx`, `screening`, `hpi`, `ros`, `pe`, `imaging`, `assessment_plan`, `orders`, `procedures`, `time_spent`, `patient_instructions`, `discussion_notes`, `follow_up` |
| **encounter_type** | `zdoc_encounter_ext.encounter_type` | `new`, `follow_up`, `procedure`, `telehealth` |
| **encounter_type (plan filter)** | `zdoc_encounter_plan.encounter_type` | `any`, `new`, `follow_up`, `procedure`, `telehealth` |
| **zdoc_status** | `zdoc_encounter_ext.zdoc_status` | `open`, `signed`, `amended`, `voided` — **and no others, ever** |
| **render_mode** | `zdoc_encounter_section.render_mode`, `zdoc_template.default_render_mode` | `list`, `paragraph` |
| **note_visibility** | `zdoc_encounter_section.note_visibility` | `everyone`, `internal` |
| **reported_by** | `zdoc_encounter_section.reported_by`, `zdoc_finding.reported_by`, `zdoc_template.default_reported_by` | `patient`, `family`, `caregiver`, `interpreter`, `staff`, `provider`, `records` |
| **owner_scope** | `zdoc_template`, `zdoc_saved_finding`, `zdoc_diagnosis_set` | `system`, `practice`, `provider` |
| **macro scope** | `zdoc_macro.scope` | `system`, `practice`, `personal` |

### 3.2 Clinical content

| Enum | Table.column | Values |
|---|---|---|
| **polarity** | `zdoc_template_option.polarity`, `zdoc_finding.polarity`, `zdoc_vocabulary_item.polarity` | `positive`, `negative`, `neutral` |
| **laterality** | `zdoc_finding`, `zdoc_problem`, `zdoc_problem_thread`, `zdoc_encounter_diagnosis`, `zdoc_order`, `zdoc_encounter_procedure` | `right`, `left`, `bilateral`, `n/a` |
| **laterality (+parameter)** | `zdoc_saved_finding.laterality` | `right`, `left`, `bilateral`, `n/a`, `parameter` |
| **laterality (+inherit)** | `zdoc_saved_finding_preset`, `zdoc_diagnosis_set_item`, `zdoc_order_set_item` | `right`, `left`, `bilateral`, `n/a`, `inherit` |
| **laterality_role** | `zdoc_template_element.laterality_role` | `none`, `right`, `left`, `bilateral`, `parameter` |
| **applies_to_sex** | `zdoc_template.applies_to_sex` | `any`, `male`, `female` |
| **provenance** | `zdoc_finding.provenance`, `zdoc_encounter_diagnosis.provenance` | `pre_populated`, `attested`, `ai_generated` |
| **review_action** | `zdoc_encounter_section.review_action` | `accepted`, `supplemented`, `corrected` (NULL = not reviewed) |
| **vocabulary value_kind** | `zdoc_vocabulary.value_kind` | `nominal`, `ordinal`, `integer_range` |
| **template_group render_mode** | `zdoc_template_group.render_mode` | `inherit`, `list`, `paragraph` |

### 3.3 Intake

| Enum | Table.column | Values |
|---|---|---|
| **bp_position** | `zdoc_vital_reading.bp_position` | `sitting`, `standing`, `supine`, `unknown` |
| **bp_site** | `zdoc_vital_reading.bp_site` | `left_arm`, `right_arm`, `left_leg`, `right_leg`, `other`, `unknown` |
| **temp_route** | `zdoc_vital_reading.temp_route` | `oral`, `tympanic`, `temporal`, `axillary`, `rectal`, `other`, `unknown` |
| **o2_delivery** | `zdoc_vital_reading.o2_delivery` | `room_air`, `nasal_cannula`, `mask`, `other`, `unknown` |
| **allergy_type** | `zdoc_allergy.allergy_type` | `drug`, `food`, `environmental`, `other` |
| **allergy severity** | `zdoc_allergy.severity` | `mild`, `moderate`, `severe`, `life_threatening`, `unknown` |
| **allergy status** | `zdoc_allergy.status` | `active`, `inactive`, `entered_in_error` |
| **clinical source** | `zdoc_allergy.source`, `zdoc_medication.source` | `provider`, `patient`, `external_feed` |
| **problem source** | `zdoc_problem.source` | `provider`, `patient`, `external_feed`, `derived` |
| **problem status** | `zdoc_problem.status` | `active`, `resolved`, `inactive`, `entered_in_error` |
| **thread status** | `zdoc_problem_thread.status` | `active`, `resolved`, `inactive` |
| **medication status** | `zdoc_medication.status` | `active`, `historical`, `discontinued`, `entered_in_error` |
| **reconciliation_state** | `zdoc_medication.reconciliation_state` | `incoming`, `accepted`, `dismissed` |
| **code_system** | `zdoc_problem.code_system` | `ICD10`, `ICD9`, `SNOMED`, `other` |
| **external source domain** | `zdoc_external_source.domain` | `medication`, `allergy`, `problem`, `immunization`, `lab`, `imaging`, `other` |
| **external source health_status** | `zdoc_external_source.health_status` | `ok`, `degraded`, `unavailable`, `misconfigured`, `never_contacted` |
| **section_flag severity** | `zdoc_section_flag.severity` | `advisory`, `warning`, `error` |

### 3.4 Plans, orders, dispatch

| Enum | Table.column | Values |
|---|---|---|
| **presentation_mode** | `zdoc_diagnosis_set.presentation_mode` | `insert_all`, `pick_list` |
| **order_set_item.item_type** | `zdoc_order_set_item.item_type` | `diagnosis`, `procedure`, `imaging`, `lab`, `referral`, `education`, `consent`, `medication` |
| **encounter_plan_item.item_type** | `zdoc_encounter_plan_item.item_type` | `hpi_template`, `hpi_saved_finding`, `ros_saved_finding`, `pe_template`, `pe_saved_finding`, `diagnosis_set`, `order_set`, `education_set` |
| **order_type.item_category** | `zdoc_order_type.item_category` | `procedure`, `imaging`, `lab`, `referral`, `medication`, `dme`, `therapy`, `other` |
| **default_destination** | `zdoc_order_type.default_destination` | `front_desk`, `scheduler`, `imaging`, `pt`, `surgery`, `pharmacy`, `in_house_procedure`, `external_provider` |
| **dispatch destination** | `zdoc_dispatch_task.destination` | `front_desk`, `scheduler`, `imaging`, `pt`, `surgery`, `pharmacy`, `in_house_procedure`, `external_provider`, `patient` |
| **method** | `zdoc_order_type.default_method`, `zdoc_dispatch_task.method` | `worklist`, `fax`, `direct_message`, `email`, `print`, `erx` |
| **order status** | `zdoc_order.status` | `draft`, `signed`, `deferred`, `cancelled`, `completed` |
| **dispatch_task status** | `zdoc_dispatch_task.status` | `new`, `working`, `scheduled`, `sent`, `failed`, `done` |
| **auth_status** | `zdoc_dispatch_task.auth_status`, `zdoc_billing_task.auth_status` | `not_required`, `required`, `requested`, `approved`, `denied`, `unknown` |
| **payer_class** | `zdoc_order_type.payer_class`, `zdoc_procedure_type.payer_class`, `zdoc_encounter_procedure.payer_class` | `insurance`, `cash_pay`, `either` |
| **receiver_type** | `zdoc_receiver.receiver_type` | `in_house_facility`, `external_practice`, `imaging_center`, `pt_clinic`, `surgeon`, `pharmacy`, `lab`, `other` |
| **consent status** | `zdoc_encounter_consent.status` | `queued`, `presented`, `obtained`, `declined`, `void` |
| **education delivery** | `zdoc_education_item.delivery` | `print`, `portal`, `email`, `verbal` |
| **education status** | `zdoc_encounter_education.status` | `queued`, `acknowledged`, `provided`, `declined` |

### 3.5 Procedures, time, billing

| Enum | Table.column | Values |
|---|---|---|
| **guidance** | `zdoc_encounter_procedure.guidance` | `none`, `fluoroscopy`, `ultrasound`, `ct`, `landmark`, `other` |
| **procedure billing_status** | `zdoc_encounter_procedure.billing_status` | `pending`, `coded`, `billed`, `paid`, `denied` |
| **time attribution** | `zdoc_encounter_time.attribution` | `em`, `em_telehealth`, `bh`, `bh_telehealth`, `clinical_staff` (NULLABLE — partial entry is retained) |
| **billing task_type** | `zdoc_billing_task.task_type` | `procedure_coding`, `em_coding`, `review` |
| **billing task status** | `zdoc_billing_task.status` | `new`, `working`, `coded`, `submitted`, `denied`, `closed` |
| **code revision field** | `zdoc_billing_code_revision.field` | `cpt`, `modifiers`, `diagnosis_pointers`, `units` |
| **claim status** | `zdoc_claim.status` | `draft`, `ready`, `submitted`, `paid`, `denied` |
| **patient_status** | `zdoc_claim.patient_status`, `zdoc_em_time_code_rule.patient_status` | `new`, `established` |
| **em_guideline_mode** | `zdoc_claim.em_guideline_mode` | `mdm`, `time_based` |
| **MDM axes** | `zdoc_claim.em_mdm_problems`, `em_mdm_data`, `em_mdm_risk` | `n/a`, `minimal`, `low`, `moderate`, `high` |
| **claim_line source** | `zdoc_claim_line.source` | `em_calculator`, `signed_order`, `encounter_procedure`, `manual` |
| **bundling edit_type** | `zdoc_bundling_edit.edit_type` | `mutually_exclusive`, `column1_column2`, `mue`, `other` |
| **revenue_cycle task_type** | `zdoc_revenue_cycle_task.task_type` | `registration_gap`, `eligibility`, `assignment_of_benefits`, `authorization`, `demographic_mismatch`, `other` |
| **revenue_cycle status** | `zdoc_revenue_cycle_task.status` | `new`, `working`, `resolved`, `waived` |

### 3.6 Infrastructure

| Enum | Table.column | Values |
|---|---|---|
| **audit action** | `zdoc_audit_event.action` | `create`, `update`, `delete`, `apply`, `undo`, `review`, `attest`, `attest_void`, `sign`, `amend`, `void`, `dismiss_warning`, `dispatch`, `code`, `deny`, `export` |
| **application_type** | `zdoc_application.application_type` | `template`, `saved_finding`, `diagnosis_set`, `encounter_plan`, `order_set`, `carry_forward`, `all_normal`, `ai_draft` |
| **usage object_type** | `zdoc_usage_stat.object_type` | `template`, `saved_finding`, `diagnosis_set`, `plan_option`, `plan_group`, `order_set`, `order_type`, `procedure_type`, `macro`, `diagnosis_code`, `encounter_plan` |
| **warning_source** | `zdoc_warning_dismissal.warning_source` | `consistency_rule`, `section_policy`, `order_set_validation`, `required_note_element`, `bundling_edit`, `other` |
| **consistency severity** | `zdoc_consistency_rule.severity`, `zdoc_consistency_violation.severity` | `error`, `warning` |
| **violation status** | `zdoc_consistency_violation.status` | `open`, `resolved`, `dismissed` |
| **section_policy on_violation** | `zdoc_section_policy.on_violation` | `warn`, `block` |

### 3.8 NORMATIVE: how to assign `polarity` and `is_normal`

**This is a data-authoring rule with direct output consequences. It binds every package and the whole content-transcription track (WP-20).** It exists because the renderer's punctuation depends on a distinction the specs never state outright.

`polarity` and `is_normal` are **two independent facts**. Conflating them produces wrong text.

#### `polarity` — the STRUCTURAL role

It drives paragraph grouping and, critically, **stem collapsing**.

| Value | Means | Examples |
|---|---|---|
| `positive` | an asserted **presence** | `tenderness`, `sharp`, `decreased`, `4/5`, `Tinel's positive` |
| `negative` | an asserted **absence** | `no swelling`, `no tenderness`, `0/10`, `Tinel's negative` |
| `neutral` | a **descriptor** — **including a normal descriptor** | `normal alignment`, `5/5`, `2+`, `normal`, `chronic`, `intermittent`, `helped a little`, `not performed` |

> **The rule, stated plainly: a normal DESCRIPTOR is `neutral`, not `negative`. Only an asserted ABSENCE is `negative`.**

**Why it matters.** The renderer collapses consecutive `negative` findings sharing a stem into one negated clause: `no tenderness of the spinous process, the facet joints, the sacroiliac joint(s), or the coccyx`. If `normal alignment` is tagged `negative`, it is swept into the preceding negated clause and renders as a denial of something nobody denied. In the reference output this is visible in two places:

```
Inspection: no induration, ecchymosis, or swelling; normal alignment.
                └── negative, collapses ──┘        └── neutral, stands alone ──┘

Motor Strength: L1, L2–L4, L5, S1 — 5/5 right and left.
                                     └── neutral; must NOT become "no 5/5" ──┘
```

`polarity` is **declared on `zdoc_template_option` and copied to `zdoc_finding` at creation**. It is never inferred from wording, at any layer, by any package — including the AI layer, which emits an option id and inherits the option's polarity.

#### `is_normal` — CLINICAL normality

Independent of `polarity`. It drives the `Normal` / `All Normal` controls (06 §3.3) and, combined with polarity, the colour.

**A normal descriptor is `polarity = neutral` AND `is_normal = 1`.**

#### Colour derivation

Doc 06 §5.4 asks for positives red, "negative/normal" green, neutral descriptors blue/black. Since the structural rule above puts normal descriptors in `neutral`, colour cannot be read off `polarity` alone. It is derived from both inputs:

| `polarity` | `is_normal` | Colour |
|---|---|---|
| `positive` | any | **red** |
| `negative` | any | **green** |
| `neutral` | `1` | **green** — a normal descriptor is clinically normal |
| `neutral` | `0` | **blue/black** |

Implemented once, as CSS custom properties in `zdoc-core.css` (§6.1). No package derives colour independently. This is a reinterpretation of §5.4 as a two-input derivation rather than a one-input lookup — see OPEN-QUESTIONS Q22, which needs a human sign-off.

#### Authoring checklist for WP-20

For every option transcribed, ask **in this order**:
1. Does the text assert that something **is present**? → `positive`, `is_normal = 0`.
2. Does the text assert that something **is absent** — does it contain or imply a "no"? → `negative`, `is_normal = 1` if that absence is the normal state.
3. Otherwise it is a **descriptor** → `neutral`. Then: is the state it describes the clinically normal one? → `is_normal = 1`, else `0`.

Seeded vocabulary distribution, as a worked reference: 24 `positive`, 2 `negative`, 42 `neutral`, 5 with `is_normal = 1`.

### 3.9 Vocabulary values — not enums, but equally frozen

Seeded by `vocabularies.sql`. 12 vocabularies, 68 items. Referenced by `vocab_key` and `item_key`, never by numeric id in code.

| vocab_key | item_keys |
|---|---|
| `response_to_treatment` | `aggravated_symptoms`, `did_not_help`, `helped_a_little`, `helped_temporarily`, `helped_significantly` |
| `laterality` | `right`, `left`, `bilateral`, `n/a` |
| `severity_scale` | `0` … `10` (labels `0/10` … `10/10`) |
| `frequency` | `constant`, `intermittent`, `nocturnal` |
| `onset` | `sudden`, `gradual`, `chronic` |
| `pain_quality` | `throbbing`, `tightness`, `numbness`, `burning`, `aching`, `cramping`, `sharp`, `dull`, `tingling` |
| `reflex_grade` | `absent`, `1plus`, `2plus`, `3plus`, `4plus` |
| `motor_grade` | `0_5` … `5_5` (labels `0/5` … `5/5`) |
| `sensation` | `normal`, `decreased`, `absent`, `hyperesthetic` |
| `special_test_result` | `positive`, `negative`, `not_performed` |
| `lumbar_levels` | `T12`, `L1`, `L2`, `L3`, `L4`, `L5`, `S1`, `S2` |
| `cervical_levels` | `C2` … `C8` |

---

## 4. Service-layer interfaces

Signatures are given in PHP 8 style because the module is a PHP OpenEMR module. **The signature — name, parameter order, parameter meaning and return shape — is the contract. The implementation is the owning package's.**

Conventions:
- Every service is constructor-injected and resolved from the module container by interface, never instantiated directly across packages.
- Every method that writes takes the acting user explicitly (`int $actorUserId`). Nothing infers the actor from ambient session state, because background and import paths have no session.
- Every write method is transactional and either fully succeeds or fully rolls back.
- Return objects are immutable readonly DTOs. No package returns a raw array for a cross-package call.
- `PreviewResult` and `ValidationResult` **never write**.
- Namespace root: `OpenEMR\Modules\ZdocClinicalEncounter\`. Interfaces live under `...\Contract\`, DTOs under `...\Contract\Dto\`.

### 4.0 Shared DTOs

```php
namespace OpenEMR\Modules\ZdocClinicalEncounter\Contract\Dto;

final readonly class SectionRef {
    public function __construct(
        public int    $encounterId,
        public string $sectionKey,      // a section_key enum value
        public ?int   $encounterSectionId = null,
        public ?int   $threadEntryId = null,
    ) {}
}

final readonly class FindingDto {
    public function __construct(
        public ?int    $id,
        public int     $encounterSectionId,
        public ?int    $threadEntryId,
        public ?int    $templateId,
        public int     $elementId,
        public ?int    $optionId,
        public ?string $valueText,
        public ?string $valueNumeric,   // decimal as string; never float
        public ?string $valueUnit,
        public string  $laterality,     // laterality enum
        public ?string $anatomicLevel,
        public string  $polarity,       // polarity enum
        public int     $sortOrder,
        public string  $provenance,     // provenance enum
        public int     $placedBy,
        public string  $placedAt,       // ISO-8601 with milliseconds
        public ?int    $attestedBy,
        public ?string $attestedAt,
        public ?string $modifiedFrom,
        public ?int    $sourceEncounterId,
        public ?int    $applicationId,
        public ?string $reportedBy,
        public ?string $noteText,
    ) {}
}

final readonly class TemplatePlacementSummary {
    public function __construct(
        public int    $templateId,
        public string $templateName,
        public int    $elementCount,      // MUST be shown before commit
        public int    $findingCount,      // how many findings it would emit
        public bool   $willApply,
        public ?string $skipReason,       // non-null when willApply = false
    ) {}
}

final readonly class PreviewResult {
    /** @param list<TemplatePlacementSummary> $templates */
    public function __construct(
        public array $templates,
        public int   $totalElementCount,
        public int   $totalFindingCount,
        /** @var list<string> human-readable skip reports */
        public array $skips,
        public bool  $isEmpty,
    ) {}
}

final readonly class ApplicationResult {
    public function __construct(
        public int   $applicationId,      // pass to undoApplication
        /** @var list<int> */
        public array $createdFindingIds,
        /** @var list<int> */
        public array $createdDiagnosisIds,
        /** @var list<int> */
        public array $createdOrderIds,
        public PreviewResult $applied,    // what was actually committed
    ) {}
}

final readonly class ValidationIssue {
    public function __construct(
        public string  $severity,         // 'error' | 'warning'
        public string  $code,             // stable machine key, e.g. 'nkda_vs_active_allergy'
        public string  $message,
        public ?string $deepLinkSectionKey,
        public ?int    $deepLinkFindingId,
        public ?string $deepLinkField,
        public ?int    $ruleId,
    ) {}
}

final readonly class ValidationResult {
    /** @param list<ValidationIssue> $issues */
    public function __construct(public array $issues) {}
    public function hasErrors(): bool { /* any severity === 'error' */ }
    public function blocksSigning(): bool { /* errors, minus dismissed warnings */ }
}
```

### 4.1 Context accessor — the only path to core data

**Owner: WP-00. Consumed by every package.** No package writes its own query against a core OpenEMR table.

```php
interface EncounterContextService {
    public function getPatientContext(int $patientPid): PatientContext;
      // pid, display name, dob, age in years at a given date, administrative sex,
      // stated pronouns if recorded, MRN. Feeds sex/age template filtering and the
      // renderer's pronoun agreement.
    public function getEncounterContext(int $encounterId): EncounterContext;
      // core encounter id, patient pid, service date/time, facility id,
      // provider user id, plus the zdoc_encounter_ext row.
    public function getUserContext(int $userId): UserContext;
      // display name, credential string, role string, signing authority,
      // attestation authority, supervision requirement.
    public function getFacilityContext(int $facilityId): FacilityContext;
      // name, address, lat/lng — the default origin for receiver search.
    public function getPriorEncounters(int $patientPid, int $limit, ?string $sectionKey = null): array;
      // list<PriorEncounterSummary>: encounter id, date, type, one-line preview,
      // whether the named section has content. Backs the Previous Visit tab
      // AND the derived new-vs-established patient status.
    public function ensureEncounterExt(int $encounterId, int $actorUserId): int;
      // idempotent: creates the zdoc_encounter_ext row if absent, returns its id.
}
```

### 4.2 WP-01 — template engine

**Owns:** `zdoc_template`, `zdoc_template_element`, `zdoc_template_option`, `zdoc_saved_finding`, `zdoc_saved_finding_template`, `zdoc_saved_finding_preset`, `zdoc_finding`, `zdoc_encounter_section`, `zdoc_application`.

```php
interface TemplateApplicationService {

    /** Create findings from a blank template. All findings are pre_populated. */
    public function applyTemplate(
        int $encounterId,
        string $sectionKey,
        int $templateId,
        int $actorUserId,
        ?int $threadEntryId = null,
        ?string $lateralityParam = null,
    ): ApplicationResult;

    /** Create findings from a saved finding: its templates PLUS its presets. */
    public function applySavedFinding(
        int $encounterId,
        string $sectionKey,
        int $savedFindingId,
        int $actorUserId,
        ?int $threadEntryId = null,
        ?string $lateralityParam = null,
    ): ApplicationResult;

    /**
     * What WOULD be placed. WRITES NOTHING.
     * Returns every template with its element count and the sex/age filter
     * outcome, so the picker can disclose the full bundle before commit.
     * MUST be called and displayed before any applySavedFinding.
     */
    public function previewApplication(
        int $encounterId,
        string $sectionKey,
        string $applicationType,   // application_type enum
        int $sourceId,
        ?string $lateralityParam = null,
    ): PreviewResult;

    /** Single atomic reversal of one application batch. Idempotent. */
    public function undoApplication(int $applicationId, int $actorUserId): void;

    /** Select / deselect an individual chip. Attested on direct entry by an
     *  actor with attestation authority; pre_populated otherwise. */
    public function setFinding(FindingDto $finding, int $actorUserId): FindingDto;
    public function removeFinding(int $findingId, int $actorUserId): void;

    /** Element and template bulk controls. All produce pre_populated findings,
     *  including when the actor is the attributed provider. */
    public function applyElementNormal(int $encounterSectionId, int $elementId, int $actorUserId): ApplicationResult;
    public function clearElement(int $encounterSectionId, int $elementId, int $actorUserId): void;
    public function applyAllNormal(int $encounterSectionId, int $templateId, int $actorUserId): ApplicationResult;
    public function clearTemplate(int $encounterSectionId, int $templateId, int $actorUserId): void;

    /** @return list<FindingDto> ordered by zdoc_template_option.sort_order */
    public function getFindings(SectionRef $ref): array;
}

interface TemplateLibraryService {
    public function listTemplates(string $sectionKey, ?int $forUserId = null, bool $activeOnly = true): array;
    public function listSavedFindings(string $sectionKey, ?int $forUserId = null, bool $activeOnly = true): array;
    public function saveTemplate(TemplateDefinition $def, int $actorUserId): int;   // runs validators, may throw
    public function saveSavedFinding(SavedFindingDefinition $def, int $actorUserId): int;
    public function retireTemplate(int $templateId, int $actorUserId): void;        // is_active = 0; NEVER deletes
    public function validateSymmetry(int $templateId): ValidationResult;            // paired right/left option sets
    public function bundleAudit(?int $savedFindingId = null): array;                // templates bundled + findings emitted
    public function recordUse(string $objectType, int $objectId, int $userId, ?string $scopeKey = null): void;
}
```

### 4.3 WP-03 — renderer

**Owns:** nothing. **Reads nothing from the database.** Pure functions: findings in, text out. This is what keeps it testable and reusable by the AI layer.

```php
interface NoteRendererService {

    /** @param list<FindingDto> $findings */
    public function renderSection(
        array $findings,
        RenderContext $context,     // render_mode, patient sex/pronouns, element metadata
    ): string;

    /** Assemble the whole note from already-rendered sections plus header,
     *  vitals, documentation block and signature. ONE note, always complete,
     *  always the same name. No near-duplicate section names. */
    public function renderNote(NoteAssembly $assembly): string;

    /** Generated from stored data, never typed. States who documented what,
     *  who reviewed, who attested, counts of adopted / removed / added items,
     *  and any AI involvement. Included in patient-facing and ROI renderings. */
    public function renderDocumentationBlock(DocumentationBlockInput $input): string;

    /** Pronoun agreement. WP-05 calls this for @HE/SHE@ rather than
     *  reimplementing it. */
    public function pronoun(PatientContext $patient, string $form): string;
      // $form: 'subject' | 'object' | 'possessive' | 'reflexive'

    /** Serial list with stem collapsing: one stem, serial list,
     *  `or` before the last item under negation, `and` when positive. */
    public function collapseStem(string $stem, array $items, string $polarity): string;
}
```

**Renderer invariants (contract, not implementation advice):**
- Ordering is `zdoc_template_option.sort_order`, stable and documented. Never silently reordered. If a section is configured for entry order instead, it uses `zdoc_finding.sort_order` — one or the other, declared, never mixed.
- Paragraph mode groups positives then negatives.
- `note_visibility = 'internal'` content is excluded from patient-facing and release-of-information renderings, **and the exclusion is logged** to `zdoc_audit_event` with action `export`.
- Polarity colouring is consistent in every state and every stage: positive red, negative/normal green, neutral blue/black.

### 4.4 WP-04 — intake sections

**Owns:** `zdoc_vital_reading`, `zdoc_allergy`, `zdoc_patient_allergy_assertion`, `zdoc_medication`, `zdoc_external_source`, `zdoc_problem`, `zdoc_section_flag`.

```php
interface VitalsService {
    public function addReading(int $encounterId, VitalReadingInput $in, int $actorUserId): int;
    public function updateReading(int $readingId, VitalReadingInput $in, int $actorUserId): void;
    public function listReadings(int $encounterId): array;   // each with its OWN timestamp
    public function calculateBmi(?string $heightIn, ?string $weightLb): ?string;
    /** Specific obesity class for the problem list; never a generic code. */
    public function suggestBmiProblemCode(string $bmi): ?ProblemSuggestion;
}

interface AllergyService {
    public function listAllergies(int $patientPid, bool $activeOnly = false): array;
    public function addAllergy(int $patientPid, AllergyInput $in, int $actorUserId): int;
    public function getAssertion(int $patientPid): AllergyAssertion;
    public function setNkda(int $patientPid, bool $nkda, int $actorUserId): ValidationResult;
      // refuses and returns an error issue when an active allergy exists
    public function ingestExternalAllergy(int $patientPid, AllergyInput $in): ExternalIngestResult;
      // auto-clears nkda, raises a reconciliation task, returns both facts
    public function recordReview(int $patientPid, int $actorUserId): void;   // an EVENT, not a checkbox
    /** Exposed for WP-10 to consume. WP-04 does NOT block signing itself. */
    public function validateNkdaConsistency(int $patientPid): ValidationResult;
}

interface MedicationService {
    public function listMedications(int $patientPid, ?string $reconciliationState = null): array;
    public function addMedication(int $patientPid, MedicationInput $in, int $actorUserId): int;
    public function dismissIncoming(int $medicationId, int $actorUserId): void;
    /** Rail state per source. When a source is configured but unhealthy this
     *  returns its health_message — never an empty list. */
    public function getReconciliationRail(int $patientPid): ReconciliationRail;
}

interface ProblemService {
    public function listProblems(int $patientPid, bool $activeOnly = true): array;   // grouped by body_system
    public function addProblem(int $patientPid, ProblemInput $in, int $actorUserId): int;
    /** One click: every active problem offered to the A/P. Calls WP-07. */
    public function pushToAssessment(int $encounterId, array $problemIds, int $actorUserId): ApplicationResult;
}
```

### 4.5 WP-05 — macros

**Owns:** `zdoc_macro`.

```php
interface MacroService {
    public function resolve(string $name, ?int $userId): ?MacroDto;   // personal > practice > system
    /** @return list<MacroDto> for inline autocomplete */
    public function search(string $prefix, ?int $userId, int $limit = 20): array;
    /** Variable substitution, nesting (depth-capped) and tab-stop markers
     *  preserved for the editor to consume. Pronouns delegate to WP-03. */
    public function expand(int $macroId, MacroExpansionContext $context): ExpandedMacro;
      // context carries patient, encounter, provider, and — when the cursor is
      // inside a plan box — @SIDE@ and @LEVEL@ from that diagnosis.
    public function listForUser(int $userId): array;
    public function save(MacroDefinition $def, int $actorUserId): int;
    public function assignHotkey(int $macroId, int $userId, ?string $hotkey): void;
}
```

Expanded text is `attested`. Every free-text box in the module attaches macro support uniformly — a macro that works in one box and not another destroys trust in the feature.

### 4.6 WP-06 — import, export, library hygiene

**Owns:** no runtime table. Writes library tables **through WP-01, WP-07 and WP-08 service calls**, never by direct SQL, so every validator runs.

```php
interface LibraryImportService {
    public function validate(string $payload, string $format): ValidationResult;   // writes nothing
    public function import(string $payload, string $format, int $actorUserId, bool $dryRun = false): ImportResult;
      // transactional: all or nothing
    public function export(ExportSelector $selector, string $format): string;      // round-trippable
    public function bundleAuditReport(): array;
    public function hygieneReport(): HygieneReport;
      // unused-in-N-months, near-duplicate names, spelling, laterality-in-name,
      // non-unique codes, ICD-10 format and specificity, vocabulary conformance
}
```

`format` ∈ `yaml` | `json`. The schema of the import format is owned by WP-06 and published as `IMPORT-FORMAT.md` **alongside** this document; it is a contract for WP-20 and changes under the same protocol.

### 4.7 WP-07 — assessment and plan

**Owns:** `zdoc_encounter_diagnosis`, `zdoc_encounter_plan_selection`, `zdoc_diagnosis_set`, `zdoc_diagnosis_set_item`, `zdoc_plan_group`, `zdoc_plan_group_code`, `zdoc_plan_option`.

```php
interface AssessmentService {
    public function addDiagnosis(int $encounterId, DiagnosisInput $in, int $actorUserId): int;
      // attested on direct entry
    public function removeDiagnosis(int $diagnosisId, int $actorUserId): void;
      // no residue in the note; the deletion IS audited
    public function reorderDiagnoses(int $encounterId, array $orderedIds, int $actorUserId): void;
      // sort_order is load-bearing: it drives claim line order and pointers
    public function setPrimary(int $diagnosisId, int $actorUserId): void;
    public function listDiagnoses(int $encounterId): array;   // in A/P order
    /** Frequency-ranked favorites first, search as fallback. */
    public function searchDiagnoses(string $query, ?int $forUserId, int $limit = 25): array;

    public function previewDiagnosisSet(int $encounterId, int $diagnosisSetId, ?string $lateralityParam): PreviewResult;
    public function applyDiagnosisSet(
        int $encounterId, int $diagnosisSetId, int $actorUserId,
        ?string $lateralityParam = null,
        ?array $selectedItemIds = null,   // null = default_selected items only
    ): ApplicationResult;

    /** Resolves a code to its plan group via zdoc_plan_group_code. */
    public function getPlanOptionsForDiagnosis(int $diagnosisId, ?int $forUserId): array;
    /** One click, up to four effects. Returns what each effect created. */
    public function selectPlanOption(
        int $diagnosisId, int $planOptionId, array $parameterValues, int $actorUserId,
    ): PlanSelectionResult;
    public function deselectPlanOption(int $selectionId, int $actorUserId): void;
    public function setPlanText(int $diagnosisId, string $text, int $actorUserId): void;
}
```

### 4.8 WP-08 — orders and dispatch

**Owns:** `zdoc_order_type`, `zdoc_order_set`, `zdoc_order_set_item`, `zdoc_order`, `zdoc_dispatch_task`, `zdoc_receiver`, `zdoc_payer_auth_rule`, `zdoc_education_item`, `zdoc_encounter_education`, `zdoc_consent_type`, `zdoc_encounter_consent`.

```php
interface OrderService {
    /** Called by WP-07 when a plan option carries creates_order_type_id.
     *  Defaults are pre-filled from the encounter — performing provider,
     *  facility, receiver — never left blank for re-entry. */
    public function createOrder(
        int $encounterId, int $orderTypeId, int $actorUserId,
        ?int $encounterDiagnosisId = null,
        ?int $planOptionId = null,
        array $parameters = [],
    ): int;

    public function updateOrder(int $orderId, OrderInput $in, int $actorUserId): void;
    public function signOrder(int $orderId, int $actorUserId): void;
    public function deferOrder(int $orderId, string $reason, int $actorUserId): void;
    public function cancelOrder(int $orderId, string $reason, int $actorUserId): void;
    public function listOrders(int $encounterId): array;

    /** The signing count. Education is NOT in it. */
    public function countUnsignedOrders(int $encounterId): int;
    /** Exposed for WP-11: every order signed or explicitly deferred. */
    public function validateOrdersForSigning(int $encounterId): ValidationResult;

    /** Diagnosis-scoped panel, plus an explicit search-all escape so a
     *  clinician can order anything. */
    public function getOrdersForDiagnosis(int $diagnosisId, int $limit = 50): array;
    public function searchAllOrderTypes(string $query, int $limit = 50): array;

    public function previewOrderSet(int $encounterId, int $orderSetId, ?string $lateralityParam): PreviewResult;
    public function applyOrderSet(int $encounterId, int $orderSetId, int $actorUserId, ?string $lateralityParam = null): ApplicationResult;
    /** Warns when a set's name implies a procedure and it contains none. */
    public function validateOrderSet(int $orderSetId): ValidationResult;
}

interface DispatchService {
    /** The live rail: what the visit will send. Reads, writes nothing. */
    public function previewDispatch(int $encounterId): DispatchPreview;
    /** Called once by WP-11 at signing. One signature sends all of it. */
    public function dispatchForEncounter(int $encounterId, int $actorUserId): array;  // list<int> task ids
    public function getQueue(string $destination, ?string $status = null, ?int $assignedUserId = null): array;
    public function updateTaskStatus(int $taskId, string $status, ?string $failureReason, int $actorUserId): void;
      // status 'failed' with a reason is what renders the task red
    public function recordScheduled(int $taskId, string $scheduledFor, int $actorUserId): void;  // loop closure
    public function openTasksReport(int $olderThanDays): array;   // dispatched, never completed
}

interface ReceiverDirectoryService {
    /** Origin defaults to the encounter's facility address. In-house
     *  facilities are first-class entries and rank first. Distance is
     *  returned so a wrong suggestion is visibly wrong. */
    public function search(string $query, int $originFacilityId, ?float $maxMiles = null, int $limit = 25): array;
}

interface PatientEducationService {
    /** Auto-attachment by diagnosis is fine; it is not an order. */
    public function attachForDiagnosis(int $encounterId, string $icd10Code, int $actorUserId): array;
    public function acknowledge(int $encounterEducationId, int $actorUserId): void;
    public function listForEncounter(int $encounterId): array;
}
```

### 4.9 WP-09 — provenance, carry-forward, assignment, attestation

**Owns:** `zdoc_section_attribution`, `zdoc_section_policy`, `zdoc_encounter_time`, `zdoc_problem_thread`, `zdoc_thread_entry`. **Co-writes** the provenance, review, attestation and assignment columns on `zdoc_finding`, `zdoc_encounter_section`, `zdoc_encounter_diagnosis` and `zdoc_encounter_ext` — see §11.2 for the column-level split.

```php
interface ProvenanceService {
    public function countPrePopulated(int $encounterSectionId): int;   // the honest cost, displayed
    public function markReviewed(int $encounterSectionId, int $actorUserId, string $reviewAction): void;
      // reviewAction: 'accepted' | 'supplemented' | 'corrected'
    /** Flips every remaining pre_populated finding in the section to attested
     *  with the provider's stamp, stores the count and the wording in force,
     *  and emits the attestation line into the note. MUST NOT be pre-ticked.
     *  MUST NOT be callable by a user for whom ma_may_attest = false. */
    public function attestSection(int $encounterSectionId, int $actorUserId): AttestationResult;
    /** Re-validates that no pre_populated finding was added after the
     *  attestation timestamp. Voids the attestation if any was. */
    public function revalidateAttestation(int $encounterSectionId): ValidationResult;
    /** Exposed for WP-10/WP-11. WP-09 does NOT block signing itself. */
    public function getSectionObligations(int $encounterId): ValidationResult;
    public function getSectionPolicy(string $sectionKey): SectionPolicy;
}

interface CarryForwardService {
    /** Several recent encounters, dated, with one-line previews — not just
     *  the most recent, so a visit with no content is never the only offer. */
    public function listCarryCandidates(int $patientPid, string $sectionKey, int $limit = 5): array;
    public function previewCarry(int $encounterId, string $sectionKey, int $sourceEncounterId, ?int $threadId): PreviewResult;
    /** Moves FINDINGS, not text, so the consistency engine, renderer and
     *  attestation machinery keep working. Honours both
     *  zdoc_template_element.carries_forward and
     *  zdoc_problem_thread.carries_forward. Per-section (or per-thread) only. */
    public function carryForward(int $encounterId, string $sectionKey, int $sourceEncounterId, int $actorUserId, ?int $threadId = null): ApplicationResult;
}

interface ProblemThreadService {
    public function listThreads(int $patientPid, ?string $status = 'active'): array;
    public function openThread(int $encounterId, ThreadInput $in, int $actorUserId): int;
    public function resolveThread(int $threadId, int $encounterId, int $actorUserId): void;
    public function addEntry(int $threadId, int $encounterId, ?int $templateId, int $actorUserId): int;
    /** The interval update row. ALWAYS new, never carried. */
    public function setIntervalUpdate(int $threadEntryId, string $text, int $actorUserId): void;
}

interface AssignmentService {
    public function assign(int $encounterId, int $toUserId, ?string $note, int $actorUserId): void;
    public function returnDraft(int $encounterId, string $reason, int $actorUserId): void;  // reverts nothing, locks nothing
    public function queueMaToDraft(int $forUserId, string $fromDate, string $toDate): array;
    public function queueAssignedToMe(int $userId): array;
    public function queueDraftedUnsignedAging(int $olderThanDays): array;
}

interface EncounterTimeService {
    /** Partial entry validates with an error AND RETAINS THE NUMBER. */
    public function recordTime(int $encounterId, TimeInput $in, int $actorUserId): TimeRecordResult;
    public function listTime(int $encounterId): array;      // separate rows, never summed
    /** The single source of truth the E&M calculator reads. Returns the
     *  billing practitioner's E&M minutes AND the excluded staff total,
     *  separately, with whose time it is. */
    public function getBillableTime(int $encounterId, int $billingPractitionerId): BillableTime;
}
```

### 4.10 WP-10 — consistency rules

**Owns:** `zdoc_consistency_rule`, `zdoc_consistency_violation`, `zdoc_warning_dismissal`.

```php
interface ConsistencyEngineService {
    /** Evaluate on change — cheap, scoped to what changed. */
    public function evaluateSection(int $encounterId, string $sectionKey): ValidationResult;
    /** Evaluate everything, at sign-off. */
    public function evaluateEncounter(int $encounterId): ValidationResult;
    public function dismissWarning(int $violationId, string $reason, int $actorUserId): void;  // reason mandatory
    public function listRules(bool $activeOnly = true): array;
    public function saveRule(ConsistencyRuleDefinition $def, int $actorUserId): int;
      // refuses to modify or delete is_system_rule = 1
}
```

**Errors block. Warnings are dismissible with a reason.** The NKDA rule is a system rule and cannot be deactivated or deleted.

### 4.11 WP-11 — signing, immutability, amendment

**Owns:** the `zdoc_status` and signing columns on `zdoc_encounter_ext`, `zdoc_revenue_cycle_task`.

```php
interface SignOffService {

    /**
     * The complete precondition check. Composes, in this order:
     *   1  patient identity resolved (name, DOB, MRN)
     *   2  service date and attributed provider present
     *   3  chief complaint or reason for visit present
     *   4  at least one assessment with a coded diagnosis
     *   5  no error-severity consistency violation (ConsistencyEngineService)
     *   6  all orders signed or explicitly deferred (OrderService)
     *   7  signer has signing authority; co-sign satisfied if required
     *   8  every section authored by someone other than the signer is reviewed
     *   9  every section with requires_provider_attestation and any content is attested
     *  10  no finding remains pre_populated in an attested section
     *  11  supervision rule satisfied when signer != attributed provider
     *  12  every required_note_element present for every encounter procedure
     *
     * NOT preconditions, and no implementation may add them:
     *   check-in, arrival, appointment status, patient location, with-provider
     *   status, insurance verification, eligibility, assignment of benefits,
     *   registration beyond identity, a procedure or E&M code, checkout,
     *   whether anyone else has touched any section, whether the note was
     *   assigned to anyone.
     *
     * Every unmet item carries a deep link.
     */
    public function validateForSigning(int $encounterId, int $signerUserId): ValidationResult;

    /** Asks the provider the modifier-25 question when the encounter holds
     *  both an E/M and a procedure. Called by the sign-off panel, answered
     *  before sign(), attached to the billing task after. */
    public function getSigningPrompts(int $encounterId): array;
    public function answerModifier25(int $encounterId, bool $asserted, ?string $rationale, int $actorUserId): void;

    public function sign(int $encounterId, int $signerUserId): SignResult;
      // freezes rendered_note_text + hash, sets signed; then triggers
      // DispatchService::dispatchForEncounter and BillingProjectionService::projectClaim
    public function cosign(int $encounterId, int $cosignerUserId): void;
    public function amend(int $encounterId, int $actorUserId): int;
      // returns the NEW addendum encounter id; the original is never edited in place
    public function void(int $encounterId, string $reason, int $actorUserId): void;
    public function raiseRevenueCycleTask(int $encounterId, string $taskType, string $detail, int $actorUserId): int;
      // holds the claim in draft; NEVER touches zdoc_status
}
```

### 4.12 WP-12 — billing projection

**Owns:** `zdoc_claim`, `zdoc_claim_line`, `zdoc_billing_task`, `zdoc_billing_code_revision`, `zdoc_em_time_code_rule`, `zdoc_bundling_edit`.

```php
interface BillingProjectionService {
    /** Called by WP-11 after a successful sign. ALL A/P diagnoses, in A/P
     *  order. Procedure codes from signed orders and encounter procedures
     *  populate lines automatically. */
    public function projectClaim(int $encounterId, int $actorUserId): int;
    public function getClaim(int $encounterId): ?ClaimDto;
    public function recomputeLines(int $claimId, int $actorUserId): void;
    /** Derived from the A/P; up to four per line. The derived default is
     *  retained alongside any biller edit. */
    public function derivePointers(int $claimId): array;
}

interface EmCalculatorService {
    /** Derived from prior encounters with the practice, with the derivation
     *  returned for display. NEVER defaults to 'new'. */
    public function derivePatientStatus(int $encounterId): PatientStatusDerivation;
    public function overridePatientStatus(int $claimId, string $status, string $reason, int $actorUserId): void;
    /** Reads EncounterTimeService::getBillableTime. Never takes a typed time.
     *  Returns the suggestion, the applied rule label and its effective date,
     *  and the excluded staff total with whose time was used. */
    public function suggest(int $encounterId, string $guidelineMode, ?string $payerClass): EmSuggestion;
}

interface BillingTaskService {
    public function createForEncounter(int $encounterId, int $actorUserId): array;   // list<int>
    public function getTask(int $taskId): BillingTaskDto;
    /** Scoped to the patient; code, description, and the encounter it came
     *  from. Two clicks, not a chart dig. */
    public function listPriorEncounterDiagnoses(int $patientPid, int $excludeEncounterId, int $limit = 50): array;
    public function confirmCodes(int $taskId, CodeConfirmation $in, int $actorUserId): void;
      // records the revision; the original suggestion is retained
    public function checkBundling(int $taskId): ValidationResult;   // warnings only, never auto-applied
    public function postDenial(int $taskId, DenialInput $in, int $actorUserId): void;
      // reopens the task; documentation-related reasons carry denial_section_key
    public function signedButUncodedReport(int $olderThanDays): array;
}
```

### 4.13 WP-15 — procedures

**Owns:** `zdoc_procedure_type`, `zdoc_encounter_procedure`.

```php
interface ProcedureService {
    public function addProcedure(int $encounterId, int $procedureTypeId, int $actorUserId, array $parameters = []): int;
      // may also be created by WP-07 when a plan option carries creates_procedure_type_id
    public function updateProcedure(int $procedureId, ProcedureInput $in, int $actorUserId): void;
    public function listProcedures(int $encounterId): array;
    /** The denial-prevention check. Exposed for WP-11, which BLOCKS signing
     *  on any missing element, naming and deep-linking each one. */
    public function validateRequiredElements(int $procedureId): ValidationResult;
    /** From the procedure type's own configuration plus the resolved
     *  parameters. Suggestions only — the biller confirms. */
    public function suggestCoding(int $procedureId): CodingSuggestion;
    public function renderProcedureNote(int $procedureId): string;   // delegates to WP-03
}
```

### 4.14 WP-13 — speed layer

**Owns:** `zdoc_encounter_plan`, `zdoc_encounter_plan_item`, `zdoc_usage_stat`, `zdoc_reason_for_visit`.

```php
interface ShorthandResolverService {
    /** Parses operator shorthand into a structured intent. Writes nothing.
     *  Shared with WP-14's natural-language path. */
    public function resolve(string $input, ResolverContext $context): array;   // list<ResolvedIntent>
}

interface EncounterPlanService {
    /** Everything the plan will place, with counts, before commit. */
    public function previewPlan(int $encounterId, int $encounterPlanId): PreviewResult;
    /** One action. Undoes as one action. */
    public function applyPlan(int $encounterId, int $encounterPlanId, int $actorUserId): ApplicationResult;
    public function listPlansForReason(int $reasonForVisitId, string $encounterType): array;
}

interface FavoritesService {
    public function rank(string $objectType, int $userId, ?string $scopeKey = null, int $limit = 10): array;
}
```

### 4.15 WP-14 — AI layer

**Owns:** no table in this contract. Writes findings **through WP-01** with `provenance = 'ai_generated'`.

```php
interface AiDraftingService {
    /** Schema-constrained: the model emits findings against template
     *  elements, not prose. The template library is the controlled
     *  vocabulary that bounds output. */
    public function draftSection(int $encounterId, string $sectionKey, AiDraftRequest $req, int $actorUserId): ApplicationResult;
}
```

**Hard rules:** `ai_generated` findings are **never auto-attested**, render distinctly, and are listed in the documentation block. The consistency engine runs over AI output as a hallucination check. The local-inference-vs-vendor decision is made **before** this package starts.

---

## 5. Route and endpoint conventions

### 5.1 Module identity, namespace and layout — verified

Read from the practice's existing custom modules in the read-only clone, so these follow the house convention rather than inventing one.

| Item | Value |
|---|---|
| Module directory | `interface/modules/custom_modules/oe-module-zdoc-clinical-encounter/` |
| composer `name` | `carolina-sports/oe-module-zdoc-clinical-encounter` |
| composer `type` | `openemr-module` |
| composer `extra.openemr` | `{"type":"custom-module","directory":"oe-module-zdoc-clinical-encounter"}` |
| **PHP namespace root** | **`OpenEMR\Modules\ZdocClinicalEncounter\`** → `src/` |
| Entry point | `openemr.bootstrap.php` — registers the namespace via `ModulesClassLoader`, then `new Bootstrap(...)->subscribeToEvents()` |
| Install SQL | `table.sql` — the content of `schema.sql` + `vocabularies.sql` + the guarded `registry` INSERT. Idempotent; a re-install after a core update is a no-op |
| Teardown SQL | `cleanup.sql` |
| Upgrade SQL | `X_Y_Z-to-A_B_C_upgrade.sql` |
| Web-exposed files | `public/` |
| Core form directory | `zdoc_encounter` — `registry.directory`, with `new.php` / `view.php` / `report.php` served from the module via `LoadEncounterFormFilterEvent`, never placed in core's `/interface/forms/` tree |

> **Two corrections to note.** (1) An earlier draft used namespace root `ZeroDoc\ClinicalEncounter\`; the house convention is `OpenEMR\Modules\<PascalName>\`. (2) An intermediate draft used `ZerodocClinicalEncounter`, which collides on a grep with a pre-existing `Zerodoc*` class in the cash-pay module that refers to the practice's previous system (§2.1.3). **`OpenEMR\Modules\ZdocClinicalEncounter\` is the frozen value**, and `zdoc` is the single code identifier throughout. Every service interface in §4 lives under `OpenEMR\Modules\ZdocClinicalEncounter\Contract\`, and the DTOs under `...\Contract\Dto\`.

### 5.1.1 Routes

The physical URL prefix depends on how this version dispatches module routes, which the scaffold agent is documenting. What is **frozen** is the **logical route namespace**, so no package hard-codes a physical path:

```
<MODULE_BASE>/api/v1/<resource>[/<id>][/<sub-resource>]
```

- `MODULE_BASE` is read from one constant, `OpenEMR\Modules\ZdocClinicalEncounter\Config::moduleBase()`. **No package writes a URL literal.** Front-end code reads `window.ZDoc.base`.
- Front-end code builds every URL through `ZDoc.url(path)`. A string-concatenated URL is a contract violation and fails code review.

### 5.2 Resource naming

- Resource segments are **plural, lower-kebab**, and match the table noun without the prefix: `zdoc_encounter_section` → `encounter-sections`; `zdoc_saved_finding` → `saved-findings`; `zdoc_dispatch_task` → `dispatch-tasks`.
- Sub-resources nest at most one level: `/encounters/{id}/sections`, `/sections/{id}/findings`.
- Actions that are not CRUD are a **sub-resource verb segment**, never a query parameter: `POST /sections/{id}/attest`, `POST /applications/{id}/undo`, `POST /encounters/{id}/sign`, `POST /encounters/{id}/amend`, `POST /billing-tasks/{id}/confirm-codes`.
- Previews are **always** `POST .../preview` and **always** side-effect-free. A `GET` never writes; a preview never writes.

### 5.3 Verbs and status codes

| Verb | Use | Success |
|---|---|---|
| `GET` | read | 200 |
| `POST` | create, or a verb action | 201 create, 200 action |
| `PUT` | full replace | 200 |
| `PATCH` | partial update | 200 |
| `DELETE` | remove | 204 |

| Code | Meaning in this module |
|---|---|
| 400 | malformed request |
| 403 | the acting user lacks the right (e.g. an MA attempting to attest where `ma_may_attest = 0`) |
| 404 | not found |
| **409** | **optimistic-concurrency rejection.** Body carries the current section content for merge. Never a silent overwrite. |
| **422** | **validation failure.** Body is a `ValidationResult`: a list of issues with severity, code, message and deep link. |
| 500 | unhandled |

### 5.4 Response envelope

Every response, success or failure:

```json
{
  "ok": true,
  "data": {},
  "issues": [
    {
      "severity": "error",
      "code": "nkda_vs_active_allergy",
      "message": "...",
      "deepLinkSectionKey": "allergies",
      "deepLinkFindingId": null,
      "deepLinkField": "nkda",
      "ruleId": 1
    }
  ],
  "version": 7
}
```

- `issues` is always present, possibly empty. A successful write may carry warnings.
- `version` is the section or encounter version to echo back on the next write.
- Every write request carries `If-Match: <version>` or a `version` body field. A request without one is rejected 400 — not silently accepted.

### 5.5 Events

The module emits its own events for internal decoupling. **Names are frozen; payloads are frozen as the id set below.** Whether these ride OpenEMR's event dispatcher or a module-local dispatcher is an M0 finding (OPEN-QUESTIONS Q20); the names do not change either way.

| Event | Emitted by | Payload |
|---|---|---|
| `zdoc.encounter.created` | WP-00 | `encounterId`, `patientPid`, `actorUserId` |
| `zdoc.section.updated` | WP-01 | `encounterId`, `sectionKey`, `encounterSectionId`, `actorUserId` |
| `zdoc.application.applied` | WP-01, WP-07, WP-08, WP-13 | `applicationId`, `encounterId`, `applicationType`, `sourceId` |
| `zdoc.application.undone` | same | `applicationId`, `encounterId` |
| `zdoc.finding.changed` | WP-01 | `findingId`, `encounterSectionId`, `provenance` |
| `zdoc.section.reviewed` | WP-09 | `encounterSectionId`, `reviewAction`, `actorUserId` |
| `zdoc.section.attested` | WP-09 | `encounterSectionId`, `attestedCount`, `actorUserId` |
| `zdoc.section.attestation_voided` | WP-09 | `encounterSectionId`, `reason` |
| `zdoc.allergy.external_ingested` | WP-04 | `patientPid`, `allergyId`, `nkdaCleared` |
| `zdoc.encounter.signed` | WP-11 | `encounterId`, `signerUserId`, `signedAt` |
| `zdoc.encounter.amended` | WP-11 | `originalEncounterId`, `addendumEncounterId` |
| `zdoc.encounter.voided` | WP-11 | `encounterId`, `reason` |
| `zdoc.dispatch.task_created` | WP-08 | `taskId`, `encounterId`, `destination`, `method` |
| `zdoc.dispatch.task_failed` | WP-08 | `taskId`, `failureReason` |
| `zdoc.billing.task_created` | WP-12 | `taskId`, `encounterId`, `taskType` |
| `zdoc.billing.denial_posted` | WP-12 | `taskId`, `denialCode`, `denialSectionKey` |

**No package subscribes to an event in order to write a table it does not own.** Events carry ids; a subscriber that needs data calls the owning service.

---

## 6. Front-end asset conventions

### 6.1 Layout

Asset paths are relative to the module root, which resolves through `MODULE_BASE`:

```
assets/
  js/
    zdoc-core.js            bootstrap, ZDoc namespace, url(), fetch wrapper, version handling
    zdoc-panel.js           the findings panel shell          (WP-02)
    zdoc-chips.js           chip rendering, value slots       (WP-02)
    zdoc-assessment.js      the assessment tree               (WP-07)
    zdoc-dispatch.js        the dispatch rail, worklists       (WP-08)
    zdoc-macros.js          macro editor attachment            (WP-05)
    zdoc-signoff.js         sign-off panel, deep links         (WP-11)
    zdoc-billing.js         biller worklist                    (WP-12)
  css/
    zdoc-core.css           tokens, polarity colours, layout
    zdoc-panel.css
  templates/                server-rendered partials
```

**Rules:**
- Every file is prefixed `zdoc-`. No unprefixed asset.
- One global: `window.ZDoc`. No other global, no jQuery plugin on a core selector, no monkey-patching a core script.
- Polarity colours are **CSS custom properties defined once** in `zdoc-core.css`: `--zdoc-polarity-positive`, `--zdoc-polarity-negative`, `--zdoc-polarity-neutral`. No package hard-codes a colour, because colour must be **consistent everywhere** — editor and rendered note, every state.
- No package edits a file it does not own. `zdoc-core.js` and `zdoc-core.css` are owned by WP-00 (integration owner).

### 6.2 The findings panel mount point — frozen

Every section header renders exactly this:

```html
<div class="zdoc-section" data-zdoc-section-key="hpi" data-zdoc-section-id="4812">
  <div class="zdoc-section-header">
    <span class="zdoc-section-title">HPI</span>
    <button type="button"
            class="zdoc-panel-trigger"
            data-zdoc-section-key="hpi"
            data-zdoc-encounter-id="10431"
            data-zdoc-section-id="4812"
            aria-controls="zdoc-findings-panel-root"
            aria-expanded="false">＋</button>
  </div>
  <div class="zdoc-section-body"><!-- rendered findings --></div>
</div>
```

And the page carries **exactly one** panel root, at body level, outside any section:

```html
<div id="zdoc-findings-panel-root"
     class="zdoc-panel"
     data-zdoc-state="closed"
     aria-hidden="true"></div>
```

**Contract:**
- `#zdoc-findings-panel-root` is a **singleton**. WP-02 owns it. WP-07's assessment tree **re-uses this same surface** — the panel content changes, there is no second panel and no modal. WP-02 therefore builds the shell generically and exposes:

```js
ZDoc.panel.open({ sectionKey, encounterId, sectionId, tabs, context })
ZDoc.panel.setTabs(tabs)        // [{ id, label, render(container, ctx) }]
ZDoc.panel.setContent(tabId, renderFn)
ZDoc.panel.close()
ZDoc.panel.on(eventName, handler)
```

- Tabs are **consistent across sections**, including sections where one tab is thin. Predictability beats optimization. Tab sets per section:

| Section | Tab 1 | Tab 2 | Tab 3 |
|---|---|---|---|
| `chief_complaint` | Previous visit | Saved findings | Templates |
| `hpi` | Previous visit | Saved findings | Templates |
| `ros` | Previous visit | Saved findings | Templates |
| `pe` | Previous visit | Saved findings | Templates |
| `imaging` | Previous visit | Saved findings | Templates |
| `assessment_plan` | Previous visit | Saved assessments | Diagnoses |

- Tab ids are frozen: `previous-visit`, `saved`, `library`. The *label* of tab 2 and 3 varies by section; the *id* does not, so a package can target a tab without string-matching a label.
- The Previous Visit tab operates **per thread** in `hpi`, and per section elsewhere.
- Every bulk placement surfaces its `PreviewResult` in the panel **before** commit, and offers single-action undo after.

### 6.3 Macro attachment — frozen

Every free-text input or textarea in the module carries `data-zdoc-macro="1"`. `zdoc-macros.js` attaches to all of them on DOM ready and on dynamic insertion via a mutation observer. A package adding a free-text box adds the attribute; it writes no macro code.

```html
<textarea class="zdoc-note" data-zdoc-macro="1" data-zdoc-macro-context='{"diagnosisId":9912}'></textarea>
```

`data-zdoc-macro-context` supplies `@SIDE@` and `@LEVEL@` when the box sits under a diagnosis.

---

## 7. Validation ownership — who refuses what

A validation implemented in two places diverges. Each rule has exactly one owner, and consumers call the owner.

| Validation | Owner | Consumed by |
|---|---|---|
| Right/left symmetry of paired elements | WP-01 (`validateSymmetry`) | WP-06 at import; refuses the save |
| Template sex/age match at application | WP-01 | WP-02 displays the skip report |
| Unique `code` on order sets, diagnosis sets, procedure types | the database (`UNIQUE`) | WP-06 surfaces the collision |
| Order set named for a procedure but containing none | WP-08 (`validateOrderSet`) | WP-06 at import |
| Vocabulary conformance, spelling, near-duplicate names, laterality-in-name, ICD-10 format | WP-06 (`hygieneReport`) | admin tooling |
| NKDA vs active allergy | WP-04 (`validateNkdaConsistency`) → seeded rule in WP-10 | WP-11 blocks signing |
| Cross-section clinical contradictions | WP-10 | WP-11 |
| Section review and attestation obligations | WP-09 (`getSectionObligations`) | WP-10, WP-11 |
| Attestation freshness after late pre-populated content | WP-09 (`revalidateAttestation`) | WP-11 |
| Orders signed or deferred | WP-08 (`validateOrdersForSigning`) | WP-11 |
| Procedure required note elements | WP-15 (`validateRequiredElements`) | WP-11 blocks signing |
| The complete signing precondition set | WP-11 (`validateForSigning`) | the sign-off panel |
| Bundling conflicts | WP-12 (`checkBundling`) | the billing task, as warnings only |

**No package blocks signing itself.** Packages expose conditions; WP-11 is the only thing that refuses a signature.

---

## 8. Transaction and concurrency contract

1. **Nothing locks a chart.** The MA and the provider can both have it open. This is a real scenario, not an edge case.
2. **Section-level optimistic concurrency.** Every write to a section or its children carries the section `version`. A stale version is rejected **409** with the current content in the body, for merge. **Never a silent overwrite.**
3. **Bulk placement is one transaction.** `zdoc_application` row plus every child row commit or roll back together. A partial application is a contract violation.
4. **`undoApplication` is idempotent.** Calling it twice is a no-op the second time, not an error.
5. **Attestation re-validates.** `attestSection` re-reads `pre_populated` counts inside the transaction. A finding added between the count being displayed and the attestation committing voids the attestation rather than silently adopting content that postdates it.
6. **Signing is one transaction** over validation, status change and note freeze. Dispatch and claim projection run **after** the commit, as subscribers to `zdoc.encounter.signed`, so a fax failure can never roll back a signature.
7. **A signed note is immutable.** No code path updates a `signed` encounter's clinical content. Amending creates a linked addendum encounter with its own signature; the original is retained and both render.

---

## 9. What is explicitly NOT a gate — the negative contract

This list is as binding as anything positive in this document. **No package may add any of these as a precondition to any clinical action, and a code review that finds one rejects the change.**

Not a precondition for entering data, for completing a section, or for signing:

- check-in, arrival, or any appointment status
- patient location or with-provider status
- insurance verification, eligibility, or assignment-of-benefits flags
- registration completeness beyond patient identity
- a procedure or E&M code
- checkout
- whether anyone else has touched any section
- whether the note was assigned to anyone
- demographic mismatch or any other clean-claim rule

And structurally:

- **No clinical field is ever rendered `disabled` because of workflow position.** Every clinical field is editable from the moment the encounter exists.
- `is_complete` is advisory and gates nothing.
- Revenue-cycle gaps set `zdoc_claim.status = 'draft'` and block **submission**. They never touch `zdoc_encounter_ext.zdoc_status`.

---

## 10. Reporting contract

Four reports share one shape — "started and never finished" — and exist for one reason. Each is owned by the package that owns the underlying table.

| Report | Owner | Query basis |
|---|---|---|
| Drafted, unsigned, aging | WP-09 | `zdoc_status = 'open'` with content, past N days, regardless of assignment |
| Dispatched, never completed | WP-08 | `zdoc_dispatch_task.status NOT IN ('done','sent')` past N days |
| Signed, never coded | WP-12 | `zdoc_billing_task.status IN ('new','working')` past N days |
| Content before service time | WP-09 | derived boolean, **a report only** — never a status that changes behaviour |

That last one is deliberate. A derived flag that looks like a stage is a source of confusion; it stays a report.

---

## 11. Write-ownership map

**One table, one writing package.** A package that needs a row in a table it does not own calls the owning service. This is the single most important section of this document for avoiding merge conflicts.

### 11.1 Table ownership

| Package | Tables it WRITES |
|---|---|
| **WP-00** (integration owner) | `zdoc_schema_version`, `zdoc_vocabulary`, `zdoc_vocabulary_item`, `zdoc_application`¹, `zdoc_encounter_ext`² |
| **WP-01** template engine | `zdoc_template`, `zdoc_template_group`, `zdoc_template_element`, `zdoc_template_option`, `zdoc_saved_finding`, `zdoc_saved_finding_template`, `zdoc_saved_finding_preset`, `zdoc_finding`³, `zdoc_encounter_section`⁴ |
| **WP-02** findings panel | *(none — front end only; writes through WP-01's API)* |
| **WP-03** renderer | *(none — pure functions, reads nothing from the database)* |
| **WP-04** intake | `zdoc_vital_reading`, `zdoc_allergy`, `zdoc_patient_allergy_assertion`, `zdoc_medication`, `zdoc_external_source`, `zdoc_problem`, `zdoc_section_flag` |
| **WP-05** macros | `zdoc_macro` |
| **WP-06** library tooling | *(none directly — imports through WP-01 / WP-07 / WP-08 services so every validator runs)* |
| **WP-07** assessment & plan | `zdoc_encounter_diagnosis`, `zdoc_encounter_plan_selection`, `zdoc_diagnosis_set`, `zdoc_diagnosis_set_item`, `zdoc_plan_group`, `zdoc_plan_group_code`, `zdoc_plan_option` |
| **WP-08** orders & dispatch | `zdoc_order_type`, `zdoc_order_set`, `zdoc_order_set_item`, `zdoc_order`, `zdoc_dispatch_task`, `zdoc_receiver`, `zdoc_payer_auth_rule`, `zdoc_education_item`, `zdoc_encounter_education`, `zdoc_consent_type`, `zdoc_encounter_consent` |
| **WP-09** provenance & attestation | `zdoc_section_attribution`, `zdoc_section_policy`, `zdoc_encounter_time`, `zdoc_problem_thread`, `zdoc_thread_entry` |
| **WP-10** consistency engine | `zdoc_consistency_rule`, `zdoc_consistency_violation`, `zdoc_warning_dismissal` |
| **WP-11** signing | `zdoc_revenue_cycle_task`, `form_zdoc_encounter`, and the `registry` / `forms` rows for the `zdoc_encounter` form directory (through core's `addForm()` only, never by direct SQL) |
| **WP-12** billing | `zdoc_claim`, `zdoc_claim_line`, `zdoc_billing_task`, `zdoc_billing_code_revision`, `zdoc_em_time_code_rule`, `zdoc_bundling_edit` |
| **WP-13** speed layer | `zdoc_encounter_plan`, `zdoc_encounter_plan_item`, `zdoc_usage_stat`, `zdoc_reason_for_visit` |
| **WP-14** AI layer | *(none — writes findings through WP-01 with `provenance = 'ai_generated'`)* |
| **WP-15** procedures | `zdoc_procedure_type`, `zdoc_encounter_procedure` |
| **WP-20** content capture | *(none — produces import files consumed by WP-06)* |
| **WP-90** QA | *(none in production schema — fixtures only, in a separate test database)* |
| **ALL packages** | `zdoc_audit_event` — append-only, never updated, never read transactionally |

### 11.2 The four shared tables, resolved at column level

These are the only tables more than one package touches. The split is by column and it is binding.

**`zdoc_encounter_ext`**

| Columns | Writer |
|---|---|
| row creation, `patient_pid`, `facility_id`, `provider_user_id`, `encounter_type`, `reason_for_visit_id`, `chief_complaint`, `service_date`, `service_time`, `note_to_intake`, `note_to_exam`, `version`, `created_*`, `updated_*` | **WP-00** (`ensureEncounterExt`) and the encounter CRUD it owns |
| `zdoc_status`, `signed_at`, `signed_by`, `voided_*`, `void_reason`, `amends_encounter_id`, `cosign_required`, `cosigned_*`, `rendered_note_text`, `rendered_note_hash`, `modifier_25_*`, `em_with_procedure_same_day` | **WP-11** only |
| `assigned_to`, `assigned_by`, `assigned_at`, `assignment_note`, `returned_at`, `return_reason` | **WP-09** only |

**`zdoc_encounter_section`**

| Columns | Writer |
|---|---|
| row creation, `render_mode`, `is_complete`, `note_text`, `note_visibility`, `reported_by`, `version`, `created_*`, `updated_*` | **WP-01** |
| `reviewed_by`, `reviewed_at`, `review_action`, `attested_by`, `attested_at`, `attested_count`, `attestation_text`, `attestation_voided_*`, `carried_from_encounter_id`, `carried_at` | **WP-09** |

**`zdoc_finding`**

| Columns | Writer |
|---|---|
| everything on create; `option_id`, `value_*`, `laterality`, `anatomic_level`, `polarity`, `sort_order`, `note_text`, `reported_by`, `application_id` | **WP-01** |
| `provenance`, `attested_by`, `attested_at`, `modified_from`, `source_encounter_id`, `source_finding_id` on transition — i.e. bulk attestation and carry-forward | **WP-09**, through `ProvenanceService` / `CarryForwardService` |

> WP-01 sets `provenance` at **creation** (`pre_populated`, or `attested` on authorised direct entry). WP-09 changes it on **attestation**. Neither reaches into the other's moment.

**`zdoc_application`**

| Columns | Writer |
|---|---|
| table and the `ApplicationLedger` helper | **WP-00** |
| rows, via `ApplicationLedger::begin()` / `::commit()` / `::undo()` | **WP-01, WP-07, WP-08, WP-09, WP-13, WP-14** — all through the helper, never by direct SQL |

### 11.3 Read access

**Any package may read any table.** Ownership restricts writes only. But a read that reconstructs business logic the owning service already implements is a defect: call the service.

---

## 12. Contract-change protocol

**No agent may alter anything in this document, in `schema.sql`, or in `vocabularies.sql`.** Not a table name, not a column name, not a type, not a nullability, not an enum value, not a signature, not a route, not an event name, not an asset convention, not an ownership assignment.

This is not bureaucracy. Two agents independently improving a shared table is the one failure mode that turns parallel work into a rewrite, and it is invisible until assembly.

### 12.1 When you believe a contract is wrong

**Stop. Do not work around it, and do not change it locally.** A local workaround is worse than a blocked package, because the blockage is visible and the workaround is not.

Raise a **Contract Change Request** to the integration owner containing:

1. **Package** raising it, and the branch.
2. **Contract element** — exact table.column, signature, route or convention.
3. **What the spec requires** — with the document and section (`06 §2.5`, `07 §11.5.1`, `08 §12.3.1`, `10 §13.3.3`).
4. **Why the current contract cannot satisfy it.** Not "would be cleaner" — *cannot*.
5. **The minimum change** that would. Prefer additive: a new nullable column, a new method, a new event. Prefer it strongly.
6. **Blast radius** — which other packages touch this element, from §11.
7. **Whether you are blocked** or can proceed with a mock.

### 12.2 What the integration owner does

1. Decides. Additive changes are cheap and usually approved. A type change, a column rename, an enum narrowing or an ownership move is expensive and usually refused in favour of an additive alternative.
2. If approved: updates `schema.sql`, `vocabularies.sql` and this document; writes a **new numbered migration** (`0002_*.sql`, never an edit to `0001`); bumps the contract version; folds the change into the consolidated file.
3. Notifies **every** package, not only the requester — including packages that are not yet started.
4. Records it in the changelog below.

Only the integration owner edits these three files. A pull request from a build agent that touches them is rejected unread.

### 12.3 Standing refusals

These are settled and will not be reopened by a change request:

- Adding a fifth value to `zdoc_status`, or any value that represents a workflow stage.
- Adding any item from §9 as a precondition to a clinical action.
- Adding a fourth `provenance` value for carry-forward, MA placement or AI drafting separately. `ai_generated` exists; carry-forward and MA placement are both `pre_populated` by design, because one attestation control must cover all three.
- Making `polarity` inferred from wording rather than declared.
- Putting laterality, level, side or approach into the `name` of any catalog object.
- Removing a `UNIQUE` constraint from a catalog `code`.
- Making `zdoc_encounter_time.minutes` NOT NULL. Its nullability is the fix for a data-loss path.
- Declaring a `FOREIGN KEY` against any core OpenEMR table.
- Any `ALTER` or `DROP` against a core OpenEMR table.

### 12.4 Changelog

| Version | Date | Change | Raised by |
|---|---|---|---|
| 1.0.0 | 2026-11-16 | Initial frozen contract. | WP-00a |
| 1.1.0 | 2026-11-16 | Renamed product to ZeroDoc Clinical Encounter Workflow; table prefix `aem_` → `zdoc_` throughout. | coordinator |
| 1.2.0 | 2026-11-16 | Added `zdoc_template_group` (§2.4.1) for the outer region-heading level; added `zdoc_template_element.template_group_id`. Added `zdoc_encounter_section.applied_template_ids` / `applied_template_label` for the §5.6 section heading. Added `zdoc_vocabulary_item.is_normal` and `zdoc_finding.is_normal`. Stated the normative polarity / `is_normal` authoring convention (§3.8) and the two-input colour derivation. Corrected seeded vocabulary polarities accordingly. | WP-03 via coordinator |
| 1.3.0 | 2026-11-16 | Target environment confirmed (§0.0): OpenEMR 8.5.0-dev, PHP 8.3, MariaDB, Docker. Core id columns re-typed to match core signedness (§2.1). Namespace corrected to `OpenEMR\Modules\ZdocClinicalEncounter\` and module layout fixed to the house convention (§5.1). Decided the `forms`-table relationship: one core form per encounter, none per section (§2.1.1). Verified `zdoc_` collision-free against 77 existing module tables (§2.1.2). `ROW_FORMAT=DYNAMIC` set explicitly on every table. | coordinator |
| 1.4.0 | 2026-11-16 | Conformed to EXISTING-MODULES-SURVEY.md and MODULE-CONVENTIONS.md. Added the cross-module rule (§0.0.1). Rewrote the core form relationship for `registry` + `forms` + `form_zdoc_encounter` (§2.1.1) and added that bridge table. Added `payer_class` routing to `zdoc_order_type`, `zdoc_procedure_type` and `zdoc_encounter_procedure` (§2.18.1) — closes a real spec gap. Modelled authorization as a read of the existing register, not a second one (§2.18.2). Added external delivery reference columns to `zdoc_dispatch_task`, kept independent (§2.18.3). Added the EHI export registration obligation (§2.22.1). Code identifier fixed to `zdoc`; namespace `OpenEMR\Modules\ZdocClinicalEncounter\` (§2.1.3, §5.1). | survey via coordinator |

**Current contract version: 1.4.0.** 63 tables, 885 columns, 12 vocabularies / 68 items, 6 seeded consistency rules, 22 section-policy rows.

---

## 13. Pre-flight checklist for every build agent

Before writing a line of code:

- [ ] Read your package brief in `09`, and the spec sections it names.
- [ ] Read this document end to end. Read `OPEN-QUESTIONS.md` — some of it may block you, and if it does, say so now rather than guessing.
- [ ] Find your package in §11. **Know exactly which tables you may write.**
- [ ] Find your interfaces in §4. Implement those signatures, not variations on them.
- [ ] For anything you consume that does not exist yet: **build against a mock and do not wait**, and do not write your own backend for it.
- [ ] Confirm nothing you plan touches a core OpenEMR file or table.
- [ ] Confirm no validation you plan to add appears in §9.
- [ ] Confirm the prior vendor system's product name (the one named in the standing constraint, §0.1) appears nowhere in your work — code, comments, table names, UI strings, docs or commit messages. A CI grep enforces this; make it pass locally first.
- [ ] Confirm nothing you write attributes a requirement to another vendor's product. State the requirement.
- [ ] Confirm you are working on synthetic patients only.

---

*End of CONTRACTS.md — ZeroDoc Clinical Encounter Workflow, contract version 1.4.0*
