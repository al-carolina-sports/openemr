import re, json

d = json.load(open('parsed.json'))
tables, order, alters = d['tables'], d['order'], d['alters']

# ---- inline the 9 deferred FKs into their table bodies ----------------------
for tbl, cname, col, rtbl, rcol in alters:
    body = tables[tbl]['body'].rstrip()
    assert not body.endswith(','), tbl
    body += (",\n  CONSTRAINT `%s`\n    FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`)\n"
             "    ON DELETE SET NULL ON UPDATE CASCADE" % (cname, col, rtbl, rcol))
    tables[tbl]['body'] = body

out = []
w = out.append

w("-- ---------------------------------------------------------------------------")
w("-- ZeroDoc clinical schema -- generated from contracts/schema.sql")
w("-- Contract version 1.5.0.  61 tables, all prefixed `zdoc_`.")
w("--")
w("-- IDEMPOTENCY: every CREATE TABLE is wrapped in #IfNotTable / #EndIf; the")
w("-- one added column is wrapped in #IfMissingColumn / #EndIf; every seed row")
w("-- is wrapped in #IfNotRow / #EndIf except where noted at that statement.")
w("-- Directives start in column 0, are case-sensitive, and terminate in #EndIf.")
w("--")
w("-- NO FOREIGN KEY targets a core OpenEMR table. Core is related to by value")
w("-- only: pid, encounter, forms.form_id, users.id -- matched to core's own")
w("-- signed types so joins keep their indexes. FKs BETWEEN zdoc_ tables are")
w("-- declared inline; tables are emitted parent-before-child so no ALTER is")
w("-- needed and no statement depends on another file's order.")
w("--")
w("-- `zdoc_schema_version` and `form_zdoc_encounter` are NOT created here --")
w("-- the scaffold owns both, above this marker.")
w("-- ---------------------------------------------------------------------------")
w("")

# ---- the one column we add to the scaffold's form table ---------------------
w("--")
w("-- The scaffold's form table gets one ZeroDoc column: the link to our own")
w("-- encounter extension row. #IfMissingColumn takes EXACTLY two arguments.")
w("--")
w("#IfMissingColumn form_zdoc_encounter encounter_ext_id")
w("ALTER TABLE `form_zdoc_encounter`")
w("  ADD COLUMN `encounter_ext_id` BIGINT UNSIGNED NULL")
w("  COMMENT 'points at zdoc_encounter_ext.id; the only ZeroDoc column on this bridge table',")
w("  ADD KEY `ix_form_zdoc_encounter_ext` (`encounter_ext_id`);")
w("#EndIf")
w("")

for name in order:
    t = tables[name]
    w("#IfNotTable %s" % name)
    w("CREATE TABLE `%s` (%s\n) %s;" % (name, t['body'], t['engine']))
    w("#EndIf")
    w("")

json.dump({'order': order}, open('order.json', 'w'))
open('clinical_block.sql', 'w', encoding='utf-8').write("\n".join(out))
print("tables emitted:", len(order))
print("IfNotTable:", "\n".join(out).count("#IfNotTable"))
print("EndIf:", "\n".join(out).count("#EndIf"))
