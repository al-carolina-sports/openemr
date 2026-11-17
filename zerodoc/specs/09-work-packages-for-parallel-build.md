# Work Packages — Parallel Build Map

**Project:** Annie's Elegant Modules — Clinical Documentation Module
**Written:** 2026-11-15, 23:30 ET
**Companion to:** `08 - build-plan-v1.md`
**Specs:** `06 - gateless-encounter-documentation-spec.md`,
`07 - ma-authored-notes-and-diagnosis-sets.md`, `08` Part A (§12)

**Purpose:** divide the build into packages that independent AI agents can
execute in parallel, with interfaces tight enough that the pieces fit together
on first assembly.

---

## 0. How to use this document

Each package below is a **self-contained brief**. Hand an agent:

1. The three spec documents (06, 07, 08)
2. `CONTRACTS.md` produced by WP-00 — **non-negotiable**
3. Its own package section from this file

An agent should need nothing else and should talk to no other agent.

### 0.1 The rule that makes parallelism work

> **No agent may alter anything in `CONTRACTS.md`.** Table names, column names,
> types, function signatures, route paths, event names and asset conventions are
> frozen. An agent that believes a contract is wrong **stops and raises it to
> the integration owner** rather than changing it locally.

Two agents independently "improving" a shared table is the single failure mode
that turns parallel work into a rewrite. Every brief below repeats this.

### 0.2 Roles

| Role | Who | Responsibility |
|---|---|---|
| **Integration owner** | one agent or human, continuous | Owns `CONTRACTS.md`, merges branches, adjudicates contract change requests, runs the full test suite |
| **Build agents** | one per package | Execute one package to its acceptance criteria |
| **Content agents** | parallel track | Transcribe the athena library (WP-20) |
| **QA agent** | continuous from Wave 1 | Owns the acceptance test harness (WP-90) |

### 0.3 Conventions all agents follow

- **No OpenEMR core file is edited, ever.** Everything lives in the module.
- **Table prefix `aem_`** on every table this module creates.
- **The name "PatientNow" appears nowhere** — not in code, comments, table
  names, UI strings, docs or commit messages.
- **No real patient data.** Synthetic patients only.
- Each package works on its own branch, named for the package.
- Every package ships with its own tests; the QA agent owns the cross-package
  acceptance suite.

---

## 1. Dependency graph

```
                        ┌──────────────────────┐
                        │  WP-00  CONTRACTS    │   SERIAL — blocks everything
                        │  + module scaffold   │
                        └──────────┬───────────┘
                                   │
   ┌───────────┬───────────┬───────┴────┬────────────┬────────────┐
   │           │           │            │            │            │
┌──▼───┐   ┌───▼──┐    ┌───▼───┐    ┌───▼───┐    ┌───▼───┐   ┌───▼───┐
│WP-01 │   │WP-02 │    │WP-03  │    │WP-04  │    │WP-05  │   │WP-06  │  WAVE 1
│tmpl  │   │panel │    │render │    │intake │    │macros │   │lib    │
│engine│   │  UI  │    │  er   │    │sects  │    │       │   │tools  │
└──┬───┘   └───┬──┘    └───┬───┘    └───┬───┘    └───────┘   └───┬───┘
   │           │           │            │                        │
   └─────┬─────┴───────────┘            │                   ┌────▼────┐
         │                              │                   │ WP-20   │
   ┌─────▼──────┬──────────────┐        │                   │ content │ PARALLEL
   │            │              │        │                   │ capture │  TRACK
┌──▼───┐   ┌────▼───┐    ┌─────▼──┐     │                   └─────────┘
│WP-07 │   │ WP-08  │    │ WP-09  │     │                              WAVE 2
│ A/P  │   │ orders │    │provnce │     │
│ tree │   │dispatch│    │attest  │     │
└──┬───┘   └────┬───┘    └────┬───┘     │
   │            │             │         │
   └────────────┴──────┬──────┴─────────┘
                       │
                 ┌─────▼─────┐
                 │  WP-10    │                                        WAVE 3
                 │ validation│
                 │ rules     │
                 └─────┬─────┘
                       │
                 ┌─────▼─────┐
                 │  WP-11    │   ← GATE: safe for real patients
                 │  signing  │
                 └─────┬─────┘
                       │
         ┌─────────────┼─────────────┐
    ┌────▼───┐   ┌─────▼────┐   ┌────▼───┐                            WAVE 4
    │ WP-12  │   │  WP-13   │   │ WP-14  │
    │ billing│   │  speed   │   │   AI   │
    └────────┘   └──────────┘   └────────┘

    WP-90 QA harness — runs continuously from Wave 1
```

**Parallelism available:** 6 agents in Wave 1, 3 in Wave 2, 3 in Wave 4, plus
the content track and QA throughout.

---

## 2. WP-00 — Contracts and scaffold

**SERIAL. Nothing starts until this lands.** Single agent.

### Objective
Produce `CONTRACTS.md` and a working module skeleton that survives an OpenEMR
update.

### Tasks

1. **Verify OpenEMR mechanics on the actual target instance.** Do not assume
   from training knowledge. Determine and document: version; how this version
   registers custom modules; where module code lives; how install/upgrade SQL is
   declared; how the module adds routes, menu entries and front-end assets; how
   it subscribes to events; how it reads encounter and patient context.
2. **Scaffold the module.** Install, uninstall, one route, one asset, one admin
   entry.
3. **Prove update survival.** Apply an OpenEMR point update; confirm the module
   and its data are intact. *This is the gate.*
4. **Write the full DDL** for every table in specs 06, 07 and 08 §12, prefixed
   `aem_`, as versioned migrations.
5. **Write `CONTRACTS.md`**, containing:
   - Complete table and column definitions with types and FKs
   - Every enum and its exact values
   - Service-layer interfaces each package must implement or consume
   - Route and endpoint naming conventions
   - Event names the module emits and consumes
   - Front-end asset conventions and the panel mount point
   - Shared vocabularies from `06` Appendix B as seeded reference data
   - The contract-change protocol

### Deliverables
- `CONTRACTS.md`
- Module scaffold, installable
- Migrations for all tables
- Seeded vocabularies

### Acceptance
Module installs on a clean instance; survives a core update with data intact;
`CONTRACTS.md` covers every table referenced in any package below.

### Must not
Implement features. This package builds the frame, not the content.

---

## 3. WAVE 1 — six agents in parallel

### WP-01 — Template engine (backend)

**Reads:** 06 §1.4, §1.5, §3.1–3.8 · 08 §12.1

**Objective:** the data layer and service API for templates, saved findings and
findings.

**Build:**
- CRUD for `aem_template`, `aem_template_element`, `aem_template_option`
- CRUD for `aem_saved_finding` and its template/preset joins
- `applyTemplate(encounterId, sectionKey, templateId)` → creates findings,
  `provenance = pre_populated`
- `applySavedFinding(...)` → same, plus presets
- `previewApplication(...)` → returns templates to be applied, element counts,
  sex/age filter results — **without writing anything**
- `undoApplication(applicationId)` → single atomic reversal
- Sex/age filtering at application time, with skip reporting
- Right/left symmetry validator (§3.6) — refuses to save asymmetric paired
  elements
- Library hygiene checks (§3.8): duplicate-name detection, spell check hook,
  `last_used_at`

**Acceptance:** tests 5, 6, 8, 40 from the suite.

**Must not:** build UI; touch rendering; alter contracts.

---

### WP-02 — Findings panel (front end)

**Reads:** 08 §12.1, §12.2 · 06 §3.1, §3.3

**Objective:** the `＋` panel — the primary interaction of the whole system.

**Build:**
- `＋` control on every section header
- Right-hand panel, three tabs, section-scoped per the §12.1.1 table
- **Templates tab:** list, filter, select → calls WP-01's apply
- **Saved findings tab:** list with bundle preview showing every template and
  its element count **before commit**; atomic undo after
- **Previous visit tab:** several dated prior encounters with one-line previews;
  carry-forward of the current section only
- Chip rendering with polarity colours (§5.4), consistent across all states
- Inline value slots (`__/10`, `__%`, level and laterality dropdowns)
- Per-element `NOTE` row; `Normal` / `Clear` per element; `All Normal` / `Clear`
  at template header; `Reported by` selector
- Render-mode toggle per section

**Interface:** consumes WP-01's service API only. Mock it until WP-01 lands —
do not wait, and do not write your own backend.

**Acceptance:** tests 5, 19, 21.

**Must not:** implement template application logic server-side; implement the
assessment tree (that is WP-07, which will reuse this panel shell — build the
shell generically).

---

### WP-03 — Renderer

**Reads:** 06 §5 entire, especially §5.3 and §5.6

**Objective:** findings → rendered note text. A pure function; the most
isolatable package in the build and the one with the subtlest requirements.

**Build:**
- List mode: `Element: value; value; value`
- Paragraph mode with:
  - **Stem collapsing** — four negatives become `no tenderness of the spinous
    process, the facet joints, the sacroiliac joint(s), or the coccyx`;
    `or` under negation, `and` when positive
  - Pronoun agreement from patient sex / stated pronouns
  - Polarity grouping: positives then negatives
- Deterministic ordering by `template_option.sort_order` — never silent
  reordering
- Section assembly into the full note per the §5.6 reference output
- One summary only; no near-duplicate section names (§5.7)

**Acceptance:** test 19, plus byte-level reproduction of the §5.6 reference note
from a fixture set of findings. **The QA agent should supply that fixture as the
package's primary test.**

**Must not:** read from the database directly — take findings as input, return
text. This keeps it testable and reusable by the AI layer later.

---

### WP-04 — Intake sections

**Reads:** 06 §2.4–2.8 · 07 §11.1 table

**Objective:** vitals, allergies, medications, problems — including the build's
single most important safety control.

**Build:**
- `aem_vital_reading`: multiple readings per encounter, each independently
  timestamped; auto-BMI; pain score; `not_performed` + reason
- BMI → specific obesity class suggestion for the problem list (30.1 →
  `E66.811`, not `E66.9`)
- Allergies with `source`, `source_name`, `source_received_at` displayed per row
- **`nkda = true` alongside any active allergy is a hard validation error.**
  Incoming external allergy on an NKDA patient auto-clears the flag and raises a
  reconciliation task. *This is the critical finding from the teardown — treat
  it as the package's reason for existing.*
- Medications: structured sig, TALLman lettering, reconciliation rail with
  `Incoming` / `Dismissed`
- If an external source is configured but non-functional, say so in the rail —
  never show an empty list that reads as "nothing to reconcile"
- Problems: body-system grouping, structured laterality, one-click push to A/P
- MU-style advisory flags that name their rule and cannot be cleared by an
  assertion contradicting stored data

**Acceptance:** tests 1, 4, 18.

**Must not:** build the A/P itself (WP-07) or the consistency engine (WP-10) —
but expose the NKDA conflict as a validation the engine can consume.

---

### WP-05 — Macros

**Reads:** 08 §12.5

**Objective:** text expansion in every free-text box. Fully isolatable; no
dependency on any other package beyond the contract.

**Build:**
- `aem_macro` with scope `system | practice | personal`, personal winning
  silently on collision
- **Inline trigger expansion** — configurable prefix, default `.`, autocomplete
  with preview, Tab/Enter to expand
- **Right-click → Insert macro**
- **User-assignable hotkeys** `Ctrl+1`–`Ctrl+9`
- Tab stops: `▮…▮` regions, Tab to move between
- Variables: `@NAME@`, `@AGE@`, `@HE/SHE@`, `@DATE@`, `@PROVIDER@`, `@SIDE@`,
  `@LEVEL@` — the last two from the diagnosis context when present
- Nesting, depth-capped
- Management UI for personal and practice libraries
- **Attachment to every free-text box in the module**, uniformly

**Provenance:** macro-expanded text is `attested` on insertion, never
`pre_populated`.

**Must not:** reimplement pronoun logic — call WP-03's.

---

### WP-06 — Library tooling and import format

**Reads:** 06 §3.8, Appendix A, Appendix B · 08 §B.3

**Objective:** the format and validators the content track depends on. **This
package gates WP-20, so it ships early or the content track idles.**

**Build:**
- A documented import format (YAML or JSON) for templates, saved findings,
  diagnosis sets, plan groups and order sets
- Importer with transactional load and rollback
- Validators run at import:
  - Right/left symmetry
  - Unique codes across diagnosis sets and order sets
  - Shared-vocabulary conformance (Appendix B)
  - Spell check
  - Near-duplicate name detection
  - Laterality-in-name detection → flags entries that should be parameterised
  - ICD-10 code format and specificity sanity check
- Export back out, for round-tripping and review
- Bundle audit report: for every saved finding, which templates it pulls and how
  many findings it emits (§1.5)

**Acceptance:** tests 8, 9, 10.

---

## 4. WAVE 2 — three agents

### WP-07 — Assessment and plan

**Reads:** 08 §12.3 · 07 §11.2.4 · 06 §4.1–4.3

**Depends on:** WP-01 (findings), WP-02 (panel shell)

**Build:**
- Diagnosis entry: frequency-ranked favorites first, search as fallback
- `aem_plan_group`, `aem_plan_group_code`, `aem_plan_option` with `parameters`
  jsonb
- The assessment tree: selecting a diagnosis switches the panel to its plan
  options — same panel surface, no modal
- A plan option fires up to four effects: narrative, order creation, follow-up,
  consent queue
- Inline parameters (level, side, contrast, protocol, interval) — never separate
  near-identical options
- Plan options ranked by the provider's own usage per group
- `aem_diagnosis_set` with `presentation_mode` (`insert_all` | `pick_list`) and
  `laterality_param`
- Pick-list UI: candidate list, laterality applied at set level, multi-select,
  `add selected`
- Per-diagnosis plan box with free-text escape; `narrative_template` may
  reference a WP-05 macro
- Deleted pre-populated diagnoses leave no residue in the note but **are**
  recorded in the audit trail

**Interface:** calls WP-08's order service to create orders. Define the call
against `CONTRACTS.md` and mock until WP-08 lands.

**Acceptance:** tests 30, 31.

---

### WP-08 — Orders and dispatch

**Reads:** 08 §12.4 · 06 §4.3–4.6 · 07 §11.2.4

**Build:**
- `aem_order_type` with `default_destination`, `default_method`,
  `default_recipient`, auth rule — **the provider never chooses these**
- `aem_order_set` / `aem_order_set_item` with unique codes, mixed item types,
  parameterised laterality and level
- Validation warning when a set's name implies a procedure and it contains no
  procedure item
- Procedure order field set surfaced **before** signing, pre-filled from the
  encounter (performing provider, location)
- Receiver directory centred on the encounter's facility; in-house facilities
  rank first; distances shown
- Diagnosis-scoped order panel with an explicit "search all" escape
- Patient education as a **separate queue** with its own Acknowledge — never
  counted as orders
- **Dispatch rail**: live strip showing what the visit will send
- `aem_dispatch_task` with status lifecycle; front-desk worklist UI
- Packet assembly (order, demographics, insurance, relevant reports)
- Fax and email integration with delivery confirmation; **failures turn the task
  red**
- Loop closure: scheduling status returns to the chart
- Report of orders dispatched but never completed

**Acceptance:** tests 10, 15, 16, 17.

---

### WP-09 — Provenance, carry-forward, assignment, attestation

**Reads:** 07 entire · 08 §12.2

**Depends on:** WP-01, WP-02, WP-03

**Build:**
- `provenance` enum on findings; `placed_by`, `attested_by`, `modified_from`,
  `source_encounter_id`
- Direct entry by an authorised clinician → `attested` immediately; template
  application → `pre_populated` **regardless of who applied it**, provider
  included
- Carry-forward: `template_element.carries_forward`, non-carrying types,
  age display on carried content
- Assignment: `assigned_to`, return path, three worklists (§11.9)
- Section review: open-and-leave marks reviewed; `accepted` / `supplemented` /
  `corrected`
- **Exam and A/P attestation** with live count of findings being adopted —
  *"42 findings not individually modified"*
- Attestation flips remaining `pre_populated` to `attested`; voided if
  pre-populated content is added afterward (§11.10)
- `aem_section_policy` config table, shipped with the §11.3 defaults
- Documentation block rendering (§11.7) — generated, never typed, wording
  configurable at practice level
- Split time: `aem_encounter_time` per user with role, provider time separable

**Acceptance:** tests 22, 24, 26, 27, 28, 29, 32, 34, 35, 36, 39.

**Must not:** implement sign-off blocking — expose the conditions; WP-10 and
WP-11 enforce them.

---

## 5. WAVE 3 — serial, two agents

### WP-10 — Consistency rule engine

**Reads:** 06 §3.7 · 07 §11.6

**Depends on:** WP-01, WP-04, WP-07, WP-09

**Build:**
- Declarative `aem_consistency_rule` with `when` / `then_conflicts` jsonb
- Evaluation across sections on change and at sign-off
- Seed rules: HPI numbness vs ROS "no numbness"; HPI weakness vs ROS/PE "no
  weakness"; NKDA vs active allergy (error, blocks); template sex/age mismatch;
  diagnosis without supporting content (warning); active problem absent from A/P
  (warning)
- Errors block; warnings dismissible with a reason
- Admin UI for rule authoring

**Acceptance:** test 7, and the §11.6 additions.

---

### WP-11 — Signing, immutability, amendment

**Reads:** 06 §6 · 07 §11.6

**Depends on:** WP-09, WP-10

**Build:**
- Four-state machine: `open | signed | amended | voided`. **No other states.**
- Sign-off validation, combining §6.3 items 1–7 with §11.6 items 8–11
- Every unmet precondition listed with a deep link to the field
- **Explicitly not preconditions:** check-in, arrival, location, eligibility,
  assignment-of-benefits, registration beyond identity, procedure code,
  checkout, assignment
- Immutability after signing; amendment as linked addendum with its own
  signature; both render
- Revenue-cycle gaps become tasks that hold the claim in draft and never touch
  encounter status

**Acceptance:** tests 2, 3, 20, 23, 25, 36.

> **This package is the gate.** Nothing touches a live patient until WP-11 and
> WP-10 are both complete and the full suite passes.

---

## 6. WAVE 4 — three agents

### WP-12 — Billing projection

**Reads:** 06 §7 · 07 §11.8

- Claim projected from the signed note; **all** A/P diagnoses in order
- Procedure codes auto-populated from signed orders
- E&M calculator: patient status **derived from encounter history**, derivation
  displayed, override requires a reason
- Time read from `aem_encounter_time` provider rows only; excluded staff total
  displayed
- Versioned, date-effective time→code table with payer-class dimension; applied
  rule and effective date shown

**Acceptance:** tests 11, 12, 13, 14, 15, 33.

---

### WP-13 — Speed layer

**Reads:** 08 §B.2 M7 · 07 §11.2.5

- Command line / shorthand resolver: `m5416r`, `esi il l45 r`, `ret 4w`
- Provider favorites, frequency-ranked, across diagnoses, templates and plan
  options
- Previous-visit default for established patients — A/P opens pre-loaded
- `aem_encounter_plan`: one selection scaffolding HPI, ROS, PE, diagnosis set
  and order set, with full preview and atomic undo

**Acceptance:** test 21, plus a timed benchmark: **right L4-L5 ESI ordered in
three interactions.**

---

### WP-14 — AI layer

**Reads:** conversation of 2026-11-15 · 06 §3.7 · 07 §11

- Third provenance value `ai_generated`; **never auto-attested**; renders
  distinctly
- Schema-constrained generation: the model emits **findings against template
  elements**, not prose — the template library is the controlled vocabulary that
  bounds output
- Consistency engine (WP-10) as hallucination check
- Pre-visit drafting from prior note, imaging reports, PDMP
- Natural language over WP-13's resolver
- Documentation block states AI involvement explicitly
- **Architectural decision required before this package starts: local inference
  vs vendor.** Do not let it be settled by whichever ships first.

---

## 7. WP-20 — Content capture (parallel track, starts immediately)

**Depends on:** WP-06's import format only. Not on any code.

Content agents or delegated staff. Runs the whole time.

| Batch | Asset | Count | Needed by |
|---|---|---|---|
| 1 | TheWorks diagnosis sets + their plan content | ~12 | WP-07 |
| 2 | HPI templates | 30 | WP-01/02 |
| 3 | PE templates and saved findings | 54 | WP-02 |
| 4 | ROS saved findings and templates | 7 | WP-02 |
| 5 | Order sets 5xx / 8xx / 85x / 9000x | ~60 | WP-08 |
| 6 | Reasons for visit and encounter plans | 49 | WP-13 |

**Capture rules — these are the package's whole value:**

- Record **structure**, not names: elements, options, polarity, parameters.
- **Fix defects during transcription.** `06` Appendix A is the checklist. Every
  typo, missing mirror, reused code, laterality-in-name and M54.16/M54.17-style
  mismatch is corrected as it is entered. Correcting later, once notes reference
  the library, costs far more.
- Collapse laterality duplicates into parameterised entries (§12.3.2).
- Attach plan groups rather than per-code plan lists.
- **Flag unclear clinical intent for Al rather than guessing.** A wrong guess
  transcribed confidently is worse than a gap.

---

## 8. WP-90 — QA harness (continuous, from Wave 1)

**Owns the 40 acceptance tests** — 20 in `06` §9, 20 in `07` §11.11.

- Builds the harness and fixtures before the packages that need them
- **Supplies WP-03 its reference fixture**: a finding set that must render the
  §5.6 note byte-for-byte
- Supplies WP-04 the NKDA conflict scenario
- Runs the full suite against every merged branch
- Owns the live traceability matrix: test → package → status
- **Flags contract drift** — the earliest symptom of parallel divergence

---

## 9. Assignment summary

| Wave | Packages | Agents | Gate to exit |
|---|---|---|---|
| 0 | WP-00 | 1 | Module survives a core update; `CONTRACTS.md` complete |
| 1 | WP-01…WP-06 | 6 | HPI documentable end to end; renderer matches reference |
| 2 | WP-07, 08, 09 | 3 | Full note with A/P, orders dispatching, MA workflow working |
| 3 | WP-10, WP-11 | 2 | **Safe for real patients.** Full suite green |
| 4 | WP-12, 13, 14 | 3 | Three-click ESI; claim projects correctly |
| — | WP-20 | continuous | Library transcribed and validated |
| — | WP-90 | continuous | Traceability matrix complete |

---

## 10. Risks specific to parallel AI execution

| Risk | Mitigation |
|---|---|
| **Agents inventing divergent schemas** | WP-00 is serial and blocking; contracts frozen; change requests go to the integration owner |
| Agents "improving" shared contracts locally | Stated in every brief; QA agent watches for drift |
| WP-02 blocked waiting on WP-01 | Build against a mock; the brief says so explicitly |
| Renderer subtleties lost | QA supplies the byte-exact reference fixture as the acceptance test |
| Content track idling | WP-06 ships its import format early; capture starts before any code is usable |
| Defects transcribed faithfully from athena | Validators in WP-06 catch them mechanically; Appendix A is the manual checklist |
| Something reaching a live patient before WP-11 | Synthetic data only until the Wave 3 gate; stated as a hard rule |
| PatientNow naming leaking in | Stated convention; add a CI grep that fails the build on the string |

---

**Next action:** assign WP-00. Nothing else starts until `CONTRACTS.md` exists
and the scaffold has survived a core update.
