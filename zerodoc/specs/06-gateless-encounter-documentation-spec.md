# Gateless Encounter Documentation — Implementation Spec

**Purpose:** a complete, implementation-ready description of a clinical note
documentation workflow, derived from a teardown of athenaOne but with athena's
workflow gating deliberately removed. Written so that an AI agent or developer
with no access to athena can build this in an open-source EMR (OpenEMR or
similar).

**Source material:** `01 - athena-lbp-documentation-teardown.md`,
`02 - addendum-prep-vs-visit-and-billing.md`,
`03 - addendum-2-live-intake-and-exam.md` (same Drive folder).

**Target reader:** an implementer. Everything below is a requirement, not a
narrative. `MUST`, `SHOULD`, `MAY` are used in the RFC-2119 sense.

---

## 0. Design thesis — what we are changing and why

athenaOne splits documentation into two layers that cannot see each other:

| Layer | Stages | What is possible |
|---|---|---|
| Prep | Intake Prep → Exam Prep | Templates work; vitals read-only; **note cannot be signed; claim cannot save** |
| Visit | Check-in → Intake → Exam → Sign-off → Checkout | Everything |

Crossing from one to the other requires a **front-desk check-in event**. The
consequence observed in the teardown: a provider can pre-document a complete
HPI, ROS, PE and A/P, assemble a correct `99213` claim line, and still have
`Save` greyed out and no `Sign` button anywhere — because a registration flag
had not been ticked.

That gate exists to serve athena's revenue-cycle sequencing. It is not clinical.
**This spec removes it.**

### The five rules that follow from that

1. **One encounter object, one state.** There is no prep copy and no visit copy.
   Anything written is written to the encounter itself.
2. **No stage ordering.** Sections are a navigation convenience, not a state
   machine. Any section may be opened, edited and completed in any order, at
   any time, by anyone with the right.
3. **No role gate on data entry.** Whether vitals are typed by an MA or by the
   provider is an attribution question (recorded), not a permission question
   (enforced).
4. **Signing requires only clinical completeness.** Never registration,
   insurance, check-in, arrival status or location.
5. **Billing is derived, never a prerequisite.** The claim is computed from the
   signed note. A missing code blocks the *claim*, never the *note*.

### Keep the parts of athena that are good

The teardown found genuinely well-engineered mechanics worth copying exactly:
the Templates-vs-Saved-Findings distinction (§3), per-section render modes
(§5), automatic grammatical collapsing of repeated stems (§5.3), level-specific
anatomical tagging (§4.3), and per-reading vital timestamps (§2.4). Copy those.

---

## 1. Data model

Minimum viable schema. Names are illustrative; match host conventions.

### 1.1 Core

```
encounter
  id                     pk
  patient_id             fk → patient
  facility_id            fk → facility
  provider_id            fk → user          -- attributed clinician
  encounter_type         enum  new | follow_up | procedure | telehealth
  reason_for_visit_id    fk → reason_for_visit  nullable
  chief_complaint        text
  service_date           date
  status                 enum  open | signed | amended | voided
  signed_at              timestamp nullable
  signed_by              fk → user nullable
  created_at, updated_at
```

`status` has **four** values, not six stages. There is no `prepped`, no
`checked_in`, no `with_provider`. An encounter is open, signed, amended after
signing, or voided.

### 1.2 Sections

A section is a container for findings. Sections are declarative config, not
code.

```
encounter_section
  id                     pk
  encounter_id           fk → encounter
  section_key            enum  reason | vitals | allergies | medications |
                               problems | family_hx | social_hx | surgical_hx |
                               past_medical_hx | screening | hpi | ros | pe |
                               assessment_plan | orders | procedures |
                               time_spent | patient_instructions |
                               discussion_notes | follow_up
  render_mode            enum  list | paragraph        -- see §5
  is_complete            boolean   -- advisory checkmark only, never a gate
  note_text              text      -- free-text row, every section has one
  note_visibility        enum  everyone | internal     -- see §6.4
  updated_by             fk → user
  updated_at             timestamp
```

### 1.3 Findings

The atomic unit. One row per selected chip or typed value.

```
finding
  id                     pk
  encounter_section_id   fk → encounter_section
  template_id            fk → template nullable   -- which template emitted it
  element_id             fk → template_element
  option_id              fk → template_option nullable
  value_text             text nullable     -- for free-entry options
  value_numeric          decimal nullable  -- for "__/10", "__ %" slots
  value_unit             text nullable
  laterality             enum  right | left | bilateral | n/a
  anatomic_level         text nullable     -- "L4", "S1", "T12"
  polarity               enum  positive | negative | neutral
  sort_order             integer
  recorded_by            fk → user
  recorded_at            timestamp
```

`polarity` drives colour rendering (§5.4) and is **derived from the option
definition**, not guessed from wording.

### 1.4 Template library

```
template
  id, name, specialty, body_region, section_key, is_active,
  owner_scope enum  system | practice | provider

template_element                    -- a row inside a template ("Severity")
  id, template_id, label, sort_order, allows_multi boolean,
  render_prefix text                -- "Sensation on the Right:"

template_option                     -- a chip ("worst pain __/10")
  id, element_id, label, polarity, sort_order,
  value_slots jsonb                 -- [{type:"numeric",max:10,suffix:"/10"}]
  excludes_option_ids jsonb         -- mutual exclusion within element
```

### 1.5 Saved findings — the key abstraction

```
saved_finding
  id, name, section_key, owner_scope, is_active

saved_finding_template             -- a saved finding MAY include >1 template
  saved_finding_id, template_id, sort_order

saved_finding_preset               -- the pre-selected answers
  saved_finding_id, element_id, option_id,
  value_text, value_numeric, laterality, anatomic_level
```

**This is the single most valuable mechanic to copy.** A *template* is blank
structure. A *saved finding* is a template (or several) **plus answers**, so one
click fills an entire exam. The teardown confirmed `POS Low Back Pain`
instantiates `Comprehensive Adult Problem ROS` fully populated across 14 systems
in one action.

#### MUST: bundle transparency

athena's `Carpal Tunnel Left` saved finding silently pulled in
`General Adult Exam` + `Wrist` + `CSS Lumbar Spine` and auto-documented a full
general physical — including Breast, Female GU and Rectal headers on a
36-year-old male — none of which was performed. This is the worst defect found.

Requirements:

- The picker **MUST** display every template a saved finding will apply, with
  its element count, *before* the user commits.
- Application **MUST** be previewable and **MUST** be undoable as a single
  atomic action.
- Templates **MUST** be sex- and age-filtered at application time: a template
  whose `applies_to_sex` or `applies_to_age_range` does not match the patient is
  skipped, and the skip is reported.
- An admin report **MUST** list, for every saved finding, which templates it
  bundles and how many findings it would emit. This is the audit tool the
  teardown recommends for all 36 of athena's saved findings.

### 1.6 Attribution, not permission

```
section_attribution
  encounter_section_id, user_id, role_at_time, first_write_at, last_write_at
```

Replaces athena's role-gated stages. The note records *who documented what*;
nothing is withheld from anyone who has the encounter open.

---

## 2. Sections — required behaviour

### 2.1 Reason for visit

- A configurable list of reasons (athena had 49).
- A reason **MAY** carry an **encounter plan**: a bundle of templates and order
  sets auto-attached on selection. athena marks these `EP`.
- Encounter plans **MUST** be visible and removable, same transparency rule as
  §1.5.
- **Avoid athena's duplication:** it carried both `low back pain` and
  `LBP New Pt` as separate EP reasons. Model new-vs-established as the
  `encounter_type` field, not as duplicate reasons.

### 2.2 Prep notes — keep, but don't let them become the workflow

athena's Intake Prep is almost entirely read-only; its only writable fields are
two free-text notes ("note to whoever does intake", "note to whoever does the
exam"). Keep the capability as plain internal notes on the encounter. Do **not**
build a separate pre-charting mode around them.

### 2.3 Editability

Every clinical field is editable from the moment the encounter exists. No field
is ever rendered `disabled` because of workflow position. This directly reverses
athena's Intake Prep behaviour, where `Ht`, `Wt`, `BP`, `RR`, `T`, `HR`,
`O2Sat`, allergies and medications were all disabled, producing a note reading
`Vitals: None recorded.`

### 2.4 Vitals

```
vital_reading
  id, encounter_id, recorded_at, recorded_by,
  height_in, weight_lb, bmi (derived, stored),
  bp_systolic, bp_diastolic, bp_position, bp_site,
  pulse, resp_rate, temp_f, temp_route, o2_sat, o2_delivery,
  pain_score  integer 0..10,
  not_performed boolean, not_performed_reason text
```

- **Multiple readings per encounter MUST be supported** (athena's `⊕` control).
- Each reading carries **its own timestamp**, surfaced in the note. Do not stamp
  the group.
- BMI is auto-calculated and **MUST** be offered to the problem list as a
  specific obesity class (BMI 30.1 → `E66.811`, not generic `E66.9`).
- Pain score belongs here, not only in HPI.
- `not_performed` + reason is a first-class state, distinct from null.

### 2.5 Allergies — the critical safety rule

The worst defect in the teardown: `NKDA` remained checked while an external feed
added **codeine** and **strawberry** allergies. No block, no warning, no
auto-clear. A note could have asserted "no known drug allergies" on a
codeine-allergic patient.

```
allergy
  id, patient_id, substance_code, substance_text, reaction, severity,
  onset_date, status enum active|inactive|entered_in_error,
  source enum provider|patient|external_feed, source_name, source_received_at
patient_allergy_assertion
  patient_id, nkda boolean, asserted_by, asserted_at
```

Requirements:

- `nkda = true` while any `allergy.status = 'active'` exists **MUST** be a
  **hard validation error** that blocks signing. Not a warning.
- An incoming external allergy on a patient asserting NKDA **MUST** auto-clear
  the NKDA flag and raise a reconciliation task.
- Every allergy displays its **source and receipt timestamp** on the row —
  athena does this well (`From MEMORIAL BOULEVARD … | 11-14-2026, 21:38`).
- In the rendered note allergies appear under a **Reviewed Allergies** header
  with the reviewer and review timestamp. A review is an event, not a checkbox.

### 2.6 Medications

- Structured sig by default: `Take [qty] [form] / [frequency] / [route] /
  for [n] days / start [date] / status [active|historical]`.
- **TALLman lettering** in the picker for look-alike drugs (`metFORMIN`,
  `LISINOPRIL`). Copy this.
- Reconciliation rail with `Incoming` / `Dismissed` tabs per external source
  (athena's ChartSync).
- **Do not repeat athena's config failure:** its medication history download was
  silently disabled ("Medication history authority must be specified on the
  Privacy Information page"), so staff reconciled against nothing. If an
  external source is configured but non-functional, the UI **MUST** say so in
  the reconciliation rail, not show an empty list.

### 2.7 Quality / regulatory flags

athena marks Allergies and Medications with orange bars and explicit Meaningful
Use text, clearable by a checkbox (`NKDA`, `No medications reported`).

Keep the pattern — a declarative per-section rule engine producing an advisory
flag — with two changes: name the rule in the flag text, and **never allow the
flag to be cleared by an assertion that contradicts stored data** (§2.5).

### 2.8 Problems

- Auto-group by body system (athena: Cardiac and Vasculature, Endocrine and
  Metabolic, Neuro).
- Laterality is a structured field, not text appended to the name.
- **MUST** offer every active problem to the A/P diagnosis list in one click.
  athena failed to carry `Diabetes type 2` from the problem list into the A/P —
  issue #9 in the teardown.

---

## 3. The template engine

### 3.1 Two object types, surfaced distinctly

| Type | Contains | Effect |
|---|---|---|
| **Template** | structure only | inserts blank elements |
| **Saved finding** | template(s) + presets | inserts a completed section |

The picker **MUST** label these separately. athena does, and it is the
difference between a one-click normal exam and a blank form.

### 3.2 Element structure — worked example

`Pain Management L-spine`, 19 elements, reproduced as the reference
implementation target:

| # | Element | Options |
|---|---|---|
| 1 | Location | *not radiating* (neg) / radiating LLE, radiating RLE, bilateral LE, LBP, L/R buttock, L/R hip, L/R groin |
| 2 | Quality | throbbing, tightness, numbness, burning, aching, cramping, sharp, dull, tingling |
| 3 | Severity | *improving* (neg) / current `__`/10, worst `__`/10, same, worsening, interferes with sleep, interferes with work |
| 4 | Duration | constant, intermittent, pain at night |
| 5 | Onset/Timing | sudden, gradual, chronic, intermittent episodes lasting `__` days/weeks/months/years, actual date |
| 6 | Context | cannot identify, bending, lifting, fall, twisting, MVA |
| 7 | Alleviating | nothing helps, heat, ice, medication, sitting, standing, stretching, position change, rest, lying down, chiropractic, ESI, PT |
| 8 | Aggravating | cannot identify, extension, flexion, carrying, twisting, lifting, out of bed, sit-to-stand, sitting, standing, walking, lying down |
| 9 | Associated Sx | *no weakness, no numbness, no bladder/bowel compromise* (neg) / weakness, numbness, bladder, bowel |
| 10 | Radiation | *no radiation* (neg) / right L`__`, left L`__`, R/L LE, bilateral LE, R/L S1, dorsum R/L foot, stocking-and-glove |
| 11 | Work Related | no / yes |
| 12 | ADL | *improves with medication* / showering, dressing, walking, sweeping, mopping / does not improve |
| 13 | Relief w/ current meds | `__` % for `__` hours |
| 14 | Driving impairment w/ meds | no / yes |
| 15 | Prior imaging | none, no recent studies, CT, discogram, MRI, myelogram |
| 16 | Prior EMG | none, normal, no recent EMG, PN, radiculopathy |
| 17 | Previous surgery | none, date, **discectomy**, fusion, laminectomy |
| 18 | Previous injections | none, ESI, TPI, RFTA, facets, SI joint / did not help, helped a little, helped temporarily, **helped significantly** |
| 19 | Previous chiropractic | none, did not help, helped a little, helped temporarily, **helped significantly**, aggravated symptoms |

Defects corrected in the table above, which the implementer **MUST NOT**
reproduce:

- athena offers `dorsum left foot` with no right equivalent → **mirror all
  laterality options**.
- athena spells it `discecotomy` → fixed.
- athena's element 19 lacks `helped significantly` while 18 has it →
  **response scales MUST be shared vocabularies, not per-element lists**.
- Elements 13 and 14 are opioid-monitoring fields sitting inside a
  mechanical-pain template → **move to a separate COT/compliance template**
  that can be attached independently.

### 3.3 Per-element controls

Every element exposes: a `NOTE` free-text row; `Normal` and `Clear` links once
touched. Every template header exposes `All Normal`, `Clear`, a `Reported by`
selector (default `patient`), and the render-mode toggle (§5).

### 3.4 Laterality must be structural

athena's 36 PE saved findings drift between `Right`/`R` and `Bilateral`/`B`
(`Hip Labral Tear R`, `SI Joint B`), leave mirrors missing (no
*Shoulder Bursitis Right*), and carry at least one unfinished name
(`Knee OA Left val`).

Requirement: **laterality is never part of the name.** One `Hip Labral Tear`
saved finding with a `laterality` attribute, rendered as
`Hip Labral Tear — Right`. This makes missing mirrors structurally impossible
and the picker sort correctly. A name-uniqueness and orphan-mirror linter
**SHOULD** run over the library on save.

### 3.5 Anatomical level tagging

Copy athena's `CSS Lumbar Spine` behaviour: an option may carry a level
qualifier — *tenderness of the spinous process at L*`1|2|3|4|5`, *facet joints
on the right at L*`1|2|3|4|5`, *sacroiliac joint(s)* `R|L|B`. Implemented via
`finding.anatomic_level`.

### 3.6 Right/left symmetry linter

athena's `CSS Lumbar Spine` Soft Tissue Palpation is not mirrored: the left list
has `iliolumbar region` and `gluteus medius`, the right list has `sciatic nerve`
and neither of those. You cannot document right gluteus medius tenderness.

Requirement: a saved template with paired `Right`/`Left` elements **MUST** fail
validation if the option sets differ.

### 3.7 Clinical consistency rules

athena's `POS Low Back Pain` saved finding hard-codes Musculoskeletal *"limited
motion, low back pain"* together with Neurological *"no numbness, no weakness,
no tingling, no shooting pain"* — which contradicts any radicular HPI, and did
contradict the one in the teardown.

Requirement: a declarative cross-section rule engine.

```
consistency_rule
  id, name, severity enum error|warning,
  when_jsonb       -- {section:"hpi", element:"Associated Symptoms", option:"numbness"}
  then_conflicts_jsonb
                   -- {section:"ros", element:"Neurological", option:"no numbness"}
  message
```

Seed rules at minimum:

| When | Conflicts with | Severity |
|---|---|---|
| HPI numbness / radiation present | ROS "no numbness" / "no shooting pain" | error |
| HPI weakness present | ROS/PE "no weakness", normal motor strength | error |
| `nkda = true` | any active allergy | **error, blocks signing** |
| PE template applied | template sex/age mismatch to patient | error |
| A/P diagnosis | no corresponding PE or HPI content | warning |
| Problem list active | problem absent from A/P | warning |

Errors block signing. Warnings are shown at sign-off and dismissible with a
reason.

### 3.8 Library hygiene, built in

athena's library carried `Pain Manangement T-spine`, two stale
`COVID-19 Symptoms March 2020` / `May 2020` templates, and overlapping stems
(`L-spine` vs `Pain Management L-spine`, `Lumbar Spine` vs `CSS Lumbar Spine`).

Provide, as admin tooling: spell-check on save, a `last_used_at` column with an
unused-in-N-months report, near-duplicate name detection, and
`is_active = false` retirement that preserves historical notes.

---

## 4. Assessment & plan, orders, procedures

### 4.1 A/P structure

Per-diagnosis: coded diagnosis, free-text plan (athena caps at 4000 chars —
use `text`, no cap), attached orders, attached patient education.

Section-level: `Copy from previous visit`, PDMP summary panel, total time spent,
patient goals, patient instructions, discussion notes with topic selector.

### 4.2 Diagnosis-scoped orders

Important mechanic the teardown initially misread: searching for orders before
selecting a diagnosis returns nothing; **orders are scoped to the selected
diagnosis**. Selecting `Carpal tunnel syndrome, left` switched the panel to
*"Orders for Carpal tunnel syndrome, left (20)"* with EMG/NCS, hand surgeon
referral and carpal tunnel splint available.

Implement the same scoping — it is good design — but **MUST** also offer an
explicit "search all orders" escape so a clinician can order anything.

### 4.3 Order sets — fix athena's model

athena's 60 lumbar order sets are named after procedures
(`511 R L4-L5 ILESI`) but previewing one reveals it contains **a single
diagnosis code and nothing else** (`M54.17`). They are diagnosis-attachment
shortcuts wearing procedure names. The teardown concludes this is the likely
source of manual downstream work in the procedure-note workflow.

Requirements:

```
order_set
  id, code, name, is_active            -- code UNIQUE, enforced
order_set_item
  order_set_id, item_type enum diagnosis|procedure|imaging|lab|referral|
                               education|consent|medication,
  reference_id, laterality, anatomic_level, default_quantity, sort_order
```

- `order_set.code` **MUST** be unique. athena reuses `850` three times
  (Lumbar, Lumbar Post-Lami, Lumbar Spondylolisthesis) and `856`/`857`/`858`
  twice each — the number is no longer a key.
- A set named for a procedure **MUST** contain the procedure item. Validation
  **SHOULD** warn when a set's name implies a procedure and no
  `item_type = 'procedure'` row exists.
- Every set is numbered; no unnumbered named sets sorting away from the catalog.
- Laterality and level come from `laterality` / `anatomic_level`, not from the
  name — collapsing athena's 30+ near-identical 5xx entries into a handful of
  parameterised sets.
- Diagnosis codes on a set **MUST** match the problem list specificity. athena's
  problem carried `M54.16` (lumbar) while the order set attached `M54.17`
  (lumbosacral).

### 4.4 Procedure orders

The post-signing procedure block athena exposes is the right field set; make it
visible **before** signing, not after:

Performing provider · assistant required · follow-up appointment · anesthesia +
notes · relevant diagnoses · prescribed relevant medications · labs · location ·
send date · STAT flag · alarm/tickler date · receiver · documentation-only flag ·
include summary of care · CC recipients · attachments · note to facility ·
internal note · authorization and insurance referral block.

Defaults **MUST** be pre-filled from the encounter (performing provider =
encounter provider, location = encounter facility). athena left all of these
blank, requiring re-entry of data it already had.

### 4.5 Receiver directory — do not geo-anchor to a vendor default

athena's `Send to → Receiver` search filtered *"Within any distance of 02472"* —
Watertown, Massachusetts. Searching `Carolina Sports and Spine` from a North
Carolina office returned only Massachusetts practices, and the practice's own
facilities were absent from the directory entirely.

Requirements: the directory origin **MUST** default to the encounter's facility
address; "in-house" facilities **MUST** be first-class directory entries and
rank first; and distance **MUST** be displayed so a 500-mile suggestion is
visibly wrong.

### 4.6 Patient education is not an order

Selecting a diagnosis in athena silently queued handouts and drove the
*Sign Orders* counter 0 → 2 → 3 → 5 with no explicit order action, so handouts
get signed alongside clinical orders.

Requirement: education items live in a separate `patient_education` queue with
its own `Acknowledge` action. They **MUST NOT** appear in the order-signing
count. Auto-attachment by diagnosis is fine and useful; it just isn't an order.

### 4.7 Time spent

```
encounter_time
  encounter_id, minutes, attribution enum em|em_telehealth|bh|bh_telehealth,
  recorded_by, recorded_at
```

- Partial entry (minutes without attribution) **MUST NOT** silently fail. athena
  blocks the save with *"Please complete all required fields to save total
  time"* and discards the number — a documented data-loss path.
- This is the **single source of truth** for time. The coder reads it (§7.3); it
  is never re-entered.

---

## 5. Note rendering

### 5.1 Per-section render mode

Each section carries `render_mode`, user-switchable (athena's
`View as: Paragraph ▼`). Defaults matching athena's behaviour, which is
sensible:

| Section | Default |
|---|---|
| HPI | list |
| ROS | paragraph |
| PE | list |

### 5.2 List mode

`Element: value; value; value`

```
Location: radiating to the RLE; LBP; right buttock pain
Severity: current pain level 6/10; worst pain 8/10; interference with sleep
```

### 5.3 Paragraph mode

Prose, with:

- **Pronoun agreement** from patient sex/stated pronouns ("He reports…").
- **Stem collapsing.** athena renders four separate negative findings as
  `no tenderness of the spinous process, the facet joints, the sacroiliac
  joint(s), or the coccyx` — one stem, serial list, correct `or` before the last
  item under negation (`and` when positive). Copy this; it is the single biggest
  readability win in the whole output.
- Sentence grouping by polarity: positives, then negatives.

### 5.4 Polarity colouring

In the editor: positive findings **red**, negative/normal **green**, neutral
descriptors **blue/black**. athena only colours positives red in the live stage
and not in prep — colour **MUST** be consistent everywhere.

### 5.5 Deterministic ordering

athena does not preserve chip order: Constitutional entered as
`healthy-appearing, normal body habitus, NAD` rendered as
`healthy-appearing, NAD, and normal body habitus`.

Requirement: rendering order is `template_option.sort_order`, stable and
documented. If entry order is preferred, use `finding.sort_order` — but pick one
and never silently reorder.

### 5.6 Reference output

The note this workflow must be able to produce, from the teardown's walkthrough:

```
PATIENT   TEST, MARTY (36yo M)  ID# 6108   DOB 01/01/1990
VISIT     11/14/2026 02:00 PM · ROCKY MOUNT OFFICE · FOLLOW UP
PROVIDER  ALVIN K. ANTONY, MD
INSURANCE Med Primary: *SELF PAY*

CHIEF COMPLAINT
low back pain

VITALS (11/14/2026 21:40)
Ht 5'10" · Wt 210 lb · BMI 30.1 · BP 132/84 · RR 16 · T 98.4 °F ·
HR 78 · O2 98% · Pain 6/10

REVIEWED ALLERGIES
CODEINE · STRAWBERRY        (external, 11/14/2026 21:38; reviewed 21:55)

REVIEWED MEDICATIONS
metFORMIN 500 mg tablet — 1 tab PO BID
lisinopril 10 mg tablet — 1 tab PO daily

PROBLEMS
Essential hypertension (I10) · Diabetes type 2, controlled (E11.9) ·
Obesity, class 1, BMI 30.0–34.9 (E66.811) ·
Carpal tunnel syndrome, left (G56.02)

HPI — Pain Management L-spine (reported by patient)
Location: radiating to the RLE; LBP; right buttock pain
Quality: aching; sharp
Severity: current pain level 6/10; worst pain 8/10; interference with sleep
Duration: intermittent
Onset/Timing: chronic
Context: lifting
Alleviating Factors: heat; medication; rest
Aggravating Factors: flexion; lifting; sit-to-stand; sitting
Associated Symptoms: numbness; no weakness; no bladder or bowel compromise
Radiation: right S1
Work Related: no
ADL: improve with medication
Driving Impairments with Medications: no
Prior Imaging: MRI   Prior EMG: none   Previous Surgery: none
Previous Injections: none   Previous PT: helped a little
Previous Chiropractic Care: none
Note: numbness and tingling in the first three digits of the LEFT hand in a
median nerve distribution, worse at night and with sustained gripping; relieved
by shaking the hand. No prior EMG/NCS. Consistent with left carpal tunnel
syndrome.

ROS — Comprehensive Adult Problem ROS
Patient reports limited motion and low back pain but reports no soft tissue
swelling, no joint swelling, no myalgia, and no trauma. He reports numbness and
tingling of the left hand. He reports no significant weight change, good
appetite, no fever, and no fatigue. [... 12 further systems ...]

PHYSICAL EXAM — CSS Lumbar Spine + Wrist
Constitutional: General Appearance: healthy-appearing, NAD, normal body habitus.
Gait and Station: normal gait, no limp, no assistive devices.
Lumbar Spine: Inspection: no induration, ecchymosis, or swelling; normal
  alignment. Bony Palpation: no tenderness of the spinous process, the facet
  joints, the sacroiliac joint(s), or the coccyx. Active ROM: no pain with
  motion.
Motor Strength: L1, L2–L4, L5, S1 — 5/5 right and left.
Wrist: Sensation Right: normal median nerve distribution. Sensation Left:
  decreased median nerve distribution. Special Tests Right: Tinel's negative,
  Phalen's negative. Special Tests Left: Tinel's at the median nerve positive,
  Phalen's positive.
Neurological: Sensation T12–S2 normal bilaterally. Straight leg raise negative
  bilaterally. Babinski absent bilaterally.
Note: no thenar atrophy.

ASSESSMENT / PLAN
1. Right lumbar radiculopathy — M54.16
   Chronic right L5–S1 radicular low back pain, mechanical onset with lifting.
   Continue HEP; PT previously gave partial relief. Discussed right L4–L5
   interlaminar ESI; will schedule. Review prior lumbar MRI. Monitor glycemic
   control before steroid injection — counseled on transient post-injection
   hyperglycemia. Return 4 weeks.
2. Carpal tunnel syndrome, left — G56.02
   Orders: nerve conduction study/EMG, upper extremity (in house). Night splint.
3. Essential hypertension — I10
4. Obesity, class 1 — E66.811

ORDERS SIGNED (1)
nerve conduction study/EMG, upper extremity — Alvin K. Antony, MD,
Rocky Mount Office · 11/14/2026

PATIENT EDUCATION PROVIDED (4)   [not an order]
carpal tunnel syndrome: care instructions · carpal tunnel syndrome: exercises ·
high blood pressure: care instructions · learning about high blood pressure

TIME SPENT
25 minutes, Evaluation and Management (E&M)

FOLLOW-UP
Return to office in 4 weeks.

SIGNED  Alvin K. Antony, MD · 11/14/2026 22:10
```

### 5.7 One summary, not two

athena shows a short *Prep Summary* omitting HPI, ROS and PE, with the real note
behind a `FULL PREP SUMMARY` link — and renames both after check-in. It also
emits an `Assessments — None recorded.` section separate from the populated
`Assessment / Plan`.

Requirement: **one** rendered note, always complete, always the same name. No
near-duplicate section names.

---

## 6. Workflow — the gateless flow

### 6.1 States

```
          create
            │
            ▼
        ┌────────┐   sign (validation passes)   ┌────────┐
        │  OPEN  │ ───────────────────────────► │ SIGNED │
        └────────┘                              └────────┘
            │                                       │
            │ void                           amend  │
            ▼                                       ▼
        ┌────────┐                            ┌─────────┐
        │ VOIDED │                            │ AMENDED │
        └────────┘                            └─────────┘
```

That is the entire state machine. Compare athena's five-stop ribbon
(Check-in → Intake → Exam → Sign-off → Checkout) plus a parallel two-stage prep
loop.

### 6.2 What is NOT a precondition for signing

Explicitly, and this is the point of the spec:

- check-in, arrival, or any appointment status
- patient location or `with provider` status
- insurance verification, eligibility, or assignment-of-benefits flags
  (athena's *Demographic Mismatch, Rule 14553* blocks a clean claim — it
  **MUST NOT** block a note)
- registration completeness beyond patient identity
- a procedure or E&M code
- checkout
- whether anyone else has touched any section

### 6.3 What IS a precondition for signing

Clinical and legal only:

1. Patient identity resolved (name, DOB, MRN).
2. Service date and attributed provider present.
3. Chief complaint or reason for visit present.
4. At least one assessment with a coded diagnosis.
5. No `error`-severity consistency rule violated (§3.7) — including the NKDA
   rule.
6. All orders either signed or explicitly deferred.
7. Signer has signing authority; co-sign requirement satisfied if the attributed
   provider requires supervision.

Each unmet precondition is listed at sign-off with a deep link to the field.

### 6.4 Note visibility

Keep athena's per-note-field `Visible to everyone` / internal toggle
(`encounter_section.note_visibility`). Internal notes are excluded from the
patient-facing and release-of-information renderings, and that exclusion is
logged.

### 6.5 Amendment

A signed note is immutable. Amending creates a linked addendum with its own
signature and timestamp; the original is retained and both render. Never
in-place edit after signing.

### 6.6 Optional convenience (not a gate)

Pre-charting is simply opening the encounter before the patient arrives, since
nothing is locked. If a practice wants visibility into that, add a derived
boolean — `has_content_before_service_time` — as a *report*, never a status that
changes behaviour. athena's `PREPPED` badge is exactly this, and the confusion
it causes (it looks like a stage, it is a flag) is the reason not to surface it
as one.

---

## 7. Billing — derived, decoupled

### 7.1 Principle

The claim is a **projection** of the signed note. The note never waits on the
claim. athena assembled a complete, valid `99213` line and still greyed out
`Save` because check-in had not run; the claim was correct and uncommittable.

### 7.2 Claim assembly

```
claim
  id, encounter_id, status enum draft|ready|submitted|paid|denied,
  rendering_provider_id, supervising_provider_id, facility_id,
  place_of_service, created_at
claim_line
  claim_id, procedure_code, description, modifiers jsonb, units,
  diagnosis_pointers jsonb, billable boolean
```

Auto-carried from the note on signing: diagnoses **in A/P order** (athena
carried only the first of four), ICD-10 codes onto each line, rendering and
supervising provider, facility, place of service, and procedure codes from
signed procedure orders.

Two fixes to athena behaviour:

- **All** A/P diagnoses carry over, not just #1. The teardown's claim held only
  `M54.16` while the visit supported four diagnoses.
- Procedure codes from signed orders populate lines automatically. athena showed
  ⚠ `Missing Procedure Code` with a signed EMG order sitting in the same
  encounter.

### 7.3 E&M calculator

Panel: place of service · guideline mode `MDM | time-based` · patient status ·
MDM axes (number/complexity of problems, amount/complexity of data reviewed,
risk of morbidity — each `N/A | Minimal | Low | Moderate | High`).

**Two mandatory corrections:**

**(a) Patient status MUST derive from history, not default to `New`.** athena
opens with `New` selected on an appointment it knows is `FOLLOW UP`. On a PM&R
panel of returning injection patients, one missed click bills a new-patient
code. Derive from prior encounters with the practice; display the derivation;
allow override with a reason.

**(b) Time MUST come from `encounter_time`, never be re-entered.** athena's
time-based mode opens with `Total time` empty despite 25 minutes documented in
the A/P and in the note text — two independent time values for one visit, with
nothing reconciling them. One field, read by both.

### 7.4 Time → code reference (established patient)

Captured from athena; note the divergence above 40 minutes.

| Minutes | Medicare | Non-Medicare |
|---|---|---|
| 10–19 | 99212 | 99212 |
| 20–29 | 99213 | 99213 |
| 30–39 | 99214 | 99214 |
| 40–54 | 99215 | 99215 |
| 55–68 | 99215 | 99215 + 1 × **99417** per additional 15 min |
| 69+ | 99215 + 1 × **G2212** per additional 15 min | 99215 + 99417 as above |

Implement as a versioned, date-effective lookup table with a payer-class
dimension. Never hard-code. Show the applied rule and its effective date beside
the suggestion.

### 7.5 Revenue-cycle items are tasks, not blocks

Registration gaps (athena's assignment-of-benefits flag), eligibility failures
and missing authorizations become **tasks on a worklist**, attached to the
encounter, assigned to front office. They set `claim.status = draft` and block
submission. They never touch `encounter.status`.

---

## 8. Build order

| Phase | Deliverable | Depends on |
|---|---|---|
| 1 | Schema §1; encounter CRUD; four-state machine §6.1 | — |
| 2 | Section framework §1.2; free-text + visibility §6.4 | 1 |
| 3 | Template engine §1.4, §3.1–3.3; one reference HPI template §3.2 | 2 |
| 4 | Saved findings §1.5 with bundle preview and sex/age filtering | 3 |
| 5 | Renderer §5: list mode, paragraph mode, stem collapsing, polarity | 3 |
| 6 | Vitals §2.4; allergies §2.5 **incl. the NKDA hard stop**; meds §2.6 | 2 |
| 7 | Problems §2.8; A/P §4.1; diagnosis-scoped orders §4.2 | 6 |
| 8 | Order sets §4.3 with unique codes and parameterised laterality | 7 |
| 9 | Procedure orders §4.4; receiver directory §4.5 | 8 |
| 10 | Consistency rule engine §3.7; sign-off validation §6.3 | 5, 7 |
| 11 | Signing, amendment §6.5; immutable rendered note | 10 |
| 12 | Claim projection §7.2; E&M calculator §7.3–7.4 | 11 |
| 13 | Library hygiene tooling §3.4, §3.6, §3.8; bundle audit report §1.5 | 4 |

Phases 1–5 produce a working documentation tool. Phase 10 is where this design
earns its keep: the validation layer is the thing athena lacks.

---

## 9. Acceptance tests

Each maps to a defect found in the teardown.

| # | Test | Expected |
|---|---|---|
| 1 | Create encounter, enter vitals immediately, no appointment or check-in | Vitals save; note renders them with per-reading timestamps |
| 2 | Document HPI/ROS/PE/A/P and sign with no registration data beyond identity | Note signs |
| 3 | Insurance unverified, assignment-of-benefits unflagged, then sign | Note signs; a front-office task is created; claim stays `draft` |
| 4 | Assert NKDA, then inject an external codeine allergy, then sign | NKDA auto-cleared; reconciliation task raised; signing blocked until resolved |
| 5 | Apply a saved finding that bundles 3 templates | All 3 named with element counts before commit; single-action undo works |
| 6 | Apply a saved finding containing Breast/Female GU/Rectal to a male patient | Those templates skipped; skip reported |
| 7 | HPI documents numbness; apply ROS saved finding asserting "no numbness" | Error-severity conflict; signing blocked |
| 8 | Save a PE template whose Right and Left option sets differ | Validation failure naming the asymmetric options |
| 9 | Save two order sets with the same code | Second save rejected |
| 10 | Create an order set named for a procedure containing only a diagnosis | Warning that no procedure item is present |
| 11 | Enter time with no attribution | Validation error; **the number is retained** |
| 12 | Enter 25 min, open E&M calculator in time-based mode | `Total time` pre-filled 25; suggests 99213; rule and effective date shown |
| 13 | Follow-up patient with prior encounters, open E&M calculator | Patient status derives `Established`; derivation displayed |
| 14 | Sign a note with four A/P diagnoses | All four appear on the claim in A/P order |
| 15 | Sign an encounter containing a signed procedure order | Procedure code auto-populates the claim line |
| 16 | Search order receivers from the NC facility | Origin is that facility; in-house facilities rank first; distances shown |
| 17 | Select a diagnosis that auto-attaches 4 handouts | Order-signing count stays 0; handouts appear in the education queue |
| 18 | Add a problem to the problem list, open A/P | Problem offered for one-click inclusion |
| 19 | Enter PE chips out of template order | Rendered in documented order, deterministically |
| 20 | Amend a signed note | Addendum created with own signature; original intact; both render |

---

## 10. Mapping to OpenEMR

Orientation notes for an implementer targeting OpenEMR specifically.

| This spec | OpenEMR analogue | Work required |
|---|---|---|
| `encounter` | `form_encounter` | Add `status` enum, signing columns; ignore existing billing-driven flow |
| `encounter_section` | LBF form instances | Sections as a first-class registry rather than ad-hoc forms |
| Template / saved finding | Layout-Based Forms + templates | **Build new.** LBF has no saved-finding equivalent — this is the largest new component |
| `finding` | `lbf_data` rows | Needs polarity, laterality, anatomic level, and render metadata |
| Renderer | report scripts per form | **Build new.** Centralised renderer with stem collapsing and polarity |
| `vital_reading` | `form_vitals` | Add multiple readings per encounter, pain score, not-performed reason |
| Allergies | `lists` (type `allergy`) | Add source/receipt columns, NKDA assertion, hard-stop validation |
| Problems | `lists` (type `medical_problem`) | Add body-system grouping, structured laterality, push-to-A/P |
| Order sets | procedure order types | Add unique code constraint, mixed item types, parameterised laterality |
| Procedure orders | `procedure_order` | Pre-fill defaults from encounter; surface the full field set pre-signing |
| Claim projection | `billing` | Project from signed note; all diagnoses; decouple from encounter status |
| E&M calculator | — | **Build new.** Versioned date-effective lookup, payer-class dimension |
| Consistency rules | — | **Build new.** Declarative engine, §3.7 |

The four genuinely new components are the saved-finding engine, the centralised
renderer, the consistency rule engine, and the E&M calculator. Everything else
is extension of existing OpenEMR structures.

---

## Appendix A — athena defect register, carried forward

Every issue from the three teardown documents, with where this spec addresses
it.

| # | Sev | Area | Issue | Addressed |
|---|---|---|---|---|
| 16 | Critical | Allergies | NKDA checked alongside active external allergies, no warning | §2.5, test 4 |
| 1 | High | ROS | `POS Low Back Pain` hard-codes "no numbness / no shooting pain" | §3.7, test 7 |
| 2 | High | A/P | 5xx order sets contain only a diagnosis code | §4.3, test 10 |
| 3 | High | A/P | `850`/`856`/`857`/`858` codes reused | §4.3, test 9 |
| 17 | High | PE | `Carpal Tunnel Left` bundles a full general exam incl. Breast/GU/Rectal on a male | §1.5, tests 5–6 |
| 18 | High | Orders | Receiver directory anchored to ZIP 02472 (MA) | §4.5, test 16 |
| 4 | Med | A/P | Problem M54.16 vs order-set M54.17 | §4.3 |
| 5 | Med | PE | Soft Tissue Palpation right/left not mirrored | §3.6, test 8 |
| 6 | Med | PE | Inconsistent laterality suffixes; missing mirrors | §3.4 |
| 7 | Med | PE | `Knee OA Left val` stray name | §3.8 |
| 8 | Med | A/P | Total Time silently fails to save without attribution | §4.7, test 11 |
| 9 | Med | Flow | Problem-list diabetes did not populate A/P | §2.8, test 18 |
| 19 | Med | Meds | Med history download disabled, silent empty reconciliation | §2.6 |
| 20 | Med | Orders | Handouts increment the order-signing count | §4.6, test 17 |
| 10 | Low | HPI | `discecotomy`, `Pain Manangement T-spine` typos | §3.2, §3.8 |
| 11 | Low | HPI | `dorsum left foot` with no right equivalent | §3.2, §3.4 |
| 12 | Low | HPI | Previous Chiropractic Care missing `helped significantly` | §3.2 |
| 13 | Low | Library | Stale 2020 COVID templates | §3.8 |
| 14 | Low | Library | Duplicate stems across the library | §3.8 |
| 15 | Low | A/P | Order sets 505/506 missing; 509 missing laterality | §4.3 |
| 21 | Low | Summary | `Assessments` vs `Assessment / Plan` near-identical names | §5.7 |
| — | — | Flow | Prep layer cannot sign or bill; check-in is the gate | §0, §6, tests 1–3 |
| — | — | Billing | E&M coder defaults to `New` on a follow-up | §7.3, test 13 |
| — | — | Billing | A/P time does not reach the coder | §7.3, test 12 |
| — | — | Billing | Only the first diagnosis carries to the claim | §7.2, test 14 |

---

## Appendix B — vocabularies to define centrally

Shared option lists referenced by many templates. Defining these once is what
prevents athena's per-element drift.

```
response_to_treatment : did not help | helped a little | helped temporarily |
                        helped significantly | aggravated symptoms
laterality            : right | left | bilateral | n/a
severity_scale        : 0..10 integer
frequency             : constant | intermittent | nocturnal
onset                 : sudden | gradual | chronic
pain_quality          : throbbing | tightness | numbness | burning | aching |
                        cramping | sharp | dull | tingling
reflex_grade          : absent | 1+ | 2+ | 3+ | 4+
motor_grade           : 0/5 .. 5/5
sensation             : normal | decreased | absent | hyperesthetic
special_test_result   : positive | negative | not performed
lumbar_levels         : T12 | L1 | L2 | L3 | L4 | L5 | S1 | S2
cervical_levels       : C2..C8
```

Any element needing one of these **MUST** reference the vocabulary rather than
restating its options.
