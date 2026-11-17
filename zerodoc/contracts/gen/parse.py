import re, json, sys

SRC = '/home/claude/build/contracts/schema.sql'
s = open(SRC, encoding='utf-8').read()

# Tables owned by the scaffold -- we must NOT create these.
SCAFFOLD_OWNED = {'zdoc_schema_version', 'form_zdoc_encounter'}

# --- split out CREATE TABLE blocks -------------------------------------------
tbl_re = re.compile(
    r'CREATE TABLE IF NOT EXISTS `(\w+)` \((.*?)\n\) (ENGINE=[^;]*);',
    re.S)
tables = {}
order_src = []
for m in tbl_re.finditer(s):
    name, body, engine = m.group(1), m.group(2), m.group(3)
    tables[name] = {'body': body, 'engine': engine, 'raw': m.group(0)}
    order_src.append(name)

print("parsed tables:", len(tables))
missing = SCAFFOLD_OWNED - set(tables)
print("scaffold-owned present in our schema:", sorted(SCAFFOLD_OWNED & set(tables)), "| absent:", sorted(missing))

# --- dependency edges (intra-zdoc FKs only) ---------------------------------
deps = {}
for n, t in tables.items():
    d = set(re.findall(r'REFERENCES `(\w+)` \(', t['body']))
    d.discard(n)                      # self-reference needs no ordering
    deps[n] = d

# deferred ALTERs currently in the file -- these must be eliminated
alters = re.findall(r"ALTER TABLE `(\w+)`\s*\n\s*ADD CONSTRAINT `(\w+)`\s*\n\s*FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`(\w+)`\)", s)
print("deferred ALTER FKs:", len(alters))
for a in alters:
    deps[a[0]].add(a[3])

# --- topological sort -------------------------------------------------------
emit, seen = [], set()
pending = [n for n in order_src if n not in SCAFFOLD_OWNED]
pset = set(pending)
def visit(n, stack):
    if n in seen: return
    if n in stack:
        print("CYCLE:", " -> ".join(stack + [n])); sys.exit(1)
    for d in sorted(deps.get(n, ())):
        if d in pset:
            visit(d, stack + [n])
    seen.add(n); emit.append(n)
for n in pending:
    visit(n, [])
print("topo order length:", len(emit))

# verify: every dep appears before its dependant
pos = {n: i for i, n in enumerate(emit)}
bad = [(n, d) for n in emit for d in deps.get(n, ()) if d in pos and pos[d] > pos[n]]
print("ordering violations:", bad)

json.dump({'tables': tables, 'order': emit, 'alters': alters},
          open('parsed.json', 'w'))
