# OPEN-QUESTIONS.md

**Product:** ZeroDoc Clinical Encounter Workflow
**Package:** WP-00a — integration contract
**Companion to:** `CONTRACTS.md` v1.4.0, `schema.sql`, `vocabularies.sql`
**Written:** 2026-11-16

---

## How to read this document

Everything below is something the specs leave ambiguous, contradict, or do not cover, where I had to **make a choice to produce a frozen contract**. Each entry states what I did and what the alternative was. **Nothing here was silently resolved.**

Three categories:

| Marker | Meaning |
|---|---|
| **DECIDED** | I made a defensible call. The contract reflects it. Reversible cheaply if the answer is no. Read and confirm. |
| **NEEDS A HUMAN DECISION** | Clinical, regulatory, commercial or practice-policy judgment that is not mine to make. **Listed in §0 by priority.** |
| **DEPENDENCY** | Depends on something I could not verify or that another agent owns. |

A **DECIDED** item is still a decision someone else may want to reverse. The cost of reversing each is stated, because that is what determines whether it can wait.

---

## 0. The items that need a human decision, in priority order

Everything else can proceed while these are open.

| # | Question | Why it cannot wait | Blocks |
|---|---|---|---|
| **Q7** | Does ZeroDoc keep its **own problem list** (`zdoc_problem`), or read/write core `lists`? | The most expensive schema decision in the set. Changing it after notes exist means migrating clinical data. | WP-04, WP-07, WP-10 |
| **Q22** | Confirm the **two-input colour derivation** (`polarity` + `is_normal`), which reinterprets 06 §5.4. | WP-20 is transcribing hundreds of options against this convention right now. Getting it wrong means re-tagging the whole library. | WP-20, WP-02, WP-03 |
| **Q11** | Who has **attestation authority**, and does an MA ever attest anything beyond vitals? | `zdoc_section_policy` ships defaults I chose. They are a compliance posture, not a technical setting. | WP-09, go-live |
| **Q13** | **E&M time→code table** (06 §7.4) is captured from a third-party screen and is **not verified against a primary source**. | Shipping unverified billing rules is a revenue and audit risk in both directions. | WP-12 |
| **Q14** | **Modifier-25 wording and policy** for the same-day E/M prompt. | It is a compliance artifact the practice owns; the provider is answering it at the point of care. | WP-12, WP-15 |
| **Q9** | `aggravated symptoms` placement in `response_to_treatment`, and whether `response_to_treatment` is really ordinal. | Cheap to change now, annoying later. Clinical judgment. | WP-20 |
| **Q18** | Do you use **half-grades** in motor strength (`4-/5`, `4+/5`)? | Adding vocabulary items after notes reference the scale is fine; changing `numeric_value` semantics is not. | WP-20 |
| **Q26** | Does the practice want **`entered_in_error`** as a user-facing state, and who may set it? | Affects whether the sign-off validator treats it as absent or present. | WP-04, WP-10 |
| **Q37** | **Prior-auth unit counting depends on a core form ZeroDoc does not write.** Patient-facing financial risk. | Staff would believe authorized units remain when they do not, and the practice delivers unauthorised services. | WP-08, WP-12 |
| **Q41** | **Two AI note generators for one encounter.** Decide precedence now, not at M9. | An existing module already puts AI notes in the same encounter's Clinical menu. Two notes, two attestations, ambiguous claim source. | WP-14, and M1 |
| **Q36** | How is `payer_class = 'either'` **resolved** to insurance or cash-pay? | Decides what signing does on a mixed encounter. Guessing is a billing error either direction. | WP-11, WP-12, WP-15 |
| **Q38** | Is the **lab-ordering module live**? | If yes, ZeroDoc must not grow a second lab path, and must consume that module's result acknowledgement or loop closure breaks. | WP-08 |
| **Q40** | **EHI export scope**: are billing, audit and internal notes in a patient's export? | ONC compliance. No ZeroDoc test surfaces an omission. | WP-11 |

---

## 1. OpenEMR integration

### Q1 — No foreign keys to core tables. **DECIDED.**

No `FOREIGN KEY` is declared against any core OpenEMR table. Core relationships are bare indexed id columns.

**Why.** The standing constraint is that the module must survive core updates and never alter core. A real FK to `form_encounter` or `patient_data` would make the module's tables participate in core's referential graph — a core upgrade that rebuilds or renames a table would fail or silently cascade into module data.

**What this costs.** Referential integrity against core is enforced at the service layer, not the database. A patient merged or an encounter deleted in core leaves orphan `zdoc_` rows. **There is no orphan-detection report in any package brief.** I have assigned it to WP-90 in `CONTRACTS.md` §2.1, but that is my assignment, not the brief's.

→ **Confirm WP-90 owns orphan detection**, or assign it.

### Q2 — Patient merge. **NEEDS A HUMAN DECISION.**

OpenEMR supports patient merge. Nothing in specs 06, 07, 08 or 10 mentions it. When two patients merge, `zdoc_problem`, `zdoc_allergy`, `zdoc_medication`, `zdoc_patient_allergy_assertion` and `zdoc_problem_thread` all carry `patient_pid` and all need re-pointing — and `zdoc_patient_allergy_assertion` is `UNIQUE` on `patient_pid`, so two NKDA assertions **collide on merge**.

A merge is also a clinical safety event here: merging a patient asserting NKDA with one carrying an active codeine allergy must trigger the §2.5 reconciliation path, not quietly pick a winner.

**Nothing is designed for this.** No package owns it.

→ **Does the practice merge patients, and how often?** If yes, this needs a package of its own. The unique constraint will fail on the first merge attempt.

### Q3 — `JSON` columns on MariaDB. **DECIDED, with a caveat.**

Columns the specs call `jsonb` are declared `JSON`. On MariaDB this is an alias for `LONGTEXT` with a `json_valid` CHECK constraint.

**Caveats that affect implementers:**
- A MariaDB JSON column **cannot be indexed directly.** Any `jsonb` field that needs querying at scale must be promoted to a real column or a generated column. The ones most likely to need it: `zdoc_claim_line.diagnosis_pointers`, `zdoc_procedure_type.default_cpt`, `zdoc_billing_task.suggested_cpt`.
- MariaDB has no `->>` operator; use `JSON_VALUE()` / `JSON_EXTRACT()`.
- JSON functions in MariaDB are not identical to MySQL's. Code written against MySQL JSON semantics may not port.

→ If billing reporting needs to query inside CPT or pointer arrays, say so and I will promote those to relational child tables before anything is built against them. **After WP-12 ships is the expensive time to discover this.**

### Q4 — Native `ENUM` versus lookup tables. **DECIDED.**

Every closed value set from the specs is a native MariaDB `ENUM`, so permitted values are self-documenting and database-enforced.

**The tradeoff.** Adding a value is an `ALTER TABLE`, which on a large table locks. The alternative — lookup tables with FKs — makes values data, but loses the self-documentation that makes a frozen contract readable and lets a typo become a silent orphan.

Mitigations already in the schema: sets genuinely expected to grow are **not** enums. `payer_class` is `VARCHAR`, `role_at_time` is `VARCHAR`, consistency rules and time-code rules are rows. `provenance` already carries `ai_generated` so WP-14 needs no `ALTER`.

→ No action unless you expect frequent enum churn.

### Q5 — Core column names and types. **RESOLVED.**

Previously an assumption; now read from `sql/database.sql` in the read-only clone. See `CONTRACTS.md` §2.1 for the mapping. Core ids are **signed**; the module's own keys are unsigned. This was corrected in contract v1.3.0 — an earlier draft had core refs as `BIGINT UNSIGNED`, which would have forced a type conversion on every join to core.

### Q6 — Authentication, ACL and session. **DEPENDENCY.**

The contract says every write method takes `int $actorUserId` explicitly rather than reading ambient session state, because import and background paths have no session. What it does **not** specify is how ZeroDoc declares its ACL objects and checks rights.

The practice's existing modules do this (`src/AclRegistrar.php`, `src/AclObject.php`, `src/Access.php` in the cash-pay module), so there is a house pattern to follow — but I have not read it in enough depth to freeze an interface against it, and getting authority checks wrong matters more here than almost anywhere, because `ma_may_attest` is a compliance control.

→ **WP-00's scaffold task must specify the ACL mechanism before WP-09 implements attestation authority.** Follow the existing modules' pattern rather than inventing one.

### Q20 — Module mechanics. **LARGELY RESOLVED.**

`MODULE-CONVENTIONS.md` now exists and this contract conforms to it. Verified and no longer open: module directory and composer declaration, namespace convention, `table.sql` / `cleanup.sql` / upgrade-file naming, `table.sql` idempotency requirement, the `registry` + `forms` + `form_<dir>` form model, `#IfNotRow` guards, `LoadEncounterFormFilterEvent` for serving form files from the module, `registry.aco_spec` for form-level ACL, and `addForm()` as the only way to write a `forms` row.

**One item remains open.** The conventions document notes that core's Module Manager looks for `sql/install.sql` when deciding whether to show an install button, and does **not** look for `table.sql` — while the `install` action does run `table.sql`. That is a difference between what the UI offers and what the action does.

→ **Confirm which file name this instance's Module Manager actually needs** so the install button appears. If it wants `sql/install.sql`, ship `table.sql` content at that path instead, or at both. Getting this wrong means the module installs from the command line but looks broken in the admin UI.

A second, smaller item: whether ZeroDoc's own 17 internal events ride core's `EventDispatcher` or a module-local one is an implementation choice left to WP-00b. Event **names** are frozen either way.

### Q24 — The core `forms` row is a handle the module does not control. **DECIDED, with one unresolved risk.**

ZeroDoc registers one core `forms` row per encounter, none per section (`CONTRACTS.md` §2.1.1).

**The unresolved risk:** core UI lets a user delete a form from an encounter, which sets `forms.deleted = 1`. ZeroDoc treats `forms.deleted` as **not** a clinical state, so a user deleting the form in core would hide the note from core navigation while `zdoc_status` still says `signed` — a signed note that has become invisible by a route the module does not mediate.

Three options:
1. **Detect and repair.** A report finds `zdoc_encounter_ext` rows whose form row is deleted, and offers re-registration. Cheap; leaves a window.
2. **Subscribe and refuse.** If the version emits a pre-delete event for forms, veto deletion of a `zerodoc_encounter` form on a signed encounter. Correct; depends on Q20.
3. **Accept it.** Document that core form deletion is not a supported operation on a ZeroDoc note.

I have specified **none** of these, because the right answer depends on whether that event exists.

→ **Decide once Q20 resolves.** Option 2 if the event exists; option 1 otherwise. This is the only place where a core action can produce a state the module considers impossible.

---

## 2. Model and schema decisions

### Q7 — `zdoc_problem` versus core `lists`. **NEEDS A HUMAN DECISION — highest priority.**

Doc 06 §10 maps problems to core `lists` with type `medical_problem` and says "add body-system grouping, structured laterality, push-to-A/P". But **adding columns to `lists` is altering a core table**, which the standing constraint forbids absolutely.

So the mapping table and the standing constraint are in direct conflict, and the spec does not resolve it.

I created `zdoc_problem`, module-owned, with an optional read-only `core_list_id` link. That satisfies the constraint. It creates a real problem the spec does not address: **the practice now potentially has two problem lists.** Every other OpenEMR feature that reads `lists` — the patient summary, CCDA export, clinical decision rules, other modules — will not see ZeroDoc problems.

The three real options:

| Option | What it means | Cost |
|---|---|---|
| **A. Module-owned, one-way sync to `lists`** | `zdoc_problem` is authoritative; ZeroDoc writes a matching `lists` row through core's own API so other features see it. | Needs a documented core write path; sync can drift. |
| **B. Core-authoritative, module-side extension** | `lists` stays the problem list; `zdoc_problem` becomes a 1:1 extension holding only body system and laterality. | Closest to the spec's intent. Means reading and writing core `lists` — permitted via core APIs, but it is core data. |
| **C. Module-only, no sync** | What the schema currently does, taken literally. | **Two divergent problem lists. I do not recommend this.** It is where the current contract sits by default, which is why this is the top item. |

My own read is that **B is what 06 §10 actually intends** — "extension of existing OpenEMR structures" is its own words — and that writing `lists` through core's API is not the same thing as altering the `lists` table. But that is an interpretation of the constraint, and the constraint was given to me as absolute, so I did not act on it.

→ **This needs answering before WP-04 starts.** It changes `zdoc_problem` from a table to an extension, and it changes `zdoc_problem_thread.problem_id`'s target.

### Q8 — Tables I created that no spec names. **DECIDED. Please review the list.**

My brief listed the tables to include. Several are referenced by other tables as foreign-key targets but are never themselves specified. I created them rather than leave dangling references:

| Table | Why it exists | Risk |
|---|---|---|
| `zdoc_problem` | `aem_problem_thread.problem_id fk → problem` (10 §13.2.1) needs a target | See Q7 |
| `zdoc_order` | `dispatch_task.order_id` and "plan option creates an order" need an order instance | Low — 06 §4.4 specifies the field set in detail |
| `zdoc_encounter_diagnosis` | The A/P rows. 07 §11.2.4 says "applying a set creates one A/P diagnosis row per item" but never defines the row | Low — but it is a central table defined by inference |
| `zdoc_encounter_plan_selection` | Records which plan options were ticked and what their four effects created | Low |
| `zdoc_application` | `undoApplication(applicationId)` implies an application ledger that nothing defines | Low — required by the signature |
| `zdoc_consent_type`, `zdoc_encounter_consent` | `plan_option.queues_consent_id fk → ?` has no stated target | Low |
| `zdoc_education_item`, `zdoc_encounter_education` | 06 §4.6 requires a separate education queue but defines no tables | Low |
| `zdoc_receiver` | 06 §4.5 requires a directory but defines no table | Low |
| `zdoc_payer_auth_rule` | `order_type.requires_auth_rule_id fk → payer_auth_rule` | Low |
| `zdoc_medication`, `zdoc_external_source` | 06 §2.6 requires both behaviours but defines no tables | Low |
| `zdoc_em_time_code_rule` | 06 §7.4 says "implement as a versioned, date-effective lookup table" | Low |
| `zdoc_bundling_edit` | 10 §13.6.2 says "maintain them as data with an effective date" | Low |
| `zdoc_revenue_cycle_task` | 06 §7.5 requires tasks but defines no table | Low |
| `zdoc_audit_event` | Audit trail is required repeatedly; no table is ever defined | Low |
| `zdoc_usage_stat` | "Ranked by the provider's own usage" needs somewhere to count | Low |
| `zdoc_warning_dismissal` | "Dismissible with a reason" needs somewhere to put the reason | Low |
| `zdoc_section_flag` | 06 §2.7's advisory flags | Low |
| `zdoc_consistency_violation` | Rule results, so sign-off and the test harness read one source | Low |
| `zdoc_billing_code_revision` | Test 48 requires the original suggestion retained | Low |
| `zdoc_template_group` | The §5.6 region-heading level. See Q21 | Low |
| `zdoc_schema_version` | Installer bookkeeping | None |

`zdoc_problem_thread.problem_id` is **nullable**, so a thread can open before its problem is coded (the carpal-tunnel case in 10 §13.2.2 opens a blank thread with no code yet). The spec shows it as a non-null FK. I believe nullable is right; flag it if not.

### Q10 — `reason` and `chief_complaint` are both section keys. **DECIDED.**

06 §1.2 lists a `reason` section. 08 §12.1.1 adds `chief_complaint` as "a structured section rather than free text". `zdoc_encounter_ext` **also** has a `chief_complaint` text column from 06 §1.1, and a `reason_for_visit_id`.

So chief complaint potentially lives in three places. I kept all three with a defined relationship:
- `zdoc_encounter_ext.reason_for_visit_id` — the structured selection that triggers an encounter plan.
- `zdoc_encounter_ext.chief_complaint` — the denormalised display string for the note header (`CHIEF COMPLAINT / low back pain`).
- `section_key = 'chief_complaint'` — the structured, templatable section from 08 §12.1.1, holding findings.
- `section_key = 'reason'` — retained from 06 §1.2 for enum compatibility. **I believe it is vestigial and 08 supersedes it.**

→ **Confirm `reason` is vestigial.** If so it should be removed from the enum before anything populates it. If not, say what distinguishes it from `chief_complaint`.

### Q12 — Polymorphic references have no foreign keys. **DECIDED.**

`zdoc_encounter_plan_item` and `zdoc_order_set_item` use `item_type` + `reference_id`, which cannot carry an FK. Integrity is the service layer's, and a retired or deleted target leaves a dangling reference that only surfaces when the plan or set is applied.

Mitigation: WP-06's importer validates every reference at import, and `previewApplication` surfaces unresolvable items before commit rather than at apply time. Retirement is `is_active = 0`, never deletion, which makes the common case safe.

→ Accept, or ask for separate typed child tables per item type (more tables, full integrity).

### Q21 — Grouping as a table rather than a column. **DECIDED — this was the coordinator's "your call".**

`zdoc_template_group` is a table; `zdoc_template_element.template_group_id` is a nullable FK. Rationale is in `CONTRACTS.md` §2.4.1: a group needs a sort order independent of element order, denormalised columns cannot be constrained and let one group acquire two spellings, and a group is a real entity in the rendered note with its own heading and potentially its own render mode.

**Cost if reversed:** low while no content exists. Three columns on `zdoc_template_element` replace the table, and WP-03 renames one DTO.

**One thing the specs do not settle:** whether groups can **nest** more than one level. The reference output is exactly two levels (region → element). I allowed **one** level only — `zdoc_template_group` has no parent pointer. If a template ever needs `Lower Extremity → Knee → Inspection`, this needs a self-referencing parent and a depth cap.

→ **Does any template need three levels of nesting?** If yes, say so now; adding it later is a migration.

### Q25 — Section-level versus finding-level ordering is declared but not configured. **DECIDED, incompletely.**

06 §5.5 says rendering order is `template_option.sort_order` "but pick one and never silently reorder" — allowing `finding.sort_order` as an alternative. Both columns exist. **Nothing in the contract says which is in force, or where that choice is stored.**

I left it as a renderer-level convention (`CONTRACTS.md` §4.3: one or the other, declared, never mixed) rather than adding a column, because the spec does not say the choice is per-section and adding a column implies it is.

→ Is template order the single global rule, or does any section want entry order? If the latter, `zdoc_encounter_section` needs an `ordering_basis` column, and it should be added **before** WP-03 is tested against the byte-exact reference fixture.

### Q26 — `entered_in_error` as a status. **NEEDS A HUMAN DECISION.**

`zdoc_allergy.status`, `zdoc_medication.status` and `zdoc_problem.status` all carry `entered_in_error`, taken from 06 §2.5. The specs never say what it means operationally.

Specifically: the NKDA hard stop blocks signing when an allergy is `active`. An `entered_in_error` allergy is **not** active, so it does not block — correct. But nothing says **who may set it**, or whether an allergy that arrived from an external feed can be marked entered-in-error by anyone, which would be a way to bypass the safety control the whole design is built around.

→ **Who may set `entered_in_error`, and should doing so on an external-feed allergy require a reason and a second signature?** This is the one place where a permission actually matters, in a design that otherwise deliberately has none.

---

## 3. Clinical content and vocabularies

### Q22 — Polarity, `is_normal`, and the colour rule. **DECIDED — needs sign-off. Second priority.**

The coordinator supplied the normative rule and I adopted it: a normal **descriptor** is `neutral`; an asserted **absence** is `negative`. `CONTRACTS.md` §3.8 states it with the authoring checklist.

**What I had to add to make it consistent, and what needs confirming:**

06 §5.4 asks for "positive findings **red**, negative/normal **green**, neutral descriptors **blue/black**". That groups "negative" and "normal" together. But under the structural rule, normal descriptors are `neutral` — so colour can no longer be read off `polarity` alone without turning every normal descriptor blue/black, which contradicts §5.4.

I resolved it by deriving colour from **two** inputs, `polarity` and `is_normal`, and added `is_normal` to `zdoc_vocabulary_item` and `zdoc_finding` to carry it. Table in `CONTRACTS.md` §3.8.

**This is a reinterpretation of §5.4, not an implementation of it.** It is the only way I can see to satisfy both §5.4's colour intent and the renderer's structural need, but it is my inference.

**Consequent changes to the seeded vocabularies** — these reverse what I originally wrote, and are the reason this is urgent:

| Item | Was | Now | Reason |
|---|---|---|---|
| `sensation.normal` | `negative` | `neutral`, `is_normal = 1` | normal descriptor |
| `motor_grade.5_5` | `negative` | `neutral`, `is_normal = 1` | normal descriptor; must not render as "no 5/5" |
| `reflex_grade.2plus` | `negative` | `neutral`, `is_normal = 1` | normal descriptor |
| `response_to_treatment.*` (all 5) | mixed | all `neutral` | descriptor answers, see Q9 |
| `frequency.*` (all 3) | `positive` | all `neutral` | descriptors of a symptom asserted elsewhere |
| `severity_scale.0` | `negative` | `negative`, `is_normal = 1` | genuinely an asserted absence — unchanged, flag added |
| `special_test_result.negative` | `negative` | `negative`, `is_normal = 1` | genuinely an asserted absence — unchanged, flag added |

→ **Confirm the colour derivation and these seven re-tagging decisions.** WP-20 is applying this convention to the whole library now; re-tagging later is expensive and will not be caught by any validator, because both values are structurally legal.

### Q9 — `response_to_treatment` ordering and value kind. **NEEDS A HUMAN DECISION.**

06 Appendix B lists: `did not help | helped a little | helped temporarily | helped significantly | aggravated symptoms`.

I made two judgment calls:
1. **Re-ordered** so `aggravated symptoms` sorts **first** (worst), on the clinical reasoning that being made worse is worse than no effect. The spec's own order puts it last. **Mine is a clinical judgment and may be wrong for your reporting.**
2. Declared the vocabulary `ordinal` with `numeric_value` −1 through 3, which makes "did the treatment help" a sortable, reportable scale.

But (2) is questionable: `aggravated symptoms` is arguably a **different axis** from the help scale — harm, not absence of benefit — in which case the vocabulary is nominal with one outlier and `numeric_value` is misleading.

→ **Keep the spec's order or mine? And is this genuinely ordinal?** Low cost to change now.

### Q16 — `severity_scale` materialised as 11 rows. **DECIDED.**

06 Appendix B gives `severity_scale : 0..10 integer`. A vocabulary needs items, so I materialised eleven, labelled `0/10` … `10/10`, with `numeric_value` carrying the integer.

Two consequences to be aware of:
- Severity is **also** capturable through `zdoc_template_option.value_slots` (`[{"type":"numeric","max":10,"suffix":"/10"}]`) and lands in `zdoc_finding.value_numeric`. **There are two routes to a pain score.** Both are legitimate — a chip picker and a numeric input — but the renderer and any reporting must treat them as one concept.
- `zdoc_vital_reading.pain_score` is a **third** place a 0–10 pain value lives, per 06 §2.4's "pain score belongs here, not only in HPI".

→ Is the vitals pain score the same datum as an HPI severity finding, or two different observations? The specs never say. If they are the same, something must reconcile them, and nothing currently does.

### Q17 — `reflex_grade` polarity. **DECIDED.**

`2+` is `neutral` + `is_normal = 1`. All other grades are `positive`. `absent` is `positive` despite the word "absent", because it asserts a finding (areflexia) rather than denying one — exactly the distinction §3.8 draws, and a good illustration of why polarity cannot be inferred from wording.

→ Confirm `absent` reflexes should be `positive`. I am confident, but it reads counterintuitively and a transcriber will question it.

### Q18 — Motor strength half-grades. **NEEDS A HUMAN DECISION.**

06 Appendix B gives `motor_grade : 0/5 .. 5/5`. I seeded six items. Clinical practice commonly uses `4-/5`, `4/5`, `4+/5`, and the reference output uses `5/5`.

Adding items later is safe. What is **not** safe later is `numeric_value`: if half-grades arrive, `4+/5` needs `4.5` or the integers need re-spacing, and anything that has reported on the integers changes meaning.

→ **Do you use half-grades?** If yes, I will seed them now with decimal `numeric_value`. `zdoc_vocabulary_item.numeric_value` is already `DECIMAL(10,3)`, so no schema change — just seed data.

### Q19 — `cervical_levels` includes C8. **DECIDED.**

06 Appendix B says `C2..C8`. I seeded seven items including C8. **C8 is a nerve root; there is no C8 vertebra.** For a sensory or motor level that is correct. For a structural finding — facet joints, spinous process tenderness, an injection level — it is wrong, and the lumbar vocabulary has the same issue inverted (`T12` is a vertebra, `S1`/`S2` are commonly used as roots).

I did not split them, because the spec defines one vocabulary per region and splitting changes the contract shape.

→ **Should levels split into `*_vertebral_levels` and `*_nerve_roots`?** This matters most for procedures: an injection at a vertebral level and a radiculopathy at a nerve root are different data, and §13's procedure parameters will carry both. Cheap now, a migration after procedure types are entered.

### Q23 — The specs' reference template is a defect-correction exercise. **DEPENDENCY on WP-20.**

06 §3.2 gives a 19-element reference template with corrections listed (mirror all laterality, fix spelling, share response vocabularies, split opioid-monitoring fields into a separate compliance template). The schema supports all four.

But §3.2 also instructs that elements 13 and 14 "move to a separate COT/compliance template that can be attached independently" — and **no spec defines that template.** It is named but never specified.

→ WP-20 needs to know whether the COT/compliance template is in scope for content capture, and what is in it. It is not in the §7 capture batch table in `09`.

### Q27 — Lumbar levels used as ranges. **DECIDED, with a gap.**

The reference output renders `Motor Strength: L1, L2–L4, L5, S1` and `Sensation T12–S2`. These are **ranges and groupings**, not single levels. `zdoc_finding.anatomic_level` is `VARCHAR(16)`, which holds `L2-L4` as a string, and `numeric_value` on the vocabulary makes ranges computable.

But nothing specifies how a range is **stored**: one finding with `anatomic_level = 'L2-L4'`, or three findings at L2, L3, L4 that the renderer collapses into a range?

The second is structurally better — it makes "is L3 affected" answerable — and the renderer already does stem collapsing, so range collapsing is the same class of operation. But it means a range selection creates multiple findings, which changes attestation counts.

→ **Decide before WP-03's byte-exact fixture is written**, because the fixture's finding set depends on the answer.

---

## 4. Workflow, regulatory, and billing

### Q11 — Section policy defaults are a compliance posture. **NEEDS A HUMAN DECISION — third priority.**

`schema.sql` §23 ships 22 `zdoc_section_policy` rows. 07 §11.3 specifies the shape and §11.1 the intent; I chose the actual values. Four are judgment calls the spec does not make:

| Section | What I shipped | Why it is a judgment |
|---|---|---|
| `procedures` | `requires_provider_attestation = 1` | 07 §11.1's table does not list procedures. 10 §13 makes procedure documentation central, and a procedure is the clearest case of personal performance, so I required attestation. **The spec does not say to.** |
| `imaging` | review required, attestation not | 08 §12.1.1 adds the section; no spec assigns it a policy. Imaging findings are interpretations of someone else's study, so review seemed right — but a provider reading their own fluoroscopy is performing, not reviewing. |
| `vitals` | `ma_may_attest = 1` | 07 §11.3 says "`ma_may_attest = false` everywhere except vitals". Implemented literally. |
| `time_spent` | `ma_may_attest = 1` | Not mentioned. 07 §11.8 says staff record their own time, which implies they may attest it. Inferred. |
| `patient_instructions`, `discussion_notes`, `follow_up` | `on_violation = 'warn'` | Not mentioned. I chose `warn` rather than `block` because blocking a signature over a missing follow-up interval contradicts the gateless thesis. **A judgment about what should stop a doctor signing.** |

07 §11.12 is explicit that NC medical assistant scope of practice, the malpractice carrier's position on MA-placed exam scaffolds, and payer contract language on ancillary documentation are **practice-policy questions to verify before go-live**. These defaults do not substitute for that.

→ **Review all 22 rows before go-live.** They are config, so changing them is a row update — but they are also the compliance posture of the practice, and the five above are mine, not the spec's.

### Q13 — The E&M time→code table is unverified. **NEEDS A HUMAN DECISION — fourth priority.**

06 §7.4 gives a time→code table "captured from" a third-party system, with a Medicare/non-Medicare divergence above 55 minutes, `99417` for non-Medicare and `G2212` for Medicare.

**I did not seed it.** `zdoc_em_time_code_rule` is created and empty.

**Why I left it empty.** It is billing rules transcribed from a vendor's screen, with no primary-source citation, and the specs' own closing note says to verify the practice's fee schedule and payer requirements before go-live. Seeding unverified billing thresholds into a system that will suggest codes to a biller is the kind of thing that is correct 90% of the time and expensive the rest. The table carries `source_citation` and `effective_from` columns precisely so each row can be traced.

→ **Someone must populate this from a primary source** (the current CPT time ranges and the relevant payer policies), with citations and effective dates, before WP-12 can suggest anything. This is not a transcription task; it needs someone who can read the source documents.

### Q14 — Modifier-25 wording and the same-day determination. **NEEDS A HUMAN DECISION — fifth priority.**

10 §13.6.1 requires the modifier-25 question be raised **to the provider at signing**, and the answer attached to the billing task. The schema does this (`zdoc_encounter_ext.modifier_25_*`, carried to `zdoc_billing_task`).

What no spec provides is **the question's wording**. "Was the E/M significant and separately identifiable from the procedure?" is 10 §13.6.1's paraphrase, not approved language. Like the attestation wording in 07 §11.7, this is a compliance artifact the practice owns, and unlike attestation wording I did **not** make it configurable — there is no `modifier_25_prompt_text` column.

→ **Should the modifier-25 prompt be practice-configurable like attestation wording?** I think yes, for the same reasons 07 §11.7 gives, and if so it needs a column. Raise it now rather than after WP-12.

Related and unaddressed: nothing specifies what happens when the provider answers **no**. Does the E/M line simply not generate? Does it generate unbilled? Does the biller see the negative answer as evidence? I would expect the last, but nothing says.

### Q28 — Amendment creates a new encounter. **DECIDED.**

06 §6.5 requires a signed note be immutable and amendment create "a linked addendum with its own signature". I implemented an addendum as a **new encounter** with `zdoc_encounter_ext.amends_encounter_id` pointing at the original, and `SignOffService::amend()` returns the new encounter id.

**The alternative** was an addendum table within the same encounter. I chose a new encounter because it reuses the entire section, finding, attestation and rendering machinery for free, and because an addendum needs its own provenance and attestation chain — which is exactly what an encounter provides.

**What this costs, and it is not trivial:**
- It creates a **core** encounter, which means an extra visit on the patient's chart and potentially in core billing. 06 §7 says the claim is a projection of the signed note; it does not say whether an amendment projects a second claim. **I have assumed it does not** — `zdoc_claim` is `UNIQUE` on `encounter_id`, so the addendum would get its own claim row, which is probably wrong.
- `aem_status = 'amended'` on 06 §6.1's state diagram is a state of the **original**. My implementation sets the original to `amended` and the addendum to `signed`. The diagram shows `SIGNED → AMENDED` as a transition of one object, which is consistent — but it does not describe a second object.

→ **Confirm an amendment should not project its own claim**, and confirm creating a core encounter for an addendum is acceptable. If not, this becomes an addendum table and `amend()` changes shape. **This is the most structurally consequential DECIDED item in the document.**

### Q29 — "Signing authority" and co-sign are referenced but never defined. **DEPENDENCY.**

06 §6.3 item 7: "Signer has signing authority; co-sign requirement satisfied if the attributed provider requires supervision." 07 §11.6 item 11 repeats it.

Neither defines where signing authority or a supervision requirement is **stored**. I put them on `UserContext` in the context accessor (`CONTRACTS.md` §4.1) as read-only properties, and `zdoc_encounter_ext` carries `cosign_required` / `cosigned_by` / `cosigned_at`.

But **what sets `cosign_required`?** It must derive from the attributed provider's supervision status, which lives in core user configuration I have not verified exists in a usable form.

→ **Where does supervision status live in this OpenEMR instance?** If nowhere, ZeroDoc needs its own provider-configuration table, which is not in any brief. Related to Q6.

### Q30 — Dismissed warnings and re-evaluation. **DECIDED.**

A warning dismissed with a reason is recorded in `zdoc_warning_dismissal` and `zdoc_consistency_violation.status = 'dismissed'`, and `ValidationResult::blocksSigning()` excludes dismissed warnings.

Unspecified: **does editing the content that caused the warning re-raise it?** I made `evaluate_on_change` re-evaluate and create a **new** violation row, leaving the old dismissal intact — so a dismissal applies to the state it was made in, not forever. I believe that is right; silently honouring a stale dismissal across edits is how a warning becomes decorative.

→ Confirm. It means a provider may dismiss the same warning twice in one encounter if they edit in between, which will be perceived as a bug if it is not intended.

---

## 5. Process and scope observations

### Q31 — WP-00's own acceptance criterion cannot be met by this deliverable. **FLAG.**

`09` §2 makes WP-00's acceptance "module installs on a clean instance; survives a core update with data intact". That is the stated gate for **everything else starting**.

This package (WP-00a) produced only the contract: `schema.sql`, `vocabularies.sql`, `CONTRACTS.md`, this file. **The scaffold, the install, and the update-survival proof are not done**, and the brief for this package explicitly says not to implement features.

→ The update-survival gate is still open. Whoever owns WP-00b should run it **before** Wave 1 agents commit code against this schema, because a schema that cannot be installed through the module mechanism is a schema that has to change.

### Q32 — Acceptance test coverage has gaps in the contract. **FLAG.**

52 acceptance tests exist (20 in `06` §9, 20 in `07` §11.11, 12 in `10` §13.10). Mapping them against the package briefs in `09`, several have no owner:

- **Test 3** (sign with insurance unverified → front-office task created, claim stays draft) spans WP-11 and WP-12. `09` assigns it to WP-11, which does not own `zdoc_claim`.
- **Tests 41–43** (thread carry-forward behaviour) are in `10` §13.10, which postdates `09`. `10` §13.9 amends WP-09's brief to cover threads, but the tests are not assigned.
- **Tests 44–52** (procedures and billing round-trip) belong to WP-15 and WP-12. WP-15 is created by `10` §13.9 and is not in `09`'s dependency graph or wave plan.
- **Test 49** (one template serves all levels and sides) tests `zdoc_procedure_type.parameters`, which needs WP-01's template engine and WP-15 together.

→ WP-90 should reconcile the test list against the amended package set before Wave 2. **WP-15 in particular has no position in the wave plan**, only a note in `10` §13.9 that it is "Wave 2, parallel with WP-07/08".

### Q33 — WP-12's scope is flagged for splitting and has not been split. **FLAG.**

`10` §13.9 says of WP-12: "Scope grows substantially: billing tasks, pointers, modifier prompts, prior-encounter picker, denial round-trip. **Consider splitting.**"

I have written WP-12's contract as three interfaces (`BillingProjectionService`, `EmCalculatorService`, `BillingTaskService`) owning six tables, which is a natural seam if you want to split it — projection and E&M calculation on one side, the biller's worklist and round-trip on the other.

→ Decide whether WP-12 splits. The contract supports either without change; the ownership map in `CONTRACTS.md` §11 would need the tables reassigned.

### Q34 — Two unresolved decisions from `08` §B.5 affect contracts. **FLAG.**

`08` §B.5 lists five open decisions. Two have contract consequences:

- **Whole-note carry-forward** — deferred per 08 §12.2. `CarryForwardService` is deliberately per-section/per-thread only. If this is un-deferred, the service gains a method; no schema change.
- **AI inference location (local vs vendor)** — "must be decided". This has a **schema** consequence nobody has raised: if inference is a vendor, PHI leaves the building, and nothing in the schema records **what was sent, to whom, and when**. There is no AI-request audit table. `zdoc_audit_event` would capture that a draft was applied, not what was transmitted.

→ If vendor inference is chosen, an AI-disclosure audit table is needed and is **not** in any brief. Raise it before WP-14 starts.

### Q35 — The specs name a parallel system that must not be assumed to persist. **FLAG.**

`08` §B.5 includes "keep the existing AI Scribe form during transition — parallel, retire after M6". The clone confirms `oe-module-elegant-ai-scribe` exists and owns tables.

Nothing in this contract touches it, and nothing should. But `CONTRACTS.md` §2.1.2's collision check is a point-in-time check against the modules present on one branch. If the AI scribe module is retired, or if another module is added, the check needs re-running.

→ Re-run the collision check against `EXISTING-MODULES-SURVEY.md` when it lands, and before each install.

---

## 5a. Cross-module integration

All five come from `EXISTING-MODULES-SURVEY.md`. The contract accommodates each; none is fully resolved by a schema change alone.

### Q36 — How is `payer_class = 'either'` resolved? **NEEDS A HUMAN DECISION.**

I added the payer-class dimension to `zdoc_order_type`, `zdoc_procedure_type` and `zdoc_encounter_procedure` (`CONTRACTS.md` §2.18.1), which closes the gap where a cash procedure would project an insurance claim line. `insurance` and `cash_pay` route unambiguously.

**`either` does not.** It is there because some procedures genuinely go both ways depending on the patient's coverage, and forcing a configuration-time choice would be wrong. But nothing specifies how it resolves at signing. I made it raise a revenue-cycle task and hold **both** paths rather than guess, because a wrong guess is a billing error in one direction and lost revenue in the other.

Open sub-questions:
1. **Who resolves it** — the provider at the point of care, the biller afterwards, or an automatic read of the patient's active coverage?
2. **Can one encounter carry both** an insurance claim line and a cash-pay quote? I believe yes — an injection billed to insurance at a visit where the patient also buys a cash-pay product. The schema allows it. Nothing confirms it should.
3. **Does a cash-pay procedure still generate a billing task?** Currently `zdoc_billing_task` is created for `procedure_coding` regardless. For a cash-pay item there is nothing for a biller to code, so it is probably noise.

→ **Decide (1) before WP-11 implements signing.** (2) and (3) can follow.

### Q37 — Prior-auth unit counting has an unmet prerequisite. **NEEDS A HUMAN DECISION — financial risk.**

ZeroDoc reads the existing authorization register rather than duplicating it (`CONTRACTS.md` §2.18.2). But that module's unit counting works by summing core `billing.units` for encounters that have a **Misc Billing Options form with `prior_auth_number` populated**.

**ZeroDoc's claim projection writes `zdoc_claim` and `zdoc_claim_line`. It does not write that core form.**

Consequence, spelled out because it is not cosmetic: every authorization reports **zero units used**, staff see full units remaining, and the practice delivers services it is not authorised for. This is a patient-facing financial exposure, and it appears the moment ZeroDoc starts projecting claims for a patient with an authorization on file.

Three options:
1. **ZeroDoc populates `form_misc_billing_options.prior_auth_number`** when projecting a claim against an authorized order. Makes the existing counter correct. Means writing a core form — permitted through core APIs, but it is core data and it is scope nobody has assigned.
2. **ZeroDoc computes units used from its own `zdoc_claim_line`** and reports that alongside the external register's number, showing both and flagging a divergence. Self-contained; leaves the other module wrong for anyone reading it directly.
3. **Display authorization units as unreliable** wherever shown, and do not act on them.

→ **This needs a decision and an owner.** It is currently in no package brief. My recommendation is option 1 plus option 2's divergence check, but it is scope, not a detail, and option 1 touches core data.

### Q38 — Is the lab-ordering module live? **NEEDS A HUMAN DECISION.**

An existing module owns lab ordering with its own orderable-item compendium, per-lab routing and result acknowledgement.

If it is live and ZeroDoc grows its own lab orders, the result: two orderable catalogs, results landing in the other module's acknowledgement flow, and `zdoc_dispatch_task` staying open forever for the order type most likely to be used — loop closure broken exactly where it matters.

The contract does not currently model this. `zdoc_order_type.default_destination` has no `lab_network` value and `default_method` has no handoff to that module.

→ **Is it in use?** If yes, `zdoc_order_type` needs that destination and method, and WP-08 must consume the other module's result acknowledgement to close the loop. That is a change request, not a detail — raise it before WP-08 starts.

### Q39 — Fax delivery confirmation is weaker than the spec assumes. **DECIDED, honestly, but it narrows a feature.**

08 §12.4 requires that "a failed fax turns the task red". That presumes a reliable delivery status source. The survey found the existing fax module's queue is populated reliably on only one vendor path; another bypasses the queue entirely, and rows can be soft-deleted beneath a live task.

So "red on failure" is not implementable as stated for every vendor. I added `external_status_reliable` and made the contract say that **a task whose delivery cannot be confirmed must not display as delivered** (`CONTRACTS.md` §2.18.3) — three states, not two, because a false green is worse than an honest unknown.

→ Two ways to actually get the feature as specified: **pin the vendor** to one whose path reports status, or **poll the vendor API directly** from ZeroDoc's adapter rather than reading the shared queue. The second is real work and is not in WP-08's brief. `08` §B.5 lists "fax provider" as an undecided item with no default — **this is why it matters.**

### Q40 — EHI export scope. **NEEDS A HUMAN DECISION.**

`CONTRACTS.md` §2.22.1 lists the `zdoc_` tables carrying patient clinical data that must be registered with the EHI exporter, and makes it a WP-11 deliverable tied to signing. Without it, signed notes are silently absent from patient EHI exports — an ONC compliance failure no ZeroDoc test will catch.

What I classified confidently: the clinical tables are in, the library and configuration tables are out.

**Three genuine judgment calls I did not make:**
1. **Billing tables** — `zdoc_claim`, `zdoc_claim_line`, `zdoc_billing_task`. Encounter-scoped patient data, but billing rather than clinical.
2. **Audit and evidence tables** — `zdoc_audit_event`, `zdoc_consistency_violation`, `zdoc_warning_dismissal`, `zdoc_section_flag`, `zdoc_billing_code_revision`. Encounter-scoped and arguably part of the record.
3. **Internal notes.** `zdoc_encounter_section.note_visibility = 'internal'` content is excluded from release-of-information renderings per 06 §6.4. **Is an EHI export an ROI rendering for that purpose?** If yes, the export must filter by `note_visibility` and log the exclusion. If no, internal notes go to the patient. This is a policy question with real consequences and **no technical default is safe** — the exporter will do whatever it is told, including the wrong thing, silently.

→ **(3) in particular needs a human answer** before WP-11 registers anything.

### Q41 — Two AI note generators in one encounter. **NEEDS A HUMAN DECISION — decide before M1, not M9.**

An existing module already places AI-generated notes in the encounter's Clinical forms menu. ZeroDoc's WP-14 adds a second AI path to the **same encounter**.

Nothing prevents a clinician producing both, with contradictory content, two attestation chains, and an ambiguous source for the claim. That is a clinical-record integrity problem, not a UI annoyance.

`08` §B.5 lists "keep the existing AI scribe form during transition — parallel, retire after M6" as an open decision with a default of "parallel". **The survey's judgment, which I share, is that parallel is the risky option** and the decision should be made now rather than at M9, because it determines whether ZeroDoc's AI layer is a second note generator or a dictation front-end that writes findings into ZeroDoc and never creates its own form.

→ **Decide the precedence rule now.** The schema supports either; the difference is whether `provenance = 'ai_generated'` findings can arrive from outside ZeroDoc, which changes WP-14's boundary and WP-09's attestation counting.

Two smaller items from the same survey, recorded so they are not rediscovered:
- **Naming collision in the UI.** The cash-pay module already ships a page called "the front-desk worklist", gated on a billing ACL. ZeroDoc's dispatch queue is a different thing for a different audience. **Name ZeroDoc's queue distinctly** — "Order Queue" — and consider cross-linking counts.
- **Patient-menu contention.** One existing module ships a *complete replacement* patient menu as JSON while another adds items via the menu event. The JSON replacement is the kind of thing that silently drops event-added items. **ZeroDoc must use the event**, and someone should verify the other module's items still appear — **this may already be broken today, independent of ZeroDoc.**

---

## 6. Things I deliberately did not do

Stated so nobody assumes they are done, or that they were overlooked.

| Not done | Why |
|---|---|
| Seeded `zdoc_em_time_code_rule` | Q13 — unverified billing rules |
| Seeded `zdoc_bundling_edit` | Same reason; NCCI edits need a primary source with effective dates |
| Seeded `zdoc_procedure_type.default_cpt` | 10 §13.3.2 is explicit: CPT and modifier logic come from **the practice's own fee schedule** during capture, from no other source |
| Seeded any template, saved finding, diagnosis set, order set or plan group | WP-20's content-capture track. The specs' §3.2 reference template is an example, not seed data |
| Defined WP-06's import format | WP-06 owns it. `CONTRACTS.md` §4.6 names the file (`IMPORT-FORMAT.md`) and binds it to the same change protocol |
| Specified the `when_jsonb` / `then_conflicts_jsonb` expression grammar | Q15 below. The six seeded rules carry **illustrative shapes**, not a specification |
| Implemented anything | The brief: define the contract, do not build against it |

### Q15 — The consistency rule expression language is not frozen. **DEPENDENCY on WP-10.**

06 §3.7 gives `when_jsonb` and `then_conflicts_jsonb` with one worked example each. That is not enough to specify a grammar, and the six seed rules I wrote need five different shapes to express them: option matching, assertion equality, existence over another table, absence-of-support across sections, a per-row iteration, and an event hook.

`CONTRACTS.md` §2.23 says explicitly that this grammar is **not frozen** and that WP-10 owns the resolver and proposes the grammar back to the integration owner.

→ **WP-10's first deliverable should be the grammar, as a change request, before it implements the engine.** The seeded rows will need rewriting to whatever it defines. They are seeded with `rule_key` values that the acceptance tests can reference, so the keys are stable even when the payloads change — that is the part worth preserving.

---

*End of OPEN-QUESTIONS.md — 41 items: 14 needing a human decision, 15 decided-and-reversible, 5 dependencies, 7 process flags.*
