import re

voc = open('/home/claude/build/contracts/vocabularies.sql', encoding='utf-8').read()
items = re.findall(
    r"(INSERT INTO `zdoc_vocabulary_item`\s*\n.*?`is_active`\s*=\s*1;)", voc, re.S)
assert len(items) == 12, len(items)

parts = []
parts.append(open('clinical_block.sql', encoding='utf-8').read())

parts.append("""
-- ---------------------------------------------------------------------------
-- SEED 1 of 4 -- section authoring policy (22 rows, one per section_key)
-- Shipped defaults. CONFIG, not code: a practice wanting MAs out of the A/P
-- flips one row. Reviewed before go-live -- see OPEN-QUESTIONS.md Q11.
-- ---------------------------------------------------------------------------

""" + open('seed_section_policy.sql', encoding='utf-8').read())

parts.append("""
-- ---------------------------------------------------------------------------
-- SEED 2 of 4 -- consistency rules (6 rows)
-- `is_system_rule = 1` marks rules that cannot be deleted. The NKDA rule is
-- one of them: it is the hard stop that blocks signing.
-- The when/then payloads are ILLUSTRATIVE SHAPES, not a frozen grammar --
-- WP-10 owns the resolver and proposes the grammar. `rule_key` IS stable and
-- is what the acceptance tests reference. See OPEN-QUESTIONS.md Q15.
-- ---------------------------------------------------------------------------

""" + open('seed_consistency_rule.sql', encoding='utf-8').read())

parts.append("""
-- ---------------------------------------------------------------------------
-- SEED 3 of 4 -- shared vocabulary headers (12 rows, doc 06 Appendix B)
-- ---------------------------------------------------------------------------

""" + open('seed_vocabulary.sql', encoding='utf-8').read())

parts.append("""
-- ---------------------------------------------------------------------------
-- SEED 4 of 4 -- shared vocabulary items (68 rows across the 12 vocabularies)
--
-- DOCUMENTED EXCEPTION TO THE #IfNotRow RULE, and the only one in this file.
-- These rows are keyed by (vocabulary_id, item_key), where vocabulary_id is a
-- surrogate not known when this file is written -- each statement resolves it
-- by joining on vocab_key. #IfNotRow and #IfNotRow2D both need literal values,
-- so neither can express the guard.
--
-- Instead these statements are NATURALLY REPEATABLE, which the conventions
-- document permits explicitly: each is INSERT ... SELECT ... ON DUPLICATE KEY
-- UPDATE against the UNIQUE key uq_zdoc_vocabulary_item (vocabulary_id,
-- item_key). Re-running inserts nothing twice and refreshes label, polarity,
-- is_normal, numeric_value and sort_order -- which is the intended behaviour,
-- since item ids must stay stable for zdoc_template_option to reference them.
--
-- See OPEN-QUESTIONS.md Q42 if the CI idempotency test is textual rather than
-- behavioural, in which case these need restructuring rather than exempting.
--
-- POLARITY / is_normal authoring rule: CONTRACTS.md §3.8. polarity is the
-- STRUCTURAL role (a normal descriptor is `neutral`, only an asserted absence
-- is `negative`); is_normal is clinical normality. Do not conflate them.
-- ---------------------------------------------------------------------------

""" + "\n\n".join(items))

parts.append("""
-- ---------------------------------------------------------------------------
-- SCHEMA REVISION BOOKKEEPING
-- src/SchemaVersion.php reads this table and the admin status page displays it.
-- The scaffold owns the table and seeds the 'core' component; this records the
-- clinical component separately so the two advance independently.
-- The UPDATE is naturally idempotent -- it is a no-op once at this revision.
-- ---------------------------------------------------------------------------

#IfNotRow zdoc_schema_version component clinical
INSERT INTO `zdoc_schema_version` (`component`, `version`, `note`)
VALUES ('clinical', 1, 'contract 1.5.0: 61 tables, 12 vocabularies, 6 rules, 22 policies');
#EndIf

UPDATE `zdoc_schema_version`
   SET `version` = 1,
       `note` = 'contract 1.5.0: 61 tables, 12 vocabularies, 6 rules, 22 policies'
 WHERE `component` = 'clinical' AND `version` < 1;
""")

open('clinical_full.sql', 'w', encoding='utf-8').write("\n".join(parts))
txt = "\n".join(parts)
import collections
d0 = [l for l in txt.split('\n') if l.startswith('#')]
c = collections.Counter(l.split()[0] for l in d0)
print("column-0 directives:", dict(c))
print("arg counts:", collections.Counter((l.split()[0], len(l.split())-1) for l in d0))
