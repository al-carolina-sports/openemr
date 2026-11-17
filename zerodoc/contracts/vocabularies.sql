-- =====================================================================
-- ZeroDoc Clinical Encounter Workflow
-- vocabularies.sql — SHARED VOCABULARY SEED  (WP-00a frozen contract)
-- =====================================================================
--
-- Source : doc 06 Appendix B, "vocabularies to define centrally".
-- Target : MySQL 5.7+ / MariaDB 10.2+
-- Runs   : AFTER schema.sql. Requires zdoc_vocabulary and
--          zdoc_vocabulary_item.
--
-- WHY THIS FILE EXISTS
--   "Shared option lists referenced by many templates. Defining these
--    once is what prevents per-element drift." (06 Appendix B)
--   The drift this prevents is concrete: in the system examined during
--   research, one element offered `helped significantly` while a sibling
--   element in the same template did not. Any element needing one of
--   these vocabularies MUST reference it via
--   zdoc_template_element.vocabulary_id rather than restating its
--   options. The WP-06 importer enforces this.
--
-- IDEMPOTENCY
--   Every insert is INSERT ... ON DUPLICATE KEY UPDATE against the
--   natural key (vocab_key / vocabulary_id+item_key). Re-running this
--   file refreshes labels, polarity, is_normal, numeric values and sort
--   orders and creates nothing twice. Item IDs are therefore stable
--   across re-runs and safe to reference from zdoc_template_option.
--
-- POLARITY AND is_normal — READ CONTRACTS.md §3.8 BEFORE EDITING
--   These are TWO INDEPENDENT facts and the distinction has real output
--   consequences.
--
--   `polarity` is a STRUCTURAL role. It drives paragraph grouping and
--   stem collapsing:
--     positive = an asserted PRESENCE          "tenderness", "sharp"
--     negative = an asserted ABSENCE           "no swelling", "0/10"
--     neutral  = a DESCRIPTOR, which INCLUDES
--                a normal descriptor           "normal alignment", "5/5"
--   Tagging a normal descriptor `negative` makes the renderer collapse
--   it into the preceding negated clause, producing text that reads as
--   a denial of something nobody denied. That is the specific defect
--   this convention exists to prevent.
--
--   `is_normal` is CLINICAL normality. It drives the Normal / All Normal
--   controls and, combined with polarity, the editor colour. A normal
--   descriptor is `polarity = neutral` AND `is_normal = 1`.
--
--   Consequence inside this file: `2+` reflexes, `5/5` strength and
--   `normal` sensation are neutral with is_normal = 1, NOT negative. An
--   asserted absence such as `0/10` pain or a `negative` provocative
--   test is negative with is_normal = 1.
--
-- SORT ORDER
--   sort_order is the authoritative render order (06 §5.5). It is
--   clinically ordered, not alphabetical, and it is stable: an agent
--   adding an item appends rather than renumbering, so no existing
--   note's rendering changes.
--
-- COMPLETENESS
--   All twelve vocabularies named in 06 Appendix B are seeded:
--     response_to_treatment, laterality, severity_scale, frequency,
--     onset, pain_quality, reflex_grade, motor_grade, sensation,
--     special_test_result, lumbar_levels, cervical_levels
--   See OPEN-QUESTIONS.md Q16-Q19 and Q23 for the judgment calls made.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Vocabulary headers
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES
  ('response_to_treatment','Response to treatment',
   'Shared response scale. Every element asking how a prior treatment worked MUST use this, so no element is missing a step (06 §3.2). All items are descriptor answers: polarity neutral.',
   'ordinal',1,NOW(),NOW()),

  ('laterality','Laterality',
   'Structural side attribute. Laterality is NEVER part of a name (06 §3.4). Mirrors are structurally impossible to omit.',
   'nominal',1,NOW(),NOW()),

  ('severity_scale','Severity scale (0-10)',
   'Integer numeric rating scale, 0 through 10. Used for current pain, worst pain and any other 0-10 slot. 0 is an asserted absence.',
   'integer_range',1,NOW(),NOW()),

  ('frequency','Frequency',
   'Temporal pattern of a symptom. Descriptors of a symptom asserted elsewhere: polarity neutral.',
   'nominal',1,NOW(),NOW()),

  ('onset','Onset',
   'How the symptom began. Descriptors: polarity neutral.',
   'nominal',1,NOW(),NOW()),

  ('pain_quality','Pain quality',
   'Descriptive character of pain. These ASSERT the presence of a quality, so polarity positive.',
   'nominal',1,NOW(),NOW()),

  ('reflex_grade','Reflex grade',
   'Deep tendon reflex grading. 2+ is the normal value and is a descriptor (neutral, is_normal = 1), not an asserted absence.',
   'ordinal',1,NOW(),NOW()),

  ('motor_grade','Motor strength grade',
   'Manual muscle testing, 0/5 through 5/5. 5/5 is the normal value and is a descriptor (neutral, is_normal = 1).',
   'ordinal',1,NOW(),NOW()),

  ('sensation','Sensation',
   'Sensory examination result. "normal" is a descriptor (neutral, is_normal = 1); decreased, absent and hyperesthetic assert a finding.',
   'ordinal',1,NOW(),NOW()),

  ('special_test_result','Special test result',
   'Provocative test outcome. "negative" IS an asserted absence. "not performed" is a first-class value, distinct from an absent finding, and asserts nothing about the patient.',
   'nominal',1,NOW(),NOW()),

  ('lumbar_levels','Lumbar and thoracolumbar levels',
   'Anatomical level qualifier for the lumbar region, T12 through S2 (06 §3.5). Referenced by zdoc_template_option.anatomic_level_vocabulary_id.',
   'ordinal',1,NOW(),NOW()),

  ('cervical_levels','Cervical levels',
   'Anatomical level qualifier for the cervical region, C2 through C8 (06 §3.5).',
   'ordinal',1,NOW(),NOW())
ON DUPLICATE KEY UPDATE
  `name`        = VALUES(`name`),
  `description` = VALUES(`description`),
  `value_kind`  = VALUES(`value_kind`),
  `is_active`   = VALUES(`is_active`),
  `updated_at`  = NOW();


-- ---------------------------------------------------------------------
-- 2. response_to_treatment
--    06 Appendix B: did not help | helped a little | helped temporarily
--                   | helped significantly | aggravated symptoms
--    Ordered worst to best, with "aggravated symptoms" below "did not
--    help" because it is worse than no effect.
--    ALL NEUTRAL: each is a descriptor answer, not an assertion that a
--    finding is present or absent. See OPEN-QUESTIONS Q23.
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, t.`polarity`, t.`is_normal`, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'aggravated_symptoms' AS item_key, 'aggravated symptoms' AS label, 'neutral' AS polarity, 0 AS is_normal, -1 AS numeric_value, 10 AS sort_order
  UNION ALL SELECT 'did_not_help',         'did not help',         'neutral', 0, 0, 20
  UNION ALL SELECT 'helped_a_little',      'helped a little',      'neutral', 0, 1, 30
  UNION ALL SELECT 'helped_temporarily',   'helped temporarily',   'neutral', 0, 2, 40
  UNION ALL SELECT 'helped_significantly', 'helped significantly', 'neutral', 0, 3, 50
) t
WHERE v.`vocab_key` = 'response_to_treatment'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;


-- ---------------------------------------------------------------------
-- 3. laterality
--    06 Appendix B: right | left | bilateral | n/a
--    All neutral: a side carries no normal-abnormal valence.
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, 'neutral', 0, NULL, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'right' AS item_key, 'right' AS label, 10 AS sort_order
  UNION ALL SELECT 'left',      'left',      20
  UNION ALL SELECT 'bilateral', 'bilateral', 30
  UNION ALL SELECT 'n/a',       'n/a',       40
) t
WHERE v.`vocab_key` = 'laterality'
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `polarity`   = VALUES(`polarity`),
  `is_normal`  = VALUES(`is_normal`),
  `sort_order` = VALUES(`sort_order`),
  `is_active`  = 1;


-- ---------------------------------------------------------------------
-- 4. severity_scale
--    06 Appendix B: 0..10 integer.
--    Materialised as eleven items so the picker, the renderer and the
--    importer all read one source. numeric_value carries the integer;
--    label carries the rendered form.
--    0 is an ASSERTED ABSENCE of pain -> negative, is_normal = 1.
--    1-10 affirm the symptom -> positive. See OPEN-QUESTIONS Q16.
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, t.`polarity`, t.`is_normal`, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT '0' AS item_key, '0/10' AS label, 'negative' AS polarity, 1 AS is_normal, 0 AS numeric_value, 0 AS sort_order
  UNION ALL SELECT '1',  '1/10',  'positive', 0,  1,  10
  UNION ALL SELECT '2',  '2/10',  'positive', 0,  2,  20
  UNION ALL SELECT '3',  '3/10',  'positive', 0,  3,  30
  UNION ALL SELECT '4',  '4/10',  'positive', 0,  4,  40
  UNION ALL SELECT '5',  '5/10',  'positive', 0,  5,  50
  UNION ALL SELECT '6',  '6/10',  'positive', 0,  6,  60
  UNION ALL SELECT '7',  '7/10',  'positive', 0,  7,  70
  UNION ALL SELECT '8',  '8/10',  'positive', 0,  8,  80
  UNION ALL SELECT '9',  '9/10',  'positive', 0,  9,  90
  UNION ALL SELECT '10', '10/10', 'positive', 0, 10, 100
) t
WHERE v.`vocab_key` = 'severity_scale'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;


-- ---------------------------------------------------------------------
-- 5. frequency
--    06 Appendix B: constant | intermittent | nocturnal
--    Descriptors of a symptom asserted elsewhere -> neutral.
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, 'neutral', 0, NULL, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'constant' AS item_key, 'constant' AS label, 10 AS sort_order
  UNION ALL SELECT 'intermittent', 'intermittent', 20
  UNION ALL SELECT 'nocturnal',    'nocturnal',    30
) t
WHERE v.`vocab_key` = 'frequency'
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `polarity`   = VALUES(`polarity`),
  `is_normal`  = VALUES(`is_normal`),
  `sort_order` = VALUES(`sort_order`),
  `is_active`  = 1;


-- ---------------------------------------------------------------------
-- 6. onset
--    06 Appendix B: sudden | gradual | chronic
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, 'neutral', 0, NULL, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'sudden' AS item_key, 'sudden' AS label, 10 AS sort_order
  UNION ALL SELECT 'gradual', 'gradual', 20
  UNION ALL SELECT 'chronic', 'chronic', 30
) t
WHERE v.`vocab_key` = 'onset'
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `polarity`   = VALUES(`polarity`),
  `is_normal`  = VALUES(`is_normal`),
  `sort_order` = VALUES(`sort_order`),
  `is_active`  = 1;


-- ---------------------------------------------------------------------
-- 7. pain_quality
--    06 Appendix B: throbbing | tightness | numbness | burning |
--                   aching | cramping | sharp | dull | tingling
--    Order preserved exactly as the spec lists it, so the rendered
--    order matches the reference note.
--    These ASSERT a quality is present -> positive.
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, 'positive', 0, NULL, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'throbbing' AS item_key, 'throbbing' AS label, 10 AS sort_order
  UNION ALL SELECT 'tightness', 'tightness', 20
  UNION ALL SELECT 'numbness',  'numbness',  30
  UNION ALL SELECT 'burning',   'burning',   40
  UNION ALL SELECT 'aching',    'aching',    50
  UNION ALL SELECT 'cramping',  'cramping',  60
  UNION ALL SELECT 'sharp',     'sharp',     70
  UNION ALL SELECT 'dull',      'dull',      80
  UNION ALL SELECT 'tingling',  'tingling',  90
) t
WHERE v.`vocab_key` = 'pain_quality'
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `polarity`   = VALUES(`polarity`),
  `is_normal`  = VALUES(`is_normal`),
  `sort_order` = VALUES(`sort_order`),
  `is_active`  = 1;


-- ---------------------------------------------------------------------
-- 8. reflex_grade
--    06 Appendix B: absent | 1+ | 2+ | 3+ | 4+
--    2+ is the normal value. It is a DESCRIPTOR, not an asserted
--    absence: neutral + is_normal = 1. Every other grade asserts an
--    abnormal finding -> positive. See OPEN-QUESTIONS Q17.
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, t.`polarity`, t.`is_normal`, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'absent' AS item_key, 'absent' AS label, 'positive' AS polarity, 0 AS is_normal, 0 AS numeric_value, 10 AS sort_order
  UNION ALL SELECT '1plus', '1+', 'positive', 0, 1, 20
  UNION ALL SELECT '2plus', '2+', 'neutral',  1, 2, 30
  UNION ALL SELECT '3plus', '3+', 'positive', 0, 3, 40
  UNION ALL SELECT '4plus', '4+', 'positive', 0, 4, 50
) t
WHERE v.`vocab_key` = 'reflex_grade'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;


-- ---------------------------------------------------------------------
-- 9. motor_grade
--    06 Appendix B: 0/5 .. 5/5
--    5/5 is the normal value and a DESCRIPTOR: neutral + is_normal = 1.
--    This matters for the reference output "Motor Strength: L1, L2-L4,
--    L5, S1 - 5/5 right and left." which must NOT collapse into a
--    negated clause. See OPEN-QUESTIONS Q18 on half-grades.
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, t.`polarity`, t.`is_normal`, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT '0_5' AS item_key, '0/5' AS label, 'positive' AS polarity, 0 AS is_normal, 0 AS numeric_value, 10 AS sort_order
  UNION ALL SELECT '1_5', '1/5', 'positive', 0, 1, 20
  UNION ALL SELECT '2_5', '2/5', 'positive', 0, 2, 30
  UNION ALL SELECT '3_5', '3/5', 'positive', 0, 3, 40
  UNION ALL SELECT '4_5', '4/5', 'positive', 0, 4, 50
  UNION ALL SELECT '5_5', '5/5', 'neutral',  1, 5, 60
) t
WHERE v.`vocab_key` = 'motor_grade'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;


-- ---------------------------------------------------------------------
-- 10. sensation
--     06 Appendix B: normal | decreased | absent | hyperesthetic
--     "normal" is a DESCRIPTOR: neutral + is_normal = 1. This is what
--     makes "Sensation Right: normal median nerve distribution." render
--     as a statement rather than collapsing into a denial.
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, t.`polarity`, t.`is_normal`, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'normal' AS item_key, 'normal' AS label, 'neutral' AS polarity, 1 AS is_normal, 0 AS numeric_value, 10 AS sort_order
  UNION ALL SELECT 'decreased',     'decreased',     'positive', 0, 1, 20
  UNION ALL SELECT 'absent',        'absent',        'positive', 0, 2, 30
  UNION ALL SELECT 'hyperesthetic', 'hyperesthetic', 'positive', 0, 3, 40
) t
WHERE v.`vocab_key` = 'sensation'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;


-- ---------------------------------------------------------------------
-- 11. special_test_result
--     06 Appendix B: positive | negative | not performed
--     "negative" IS an asserted absence -> negative, is_normal = 1, so
--     "Tinel's and Phalen's negative" collapses correctly.
--     "not performed" asserts nothing about the patient -> neutral.
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, t.`polarity`, t.`is_normal`, NULL, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'positive' AS item_key, 'positive' AS label, 'positive' AS polarity, 0 AS is_normal, 10 AS sort_order
  UNION ALL SELECT 'negative',      'negative',      'negative', 1, 20
  UNION ALL SELECT 'not_performed', 'not performed', 'neutral',  0, 30
) t
WHERE v.`vocab_key` = 'special_test_result'
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `polarity`   = VALUES(`polarity`),
  `is_normal`  = VALUES(`is_normal`),
  `sort_order` = VALUES(`sort_order`),
  `is_active`  = 1;


-- ---------------------------------------------------------------------
-- 12. lumbar_levels
--     06 Appendix B: T12 | L1 | L2 | L3 | L4 | L5 | S1 | S2
--     numeric_value is a cranio-caudal ordinal so ranges ("T12-S2")
--     and sorting work without string parsing.
--     Consumed via zdoc_template_option.anatomic_level_vocabulary_id;
--     the chosen item_key is stored in zdoc_finding.anatomic_level.
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, 'neutral', 0, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'T12' AS item_key, 'T12' AS label, 12 AS numeric_value, 10 AS sort_order
  UNION ALL SELECT 'L1', 'L1', 13, 20
  UNION ALL SELECT 'L2', 'L2', 14, 30
  UNION ALL SELECT 'L3', 'L3', 15, 40
  UNION ALL SELECT 'L4', 'L4', 16, 50
  UNION ALL SELECT 'L5', 'L5', 17, 60
  UNION ALL SELECT 'S1', 'S1', 18, 70
  UNION ALL SELECT 'S2', 'S2', 19, 80
) t
WHERE v.`vocab_key` = 'lumbar_levels'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;


-- ---------------------------------------------------------------------
-- 13. cervical_levels
--     06 Appendix B: C2..C8
--     Expanded to the seven discrete levels. C8 is retained because the
--     spec states the range explicitly; note that C8 is a nerve root,
--     not a vertebral body. See OPEN-QUESTIONS Q19.
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_vocabulary_item`
  (`vocabulary_id`,`item_key`,`label`,`polarity`,`is_normal`,`numeric_value`,`sort_order`,`is_active`)
SELECT v.`id`, t.`item_key`, t.`label`, 'neutral', 0, t.`numeric_value`, t.`sort_order`, 1
FROM `zdoc_vocabulary` v
JOIN (
  SELECT 'C2' AS item_key, 'C2' AS label, 2 AS numeric_value, 10 AS sort_order
  UNION ALL SELECT 'C3', 'C3', 3, 20
  UNION ALL SELECT 'C4', 'C4', 4, 30
  UNION ALL SELECT 'C5', 'C5', 5, 40
  UNION ALL SELECT 'C6', 'C6', 6, 50
  UNION ALL SELECT 'C7', 'C7', 7, 60
  UNION ALL SELECT 'C8', 'C8', 8, 70
) t
WHERE v.`vocab_key` = 'cervical_levels'
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `polarity`      = VALUES(`polarity`),
  `is_normal`     = VALUES(`is_normal`),
  `numeric_value` = VALUES(`numeric_value`),
  `sort_order`    = VALUES(`sort_order`),
  `is_active`     = 1;


-- ---------------------------------------------------------------------
-- 14. Record the seed
-- ---------------------------------------------------------------------

INSERT INTO `zdoc_schema_version` (`migration`,`applied_at`,`applied_by`)
VALUES ('0001_vocabularies', NOW(), CURRENT_USER())
ON DUPLICATE KEY UPDATE `applied_at` = `applied_at`;


-- ---------------------------------------------------------------------
-- 15. Self-check. Expected counts, for the installer and WP-90.
--     response_to_treatment 5, laterality 4, severity_scale 11,
--     frequency 3, onset 3, pain_quality 9, reflex_grade 5,
--     motor_grade 6, sensation 4, special_test_result 3,
--     lumbar_levels 8, cervical_levels 7   = 68 items, 12 vocabularies
--
--     Expected polarity distribution: positive 24, negative 2,
--     neutral 42.
--     Expected is_normal = 1 count: 5 — severity_scale 0/10,
--     reflex_grade 2+, motor_grade 5/5, sensation normal,
--     special_test_result negative.
-- ---------------------------------------------------------------------

SELECT v.`vocab_key`,
       COUNT(i.`id`)                  AS item_count,
       SUM(i.`polarity` = 'positive') AS positive_count,
       SUM(i.`polarity` = 'negative') AS negative_count,
       SUM(i.`polarity` = 'neutral')  AS neutral_count,
       SUM(i.`is_normal` = 1)         AS normal_count
FROM `zdoc_vocabulary` v
LEFT JOIN `zdoc_vocabulary_item` i ON i.`vocabulary_id` = v.`id`
GROUP BY v.`vocab_key`
ORDER BY v.`vocab_key`;

-- =====================================================================
-- END vocabularies.sql
-- =====================================================================
