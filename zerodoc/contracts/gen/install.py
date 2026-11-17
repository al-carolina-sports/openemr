import json, re
SC = '/home/claude/build/module/oe-module-zerodoc-encounter/'
order = json.load(open('order.json'))['order']
block = open('clinical_full.sql', encoding='utf-8').read()

# ---------- table.sql ----------
p = SC + 'table.sql'
s = open(p, encoding='utf-8').read()
BEG = '-- END ZeroDoc clinical schema'
m = re.search(r'(-- =+\n-- BEGIN ZeroDoc clinical schema\n.*?-- =+\n)(.*?)(\n-- =+\n-- END ZeroDoc clinical schema\n-- =+)', s, re.S)
assert m, 'markers not found in table.sql'
s = s[:m.start(2)] + "\n" + block + "\n" + s[m.end(2):]
open(p, 'w', encoding='utf-8').write(s)
print("table.sql written:", len(s), "bytes")

# ---------- cleanup.sql ----------
p = SC + 'cleanup.sql'
s = open(p, encoding='utf-8').read()
m = re.search(r'(-- =+\n-- BEGIN ZeroDoc clinical teardown\n.*?-- =+\n)(.*?)(\n-- Encounter form data)', s, re.S)
assert m, 'markers not found in cleanup.sql'

drops = []
drops.append("--")
drops.append("-- Children before parents: exact reverse of the install order, so every")
drops.append("-- FK is gone before its target. FOREIGN_KEY_CHECKS is NOT disabled --")
drops.append("-- if a drop fails here, something still references it and you want to know.")
drops.append("--")
drops.append("-- EVERY TABLE BELOW MAY HOLD PATIENT DATA. Back up first (DEPLOY.md).")
drops.append("-- zdoc_finding, zdoc_encounter_section, zdoc_encounter_diagnosis,")
drops.append("-- zdoc_thread_entry and zdoc_encounter_procedure hold the clinical note")
drops.append("-- itself; zdoc_allergy and zdoc_patient_allergy_assertion hold the")
drops.append("-- allergy record. Dropping these destroys signed medical records.")
drops.append("--")
for n in reversed(order):
    drops.append("DROP TABLE IF EXISTS `%s`;" % n)
drops.append("")
drops.append("-- The clinical schema revision row. The scaffold's `unregister` hook drops")
drops.append("-- the whole table; this line is for the case where you are tearing down")
drops.append("-- only the clinical schema and leaving the module registered.")
drops.append("DELETE FROM `zdoc_schema_version` WHERE `component` = 'clinical';")
drops.append("")
drops.append("-- The column added to the scaffold's form table. Dropped only if you are")
drops.append("-- removing the clinical schema but keeping the stub form.")
drops.append("-- ALTER TABLE `form_zdoc_encounter` DROP COLUMN `encounter_ext_id`;")

s = s[:m.start(2)] + "\n" + "\n".join(drops) + "\n" + s[m.end(2):]
open(p, 'w', encoding='utf-8').write(s)
print("cleanup.sql written:", len(s), "bytes;", len(order), "drops")
