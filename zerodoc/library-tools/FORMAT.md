# Clinical Template Library — Import Format

**ZeroDoc Clinical Encounter Workflow** · library tooling (WP-06)

This is the file format for transcribing the clinical template library: templates,
saved findings, diagnosis sets, plan groups and order sets. It is written to be
used by someone transcribing content, not by a programmer. You need a text
editor and nothing else.

Everything you write here is checked mechanically before it reaches the system.
Run the checker on your own work as you go:

```
php bin/validate-library.php my-file.yaml
```

It needs no database, no installation and no internet. It names the entry, says
what is wrong and says what to do about it. **Run it before handing anything
over.** If it is clean, your batch is done; if it is not, the fix is almost
always one line.

---

## Contents

1. [How to write the file](#1-how-to-write-the-file)
2. [**Polarity: descriptor or absence**](#2-polarity-descriptor-or-absence) ← read this before transcribing any exam content
3. [Rules that apply to everything](#3-rules-that-apply-to-everything)
4. [Shared vocabularies](#4-shared-vocabularies)
5. [Templates](#5-templates)
6. [Saved findings](#6-saved-findings)
7. [Diagnosis sets](#7-diagnosis-sets)
8. [Plan groups and plan options](#8-plan-groups-and-plan-options)
9. [Order sets](#9-order-sets)
10. [Field reference](#10-field-reference)
11. [The checkers, and what each one means](#11-the-checkers-and-what-each-one-means)
12. [Importing and exporting](#12-importing-and-exporting)

---

## 1. How to write the file

The format is YAML. You only need five things from it.

**Indent with spaces, two per level. Never tabs.** The checker rejects tabs with
a line number, because a tab that looks like an indent and is not is the hardest
mistake in this format to find by eye.

**A name followed by a colon is a field.**

```yaml
name: Lumbar Spine Examination
section_key: pe
```

**A dash starts a new item in a list.** Every entry in the library is a list
item.

```yaml
templates:
  - name: Lumbar Spine Examination
    section_key: pe
  - name: Wrist and Hand Examination
    section_key: pe
```

**Quote a value that contains a colon, or starts or ends with a space.**
Otherwise quotes are optional.

```yaml
render_prefix: "Sensation on the right:"
label: current __/10
code: "511"              # always quote a code, so 0511 stays 0511
```

**A `#` starts a comment.** Use them freely. Comments are for the next person
and cost nothing.

**Long text** goes after `|-` (keeps your line breaks) or `>-` (joins the lines
into one paragraph), indented underneath:

```yaml
narrative_template: >-
  Discussed @SIDE@ @LEVEL@ interlaminar epidural steroid injection; risks,
  benefits and alternatives reviewed; patient wishes to proceed.
```

### The shape of a file

One file can hold any mix of the five entry types. Put each top-level key at
most once per file:

```yaml
library:
  name: PE templates, batch 3
  transcribed_by: initials or name
  notes: anything the next person should know

vocabularies:        # optional, see §4
  my_local_scale: [first, second, third]

templates:       [ ... ]
saved_findings:  [ ... ]
diagnosis_sets:  [ ... ]
plan_groups:     [ ... ]
order_sets:      [ ... ]
```

Split the work across as many files as you like — one per batch is sensible, and
the tools take a whole folder:

```
php bin/validate-library.php library/
```

Cross-references work across files in the same run, so a saved finding in one
file may bundle a template in another. Validate the folder, not the single file,
once your entries start referring to each other.

### If something does not fit

**Do not invent a field.** The field list in §10 is complete, and the checker
rejects anything else by name. If the content you are transcribing needs
something the format cannot express, put it in a `notes:` line on the entry and
raise it. A guess transcribed confidently is worse than a gap.

**If the clinical intent is unclear, flag it rather than guessing.** Write what
you are sure of, add `notes:` saying what you are unsure of, and ask. This is the
single most useful habit in this work.

---

## 2. Polarity: descriptor or absence

**Read this section before transcribing exam or review-of-systems content. It is
the mistake that is easiest to make and hardest to see.**

Every option carries a `polarity`. It has two jobs: it colours the finding on
screen, and — this is the part that is not obvious — **it decides the
punctuation of the finished note**.

The renderer collapses consecutive `negative` findings into one negated clause.
So:

| Option text | What it is | Polarity |
|---|---|---|
| `no swelling`, `no tenderness`, `no fever`, `without erythema`, `denies fever` | an asserted **absence** | `negative` |
| `normal alignment`, `normal gait`, `healthy-appearing`, `good appetite`, `intact sensation` | a normal **descriptor** | `neutral` |
| `swelling`, `tenderness`, `weakness`, `limited flexion` | a positive finding | `positive` |

### Why it matters

Take an exam with four findings documented: no induration, no ecchymosis, no
swelling, normal alignment.

Tagged correctly — the three absences `negative`, the descriptor `neutral` — the
note reads:

> no induration, ecchymosis, or swelling; normal alignment

Two clauses. Both true.

Tag `normal alignment` as `negative` and it is pulled into the negated clause
with the others:

> no induration, ecchymosis, swelling, or normal alignment

One clause, and the note now says the patient does **not** have normal
alignment. The exam said the opposite.

Nothing on screen warns you. The option looks right in the picker, it looks
right in the template, and the note is wrong.

### The rule

> **An asserted absence is `negative`. A normal descriptor is `neutral`.**
>
> If the option text begins with "no", "without", "denies" or "negative for", it
> is `negative`. If it describes a normal state in positive words, it is
> `neutral` — however normal the finding is.

"Normal" is not the same as "negative". `negative` means *this finding is
absent*. A descriptor says *this structure is normal*, which is a statement in
its own right and needs its own clause.

### Worked example

```yaml
- label: Inspection
  sort_order: 1
  options:
    # asserted absences - these collapse into one negated clause
    - label: no induration
      polarity: negative
    - label: no ecchymosis
      polarity: negative
    - label: no swelling
      polarity: negative

    # normal descriptors - each stands as its own statement
    - label: normal alignment
      polarity: neutral
    - label: healthy-appearing skin
      polarity: neutral

    # positive findings
    - label: induration
      polarity: positive
    - label: ecchymosis
      polarity: positive
```

The `POLARITY` checker flags both directions of this mistake — an absence tagged
`neutral` or `positive`, and anything else tagged `negative`. It reports them as
warnings, not errors, because there are real exceptions ("nontender" is an
absence without the word "no"; a scale member may be neither). **Read every
POLARITY warning and decide it deliberately.** Do not clear them in bulk.

If you tag an option `negative` whose text is not a negation, the checker will
ask you about it every time, by design.

---

## 3. Rules that apply to everything

These five rules are what the whole package exists to enforce. They are not
style preferences; each one prevents a specific defect that is expensive to
correct once there are signed notes referencing the library.

### 3.1 Laterality is never part of a name

Not `Hip Labral Tear R`. Not `Carpal Tunnel Left`. Not `SI Joint B`. Not
`TheWorks Shoulder Right`.

One entry, named for the condition, with the side as an attribute:

```yaml
- name: Hip Labral Tear Examination      # not "Hip Labral Tear R"
  section_key: pe
  templates: [pe_hip]
```

The side is chosen when the entry is used, and renders as
`Hip Labral Tear Examination — Right`.

Why: a side in the name means two entries per condition, which sort apart in the
picker, drift apart when one is edited, and leave a missing mirror as soon as
anyone forgets one. With the side as an attribute, a missing mirror is
impossible.

The same applies to anatomical levels. Not `Right L4-L5 Interlaminar ESI` — one
parameterised entry, with level and side as fields. This is what collapses
dozens of near-identical numbered sets into a handful.

### 3.2 Mirror everything

If an element exists for the right, it exists for the left, **with the identical
option set**. If an option offers "dorsum left foot", it offers "dorsum right
foot" too.

An option present on one side only means that finding cannot be documented on
the other side at all. The clinician either leaves it out or writes free text,
and the structure has failed at the one job it had.

### 3.3 Set codes are unique

A code identifies exactly one set, across diagnosis sets and order sets
together. They share one catalog. If the content you are transcribing uses the
same number for three different sets, that is a defect to fix now: give two of
them new numbers, or — more often — recognise that they are one set with a side
selector and merge them.

### 3.4 Shared scales are referenced, never retyped

If an option list is one of the shared vocabularies in §4, write
`vocabulary: name` instead of typing the options out. Two elements that retype
the same scale by hand will end up with different scales. That is not
hypothetical: it is the defect where one element's scale carries "helped
significantly" and the element next to it does not, so the same answer can be
recorded in one place and not the other.

### 3.5 Fix defects as you transcribe

You are not making a faithful copy. You are making a correct library. If the
content you are reading from has a typo, a missing mirror, a reused code, a side
baked into a name, or a diagnosis code that disagrees with the one attached to
its orders — **fix it as you enter it**. Correcting it later, once notes
reference the library, costs far more.

The checker catches these mechanically. What it cannot catch is a clinical
judgement: whether two entries are really the same thing, whether a contradictory
pair of pre-set answers is wrong, whether a code is the right code. Those come to
you. Flag them.

---

## 4. Shared vocabularies

These option lists are defined once, centrally. Reference them by name; do not
retype their options.

| Vocabulary | Options |
|---|---|
| `response_to_treatment` | did not help · helped a little · helped temporarily · helped significantly · aggravated symptoms |
| `laterality` | right · left · bilateral · n/a |
| `severity_scale` | 0 … 10 |
| `frequency` | constant · intermittent · nocturnal |
| `onset` | sudden · gradual · chronic |
| `pain_quality` | throbbing · tightness · numbness · burning · aching · cramping · sharp · dull · tingling |
| `reflex_grade` | absent · 1+ · 2+ · 3+ · 4+ |
| `motor_grade` | 0/5 … 5/5 |
| `sensation` | normal · decreased · absent · hyperesthetic |
| `special_test_result` | positive · negative · not performed |
| `lumbar_levels` | T12 · L1 · L2 · L3 · L4 · L5 · S1 · S2 |
| `cervical_levels` | C2 … C8 |

Use one like this:

```yaml
- label: Previous Injections
  sort_order: 18
  vocabulary: response_to_treatment     # no "options:" list at all
```

Polarity comes with the vocabulary, so you do not tag it. "did not help" is
negative, "helped significantly" is positive, and every element that references
the scale gets the same answer.

**A vocabulary is all-or-nothing.** Reference it or do not. If you need five of
the ten `pain_quality` options and no more, write your own `options:` list — the
checker only objects when a hand-written list is *substantially* a vocabulary,
which is the drift case.

**If the practice needs a scale that is not here**, declare it at the top of
your file and reference it the same way:

```yaml
vocabularies:
  satisfaction_scale: [not satisfied, somewhat satisfied, very satisfied]

templates:
  - name: Post-Procedure Follow-Up
    section_key: hpi
    elements:
      - label: Satisfaction With Result
        sort_order: 1
        vocabulary: satisfaction_scale
```

Raise a scale you expect several templates to use, so it can be added centrally
instead of living in one file.

---

## 5. Templates

A **template** is blank structure: elements (the rows) and options (the chips you
click). It inserts an empty form. It is not answers — that is a saved finding.

### Worked example

```yaml
templates:
  - key: hpi_lumbar_pain                   # your own short handle, see below
    name: Pain Management Lumbar Spine
    specialty: pain management
    body_region: lumbar spine
    section_key: hpi                       # which section it belongs in
    owner_scope: practice
    is_active: true

    elements:

      # A plain element with its own option list.
      - label: Location
        sort_order: 1
        allows_multi: true                 # more than one chip may be picked
        render_prefix: "Pain located:"     # text the note puts before the list
        options:
          - label: low back
            polarity: positive
          - label: buttock
            polarity: positive
          - label: hip
            polarity: positive

      # An element that references a shared scale. No options of its own.
      - label: Quality
        sort_order: 2
        allows_multi: true
        vocabulary: pain_quality

      # Options with a blank to fill in, and two that exclude each other.
      - label: Severity
        sort_order: 3
        allows_multi: true
        options:
          - label: current __/10
            polarity: positive
            value_slots:
              - type: numeric
                min: 0
                max: 10
                suffix: /10
          - label: worst __/10
            polarity: positive
            value_slots:
              - type: numeric
                min: 0
                max: 10
                suffix: /10
          - label: improving
            polarity: neutral
            excludes: [worsening]          # cannot be picked together
          - label: worsening
            polarity: positive
            excludes: [improving]

      # Both polarity cases together (see §2).
      - label: Associated Symptoms
        sort_order: 4
        allows_multi: true
        options:
          - label: no weakness
            polarity: negative             # asserted absence
          - label: no numbness
            polarity: negative
          - label: normal gait
            polarity: neutral              # normal descriptor
          - label: weakness
            polarity: positive
          - label: numbness
            polarity: positive
```

### Paired right and left elements

When an exam element genuinely exists per side, write both, mark the side, and
give them the same `pair_key`. The option sets must be identical.

```yaml
      - label: Soft Tissue Palpation - Right
        sort_order: 2
        side: right
        pair_key: soft tissue palpation
        render_prefix: "Tenderness on the right:"
        options:
          - label: no tenderness
            polarity: negative
          - label: paraspinal muscles
            polarity: positive
          - label: iliolumbar region
            polarity: positive
          - label: gluteus medius
            polarity: positive

      - label: Soft Tissue Palpation - Left
        sort_order: 3
        side: left
        pair_key: soft tissue palpation
        render_prefix: "Tenderness on the left:"
        options:                           # identical, option for option
          - label: no tenderness
            polarity: negative
          - label: paraspinal muscles
            polarity: positive
          - label: iliolumbar region
            polarity: positive
          - label: gluteus medius
            polarity: positive
```

`pair_key` is what tells the checker these two are a pair. Without it the checker
guesses from the label, which usually works and sometimes does not. Write it.

Two paired elements referencing the same vocabulary are symmetric by
construction, and are the easier way to do this:

```yaml
      - label: Deep Tendon Reflexes - Right
        sort_order: 6
        side: right
        pair_key: deep tendon reflexes
        vocabulary: reflex_grade

      - label: Deep Tendon Reflexes - Left
        sort_order: 7
        side: left
        pair_key: deep tendon reflexes
        vocabulary: reflex_grade
```

### Anatomical levels

An option may carry a level rather than spelling one out per level. Reference the
level vocabulary:

```yaml
      - label: Spinous Process Tenderness
        sort_order: 4
        render_prefix: "Tenderness of the spinous process at:"
        vocabulary: lumbar_levels
```

### `key`

`key` is a short handle you choose — `hpi_lumbar_pain`, `pe_wrist_hand` — used
only to point at this template from elsewhere in the files. It is not stored and
never shown to anyone. If you leave it out, the name is used instead, and
references have to spell the name exactly. Writing a key is less error-prone.

### Monitoring and compliance fields

Controlled-substance monitoring fields — percent relief, relief duration, driving
impairment, monitoring-program review, drug screen — go in their **own template**
that can be attached when it applies:

```yaml
  - key: hpi_controlled_substance_monitoring
    name: Controlled Substance Monitoring
    section_key: hpi
    elements:
      - label: Relief With Current Medication
        sort_order: 1
        options:
          - label: __ percent relief
            polarity: neutral
            value_slots:
              - type: percent
                max: 100
                suffix: "%"
      - label: Driving Impairment With Medication
        sort_order: 2
        options:
          - label: no impairment reported
            polarity: negative
          - label: impairment reported
            polarity: positive
```

Do not leave them inside a mechanical-pain history, where they get documented on
every patient whether or not they are on a controlled substance. The checker
warns when it sees them there.

---

## 6. Saved findings

A **saved finding** is one or more templates **plus the answers**. One click fills
a whole section. It is the most valuable mechanic in the library and the one with
the sharpest edge:

> **A saved finding documents everything it bundles, whether or not it was
> examined.**

A finding named for one narrow condition that bundles a general physical exam
will auto-document that whole exam, under the clinician's name, on a patient who
had none of it. Keep bundles narrow and keep the name honest about the breadth.

### Worked example

```yaml
saved_findings:
  - key: sf_pe_carpal_tunnel
    name: Carpal Tunnel Syndrome Examination     # no side in the name
    section_key: pe
    owner_scope: practice
    is_active: true

    # Every template this will apply. All of it gets documented.
    templates:
      - template: pe_wrist_hand
        sort_order: 1

    # The pre-selected answers.
    presets:
      - template: pe_wrist_hand
        element: Inspection
        option: no swelling
      - template: pe_wrist_hand
        element: Phalen Test - Right
        option: positive
      - template: pe_wrist_hand
        element: Tinel Sign - Right
        option: positive
      - template: pe_wrist_hand
        element: Median Sensation - Right
        option: decreased
```

A preset names the template, the element and the option, each spelled exactly as
in the template. It may also carry a typed value, a side and a level:

```yaml
      - template: pe_lumbar_spine
        element: Severity
        option: current __/10
        value_numeric: 7
      - template: pe_lumbar_spine
        element: Spinous Process Tenderness
        option: L4
        laterality: right
        anatomic_level: L4
```

### Leave the side off

Do not set `laterality:` on the saved finding itself unless the finding is
inherently one-sided. A side-neutral finding serves right, left and bilateral,
and the side is chosen when it is applied — which is the whole point of §3.1.

If you do set it, the checker will look for the opposite side and report it
missing, because a side-specific entry with no mirror is exactly the defect being
removed.

### Rules the checker enforces

- Every bundled template must exist in the library.
- Every preset must name a template the finding bundles, an element that template
  has, and an option that element offers.
- A narrowly named finding bundling a general or comprehensive template is an
  error: either drop the template or rename the finding.
- A bundled template with no presets is a warning — it contributes blank
  structure, which is a template's job, not a finding's.
- A template whose content is sex-specific (breast, genitourinary, rectal,
  pelvic, prostate, obstetric) must declare `applies_to_sex`, so it is skipped
  for patients it does not apply to.

Before handing a batch over, run the audit:

```
php bin/bundle-audit.php my-file.yaml
```

It lists, for every saved finding, which templates it bundles, how many findings
it would emit and which elements it leaves blank. If a number surprises you,
that finding needs a second look.

---

## 7. Diagnosis sets

A **diagnosis set** is the assessment-and-plan equivalent of a saved finding. It
has two modes.

**`pick_list`** — the common one. It does not insert diagnoses; it narrows the
whole code book to the handful used for a region, each already wired to its
plans. The provider ticks what applies.

**`insert_all`** — for a deterministic visit where the diagnosis is known in
advance. Items with `default_selected: true` go straight in.

A set may mix: a few pre-ticked, the rest offered.

### Worked example — `pick_list` with a side selector

```yaml
diagnosis_sets:
  - code: "850"                      # quoted, unique across all sets
    name: Lumbar Spine Candidates    # no side, no level in the name
    specialty: pain management
    body_region: lumbar spine
    owner_scope: practice
    is_active: true
    presentation_mode: pick_list
    laterality_param: true           # one side selector for the whole set
    items:
      - icd10_code: M54.16
        display_name: Radiculopathy, lumbar region
        laterality: n/a              # the selector supplies it
        default_selected: false      # offered, not inserted
        sort_order: 1
        default_plan_text: Radicular lumbar pain. Plan discussed below.
        linked_order_set: "511"
        linked_education: [education_low_back_pain]
      - icd10_code: M51.26
        display_name: Other intervertebral disc displacement, lumbar region
        laterality: n/a
        default_selected: false
        sort_order: 2
      - icd10_code: M48.062
        display_name: Spinal stenosis, lumbar region with neurogenic claudication
        laterality: n/a
        default_selected: false
        sort_order: 3
```

`laterality_param: true` is what replaces a right set and a left set with one
set. **It only works when the side is not already part of the code.** Many
musculoskeletal codes encode the side themselves — `M75.101` is the right
shoulder, `M75.102` the left — and no selector can change a code. For those
families, list the side-specific codes as separate items and leave
`laterality_param: false`:

### Worked example — side encoded in the code

```yaml
  - code: "857"
    name: Shoulder Candidates
    specialty: physical medicine and rehabilitation
    body_region: shoulder
    presentation_mode: pick_list
    laterality_param: false
    items:
      - icd10_code: M75.101
        display_name: Rotator cuff tear, right shoulder
        laterality: right          # agrees with the code
        default_selected: false
        sort_order: 1
      - icd10_code: M75.102
        display_name: Rotator cuff tear, left shoulder
        laterality: left
        default_selected: false
        sort_order: 2
      - icd10_code: M75.01
        display_name: Adhesive capsulitis of right shoulder
        laterality: right
        default_selected: false
        sort_order: 3
      - icd10_code: M75.02
        display_name: Adhesive capsulitis of left shoulder
        laterality: left
        default_selected: false
        sort_order: 4
```

The checker tells you which case you are in: it knows, for the codes in
`data/icd10-reference.txt`, whether a code carries a side, and it errors if
`laterality_param` and the code disagree.

### Codes

- Format is a letter, two digits, then optionally a dot and up to four more
  characters: `M54.16`, `G56.01`, `I10`.
- Watch for the letter **O** typed where a **zero** belongs. It is legal-looking
  and the checker catches it only for codes in the reference file.
- Prefer the specific code over the "unspecified" one. If the side or the level
  is known, "unspecified" loses information the exam captured.
- **A code attached to a set must be the same code the problem carries.** A
  problem recorded as `M54.16` (lumbar) whose attached order set carries `M54.17`
  (lumbosacral) is a real defect with real downstream consequences; the checker
  compares a set against the order set it links to and reports the mismatch.
- Codes you transcribe that are not in `data/icd10-reference.txt` are reported as
  unverified. Add them to that file, with the description and the side flag, once
  you have confirmed them against the code book.

### Plans

Do not attach plan content to items here. Plans live in plan groups (§8) and are
reached by code. That is deliberate: it is the reason two codes for the same
problem cannot end up with different plans.

---

## 8. Plan groups and plan options

A **plan group** is one problem and the actions available under it. The group is
reached from any of its codes, so every code meaning the same problem gets the
same options.

A **plan option** is one click that may do up to four things at once: write its
narrative into the plan, create an order, set the follow-up interval, and queue a
consent.

### Worked example

```yaml
plan_groups:
  - key: pg_lumbar_radiculopathy
    name: lumbar radiculopathy
    is_active: true

    # Every code that means this problem - including the unspecified one.
    codes: [M54.16, M54.17]

    options:
      - label: Observe
        sort_order: 1
        narrative_template: Symptoms are improving. Continue current measures and observe.
        sets_followup: false

      - label: Physical therapy
        sort_order: 2
        narrative_template: Referred for physical therapy, @REGION@ protocol.
        creates_order_type: referral_physical_therapy
        sets_followup: false
        parameters:
          - key: region
            options: [lumbar, lumbosacral, core stabilization]
            required: true
            default: lumbar

      # One option, four effects, three dropdowns - not forty near-identical
      # options.
      - label: Epidural steroid injection
        sort_order: 3
        narrative_template: >-
          Discussed @SIDE@ @LEVEL@ @APPROACH@ epidural steroid injection; risks,
          benefits and alternatives reviewed; patient wishes to proceed and will
          be scheduled.
        creates_order_type: procedure_lumbar_esi
        queues_consent: consent_epidural_steroid_injection
        sets_followup: true
        parameters:
          - key: side
            vocabulary: laterality
            required: true
          - key: level
            vocabulary: lumbar_levels
            required: true
          - key: approach
            options: [interlaminar, transforaminal, caudal]
            required: true
            default: interlaminar
```

### Parameters

A parameter is a dropdown on the option: side, level, contrast, protocol,
interval. **Use parameters instead of writing out every combination.** Eight
readable options beat forty near-identical ones, and the forty will drift.

Refer to a parameter in the narrative as `@KEY@` in upper case: a parameter
`level` appears as `@LEVEL@`. The checker warns when a required parameter is
never used in the narrative, because then the provider's choice does not reach
the note.

A parameter may reference a shared vocabulary instead of listing options, and
should when one fits.

### What to put in `codes`

Every code that means this problem, specific and unspecified alike. A plan group
is a mapping; a code listed in two different groups is a question for a
clinician, but a code listed nowhere means the provider selects a diagnosis and
sees no plan options under it. The checker warns about that case.

---

## 9. Order sets

An **order set** is a numbered bundle of things to place: a procedure, imaging,
labs, a referral, a consent, education, a medication, and the diagnosis they
attach to.

### Worked example

```yaml
order_sets:
  - code: "511"                                            # unique, quoted
    name: Lumbar Interlaminar Epidural Steroid Injection   # no side, no level
    is_active: true
    items:
      # A set named for a procedure must contain the procedure.
      - item_type: procedure
        reference: procedure_lumbar_esi
        display_name: Interlaminar epidural steroid injection
        laterality: n/a               # side comes from the field, not the name
        anatomic_level: L4-L5
        default_quantity: 1
        sort_order: 1
      - item_type: diagnosis
        reference: M54.16             # an ICD-10 code for a diagnosis item
        display_name: Radiculopathy, lumbar region
        laterality: n/a
        sort_order: 2
      - item_type: consent
        reference: consent_epidural_steroid_injection
        display_name: Epidural steroid injection consent
        sort_order: 3
      - item_type: education
        reference: education_epidural_steroid_injection
        display_name: What to expect after an epidural steroid injection
        sort_order: 4
```

`item_type` is one of: `diagnosis` · `procedure` · `imaging` · `lab` ·
`referral` · `education` · `consent` · `medication`.

`reference` points at what the item is — an ICD-10 code for a `diagnosis` item,
the catalog handle of the procedure, imaging study, lab, referral, consent or
handout for the rest. If you do not know the handle yet, write a sensible one
consistently and note it; resolving handles is a later pass, and a consistent
invented handle is easy to map while an inconsistent one is not.

### The defect to watch for

**A set named for a procedure that contains only a diagnosis code.** It looks
like a procedure shortcut and does nothing but attach a diagnosis, so the order
is never placed and somebody redoes the work by hand downstream. The checker
warns on it, both when the name implies a procedure and no procedure item exists,
and when a set contains nothing but diagnosis codes.

If a set really is only a diagnosis attachment, make it a diagnosis set, so the
name says what it does.

### Every set is numbered

No unnumbered named sets. They sort away from the catalog and cannot be
referenced.

---

## 10. Field reference

Anything not listed here is rejected by name. Fields in **bold** are required.

### `templates`

| Field | Notes |
|---|---|
| `key` | your short handle for cross-references; defaults to the name |
| **`name`** | shown in the picker; no side, no level, no date |
| **`section_key`** | `reason` `vitals` `allergies` `medications` `problems` `family_hx` `social_hx` `surgical_hx` `past_medical_hx` `screening` `hpi` `ros` `pe` `assessment_plan` `orders` `procedures` `time_spent` `patient_instructions` `discussion_notes` `follow_up` |
| `specialty`, `body_region` | free text, used for grouping |
| `owner_scope` | `system` · `practice` · `provider` (default `practice`) |
| `is_active` | `true` / `false`; `false` retires it without touching historical notes |
| `applies_to_sex` | `any` · `male` · `female` — set it on sex-specific content |
| `applies_to_age_range` | free text, e.g. `18+` |
| `elements` | list, below |
| `notes` | anything you want the reviewer to read |

### `templates[].elements`

| Field | Notes |
|---|---|
| **`label`** | the row heading |
| `sort_order` | integer; order in the note is this order |
| `allows_multi` | `true` (default) if more than one option may be picked |
| `render_prefix` | text the note puts before the list |
| `vocabulary` | a shared scale (§4); use **instead of** `options` |
| `options` | list, below; use **instead of** `vocabulary` |
| `side` | `right` · `left` · `bilateral` for a per-side element |
| `pair_key` | the same value on both sides of a pair |
| `notes` | |

### `templates[].elements[].options`

| Field | Notes |
|---|---|
| **`label`** | the chip text; put `__` where a value is typed |
| `polarity` | `positive` · `negative` · `neutral` — **read §2** |
| `sort_order` | integer |
| `value_slots` | list of `{type, min, max, suffix, unit}`; `type` is `numeric` `text` `date` `duration` `percent` |
| `excludes` | option labels in the same element that cannot be picked with this one |
| `notes` | |

An option may also be written as a bare string when it needs nothing else:
`options: [low back, buttock, hip]`. Polarity then defaults to `neutral`, so
spell out any option that is an absence.

### `saved_findings`

| Field | Notes |
|---|---|
| `key` | your short handle |
| **`name`** | no side in the name |
| **`section_key`** | as above |
| `owner_scope`, `is_active` | as above |
| `laterality` | `right` · `left` · `bilateral` · `n/a` (default) — leave it out unless the finding is inherently one-sided |
| **`templates`** | list of `{template, sort_order}`, or bare handles |
| `presets` | list, below |
| `notes` | |

### `saved_findings[].presets`

| Field | Notes |
|---|---|
| **`template`** | the handle or name of a template this finding bundles |
| **`element`** | an element label in that template, spelled exactly |
| `option` | an option label in that element, spelled exactly |
| `value_text`, `value_numeric` | for options with a value slot |
| `laterality` | `right` · `left` · `bilateral` · `n/a` |
| `anatomic_level` | `L4`, `S1`, `T12` |

### `diagnosis_sets`

| Field | Notes |
|---|---|
| **`code`** | quoted; unique across diagnosis sets and order sets |
| **`name`** | no side, no level |
| **`presentation_mode`** | `pick_list` · `insert_all` |
| `laterality_param` | `true` for one set with a side selector — see §7 |
| `specialty`, `body_region`, `owner_scope`, `is_active` | as above |
| **`items`** | list, below |
| `notes` | |

### `diagnosis_sets[].items`

| Field | Notes |
|---|---|
| **`icd10_code`** | e.g. `M54.16` |
| **`display_name`** | what the provider reads |
| `laterality` | `right` · `left` · `bilateral` · `n/a` |
| `anatomic_level` | |
| `default_plan_text` | starting narrative for the plan box |
| `default_selected` | `true` inserts it, `false` offers it (default) |
| `sort_order` | |
| `linked_order_set` | the `code` of an order set, quoted |
| `linked_education` | list of education handles |
| `notes` | |

### `plan_groups`

| Field | Notes |
|---|---|
| `key` | your short handle |
| **`name`** | the problem, e.g. `lumbar radiculopathy` |
| `is_active` | |
| **`codes`** | every ICD-10 code that means this problem |
| `options` | list, below |
| `notes` | |

### `plan_groups[].options`

| Field | Notes |
|---|---|
| **`label`** | what the provider ticks |
| `sort_order` | |
| `narrative_template` | the plan text; `@PARAM@` inserts a parameter |
| `creates_order_type` | handle of the order this places |
| `sets_followup` | `true` if picking it sets the follow-up interval |
| `queues_consent` | handle of a consent to queue |
| `parameters` | list of `{key, options \| vocabulary, required, default}` |
| `notes` | |

### `order_sets`

| Field | Notes |
|---|---|
| **`code`** | quoted; unique across diagnosis sets and order sets |
| **`name`** | no side, no level |
| `is_active` | |
| **`items`** | list of `{item_type, reference, display_name, laterality, anatomic_level, default_quantity, sort_order}` |
| `notes` | |

---

## 11. The checkers, and what each one means

```
php bin/validate-library.php my-file.yaml           # everything
php bin/validate-library.php library/ --errors-only # just the blockers
php bin/validate-library.php my-file.yaml --only SYMMETRY,MIRROR
php bin/validate-library.php --list                 # what each one does
```

An **error** blocks the import. A **warning** does not, but every warning is
something a person should look at and decide. `--strict` makes warnings block
too; use it on a batch you believe is finished.

| Code | What it means |
|---|---|
| `STRUCTURE` | The file's shape: a misspelled field name, a value outside its list, a required field missing, an invented field. |
| `SYMMETRY` | Paired right/left elements whose option sets differ, or a side with no counterpart. Names the exact options that differ. |
| `MIRROR` | An entry or an option that exists for one side and not the other. Also flags a complete right/left pair that should be one entry with a side attribute. |
| `LAT_IN_NAME` | A name embedding Right, Left, Bilateral, R, L or B — or a level. Tells you what to rename it to. |
| `DUP_CODE` | One code on more than one set. Says whether they look like a side pair that should be merged. |
| `VOCAB` | An element or parameter retyping a shared vocabulary, naming the options it is missing; or referencing one that does not exist. |
| `POLARITY` | A likely descriptor/absence mis-tag — see §2. Always a warning; always worth reading. |
| `SPELLING` | A word in no dictionary. Error in a name, warning in option text. If the word is right, add it to `data/clinical-words.txt`. |
| `NEAR_DUP` | Two entries whose names are the same, overlapping, or near-identical; and names with a date in them. |
| `ICD10` | Code format, "unspecified" codes where a side is known, a code's side disagreeing with the item's, a letter O for a zero, a set and its linked order set carrying different subdivisions of one category, and codes no plan group covers. |
| `BUNDLE` | A saved finding bundling more than its name claims, sex-specific content that is not declared, a bundled template that does not exist, or a preset that would apply nothing. |
| `INTEGRITY` | A set named for a procedure with no procedure item; a preset pointing outside its bundle; an active entry depending on a retired one; an option promising a blank with no value slot; monitoring fields in a general template. |

If a checker is wrong about your content, say so rather than working around it.
A false positive we can narrow is worth more than a rule you have learned to
ignore.

---

## 12. Importing and exporting

**Import** — validates first, in full, then loads everything in one transaction:

```
php bin/import-library.php library/ --dsn="mysql:host=localhost;dbname=openemr" --user=USER --pass=PASS
php bin/import-library.php library/ --dsn="..." --dry-run    # rehearse it
```

One error and nothing is written. Any failure part-way through rolls the whole
import back: the database is either the library before the import or the library
after it, never half of each. A code or a name that already exists is reported as
a conflict, never merged.

The import prints a **NOT PERSISTED** section listing values your file carries
for which no database column exists yet, and writes them to
`deferred-fields.json`. Those are not mistakes on your part — keep writing them.
The gaps are listed in `NOTES.md`, and the YAML stays the source of truth until
the columns exist.

**Export** — writes the library back out in this format, for review or for
re-import:

```
php bin/export-library.php --dsn="..." --out=review.yaml
```

**Audit** — for every saved finding, what it bundles and what it would document:

```
php bin/bundle-audit.php library/
php bin/bundle-audit.php library/ --flagged
php bin/bundle-audit.php library/ --csv > bundles.csv
```

**Tests** — if you change a tool or a word list, run them:

```
php tests/run-tests.php
```

`tests/fixtures/pass/clean-library.yaml` is a complete, clean library covering
every entry type. It is the best thing to copy from when you start a batch.
`tests/fixtures/fail/` holds one file per checker, each a worked example of the
defect it catches, with a comment explaining why it matters.
