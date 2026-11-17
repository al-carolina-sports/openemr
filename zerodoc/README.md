# ZeroDoc — Project Map and Implementation Handoff

**Project:** ZeroDoc Clinical Encounter Workflow — a clinical documentation-to-billing
module for OpenEMR
**Practice:** Carolina Sports and Spine
**Written:** 2026-11-16, 08:17 ET
**For:** the agent taking over implementation (Grokbot), and anyone who needs to
find the project

---

## 1. Where the project lives

Everything is in Google Drive, under one root:

```
OpenEMR Project - Annie's Elegant Modules/          ← project root
│
├── ZeroDoc - Deploy Agent Handoff Brief.md         ← deployment context
├── ZeroDoc-CLAUDE.md                               ← standing rules file
├── ZeroDoc-build-artifacts.zip                     ← all built code (§3)
│
└── Athena Reverse Engineering/                     ← specification set
    │   (folder name predates the project; contents are the current spec)
    │
    ├── 01 - athena-lbp-documentation-teardown.md       ─┐
    ├── 02 - addendum-prep-vs-visit-and-billing.md       │ research archive —
    ├── 03 - addendum-2-live-intake-and-exam.md          │ evidence base only
    ├── 04 - screenshots-part1of2.zip                    │
    ├── 05 - screenshots-part2of2.zip                   ─┘
    │
    ├── 06 - gateless-encounter-documentation-spec.md   ─┐
    ├── 07 - ma-authored-notes-and-diagnosis-sets.md     │ THE SPECIFICATION
    ├── 08 - build-plan-v1.md                            │ — read these
    ├── 10 - documentation-to-billing-integration.md     │
    ├── 11 - existing-modules-survey.md                 ─┘
    │
    ├── 09 - work-packages-for-parallel-build.md        ← work breakdown
    ├── 12 - deploy-agent-handoff.md                    ← deployment brief
    └── 13 - project-map-and-implementation-handoff.md  ← this document
```

### 1.1 What each document is

| Document | What it contains | Read it when |
|---|---|---|
| **06** | The core design. Gateless encounter model, structured findings, template engine, renderer, A/P, orders, billing projection. 20 acceptance tests, a 21-item defect register. | **First. Everything else assumes it.** |
| **07** | MA-authored notes, provenance, assignment, review, attestation, diagnosis sets. Regulatory boundary (CMS: who may document what). 20 more acceptance tests. | Before building anything touching authorship or signing |
| **08** | §12 — the findings panel, carry-forward, the assessment tree, plan options, macros. Plus the phased build plan M0–M9. | Before building UI or the A/P |
| **10** | §13 — problem threads, the procedure section, billing hand-off, diagnosis pointers, modifiers. 12 more acceptance tests. | Before building procedures or billing |
| **11** | Survey of the practice's 12 existing custom modules: what each owns, what to reuse, 12 named integration risks. | **Before writing any code that touches another module** |
| **09** | Work breakdown into packages with dependencies, interfaces and acceptance criteria. Written for independent agents. | When dividing the work |
| **12** | Deployment context: environment facts, standing rules, deployment landmines. | Before touching the server |
| **01–05** | Research archive. The teardown of the practice's current EMR that this design came from. Reference only — **not** the design. | Only when tracing why a requirement exists |

---

## 2. What is settled, and what is not

### 2.1 Settled — do not redesign

- **One encounter, four states** (`open | signed | amended | voided`). No
  check-in gate, no stage ordering, no role gate on data entry. A note signs on
  clinical completeness alone — never on registration, insurance or checkout.
- **Structured findings, not prose.** Clinical content is discrete findings
  bound to template elements, each carrying a `provenance` value. The
  consistency checks, attestation counts and claim projection all depend on
  this. Free text cannot support any of them.
- **Templates vs saved findings.** A template is blank structure; a saved
  finding is a template plus pre-selected answers. One click fills a section.
- **An MA can build the entire note** — exam templates and diagnosis sets
  included — and assign it to the provider, who examines, edits and attests.
- **Billing is a projection of the signed note.** It never gates the note.
- **Everything ships as a custom module.** Core is never edited.
- **Table prefix `zdoc_`.** Namespace is ZeroDoc.

### 2.2 Open — four decisions that will cause wrong work if guessed

Full list: `OPEN-QUESTIONS.md` in the build artifacts, 41 items, 14 needing a
human. These four are blocking:

| # | Decision | Why it matters |
|---|---|---|
| **1** | **Problem list** — ZeroDoc's own, or read OpenEMR's core `lists`? | Spec 06 says extend `lists`; the no-core-changes rule forbids it. The schema currently produces **two problem lists in one chart.** Not recommended. Blocks intake work. |
| **2** | **AI Scribe precedence** — retire it when ZeroDoc lands, or convert it to a dictation front-end feeding ZeroDoc findings? | `oe-module-elegant-ai-scribe` already puts an AI note on the encounter. Two AI notes with contradictory content and an ambiguous claim source is the worst outcome. Decide before building, not at the AI phase. |
| **3** | **Cash vs insurance routing** — `order_type` needs a payer-class dimension | A cosmetic/cash procedure planned in the A/P currently projects an insurance claim instead of a cash-pay quote. With three entities on one OpenEMR this breaks in week one. |
| **4** | **E&M time→code table** — unseeded, deliberately | Billing rules were not written from memory. Needs the practice's own fee schedule and payer classes. |

**Decisions 1 and 2 are Al's.** 3 and 4 need his data.

---

## 3. Build artifacts — the code that already exists

Packaged as **`ZeroDoc-build-artifacts.zip`** in the project root. ~2.7 MB,
131 files. Unpack it and you have:

```
contracts/
  schema.sql                      63 tables, 885 columns, MariaDB, idempotent
  CONTRACTS.md                    v1.4.0 — the frozen interface contract
  vocabularies.sql                12 shared vocabularies, 68 items
  OPEN-QUESTIONS.md               41 items, 14 needing a human decision
  validate-schema.php             static validator for the install SQL
  SCHEMA-VALIDATION-REPORT.md     its findings against the current schema

module/
  oe-module-zerodoc-encounter/    25-file working scaffold
  MODULE-CONVENTIONS.md           OpenEMR 8.5 module mechanics, with core
                                  source citations — several findings
                                  contradict common assumptions
  DEPLOY.md                       deployment runbook for this environment
  EXISTING-MODULES-SURVEY.md      the 12-module survey (same as Drive doc 11)

renderer/
  src/, tests/, fixtures/         findings → note text. 52 assertions passing.
                                  Reproduces spec 06 §5.6 reference output.

library-tools/
  FORMAT.md                       YAML import format for the clinical library
  bin/import-library.php          transactional import, proven rollback
  bin/export-library.php          round-trip stable
  bin/bundle-audit.php            what each saved finding actually emits
  src/, tests/, fixtures/         12 validators, 88 tests passing
```

### 3.1 How this should actually be delivered

The zip works but it is one-way and has no history. **The right answer is a
git repo** — `al-carolina-sports/zerodoc`, private — so the implementing agent
pulls, commits its own work, and both sides read the same tree. That removes
the human from the middle entirely.

Pending Al's go-ahead. Until then, the zip is current as of the date above.

---

## 4. Build order

**Do not start with features.**

### Gate 0 — prove the module survives
Install the scaffold on the dev VM through the **Modules admin UI** (not the
CLI — see §6). Confirm it registers, its form appears, its tables create. Then
**apply an OpenEMR point update and confirm the module and its data survive.**

Everything in this project rests on the custom-module architecture holding
through core updates. Nobody has verified it yet. If it fails, the architecture
needs rethinking and it is far cheaper to know now.

### Gate 1 — the test harness
The 52 acceptance tests across specs 06, 07 and 10 exist as prose, not as an
executable suite. Build the harness early, or "done" stays a judgment call.

### Then, in dependency order
1. **Template engine + findings storage** (spec 06 §1.4, §3)
2. **The `＋` panel** — three tabs, section-scoped (spec 08 §12.1)
3. **Renderer integration** — already built, wire it in
4. **Intake sections** — vitals, allergies, meds, problems (spec 06 §2).
   **The NKDA hard stop is a patient-safety control, not a validation nicety.**
5. **Assessment and plan** — diagnoses, plan groups, plan options, pick lists
   (spec 08 §12.3)
6. **Orders and dispatch** — order types, dispatch rail, front-desk worklist
   (spec 08 §12.4)
7. **Provenance, assignment, attestation** (spec 07)
8. **Consistency rules and signing** (spec 06 §3.7, §6) ← **safe for real
   patients only after this**
9. **Macros, speed layer** (spec 08 §12.5)
10. **Billing projection** (spec 06 §7, spec 10 §13)
11. **AI layer** — structured generation, never prose

Full detail with interfaces and acceptance criteria per package: document 09.

### Parallel, starts immediately
**Content capture.** Transcribe the practice's existing clinical library —
diagnosis sets, HPI templates, PE templates and saved findings, ROS, order sets
— into the YAML format in `library-tools/FORMAT.md`. Roughly 180 assets. This
is data entry, not engineering, and it is the schedule risk. **Fix defects
during transcription**; spec 06 Appendix A is the checklist.

---

## 5. Acceptance criteria

52 numbered tests: spec 06 §9 (1–20), spec 07 §11.11 (21–40), spec 10 §13.10
(41–52). Each names a scenario and an expected outcome. They are the definition
of done.

The ones that matter most, because each encodes a real defect found in the
practice's current system:

- **Test 4** — assert NKDA, inject an external codeine allergy, attempt to sign
  → **blocked.** A note must never assert "no known drug allergies" over a
  documented allergy.
- **Test 6** — apply a saved finding containing Breast/GU/Rectal templates to a
  male patient → those templates skipped, skip reported.
- **Test 7** — HPI documents numbness, ROS saved finding asserts "no numbness"
  → signing blocked on the contradiction.
- **Test 25** — pre-populated exam, unattested, attempt to sign → blocked, with
  a count of findings being adopted.
- **Test 32** — MA logs 12 min, provider logs 25 min → the E&M calculator uses
  25 and shows the exclusion.

---

## 6. Standing rules — non-negotiable

1. **Modules only. Never edit OpenEMR base code.**
2. **Never recreate the Docker containers.**
3. **Back up to `~/openemr-backups/` on the VM before any change.**
4. **Never print database passwords, admin passwords, or AWS/Deepgram keys.**
5. **The former vendor's name appears nowhere** — code, comments, table names,
   UI, docs, commit messages. Al has ruled it out entirely.
6. **Leave `oe-module-elegant-themes` alone.** Al is mid-edit on it.
7. **Test data only.** The dev VM is sterile — demo patients, fake names. If
   real patient data ever appears, stop and tell Al.

### 6.1 Deployment landmines — confirmed by reading core source

- **Enable modules through the admin UI, never the CLI.** `openemr:zfc-module`
  does not call `ModuleManagerListener` at all, so a CLI install silently skips
  ACL registration, table creation and form registration. Everything looks fine
  and nothing works.
- **OpenEMR does not run `cleanup.sql` itself.** The listener must drop its own
  tables on unregister.
- **A fatal in `openemr.bootstrap.php` is swallowed** — only `error_log` records
  it, and a missing bootstrap makes core force-disable the module. If it appears
  disabled for no reason, read the PHP error log first.
- **Install SQL directives degrade silently.** `#IfNotTable` and friends with
  the wrong argument count become comments and their statements run unguarded.
  Run `validate-schema.php` before any install.
- Run `openemr-cmd phpstan` before merging.

---

## 7. The environment

| | |
|---|---|
| **EMR host** | Linux VM `functional-emr`, Tailscale IP `100.79.84.89` |
| **Web UI** | https://functional-emr.tail98fb9d.ts.net/ |
| **OpenEMR** | 8.5.0-dev, Docker |
| **Web container** | `openemr-proto-openemr-1` |
| **DB container** | `openemr-proto-mysql-1` (MariaDB; client `mariadb`) |
| **Web root** | `/var/www/localhost/htdocs/openemr` (in container) |
| **Custom modules** | `interface/modules/custom_modules/` |
| **Backups** | `~/openemr-backups/` on the VM |
| **Fork** | `github.com/al-carolina-sports/openemr` |

The VM is hosted on `lenovo-i7` via Hyper-V. Claude Code is installed and
authenticated on the VM.

---

## 8. First instruction for the implementing agent

```
Read, in this order:
  1. This document
  2. Spec 06 — the core design
  3. MODULE-CONVENTIONS.md and DEPLOY.md in the build artifacts
  4. Spec 11 — the existing-modules survey

Then, before writing any feature code:
  a. Run validate-schema.php against schema.sql and report the findings.
  b. Install the scaffold on the dev VM through the Modules admin UI.
  c. Apply an OpenEMR point update and confirm the module and its data survive.
  d. Report.

Do not start feature work until (c) passes. Do not redesign anything in §2.1.
If something in the spec looks wrong, say so — do not fix it unilaterally.
```

---

## 9. Division of responsibility

- **Al** — decisions, approvals, clinical judgment, anything touching real
  operations. The four open decisions in §2.2 are his.
- **Implementing agent** — builds against the specification, deploys, verifies,
  reports. Does not redesign.
- **Design side** — specification, contracts, architectural decisions, and
  resolving the open questions as Al answers them.

Nobody guesses at another's work. When the specification is ambiguous, ask —
there are 41 logged open questions precisely because guessing was refused.
