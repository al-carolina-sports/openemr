# WP-03 — Note renderer

Findings in, rendered note text out. A pure library: no database access, no
OpenEMR dependencies, no framework, no Composer install, no clock, no globals.
Everything it renders is passed to it.

Specification: document 06 (*Gateless Encounter Documentation — Implementation
Spec*) §5 in full, with §1.3 defining the `finding` structure consumed and §5.6
the reference note that must be producible.

```
php tests/run.php     # 52 assertions, no dependencies
```

---

## 1. Layout

```
autoload.php                  PSR-4 autoloader for Aem\Renderer (delete if the host has Composer)
src/
  Contracts/Finding.php       the `finding` row of 06 §1.3, column for column
  Contracts/OptionDef.php     resolved `template_option` descriptor (caller-supplied)
  Contracts/ElementDef.php    resolved `template_element` descriptor (caller-supplied)
  Contracts/PatientContext.php  name, sex, age, stated pronouns
  Contracts/Section.php       an `encounter_section` plus its findings
  Contracts/TextSection.php   a note section owned by another package (vitals, A/P, orders …)
  TemplateMap.php             the element/option lookup the caller hands in
  RenderOptions.php           presentation options (not schema)
  Ordering.php                the deterministic sort (§5.5)
  LabelResolver.php           finding + option label -> clause text; value slots; level aggregation
  StemCollapser.php           stem collapsing (§5.3) — the core behaviour
  ListModeRenderer.php        list mode, two styles (§5.2 and the §5.6 exam layout)
  ParagraphModeRenderer.php   paragraph mode: pronouns, polarity grouping, stems (§5.3)
  SectionRenderer.php         dispatch on render_mode, append the NOTE row
  NoteRenderer.php            whole-note assembly (§5.6), one-note rule (§5.7)
fixtures/
  ReferenceEncounter.php      the §5.6 fixture: HPI, ROS and physical exam
  reference/*.txt             the reference text, extracted verbatim from doc 06 §5.6
tests/
  run.php, Harness.php, UnitTest.php, ReferenceNoteTest.php
```

Nothing here reads or writes storage. The AI layer (WP-14) can call it with
findings it generated and never touched the database with.

---

## 2. Public API

```php
require __DIR__ . '/autoload.php';

use Aem\Renderer\{NoteRenderer, SectionRenderer, TemplateMap, RenderOptions};
use Aem\Renderer\Contracts\{Finding, ElementDef, OptionDef, PatientContext, Section, TextSection};

$map = new TemplateMap($elementDefs, $optionDefs);   // arrays or value objects
$patient = new PatientContext(sex: 'M', age: 36, pronouns: 'he/him');

// One section
$sections = new SectionRenderer($map, $patient);
$text = $sections->render($section);        // heading + body
$body = $sections->renderBody($section);    // body only

// The whole note
$note = (new NoteRenderer($map, $patient))->render(
    ['PATIENT' => '…', 'VISIT' => '…', 'PROVIDER' => '…', 'INSURANCE' => '…'],
    [$chiefComplaint, $vitals, …, $hpiSection, $rosSection, $peSection, …],
    'Alvin K. Antony, MD · 11/14/2026 22:10'
);
```

Every renderer is also usable on its own — `ListModeRenderer::render(array
$findings): string` and `ParagraphModeRenderer::render(array $findings):
string` both take a flat list of findings and return text.

### Error behaviour

The renderer raises rather than guessing:

| Condition | Exception |
|---|---|
| `Finding::fromArray()` given a column not in 06 §1.3 | `InvalidArgumentException` |
| A finding references an `option_id` or `element_id` with no descriptor | `OutOfBoundsException` |
| Two sections in one note with duplicate or near-duplicate headings (§5.7) | `LogicException` |

---

## 3. Input structure

### 3.1 `finding` — exactly 06 §1.3, no additions

`id`, `encounter_section_id`, `template_id`, `element_id`, `option_id`,
`value_text`, `value_numeric`, `value_unit`, `laterality`, `anatomic_level`,
`polarity`, `sort_order`, `recorded_by`, `recorded_at`.

`Finding::fromArray()` rejects any other key by name, so a future agent cannot
quietly widen the schema through this package.

### 3.2 Resolved descriptors

A `finding` row carries `option_id` but not the option's label, and the
renderer cannot query. The caller therefore supplies a `TemplateMap` of:

* `OptionDef` — `id`, `element_id`, `label`, `polarity`, `sort_order`
  (all 06 §1.4 columns of `template_option`)
* `ElementDef` — `id`, `template_id`, `label`, `sort_order`, `allows_multi`,
  `render_prefix` (all 06 §1.4 columns of `template_element`), plus
  `group_label` (see §6 below).

These are read-only inputs. They are not storage and they do not extend any
table.

### 3.3 Value slots

06 §1.4 stores value slots in `template_option.value_slots`. This package does
not interpret that JSON; it expects the slot to appear in the option's `label`
as a placeholder, which keeps the slot's position in the sentence under the
content author's control rather than the renderer's:

| Placeholder | Filled from |
|---|---|
| `{numeric}` | `finding.value_numeric` |
| `{unit}` | `finding.value_unit` |
| `{text}` | `finding.value_text` |
| `{level}` | `finding.anatomic_level` |
| `{levels}` | the aggregated levels of a collapsed level group |
| `{lat}` | `finding.laterality` → `right` / `left` / `bilaterally` / `` |

So `current pain level {numeric}/10` with `value_numeric = 6` renders
`current pain level 6/10`. A null column renders as nothing and the surrounding
whitespace and punctuation are normalised, so a missing value never prints
`{numeric}`.

A finding with no `option_id` renders `value_text` verbatim (free entry).

### 3.4 `PatientContext`

`display_name`, `sex`, `age`, `pronouns`, `record_id`, `dob`, `subject_noun`
(default `Patient`). Stated `pronouns` win over `sex`; an absent or
unrecognised sex gives the neutral set with plural verb agreement. Nothing is
inferred from the patient's name.

---

## 4. The behaviours

### 4.1 List mode (§5.2)

`Element: value; value; value`, one element per line:

```
Location: radiating to the RLE; LBP; right buttock pain
Severity: current pain level 6/10; worst pain 8/10; interference with sleep
```

The element prefix is `render_prefix`, falling back to `label`; a null
`render_prefix` emits the clause bare.

### 4.2 Exam list style (§5.6)

The §5.6 physical exam is not the §5.2 layout: it groups elements under a
region heading, separates values with commas, terminates each element as a
sentence, and applies stem collapsing. That is selected with
`RenderOptions::$listStyle = LIST_EXAM`, which `SectionRenderer` turns on
automatically for `section_key = 'pe'`. `encounter_section.render_mode` stays
the two-value enum the spec defines; the style is a renderer option, not a
column.

Within one element, segments are joined with `, ` — or with `; ` when one of
them is a collapsed multi-item cluster, so that commas are not doing two jobs
at once. That is what produces §5.6's
`no induration, ecchymosis, or swelling; normal alignment`.

### 4.3 Stem collapsing (§5.3) — the core behaviour

Repeated findings in one element that share a leading stem become one clause:

```
no tenderness of the spinous process       no tenderness of the spinous process,
no tenderness of the facet joints       →  the facet joints, the sacroiliac
no tenderness of the sacroiliac joint(s)   joint(s), or the coccyx
no tenderness of the coccyx
```

Rules:

1. Collapsing works **only within one element**, and only across a maximal run
   of **consecutive items of the same polarity** in the already-decided render
   order. It never reorders, and it never crosses an element boundary.
2. The stem is the longest common leading word sequence of the run, **with
   trailing determiners (`the`, `a`, `an`) handed back to the tails**. This is
   what gives `no tenderness of` + `the spinous process` rather than
   `no tenderness of the` + `spinous process`, matching §5.6 exactly.
3. The conjunction before the last tail is **`or` when the run is negative**
   and **`and` otherwise**, with an Oxford comma on three or more tails and no
   comma on two.
4. Thresholds: a stem of ≥ `minStemWords` words collapses a run of ≥ 2 items;
   a shorter stem — in practice the bare negation particle `no` — needs
   ≥ `minItemsForShortStem` items. See ambiguity 3 below for why these differ
   between prose and exam output.
5. An item that is exactly the stem blocks the collapse, rather than emitting a
   dangling clause.

### 4.4 Pronoun agreement (§5.3)

Resolution order: stated `pronouns` → `sex` → neutral. The subject of the
**first** sentence of a paragraph section is the subject noun (`Patient`); every
later sentence uses the subject pronoun, as §5.6 does (`Patient reports … He
reports …`). A noun subject always takes the third-person singular verb, so a
patient whose pronouns are *they/them* renders `Patient reports … They report
…`. Turn this off with `RenderOptions::$nounThenPronoun = false` to use the
pronoun throughout.

### 4.5 Polarity grouping (§5.3)

Paragraph mode renders one sentence per element:

```
<Subject> <verb> <positives, joined "and"> but <verb> <the rest, joined "and">.
```

Positives come first. The second group is negatives **and neutrals together**,
in sort order (ambiguity 4 below). List mode does not regroup at all — polarity
grouping is a paragraph-mode rule, and §5.6's exam output depends on list mode
preserving template order.

### 4.6 Ordering (§5.5) — the documented rule

Findings are sorted by the tuple

```
(element.sort_order, element_id, KEY, original array index)
```

where `KEY` is `template_option.sort_order` when
`RenderOptions::$orderBy = 'option'` (the default, per §5.5) and
`finding.sort_order` when it is `'entry'`.

* `element.sort_order` groups the note by template element, in template order.
* `element_id` breaks ties between elements sharing a sort order, so two
  templates applied to one section cannot interleave unpredictably.
* The original array index is the final tiebreak, which makes the sort stable:
  equal keys keep the caller's order. Stability is enforced explicitly rather
  than relying on PHP's sort being stable.

**Polarity never participates in this sort.** Paragraph mode partitions the
already-ordered findings afterwards. Nothing anywhere else in the package
reorders findings. Acceptance test 19 (chips entered out of template order) is
covered in `tests/UnitTest.php`.

### 4.7 Level aggregation

Consecutive findings sharing `element_id`, `option_id`, `polarity` and
`laterality` and differing only in `anatomic_level` collapse into one clause,
with the levels joined into `{levels}`. Four motor-strength rows become
`L1, L2–L4, L5, S1 — 5/5 right and left`. Disable with
`RenderOptions::$aggregateLevels = false`.

The renderer does **not** synthesise level ranges: `L2–L4` is the value stored
in `finding.anatomic_level`, not something inferred from `L2`, `L3`, `L4`.
Collapsing a run of adjacent levels into a range would be a clinical judgement,
not a formatting one.

### 4.8 Section and note assembly (§5.6, §5.7)

`SectionRenderer` dispatches on `render_mode` (defaults from §5.1: HPI list,
ROS paragraph, PE list) and appends the section's free-text NOTE row (§3.3) as
a final `Note:` line. A note whose `note_visibility` is `internal` (§6.4) is
withheld unless the caller constructs the renderer with
`$includeInternalNotes = true`.

`NoteRenderer` assembles the header block, the sections in the order given, and
the `SIGNED` line. §5.7 is enforced here: **one** rendered note, always
complete, and a duplicate or near-duplicate heading raises rather than
producing athena's `Assessments — None recorded.` next to a populated
`Assessment / Plan`.

Sections not made of findings — vitals, allergies, medications, problems,
assessment/plan, orders, education, time, follow-up — are passed as
`TextSection` objects carrying already-composed lines. Those sections belong to
other work packages; this one supplies only the heading and the blank-line
discipline, so that the note is assembled in exactly one place.

### 4.9 Line wrapping

Default width 79 columns, hanging indent 0 (2 for exam groups), both
configurable; width 0 disables wrapping. 79 is derived from §5.6 itself, whose
longest HPI, ROS and exam lines are 79 characters.

---

## 5. Acceptance: how close the fixture gets to §5.6

`fixtures/ReferenceEncounter.php` builds the findings; `fixtures/reference/*.txt`
holds the reference text extracted verbatim from doc 06 so the expectation
cannot drift from the document.

| Section | Result |
|---|---|
| **HPI** (list mode) | **Byte-for-byte**, after the reference's triple-space line packing is undone (ambiguity 2) |
| **ROS** (paragraph mode) | **Byte-for-byte** for the three systems the walkthrough documents; the reference elides 12 more for which it gives no findings |
| **Physical exam** (exam list) | **Word-for-word identical**; 13 of 14 lines byte-identical. The one divergence is line breaking inside the Constitutional group (ambiguity 6) |
| Full note | Assembles in §5.6 order with matching headings, header column alignment and signature line; the non-findings sections are supplied as text by their owning packages |

The exact bytes the exam section emits are pinned in
`fixtures/reference/pe-as-rendered.txt` and asserted, so a wrapper regression
cannot hide behind the word-for-word comparison.

---

## 6. Fields the renderer needs that the schema does not define

Flagged here rather than added, because the schema belongs to another agent.
None of these is a column on `finding`; all are presentation inputs the caller
passes in, and the renderer works today without any schema change.

1. **`template_element.group_label`** — §5.6's exam output nests element
   prefixes under region headings (`Constitutional:` → `General Appearance:`,
   `Lumbar Spine:` → `Inspection:`). 06 §1.4 gives `template_element` only
   `label`, `sort_order`, `allows_multi` and `render_prefix`, so the outer level
   has nowhere to live. Accepted on `ElementDef` as `group_label`. The schema
   owner may prefer to derive it from `template.body_region`.
2. **Section template label and `reported by`** — §5.6 heads each section
   `HPI — Pain Management L-spine (reported by patient)`. `encounter_section`
   (06 §1.2) carries neither the template name nor the attribution; §3.3 does
   specify a per-template `Reported by` selector, so a column or a join is
   needed somewhere. Accepted on `Section` as `template_label` and
   `reported_by`.
3. **A "Previous PT" element** — §5.6's HPI contains one; §3.2's 19-element
   `Pain Management L-spine` table does not list it. Content question for WP-20,
   not a renderer one.
4. **Vitals, allergy, order and education section headings with counts** —
   `VITALS (11/14/2026 21:40)`, `ORDERS SIGNED (1)`,
   `PATIENT EDUCATION PROVIDED (4)   [not an order]`. Supplied by the owning
   package as `TextSection::$title_suffix`; the renderer counts nothing.

---

## 7. Resolved ambiguities

Where §5 and the §5.6 reference output disagree, the resolution is recorded
here rather than guessed silently. Each is a renderer option with a default, so
the integration owner can overrule any of them without touching the code.

1. **§5.2's list layout is not the layout §5.6's physical exam uses.** §5.2
   specifies `Element: value; value; value`; §5.6's exam uses region groups,
   comma separators, sentence termination and stem collapsing. Both are
   implemented, as `listStyle = LIST_SIMPLE` (the §5.2 contract, used for HPI)
   and `LIST_EXAM` (used for PE). `encounter_section.render_mode` is untouched.

2. **§5.6's HPI packs short elements onto shared lines**
   (`Prior Imaging: MRI   Prior EMG: none   Previous Surgery: none`, triple
   spaces). No rule in §5.2 describes this, and the packing in §5.6 is not
   self-consistent — `Work Related: no` (16 characters) sits on its own line
   while three longer elements share one. Not implemented: one element per
   line. The acceptance test unpacks the reference on `/ {3,}/` before
   comparing, and the comparison is then byte-exact.

3. **The stem-collapse threshold has to differ between prose and exam output.**
   §5.6 collapses three bare-`no` negatives in the exam
   (`no induration, ecchymosis, or swelling`) but leaves four bare-`no`
   negatives as four clauses in the ROS (`no soft tissue swelling, no joint
   swelling, no myalgia, and no trauma`) — and leaves two uncollapsed in the
   exam's `Gait and Station` (`normal gait, no limp, no assistive devices`).
   No single threshold satisfies all three. Resolution: a stem of ≥ 2 words
   always collapses a run of ≥ 2; a bare one-word stem collapses a run of ≥ 3
   in list/exam mode and never in paragraph mode. Prose wants each negation
   read as a full clause; a terse exam line does not. Both numbers are
   `RenderOptions` fields.

4. **§5.6's ROS violates §5.3's polarity grouping.** The Constitutional
   sentence reads `no significant weight change, good appetite, no fever, and
   no fatigue` — an apparent positive between negatives. Resolution:
   `good appetite` is a **neutral** descriptor, which §5.4 recognises as a
   third polarity, and the renderer groups positives first, then negatives and
   neutrals together in sort order. Hoisting neutrals with the positives would
   make §5.6 unreproducible. Disable grouping entirely with
   `polarityGrouping = false`.

5. **Polarity assignment is a content decision with punctuation consequences.**
   §1.3 says polarity is derived from the option definition, not guessed from
   wording, so the renderer takes it as given. But §5.6 only comes out if
   normal *descriptors* (`normal alignment`, `normal gait`) are `neutral` while
   asserted *absences* (`no swelling`, `no limp`) are `negative`: it is that
   boundary that breaks the collapse run and produces `no induration,
   ecchymosis, or swelling; normal alignment` instead of one clause spanning
   all four. WP-20 needs this convention when transcribing the library; the
   fixture documents it.

6. **§5.6's exam block is wrapped by two different rules.** The Constitutional
   group runs to 80 characters and continues at indent 0; every other group
   stays within 79 and continues at indent 2. Within the Lumbar Spine group,
   sentences are packed greedily, so the Constitutional break is not a
   sentence-boundary rule either. No single wrapper reproduces both.
   Resolution: one documented wrapper, greedy at 79 with a 2-space hanging
   indent, which reproduces every other line of §5.6's HPI, ROS and exam
   exactly. This is the only place the reference output cannot be met
   byte-for-byte, and the divergence is whitespace only.

7. **`or` under negation versus `and`.** §5.3 says "correct `or` before the
   last item under negation (`and` when positive)", but §5.6's ROS joins four
   negatives with `and`. Resolution: `or` applies to the **tails distributed
   under one collapsed negative stem** — one negated predicate over several
   objects, where `or` is what negation distributes to. Whole independent
   findings join with `and` whatever their polarity. This reproduces both
   strings.

---

## 8. Constraints observed

* No database access, no OpenEMR dependency, no framework, no Composer
  requirement, no clock, no superglobals. Findings in, text out.
* The `finding` structure is exactly 06 §1.3; `Finding::fromArray()` rejects
  any other column by name. Fields the renderer needed and did not have are
  listed in §6 above instead of invented.
* Nothing in this package names any third-party vendor product; a test asserts
  the absence of the forbidden string across every file, this README included.
* Synthetic patient data only.
* Pure boundary, for WP-14: the AI layer constructs `Finding` objects and a
  `TemplateMap` and calls `render()`. There is no other entry point, no state
  between calls, and no I/O.

## 9. Not in scope for this package

Polarity **colouring** (§5.4) is an editor concern, not a text one.
`finding.polarity` is carried through unmodified and every rendered segment
knows its polarity (`StemCollapser::collapse()` returns it per segment), so the
editor can colour from the same data without the renderer emitting markup. If
an HTML renderer is wanted later it should be a sibling class over the same
segment list, not an escape hatch in the text renderer.
