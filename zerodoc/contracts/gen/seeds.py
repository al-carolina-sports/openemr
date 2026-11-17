import re

def split_tuples(vals):
    """Quote- and paren-aware split of  (a,b),(c,d)  into ['(a,b)','(c,d)']."""
    out, depth, cur, q, i = [], 0, '', None, 0
    while i < len(vals):
        c = vals[i]
        if q:
            cur += c
            if c == "'" and vals[i+1:i+2] == "'":   # escaped '' inside literal
                cur += vals[i+1]; i += 2; continue
            if c == q: q = None
            i += 1; continue
        if c == "'":
            q = c; cur += c; i += 1; continue
        if c == '(':
            depth += 1; cur += c; i += 1; continue
        if c == ')':
            depth -= 1; cur += c
            if depth == 0:
                out.append(cur.strip()); cur = ''
            i += 1; continue
        if depth == 0 and c in ',\n\r\t ':
            i += 1; continue
        cur += c; i += 1
    assert not cur.strip(), cur[:80]
    return out

def first_literal(tup):
    """First value in the tuple, unquoted."""
    inner = tup[1:-1]
    parts = split_scalars(inner)
    v = parts[0].strip()
    return v[1:-1].replace("''", "'") if v.startswith("'") else v

def split_scalars(inner):
    out, cur, q, depth = [], '', None, 0
    i = 0
    while i < len(inner):
        c = inner[i]
        if q:
            cur += c
            if c == "'" and inner[i+1:i+2] == "'":
                cur += inner[i+1]; i += 2; continue
            if c == q: q = None
            i += 1; continue
        if c == "'": q = c; cur += c; i += 1; continue
        if c == '(': depth += 1; cur += c; i += 1; continue
        if c == ')': depth -= 1; cur += c; i += 1; continue
        if c == ',' and depth == 0:
            out.append(cur); cur = ''; i += 1; continue
        cur += c; i += 1
    out.append(cur)
    return out

def build(src_file, table, cols_sql, guard_col, label):
    s = open(src_file, encoding='utf-8').read()
    m = re.search(r'INSERT INTO `' + table + r'`\s*\n(\s*\([^;]*?\))\s*\nVALUES\s*\n(.*?)\nON DUPLICATE KEY UPDATE', s, re.S)
    assert m, table
    collist = ' '.join(m.group(1).split())
    tups = split_tuples(m.group(2))
    guard_i = [c.strip(' `') for c in collist.strip('()').split(',')].index(guard_col)
    blocks = []
    for t in tups:
        scal = split_scalars(t[1:-1])
        g = scal[guard_i].strip()
        g = g[1:-1].replace("''", "'") if g.startswith("'") else g
        blocks.append("#IfNotRow %s %s %s\nINSERT INTO `%s`\n  %s\nVALUES %s;\n#EndIf\n"
                      % (table, guard_col, g, table, collist, t))
    print("%-26s rows=%d" % (label, len(tups)))
    return "\n".join(blocks), len(tups)

sp, n1 = build('/home/claude/build/contracts/schema.sql', 'zdoc_section_policy',
               None, 'section_key', 'section_policy')
cr, n2 = build('/home/claude/build/contracts/schema.sql', 'zdoc_consistency_rule',
               None, 'rule_key', 'consistency_rule')
vh, n3 = build('/home/claude/build/contracts/vocabularies.sql', 'zdoc_vocabulary',
               None, 'vocab_key', 'vocabulary headers')

open('seed_section_policy.sql','w').write(sp)
open('seed_consistency_rule.sql','w').write(cr)
open('seed_vocabulary.sql','w').write(vh)
print("totals:", n1, n2, n3)
