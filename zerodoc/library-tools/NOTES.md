# NOTES — schema gaps and open questions

**ZeroDoc Clinical Encounter Workflow** · library tooling (WP-06)

The schema is owned elsewhere. This package does not add columns, so anything the
import format needs and the schema does not have is recorded here instead, and
reported at import time rather than dropped quietly.

Each gap below is referenced by its code (`G1`…`G9`) from
`src/Import/Schema.php::DEFERRED`, and appears in the importer's
**NOT PERSISTED** report and in `deferred-fields.json`.

---

## Gaps in the schema as specified

### G1 — `template.applies_to_sex`, `template.applies_to_age_range`

**Needed by:** spec 06 §1.5 — "Templates **MUST** be sex- and age-filtered at
application time: a template whose `applies_to_sex` or `applies_to_age_range`
does not match the patient is skipped, and the skip is reported."

**Problem:** §1.4's `template` definition has neither column. The MUST in §1.5
names fields that §1.4 does not define.

**Impact:** without them, nothing stops a bundled breast, genitourinary or rectal
template documenting on a patient it does not apply to — the single most serious
defect in the catalogue. This is not a cosmetic gap; it is the gap the
requirement exists to close.

**Interim:** the format carries both fields, the `BUNDLE` validator uses
`applies_to_sex` to flag sex-specific content that declares `any`, and the
importer reports the values as not persisted. The filtering itself cannot be
implemented until the columns exist.

**Asks for:** two columns on `template` — `applies_to_sex` (enum
`any|male|female`) and `applies_to_age_range` (text or a min/max pair).

---

### G2 — no column records which vocabulary an element references

**Needed by:** spec 06 Appendix B — "Any element needing one of these **MUST**
reference the vocabulary rather than restating its options."

**Problem:** `template_element` has no `vocabulary` column, so the import expands
the vocabulary into `template_option` rows and the reference is lost. In the
database, an element that referenced `response_to_treatment` is indistinguishable
from one that retyped it.

**Impact:** the whole point of Appendix B is that the list is defined once.
Without the reference stored, adding a member to a vocabulary cannot propagate to
the elements using it, and the drift the requirement forbids returns through the
back door — one edit at a time, in the admin UI.

**Interim:** the format carries `vocabulary:` on the element; the `VOCAB`
validator enforces the MUST at transcription time; the importer expands the
options and reports the reference as not persisted; the exporter reconstructs it
by matching an element's stored options against the vocabularies. That
reconstruction is exact — an element whose options are precisely a vocabulary is
required to reference it, so there is no legal second interpretation — but it is
reconstruction, not storage.

**Asks for:** `template_element.vocabulary_key` (nullable text or an FK to a
vocabulary table). WP-00 already seeds Appendix B as reference data, so a table
may exist to point at.

---

### G3 — `template_element.side` and `template_element.pair_key`

**Needed by:** spec 06 §3.6 — a template with paired Right/Left elements MUST
fail validation if the option sets differ.

**Problem:** nothing on `template_element` expresses which side an element is, or
which element it pairs with. The only place a side can live today is the label or
`render_prefix` — as text.

**Impact:** the symmetry linter can run at transcription time, where it has the
YAML. It cannot run reliably against the database on save, which is where §3.6
asks for it, because pairing would have to be inferred from label text. §3.4
("laterality is never part of the name") and the symmetry linter pull in opposite
directions while the side is only expressible as text.

**Interim:** `side:` and `pair_key:` are format fields, used by the `SYMMETRY`
validator and reported as not persisted.

**Asks for:** `template_element.side` (enum, nullable) and
`template_element.pair_key` (text, nullable).

---

### G4 — `saved_finding.laterality`

**Needed by:** spec 06 §3.4 — "One `Hip Labral Tear` saved finding with a
`laterality` attribute, rendered as `Hip Labral Tear — Right`."

**Problem:** §1.5 puts `laterality` on `saved_finding_preset`, not on
`saved_finding`. So the side exists per pre-set answer but not for the finding as
a whole.

**Reading that may resolve it:** if the side is chosen when the finding is
applied, the entity needs no column — the laterality lands on the `finding` rows,
which do have one, and the library entry is genuinely side-neutral. That reading
is consistent with §3.4's intent and is what this format recommends (see
FORMAT.md §6: leave the side off).

**What is still missing on that reading:** a default. A finding that is
inherently one-sided has nowhere to say so.

**Interim:** `laterality:` is an optional format field, defaulting to `n/a`; the
`MIRROR` validator treats a finding that sets it as side-specific and looks for
the opposite side.

**Asks for:** a decision, not necessarily a column. If side-neutral is the
intent, say so in the contracts document, because the current schema reads as an
omission.

---

### G5 — `last_used_at` on `template` and `saved_finding`

**Needed by:** spec 06 §3.8 — "a `last_used_at` column with an unused-in-N-months
report".

**Problem:** not present in §1.4 or §1.5.

**Impact:** no retirement report. Stale entries accumulate, which is how a
library ends up with two dated versions of the same template, both live.

**Interim:** the format has no equivalent — usage is runtime data, not something
a transcriber can supply. Recorded here so the column is not forgotten. The
`NEAR_DUP` validator catches the symptom (dated and overlapping names) but not
the cause.

**Asks for:** `last_used_at` (timestamp, nullable) on both tables.

---

### G6 — nowhere to record a transcription note

**Needed by:** the capture rule "flag anything whose clinical intent is unclear
rather than guessing".

**Problem:** no table has a free-text note or provenance column for library
entries.

**Impact:** a transcriber's uncertainty has nowhere to travel. It is the most
valuable output of the content track after the content itself: "this is what the
source said, and I do not believe it" is exactly what a clinician needs to see.

**Interim:** `notes:` on every entry type, reported as not persisted and carried
in `deferred-fields.json`. The YAML files remain the record.

**Asks for:** `notes` (text, nullable) on `template`, `saved_finding`,
`diagnosis_set`, `order_set` and `plan_group` — or a single
`zdoc_library_annotation` table keyed by entity and id.

---

### G7 — `order_set_item.reference_id` is typed as an id but holds different kinds of thing

**Needed by:** spec 06 §4.3 — `order_set_item(… item_type enum
diagnosis|procedure|imaging|lab|referral|education|consent|medication,
reference_id, …)`.

**Problem:** for `item_type = 'diagnosis'` the reference is an ICD-10 code (a
string); for the rest it is a row in some catalog (procedure types, education
items, consent forms) — and several of those catalogs do not exist yet.
`reference_id` is one column serving both.

**Interim:** the format's `reference:` is text, the importer writes it to
`reference_id` as text, and the ICD-10 validator treats it as a code when
`item_type` is `diagnosis`. If `reference_id` is defined as an integer FK, the
importer needs a resolution step per item type, and every unresolved handle
becomes an import failure rather than a transcription note.

**Asks for:** confirmation that `reference_id` is text, or a documented
resolution table per `item_type`. This affects whether the content track can
invent handles now (currently assumed yes) or must wait for the catalogs.

The same question applies to `plan_option.creates_order_type_id` and
`plan_option.queues_consent_id`, and to `diagnosis_set_item.linked_education_ids`.

---

### G8 — `laterality_param` cannot work where the side is inside the code

**Needed by:** spec 08 §12.3.2 — one set with a side selector replaces the
right/left pair, "and the laterality flows to every code ticked".

**Problem:** in many musculoskeletal families the side *is* part of the ICD-10
code: `M75.101` right shoulder, `M75.102` left. A set-level side selector cannot
change a code. The mechanism works for codes that do not encode a side
(`M54.16`, `M51.26`) and is impossible for those that do.

**Impact:** the shoulder and knee pick lists — exactly the sets cited as the
motivating example — cannot use `laterality_param` as specified. They have to
list the side-specific codes separately, which doubles the candidate list.

**Interim:** the `ICD10` validator errors when `laterality_param: true` meets a
code that encodes a side, and tells the transcriber to list the codes
separately. FORMAT.md §7 documents both cases with worked examples.

**Asks for:** a per-item side-to-code map, so one candidate can resolve to
`M75.101` or `M75.102` at selection time. Something like
`diagnosis_set_item.code_right` / `code_left` / `code_bilateral`, or a child
table. Without it the design intent in §12.3.2 is only half achievable, and the
duplication it was meant to remove comes back as duplicate items instead of
duplicate sets.

---

### G9 — `order_set_item` has no `id`

**Problem:** §4.3 defines `order_set_item` with no primary key, unlike every
other child table in the spec. Probably an omission in the sketch rather than a
decision.

**Impact:** nothing can reference an item, and the exporter cannot give a stable
order beyond `sort_order`.

**Interim:** the test schema adds one. Flagged so the migration does too.

---

## Choices made inside this package

These are decisions, not gaps — recorded so they can be overruled.

**Set codes share one namespace.** `DUP_CODE` checks uniqueness across diagnosis
sets and order sets together, not per type, because the content being transcribed
numbers them from one catalog and the reuse to be caught crosses the two. If the
intent is two namespaces, the rule relaxes to per-type with a one-line change.

**Spelling errors on names, warnings elsewhere.** A name is referenced by
everything else and is what the picker shows; a typo there outlives the
transcription. Option text is prose and the dictionary cannot be complete, so it
warns. The fix for a false positive is one line in `data/clinical-words.txt`,
never silencing the rule.

**Polarity mis-tagging warns, never errors.** It is a judgement with real
exceptions ("nontender" is an absence without the word "no"). It is surfaced
every time and never auto-corrected, because a wrong automatic fix here changes
what the note asserts.

**Near-duplicate names warn.** Two similar names can be two legitimately similar
things. A reviewer decides.

**The word lists are data files, not code.** `data/english-words.txt` is the base
English list, `data/common-words.txt` patches its gaps,
`data/clinical-words.txt` is the clinical supplement, and
`data/local-words.txt` — create it if you need it — is for practice-local terms.
The base list holds stems only, so the checker strips one prefix and one suffix
to find a stem; that is deliberately permissive, because a checker that cries
wolf gets switched off and then the real typos ship.

**`data/icd10-reference.txt` is the practice's own code list, not an ICD-10
release.** A code that is well-formed but absent from it is reported as
*unverified*, never as wrong. Specificity and side checks only run against codes
in the file, so the file is worth extending as the library grows. A full ICD-10-CM
distribution would make the specificity checks complete; that is a decision about
shipping and licensing third-party data, not a code change.

**The YAML reader is hand-written and parses a deliberately narrow subset.** No
composer step, so the content track can run the validators on a bare PHP install
— which was the point of shipping the format early. The subset is exactly what
FORMAT.md tells people to write; anchors, aliases, tags and multiple documents are
rejected with a line number rather than mis-parsed. If a composer dependency
becomes acceptable, `src/Yaml/Yaml.php` can be swapped for a library behind the
same two methods.

**SQLite is used for the tests only.** `tests/schema.sqlite.sql` mirrors the spec
column for column so the importer, the exporter and the rollback can be tested
without a database server. It is not the production DDL and says so. If it and
the real migrations ever disagree, the migrations are right.

---

## Open questions for the integration owner

1. **G1 is blocking a MUST.** Sex and age filtering is required by §1.5 and has
   no columns. Everything else here can wait; this one lets sex-specific content
   document on the wrong patient.
2. **G8 changes the content work.** Whether a side-to-code map exists decides
   whether the shoulder and knee pick lists are transcribed as one set or two
   sets' worth of items. Worth settling before batch 1, since those sets are
   first in the queue.
3. **G7 decides whether handles can be invented now.** If `reference_id` is text,
   the content track can write consistent handles and map them later. If it is an
   integer FK, the order-set batch is blocked on the catalogs existing.
4. **G4 wants a sentence, not a column.** Is a saved finding side-neutral by
   design? The format assumes yes.
5. **Codes:** confirm one namespace for set codes, or two.
