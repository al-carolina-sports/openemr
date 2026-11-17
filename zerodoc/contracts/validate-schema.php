<?php

declare(strict_types=1);

/**
 * validate-schema.php — static validator for the ZeroDoc module's OpenEMR install SQL.
 *
 * No database is touched. Everything here is lexical / structural analysis of the
 * SQL text plus a cross-check against the written contract.
 *
 * Why this exists
 * ---------------
 * OpenEMR runs a custom module's install SQL through
 * OpenEMR\Services\Utils\SQLUpgradeService::upgradeFromSqlFile(), which guards each
 * statement with conditional directives (`#IfNotTable foo` … `#EndIf`). The parser is
 * line-oriented and forgiving in the worst way: a directive with the wrong argument
 * count, or with the wrong letter case, is not an error — it is just another `#`
 * comment. The statements it was meant to guard then run unguarded, so the second
 * install fails or duplicates rows. Core asserts re-runnability in
 * tests/Tests/Isolated/Modules/ModuleSqlIdempotencyTest.php. This tool is the local
 * equivalent of that gate.
 *
 * Usage:   php validate-schema.php [options]
 *          php validate-schema.php --help
 *
 * Exit status: 0 = no errors (warnings allowed), 1 = errors found, 2 = bad invocation.
 *
 * This file is the checker. It never writes to the files it inspects.
 */

// ---------------------------------------------------------------------------
// Authoritative directive table.
//
// Names and the "every block ends with #EndIf" rule come from
// MODULE-CONVENTIONS.md §4 "Directives", which records the supported set in
// SQLUpgradeService (doc block lines ~100-220) for OpenEMR 8.5. The argument
// count is the number of whitespace-separated tokens the directive requires
// AFTER the directive word itself.
// ---------------------------------------------------------------------------
const DIRECTIVES = [
    'IfNotTable'              => 1,  // <table>
    'IfTable'                 => 1,  // <table>
    'IfColumn'                => 2,  // <table> <column>
    'IfMissingColumn'         => 2,  // <table> <column>
    'IfNotColumnType'         => 3,  // <table> <column> <type>
    'IfNotColumnTypeDefault'  => 4,  // <table> <column> <type> <default>
    'IfNotRow'                => 3,  // <table> <column> <value>
    'IfNotRow2D'              => 5,  // <table> <col> <val> <col2> <val2>
    'IfNotRow3D'              => 7,  // + <col3> <val3>
    'IfNotRow4D'              => 9,  // + <col4> <val4>
    'IfNotRow2Dx2'            => 7,  // <table> <col> <val> <col2> <val2> <col3> <val3>
    'IfRow'                   => 3,  // <table> <column> <value>
    'IfRow2D'                 => 5,
    'IfRow3D'                 => 7,
    'IfIndex'                 => 2,  // <table> <index>
    'IfNotIndex'              => 2,  // <table> <index>
    'IfTableEngine'           => 2,  // <table> <engine>
];

const TERMINATOR = 'EndIf';

/** OpenEMR core tables a module must never create, alter or shadow. */
const CORE_TABLES = [
    'billing', 'claims', 'procedure_order', 'procedure_order_code', 'procedure_type',
    'lists', 'forms', 'form_encounter', 'registry', 'code_types', 'patient_data',
    'users', 'facility',
];

/** Core tables a module may legitimately INSERT into / UPDATE (registration rows). */
const CORE_WRITABLE = ['registry', 'forms'];

const PREFIX_TABLE = 'zdoc_';
const PREFIX_FORM  = 'form_zdoc_';

// ===========================================================================
// Finding collection
// ===========================================================================

final class Findings
{
    /** @var array<int,array{sev:string,file:string,line:int,code:string,msg:string,fix:string}> */
    public array $items = [];

    public function add(string $sev, string $file, int $line, string $code, string $msg, string $fix = ''): void
    {
        $this->items[] = [
            'sev' => $sev, 'file' => $file, 'line' => $line,
            'code' => $code, 'msg' => $msg, 'fix' => $fix,
        ];
    }

    public function error(string $f, int $l, string $c, string $m, string $fix = ''): void
    {
        $this->add('ERROR', $f, $l, $c, $m, $fix);
    }

    public function warn(string $f, int $l, string $c, string $m, string $fix = ''): void
    {
        $this->add('WARNING', $f, $l, $c, $m, $fix);
    }

    public function count(string $sev): int
    {
        return count(array_filter($this->items, fn($i) => $i['sev'] === $sev));
    }
}

// ===========================================================================
// Lexer / statement splitter
// ===========================================================================

/**
 * One SQL statement with the line it started on and the directive guards in force.
 */
final class Stmt
{
    public function __construct(
        public string $sql,
        public int $startLine,
        public int $endLine,
        /** @var array<int,array{name:string,args:array<int,string>,line:int}> */
        public array $guards,
        public bool $terminated,
    ) {
    }

    public function firstWords(int $n = 4): string
    {
        $s = preg_replace('/\s+/', ' ', trim($this->sql)) ?? '';
        $parts = explode(' ', $s);
        return implode(' ', array_slice($parts, 0, $n));
    }
}

final class SqlFile
{
    /** @var array<int,string> 1-indexed lines */
    public array $lines = [];
    /** @var array<int,Stmt> */
    public array $stmts = [];

    public function __construct(public string $path, public string $name)
    {
    }
}

/**
 * Split a file into statements while tracking the directive stack.
 *
 * Mirrors SQLUpgradeService's own model closely enough to find the failure modes:
 * directives are recognised only at column 0, are case sensitive, and any other
 * line beginning with '#' is a comment.
 */
function lexFile(string $path, string $display, Findings $F, bool $directiveAware): SqlFile
{
    $file = new SqlFile($path, $display);
    $raw = file_get_contents($path);
    if ($raw === false) {
        $F->error($display, 0, 'IO', "cannot read $path", 'check the path');
        return $file;
    }
    $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
    foreach ($lines as $i => $t) {
        $file->lines[$i + 1] = $t;
    }

    /** @var array<int,array{name:string,args:array<int,string>,line:int}> $stack */
    $stack = [];
    /** @var array<int,int> count of statements seen inside each open guard */
    $guardStmtCount = [];

    $buf = '';
    $bufStart = 0;

    // string/comment state carried across lines
    $inBlockComment = false;

    foreach ($file->lines as $lineNo => $line) {
        // --- directive handling: only at column 0, only outside a block comment
        if (!$inBlockComment && $buf === '' && str_starts_with($line, '#')) {
            $tokens = preg_split('/\s+/', trim($line)) ?: [];
            $word = ltrim($tokens[0] ?? '', '#');
            $args = array_values(array_filter(array_slice($tokens, 1), fn($t) => $t !== ''));

            if ($directiveAware) {
                handleDirectiveLine($display, $lineNo, $line, $word, $args, $stack, $guardStmtCount, $F);
            }
            continue;
        }
        // a '#' not at column 0 inside SQL text is just part of the statement/comment
        if (!$inBlockComment && $buf === '' && preg_match('/^\s+#/', $line) && $directiveAware) {
            $trimmed = ltrim($line);
            $tokens = preg_split('/\s+/', trim($trimmed)) ?: [];
            $word = ltrim($tokens[0] ?? '', '#');
            if (isset(DIRECTIVES[$word]) || strcasecmp($word, TERMINATOR) === 0) {
                $F->error(
                    $display,
                    $lineNo,
                    'DIR-COL0',
                    "directive `#$word` is indented; SQLUpgradeService only recognises directives starting at column 0, so this line is a plain comment and everything it guards runs unguarded",
                    'move the directive flush to column 0'
                );
            }
            continue;
        }

        // --- SQL text accumulation with quote / comment awareness
        [$emitted, $buf, $bufStart, $inBlockComment] =
            consumeLine($line, $lineNo, $buf, $bufStart, $inBlockComment);

        foreach ($emitted as $one) {
            $stmt = new Stmt($one['sql'], $one['start'], $lineNo, $stack, true);
            if (trim($stmt->sql) === '') {
                continue;
            }
            $file->stmts[] = $stmt;
            foreach (array_keys($stack) as $k) {
                $guardStmtCount[$k] = ($guardStmtCount[$k] ?? 0) + 1;
            }
        }
    }

    // unterminated trailing text
    if (trim($buf) !== '') {
        $stmt = new Stmt($buf, $bufStart, array_key_last($file->lines) ?? $bufStart, $stack, false);
        $file->stmts[] = $stmt;
        $F->error(
            $display,
            $bufStart,
            'SQL-UNTERM',
            'statement is not terminated by a semicolon before end of file: "' . $stmt->firstWords(6) . ' …"',
            'add the closing `;`'
        );
    }
    if ($inBlockComment) {
        $F->error($display, array_key_last($file->lines) ?? 0, 'SQL-COMMENT', 'unclosed /* block comment at end of file', 'close it with */');
    }

    // unclosed guards
    foreach ($stack as $g) {
        $F->error(
            $display,
            $g['line'],
            'DIR-UNCLOSED',
            "`#{$g['name']} " . implode(' ', $g['args']) . '` is never closed',
            'add a matching `#EndIf`'
        );
    }

    return $file;
}

/**
 * @param array<int,array{name:string,args:array<int,string>,line:int}> $stack
 * @param array<int,int> $guardStmtCount
 */
function handleDirectiveLine(
    string $display,
    int $lineNo,
    string $line,
    string $word,
    array $args,
    array &$stack,
    array &$guardStmtCount,
    Findings $F
): void {
    // terminator
    if (strcasecmp($word, TERMINATOR) === 0) {
        if ($word !== TERMINATOR) {
            $F->error(
                $display,
                $lineNo,
                'DIR-CASE',
                "terminator is spelled `#$word`; SQLUpgradeService matches `#" . TERMINATOR . "` case-sensitively, so this is a comment and the preceding guard is left open",
                'spell it exactly `#' . TERMINATOR . '`'
            );
            return; // it really is a comment — do not pop
        }
        if ($args !== []) {
            $F->warn($display, $lineNo, 'DIR-ENDIF-ARGS', '`#EndIf` carries trailing tokens: ' . implode(' ', $args), 'remove them');
        }
        if ($stack === []) {
            $F->error($display, $lineNo, 'DIR-ORPHAN', 'orphan `#EndIf` — no directive is open here', 'delete it or add the missing opener');
            return;
        }
        $k = array_key_last($stack);
        if (($guardStmtCount[$k] ?? 0) === 0) {
            $g = $stack[$k];
            $F->warn(
                $display,
                $g['line'],
                'DIR-EMPTY',
                "`#{$g['name']} " . implode(' ', $g['args']) . '` guards no statement',
                'remove the empty guard, or move the statement inside it'
            );
        }
        unset($stack[$k], $guardStmtCount[$k]);
        $stack = array_values($stack);
        $guardStmtCount = array_values($guardStmtCount);
        return;
    }

    // exact directive
    if (isset(DIRECTIVES[$word])) {
        $need = DIRECTIVES[$word];
        $have = count($args);
        if ($have < $need) {
            $F->error(
                $display,
                $lineNo,
                'DIR-ARGC',
                "`#$word` needs $need argument(s), found $have — with too few arguments SQLUpgradeService treats the line as a comment, so every statement under it runs UNGUARDED and the install stops being idempotent",
                "supply all $need arguments"
            );
            return; // degrades to a comment: do not open a guard
        }
        if ($have > $need) {
            $F->warn(
                $display,
                $lineNo,
                'DIR-ARGC-EXTRA',
                "`#$word` takes $need argument(s), found $have — the extra tokens are silently ignored (a value containing a space cannot be expressed this way)",
                'quote-free single-token values only; use a different directive if the value has spaces'
            );
        }
        if ($stack !== []) {
            $outer = $stack[array_key_last($stack)];
            $F->error(
                $display,
                $lineNo,
                'DIR-NEST',
                "`#$word` opens inside the still-open `#{$outer['name']}` from line {$outer['line']}; SQLUpgradeService keeps no directive stack and does not support nesting",
                'close the outer block with `#EndIf` before opening another'
            );
        }
        $stack[] = ['name' => $word, 'args' => $args, 'line' => $lineNo];
        $guardStmtCount[] = 0;
        return;
    }

    // case-variant of a real directive
    foreach (array_keys(DIRECTIVES) as $known) {
        if (strcasecmp($word, $known) === 0) {
            $F->error(
                $display,
                $lineNo,
                'DIR-CASE',
                "`#$word` is a case variant of `#$known`; directives are case sensitive, so this line is a comment and its statements run unguarded",
                "spell it exactly `#$known`"
            );
            return;
        }
    }

    // looks like an attempted directive (#IfSomething) but isn't one
    if (preg_match('/^If/i', $word)) {
        $F->error(
            $display,
            $lineNo,
            'DIR-UNKNOWN',
            "`#$word` is not a directive SQLUpgradeService knows; it is being read as a comment",
            'use one of: #' . implode(', #', array_keys(DIRECTIVES))
        );
    }
    // anything else beginning with '#' is an ordinary comment — fine.
}

/**
 * Consume one physical line of SQL, returning any completed statements.
 *
 * @return array{0:array<int,array{sql:string,start:int}>,1:string,2:int,3:bool}
 */
function consumeLine(string $line, int $lineNo, string $buf, int $bufStart, bool $inBlockComment): array
{
    $out = [];
    $n = strlen($line);
    $i = 0;
    while ($i < $n) {
        $c = $line[$i];
        $c2 = substr($line, $i, 2);

        if ($inBlockComment) {
            if ($c2 === '*/') {
                $inBlockComment = false;
                $i += 2;
                continue;
            }
            $i++;
            continue;
        }
        if ($c2 === '/*') {
            $inBlockComment = true;
            $i += 2;
            continue;
        }
        if ($c2 === '--' && (($i + 2) >= $n || preg_match('/\s/', $line[$i + 2] ?? ' '))) {
            break; // rest of line is a comment
        }
        if ($c === '#' && $i > 0) {
            break; // MySQL line comment
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            if ($buf === '') {
                $bufStart = $lineNo;
            }
            $buf .= $c;
            $i++;
            while ($i < $n) {
                $ch = $line[$i];
                if ($ch === '\\' && $q !== '`') {
                    $buf .= substr($line, $i, 2);
                    $i += 2;
                    continue;
                }
                $buf .= $ch;
                $i++;
                if ($ch === $q) {
                    // doubled quote = literal
                    if (($line[$i] ?? '') === $q) {
                        $buf .= $q;
                        $i++;
                        continue;
                    }
                    break;
                }
            }
            continue;
        }
        if ($c === ';') {
            $out[] = ['sql' => $buf, 'start' => $buf === '' ? $lineNo : $bufStart];
            $buf = '';
            $bufStart = 0;
            $i++;
            continue;
        }
        if ($buf === '' && preg_match('/\s/', $c)) {
            $i++;
            continue;
        }
        if ($buf === '') {
            $bufStart = $lineNo;
        }
        $buf .= $c;
        $i++;
    }
    if ($buf !== '') {
        $buf .= "\n";
    }
    return [$out, $buf, $bufStart, $inBlockComment];
}

// ===========================================================================
// DDL model
// ===========================================================================

final class TableDef
{
    /** @var array<string,int> column name => line */
    public array $columns = [];
    /** @var array<int,array{name:string,cols:array<int,string>,line:int,kind:string}> */
    public array $indexes = [];
    /** @var array<int,array{name:string,cols:array<int,string>,refTable:string,refCols:array<int,string>,line:int}> */
    public array $fks = [];
    public ?string $engine = null;
    public ?string $charset = null;
    public ?string $collate = null;
    public ?string $rowFormat = null;
    public bool $ifNotExists = false;

    public function __construct(
        public string $name,
        public string $file,
        public int $line,
    ) {
    }
}

/** @return array{0:array<string,TableDef>,1:array<int,Stmt>} */
function parseTables(SqlFile $f, Findings $F): array
{
    /** @var array<string,TableDef> $tables */
    $tables = [];
    foreach ($f->stmts as $stmt) {
        $sql = $stmt->sql;
        if (!preg_match('/^\s*CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_$]+)`?\s*\(/is', $sql, $m)) {
            continue;
        }
        $name = $m[2];
        $def = new TableDef($name, $f->name, $stmt->startLine);
        $def->ifNotExists = trim($m[1] ?? '') !== '';

        // body: balanced parens from the first '('
        $open = strpos($sql, '(', (int) strpos($sql, $m[2]));
        [$body, $after] = balanced($sql, (int) $open);

        foreach (splitTopLevel($body) as $part) {
            parseTablePart($part, $def, $stmt->startLine, $f, $F);
        }

        if (preg_match('/ENGINE\s*=\s*([A-Za-z0-9_]+)/i', $after, $mm)) {
            $def->engine = $mm[1];
        }
        if (preg_match('/(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET)\s*=?\s*([A-Za-z0-9_]+)/i', $after, $mm)) {
            $def->charset = strtolower($mm[1]);
        }
        if (preg_match('/COLLATE\s*=?\s*([A-Za-z0-9_]+)/i', $after, $mm)) {
            $def->collate = strtolower($mm[1]);
        }
        if (preg_match('/ROW_FORMAT\s*=?\s*([A-Za-z0-9_]+)/i', $after, $mm)) {
            $def->rowFormat = strtoupper($mm[1]);
        }

        if (isset($tables[$name])) {
            $F->error(
                $f->name,
                $stmt->startLine,
                'DUP-TABLE',
                "duplicate definition of `$name` (first at {$f->name}:{$tables[$name]->line})",
                'remove one of the two CREATE TABLE statements'
            );
            continue;
        }
        $tables[$name] = $def;
    }
    return [$tables, $f->stmts];
}

/** @return array{0:string,1:string} body inside outermost parens, remainder after it */
function balanced(string $s, int $openPos): array
{
    $depth = 0;
    $n = strlen($s);
    $start = $openPos + 1;
    for ($i = $openPos; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            $i++;
            while ($i < $n) {
                if ($s[$i] === '\\' && $q !== '`') {
                    $i += 2;
                    continue;
                }
                if ($s[$i] === $q) {
                    break;
                }
                $i++;
            }
            continue;
        }
        if ($c === '(') {
            $depth++;
        } elseif ($c === ')') {
            $depth--;
            if ($depth === 0) {
                return [substr($s, $start, $i - $start), substr($s, $i + 1)];
            }
        }
    }
    return [substr($s, $start), ''];
}

/** @return array<int,string> */
function splitTopLevel(string $body): array
{
    $parts = [];
    $depth = 0;
    $cur = '';
    $n = strlen($body);
    for ($i = 0; $i < $n; $i++) {
        $c = $body[$i];
        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            $cur .= $c;
            $i++;
            while ($i < $n) {
                $ch = $body[$i];
                if ($ch === '\\' && $q !== '`') {
                    $cur .= substr($body, $i, 2);
                    $i += 2;
                    continue;
                }
                $cur .= $ch;
                $i++;
                if ($ch === $q) {
                    break;
                }
            }
            $i--;
            continue;
        }
        if ($c === '(') {
            $depth++;
        }
        if ($c === ')') {
            $depth--;
        }
        if ($c === ',' && $depth === 0) {
            $parts[] = $cur;
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if (trim($cur) !== '') {
        $parts[] = $cur;
    }
    return $parts;
}

function parseTablePart(string $part, TableDef $def, int $stmtLine, SqlFile $f, Findings $F): void
{
    $p = trim($part);
    if ($p === '') {
        return;
    }
    $line = locateInFile($f, $stmtLine, $p);

    // CONSTRAINT ... FOREIGN KEY
    if (preg_match('/^(?:CONSTRAINT\s+`?([A-Za-z0-9_$]*)`?\s+)?FOREIGN\s+KEY\s*\(([^)]*)\)\s*REFERENCES\s+`?([A-Za-z0-9_$]+)`?\s*\(([^)]*)\)/is', $p, $m)) {
        $def->fks[] = [
            'name' => $m[1] ?: '(unnamed)',
            'cols' => idents($m[2]),
            'refTable' => $m[3],
            'refCols' => idents($m[4]),
            'line' => $line,
        ];
        return;
    }
    // CHECK constraint — ignore for column purposes
    if (preg_match('/^(?:CONSTRAINT\s+`?[A-Za-z0-9_$]*`?\s+)?CHECK\s*\(/is', $p)) {
        return;
    }
    // keys / indexes
    if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+(?:KEY|INDEX)|FULLTEXT\s+(?:KEY|INDEX)|SPATIAL\s+(?:KEY|INDEX)|KEY|INDEX)\s*(?:`?([A-Za-z0-9_$]+)`?)?\s*\((.*)\)/is', $p, $m)) {
        $kind = strtoupper(preg_replace('/\s+/', ' ', $m[1]) ?? '');
        [$inner] = balanced($p, (int) strpos($p, '('));
        $def->indexes[] = [
            'name' => $m[2] ?? '',
            'cols' => idents($inner),
            'line' => $line,
            'kind' => $kind,
        ];
        return;
    }
    // column definition
    if (preg_match('/^`([A-Za-z0-9_$]+)`/', $p, $m) || preg_match('/^([A-Za-z0-9_$]+)\s+[A-Za-z]/', $p, $m)) {
        $col = $m[1];
        if (isset($def->columns[$col])) {
            $F->error(
                $f->name,
                $line,
                'DUP-COLUMN',
                "table `{$def->name}` defines column `$col` twice (first at line {$def->columns[$col]})",
                'remove the duplicate column'
            );
            return;
        }
        $def->columns[$col] = $line;
        return;
    }
    $F->warn($f->name, $line, 'DDL-UNPARSED', "could not classify this element of `{$def->name}`: " . substr(preg_replace('/\s+/', ' ', $p) ?? '', 0, 70), 'review by hand');
}

/** @return array<int,string> */
function idents(string $s): array
{
    $out = [];
    // backticked first
    if (preg_match_all('/`([A-Za-z0-9_$]+)`/', $s, $m)) {
        $out = $m[1];
    }
    if ($out === []) {
        foreach (explode(',', $s) as $piece) {
            $piece = trim($piece);
            if (preg_match('/^([A-Za-z0-9_$]+)/', $piece, $mm)) {
                $out[] = $mm[1];
            }
        }
    }
    return $out;
}

/** Best-effort: find the line within the statement that contains this fragment. */
function locateInFile(SqlFile $f, int $stmtLine, string $fragment): int
{
    $needle = trim(explode("\n", trim($fragment))[0]);
    if ($needle === '') {
        return $stmtLine;
    }
    $needle = substr($needle, 0, 40);
    $max = array_key_last($f->lines) ?? $stmtLine;
    for ($l = $stmtLine; $l <= min($max, $stmtLine + 400); $l++) {
        if (str_contains($f->lines[$l] ?? '', $needle)) {
            return $l;
        }
    }
    return $stmtLine;
}

// ===========================================================================
// Checks
// ===========================================================================

/**
 * 1. Directive correctness that depends on what follows the directive.
 * @param array<string,TableDef> $tables
 */
function checkGuardCoverage(SqlFile $f, Findings $F): void
{
    foreach ($f->stmts as $stmt) {
        $sql = ltrim($stmt->sql);
        $guard = $stmt->guards === [] ? null : $stmt->guards[array_key_last($stmt->guards)];
        $gname = $guard['name'] ?? null;

        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_$]+)`?/is', $sql, $m)) {
            $t = $m[1];
            if ($gname !== 'IfNotTable') {
                $F->error(
                    $f->name,
                    $stmt->startLine,
                    'GUARD-CREATE',
                    "CREATE TABLE `$t` is " . ($gname === null ? 'not guarded at all' : "guarded by `#$gname`, not `#IfNotTable`"),
                    "wrap it in `#IfNotTable $t` … `#EndIf`"
                );
            } elseif (($guard['args'][0] ?? '') !== $t) {
                $F->error(
                    $f->name,
                    $guard['line'],
                    'GUARD-MISMATCH',
                    "`#IfNotTable {$guard['args'][0]}` guards `CREATE TABLE $t` — the names differ, so the guard tests the wrong table",
                    "change the directive to `#IfNotTable $t`"
                );
            }
            continue;
        }

        if (preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_$]+)`?\s+(.*)$/is', $sql, $m)) {
            $t = $m[1];
            $rest = $m[2];
            $addedCols = [];
            if (preg_match_all('/\bADD\s+(?:COLUMN\s+)?`([A-Za-z0-9_$]+)`/is', $rest, $mcAll)) {
                $addedCols = $mcAll[1];
            }
            $addedIndexes = [];
            if (preg_match_all('/\bADD\s+(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?(?:KEY|INDEX)\s+`([A-Za-z0-9_$]+)`/is', $rest, $miAll)) {
                $addedIndexes = $miAll[1];
                $addedCols = array_values(array_diff($addedCols, $addedIndexes));
            }

            if ($addedCols !== []) {
                $col = $addedCols[0];
                if (count($addedCols) > 1) {
                    $F->error(
                        $f->name,
                        $stmt->startLine,
                        'GUARD-ADDCOL-MULTI',
                        "one ALTER TABLE `$t` adds " . count($addedCols) . ' columns (' . implode(', ', $addedCols) . '); `#IfMissingColumn` can test only one, so if the statement ever half-applies the guard reports the table as done',
                        'split into one guarded ALTER per column'
                    );
                }
                if ($gname !== 'IfMissingColumn') {
                    $F->error(
                        $f->name,
                        $stmt->startLine,
                        'GUARD-ADDCOL',
                        "ALTER TABLE `$t` ADD COLUMN `$col` is " . ($gname === null ? 'not guarded' : "guarded by `#$gname`, not `#IfMissingColumn`"),
                        "wrap it in `#IfMissingColumn $t $col` … `#EndIf`"
                    );
                } else {
                    $ga = $guard['args'];
                    if (($ga[0] ?? '') !== $t || ($ga[1] ?? '') !== $col) {
                        $F->error(
                            $f->name,
                            $guard['line'],
                            'GUARD-MISMATCH',
                            '`#IfMissingColumn ' . implode(' ', $ga) . "` does not match `ALTER TABLE $t ADD $col`",
                            "change the directive to `#IfMissingColumn $t $col`"
                        );
                    }
                }
            } elseif (preg_match('/\bADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?([A-Za-z0-9_$]+)`?/is', $rest, $mi)) {
                if ($gname !== 'IfNotIndex') {
                    $F->warn(
                        $f->name,
                        $stmt->startLine,
                        'GUARD-ADDINDEX',
                        "ALTER TABLE `$t` ADD INDEX `{$mi[1]}` is " . ($gname === null ? 'not guarded' : "guarded by `#$gname`"),
                        "wrap it in `#IfNotIndex $t {$mi[1]}` … `#EndIf`"
                    );
                }
            } elseif ($gname === null) {
                $F->warn($f->name, $stmt->startLine, 'GUARD-ALTER', "unguarded ALTER TABLE `$t`: " . $stmt->firstWords(8), 'guard it with the matching #If… directive');
            }
            if (in_array(strtolower($t), CORE_TABLES, true)) {
                $F->error(
                    $f->name,
                    $stmt->startLine,
                    'CORE-ALTER',
                    "ALTER TABLE on core table `$t` — MODULE-CONVENTIONS.md §10.5 forbids changing the core schema from a module",
                    'relate to core by value (pid, encounter, forms.form_id, users.id) instead'
                );
            }
            continue;
        }

        if (preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?([A-Za-z0-9_$]+)`?/is', $sql, $m)) {
            $t = $m[1];
            $hasOdku = (bool) preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE/is', $sql);
            $isSelect = (bool) preg_match('/\)\s*SELECT|\bINSERT\b[^;]*?\bSELECT\b/is', $sql);
            $guardOk = in_array((string) $gname, ['IfNotRow', 'IfNotRow2D', 'IfNotRow3D', 'IfNotRow4D', 'IfNotRow2Dx2', 'IfNotTable'], true);
            if (!$guardOk) {
                if ($hasOdku || preg_match('/^INSERT\s+IGNORE/is', $sql)) {
                    $F->warn(
                        $f->name,
                        $stmt->startLine,
                        'GUARD-INSERT-NAT',
                        "INSERT INTO `$t` is " . ($gname === null ? 'unguarded' : "guarded by `#$gname`") . ' but is naturally repeatable (' . ($hasOdku ? 'ON DUPLICATE KEY UPDATE' : 'INSERT IGNORE') . ') — allowed by MODULE-CONVENTIONS.md §4, flagged so the exemption stays deliberate',
                        'leave as is if intentional, or add `#IfNotRow ' . $t . ' <col> <value>`'
                    );
                } else {
                    $F->error(
                        $f->name,
                        $stmt->startLine,
                        'GUARD-INSERT',
                        "INSERT INTO `$t` is " . ($gname === null ? 'not guarded' : "guarded by `#$gname`") . ' and is not naturally repeatable, so a re-install duplicates the row',
                        "wrap it in `#IfNotRow $t <col> <value>` … `#EndIf`, or add ON DUPLICATE KEY UPDATE"
                    );
                }
            } elseif ($gname !== null && str_starts_with($gname, 'IfNotRow') && ($guard['args'][0] ?? '') !== $t) {
                $F->error(
                    $f->name,
                    $guard['line'],
                    'GUARD-MISMATCH',
                    "`#$gname {$guard['args'][0]}` guards `INSERT INTO $t` — the table names differ",
                    "change the directive's table to `$t`"
                );
            }
            if ($guardOk && $gname === 'IfNotRow' && !$isSelect) {
                checkIfNotRowValue($f, $stmt, $guard, $F);
            }
            if (in_array(strtolower($t), CORE_TABLES, true) && !in_array(strtolower($t), CORE_WRITABLE, true)) {
                $F->warn(
                    $f->name,
                    $stmt->startLine,
                    'CORE-INSERT',
                    "INSERT into core table `$t`",
                    'confirm this is a registration row core expects a module to write'
                );
            }
            continue;
        }

        if (preg_match('/^(UPDATE|DELETE)\b/is', $sql) && $stmt->guards === []) {
            $F->warn(
                $f->name,
                $stmt->startLine,
                'GUARD-DML',
                strtoupper($m[1] ?? 'DML') . ' statement is unguarded: ' . $stmt->firstWords(8) . ' — permitted only if re-running it is a no-op',
                'confirm idempotence or guard it'
            );
            continue;
        }

        if (preg_match('/^DROP\s+TABLE\b/is', $sql)) {
            $F->error($f->name, $stmt->startLine, 'INSTALL-DROP', 'DROP TABLE in install SQL: ' . $stmt->firstWords(6), 'teardown belongs in cleanup.sql');
        }
    }
}

/** Verify the literal in #IfNotRow actually appears in the INSERT's value for that column. */
function checkIfNotRowValue(SqlFile $f, Stmt $stmt, array $guard, Findings $F): void
{
    [, $col, $val] = array_pad($guard['args'], 3, '');
    if (!preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?[A-Za-z0-9_$]+`?\s*\(([^)]*)\)/is', $stmt->sql, $m)) {
        return;
    }
    $cols = idents($m[1]);
    $idx = array_search($col, $cols, true);
    if ($idx === false) {
        $F->error(
            $f->name,
            $guard['line'],
            'GUARD-COL',
            "`#IfNotRow " . implode(' ', $guard['args']) . "` tests column `$col`, which the INSERT's column list does not set",
            'guard on a column the statement actually writes'
        );
        return;
    }
    if (!str_contains($stmt->sql, "'" . $val . "'") && !preg_match('/\b' . preg_quote($val, '/') . '\b/', $stmt->sql)) {
        $F->warn(
            $f->name,
            $guard['line'],
            'GUARD-VALUE',
            "`#IfNotRow " . implode(' ', $guard['args']) . "` tests for value `$val`, which does not appear in the INSERT",
            'make the guard value match the inserted value exactly'
        );
    }
}

/** 2. Naming and collisions. @param array<string,TableDef> $tables */
function checkNaming(array $tables, array $takenPrefixes, Findings $F): void
{
    foreach ($tables as $name => $def) {
        $lower = strtolower($name);
        if (!str_starts_with($name, PREFIX_TABLE) && !str_starts_with($name, PREFIX_FORM)) {
            $F->error(
                $def->file,
                $def->line,
                'NAME-PREFIX',
                "table `$name` is not prefixed `" . PREFIX_TABLE . '` (nor `' . PREFIX_FORM . '` for an encounter form table)',
                'rename it with the zdoc_ prefix'
            );
        }
        if (in_array($lower, CORE_TABLES, true)) {
            $F->error($def->file, $def->line, 'NAME-CORE', "table `$name` collides with an OpenEMR core table", 'rename it zdoc_' . $name);
        }
        foreach ($takenPrefixes as $pfx => $owner) {
            if ($pfx !== '' && str_starts_with($lower, strtolower($pfx))) {
                $F->error(
                    $def->file,
                    $def->line,
                    'NAME-COLLIDE',
                    "table `$name` falls under prefix `$pfx`, owned by $owner (EXISTING-MODULES-SURVEY.md ownership map)",
                    'use the zdoc_ namespace'
                );
            }
        }
        if (strlen($name) > 64) {
            $F->error($def->file, $def->line, 'NAME-LEN', "table name `$name` exceeds MySQL's 64-character identifier limit", 'shorten it');
        }
        foreach (array_keys($def->columns) as $c) {
            if (strlen($c) > 64) {
                $F->error($def->file, $def->columns[$c], 'NAME-LEN', "column `$name`.`$c` exceeds 64 characters", 'shorten it');
            }
        }
        foreach ($def->indexes as $ix) {
            if ($ix['name'] !== '' && strlen($ix['name']) > 64) {
                $F->error($def->file, $ix['line'], 'NAME-LEN', "index name `{$ix['name']}` on `$name` exceeds 64 characters", 'shorten it');
            }
        }
    }
}

/** 3. Structural. @param array<string,TableDef> $tables */
function checkStructural(array $tables, Findings $F): void
{
    // engine
    foreach ($tables as $name => $def) {
        if ($def->engine === null) {
            $F->error($def->file, $def->line, 'ENGINE-MISSING', "table `$name` declares no ENGINE; it inherits the server default, which may not be InnoDB", 'add ENGINE=InnoDB');
        } elseif (strcasecmp($def->engine, 'InnoDB') !== 0) {
            $F->error($def->file, $def->line, 'ENGINE-NOT-INNODB', "table `$name` uses ENGINE={$def->engine}", 'change to ENGINE=InnoDB');
        }
    }

    // charset / collation consistency
    $charsets = [];
    $collations = [];
    foreach ($tables as $name => $def) {
        $charsets[$def->charset ?? '(none)'][] = $name;
        $collations[$def->collate ?? '(none)'][] = $name;
    }
    reportConsistency($charsets, 'CHARSET', $tables, $F);
    reportConsistency($collations, 'COLLATE', $tables, $F);

    // FKs
    foreach ($tables as $name => $def) {
        foreach ($def->fks as $fk) {
            $target = $fk['refTable'];
            if (!str_starts_with($target, PREFIX_TABLE) && !str_starts_with($target, PREFIX_FORM)) {
                $F->error(
                    $def->file,
                    $fk['line'],
                    'FK-CROSSES-CORE',
                    "`$name`.{$fk['name']} declares FOREIGN KEY → `$target`, a non-zdoc_ table; CONTRACTS.md §2.1 requires core relationships be held by value with no FK",
                    "drop the constraint and keep the bare indexed id column"
                );
                continue;
            }
            if (!isset($tables[$target])) {
                $F->error(
                    $def->file,
                    $fk['line'],
                    'FK-TARGET-MISSING',
                    "`$name`.{$fk['name']} references `$target`, which this schema never creates",
                    'create the target table or remove the constraint'
                );
                continue;
            }
            foreach ($fk['refCols'] as $rc) {
                if (!isset($tables[$target]->columns[$rc])) {
                    $F->error(
                        $def->file,
                        $fk['line'],
                        'FK-REFCOL-MISSING',
                        "`$name`.{$fk['name']} references `$target`.`$rc`, which does not exist",
                        'fix the referenced column name'
                    );
                }
            }
            foreach ($fk['cols'] as $lc) {
                if (!isset($def->columns[$lc])) {
                    $F->error($def->file, $fk['line'], 'FK-COL-MISSING', "`$name`.{$fk['name']} constrains column `$lc`, which `$name` does not define", 'fix the column name');
                }
            }
            // FK target must be indexed on the referenced columns
            if ($fk['refCols'] !== [] && !hasIndexPrefix($tables[$target], $fk['refCols'])) {
                $F->warn(
                    $def->file,
                    $fk['line'],
                    'FK-TARGET-UNINDEXED',
                    "`$target` (" . implode(',', $fk['refCols']) . ') has no index starting with those columns; InnoDB needs one for the constraint to be creatable',
                    'add a PRIMARY KEY / UNIQUE / KEY on the referenced columns'
                );
            }
            // FK without an index on the child side (InnoDB auto-creates one, but be explicit)
            if ($fk['cols'] !== [] && !hasIndexPrefix($def, $fk['cols'])) {
                $F->warn($def->file, $fk['line'], 'FK-CHILD-UNINDEXED', "`$name` has no explicit index on (" . implode(',', $fk['cols']) . "); InnoDB will auto-create one named after the constraint, which makes later #IfNotIndex guards guess", 'declare the index explicitly');
            }
        }

        // index columns must exist
        foreach ($def->indexes as $ix) {
            foreach ($ix['cols'] as $c) {
                if (!isset($def->columns[$c])) {
                    $F->error(
                        $def->file,
                        $ix['line'],
                        'INDEX-COL-MISSING',
                        "{$ix['kind']} " . ($ix['name'] !== '' ? "`{$ix['name']}` " : '') . "on `$name` indexes column `$c`, which `$name` does not define",
                        'correct the column name, or add the column'
                    );
                }
            }
            if ($ix['cols'] === []) {
                $F->warn($def->file, $ix['line'], 'INDEX-EMPTY', "{$ix['kind']} on `$name` has no parseable column list", 'review by hand');
            }
        }

        // duplicate index names within a table
        $seen = [];
        foreach ($def->indexes as $ix) {
            if ($ix['name'] === '') {
                continue;
            }
            if (isset($seen[$ix['name']])) {
                $F->error($def->file, $ix['line'], 'DUP-INDEX', "table `$name` declares index `{$ix['name']}` twice", 'rename or remove one');
            }
            $seen[$ix['name']] = true;
        }

        // identical index column lists
        $bycols = [];
        foreach ($def->indexes as $ix) {
            if ($ix['kind'] === 'PRIMARY KEY' || $ix['cols'] === []) {
                continue;
            }
            $k = implode(',', $ix['cols']);
            if (isset($bycols[$k])) {
                $F->warn($def->file, $ix['line'], 'INDEX-REDUNDANT', "`$name` indexes ($k) twice: `{$bycols[$k]}` and `{$ix['name']}`", 'drop the redundant index');
            }
            $bycols[$k] = $ix['name'];
        }

        if ($def->columns === []) {
            $F->error($def->file, $def->line, 'DDL-NO-COLUMNS', "table `$name` parsed with no columns", 'review the CREATE TABLE body by hand');
        }
        $hasPk = false;
        foreach ($def->indexes as $ix) {
            if ($ix['kind'] === 'PRIMARY KEY') {
                $hasPk = true;
            }
        }
        if (!$hasPk && !preg_match('/PRIMARY\s+KEY/i', implode(' ', array_keys($def->columns)))) {
            $inlinePk = false;
            if (!$hasPk && !$inlinePk) {
                $F->warn($def->file, $def->line, 'NO-PK', "table `$name` declares no PRIMARY KEY", 'add one — InnoDB needs a clustered key and replication wants it');
            }
        }
    }
}

/** @param array<string,TableDef> $t */
function hasIndexPrefix(TableDef $t, array $cols): bool
{
    foreach ($t->indexes as $ix) {
        if (array_slice($ix['cols'], 0, count($cols)) === $cols) {
            return true;
        }
    }
    return false;
}

/** @param array<string,array<int,string>> $groups */
function reportConsistency(array $groups, string $what, array $tables, Findings $F): void
{
    if (count($groups) <= 1) {
        return;
    }
    uasort($groups, fn($a, $b) => count($b) <=> count($a));
    $majority = array_key_first($groups);
    foreach ($groups as $value => $names) {
        if ($value === $majority) {
            continue;
        }
        foreach ($names as $n) {
            $F->warn(
                $tables[$n]->file,
                $tables[$n]->line,
                'CHARSET-INCONSISTENT',
                "table `$n` declares $what=$value while " . count($groups[$majority]) . " other table(s) use $what=$majority",
                "use $what=$majority everywhere, or state the exception in the contract"
            );
        }
    }
}

// ===========================================================================
// Cross-file
// ===========================================================================

/** @param array<string,TableDef> $installTables */
function checkCleanup(array $installTables, SqlFile $cleanup, Findings $F): void
{
    $dropped = [];
    foreach ($cleanup->stmts as $s) {
        if (preg_match('/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(.+)$/is', ltrim($s->sql), $m)) {
            foreach (idents($m[1]) as $t) {
                $dropped[$t] = $s->startLine;
            }
        }
    }
    // commented-out drops are deliberate for clinical form tables — pick them up too
    $commented = [];
    foreach ($cleanup->lines as $no => $line) {
        if (preg_match('/^\s*--\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([A-Za-z0-9_$]+)`?/i', $line, $m)) {
            $commented[$m[1]] = $no;
        }
    }

    foreach ($installTables as $name => $def) {
        if (isset($dropped[$name])) {
            continue;
        }
        if (isset($commented[$name])) {
            $F->warn(
                $cleanup->name,
                $commented[$name],
                'CLEANUP-COMMENTED',
                "`$name` is created by {$def->file} but its DROP in cleanup.sql is commented out",
                'intentional for tables holding signed clinical data — confirm the comment says so'
            );
            continue;
        }
        $F->error(
            $cleanup->name,
            0,
            'CLEANUP-MISSING',
            "table `$name` (created at {$def->file}:{$def->line}) has no DROP TABLE in cleanup.sql",
            "add `DROP TABLE IF EXISTS \\`$name\\`;` in reverse dependency order"
        );
    }
    foreach ($dropped as $name => $line) {
        if (!isset($installTables[$name])) {
            $F->error(
                $cleanup->name,
                $line,
                'CLEANUP-ORPHAN',
                "cleanup.sql drops `$name`, which the install SQL never creates",
                'remove the stale DROP, or add the missing CREATE TABLE'
            );
        }
    }

    // drop ordering vs FK direction: a parent must not be dropped before its child
    $pos = [];
    $i = 0;
    foreach ($cleanup->stmts as $s) {
        if (preg_match('/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([A-Za-z0-9_$]+)`?/is', ltrim($s->sql), $m)) {
            $pos[$m[1]] = ['order' => $i++, 'line' => $s->startLine];
        }
    }
    foreach ($installTables as $child => $def) {
        foreach ($def->fks as $fk) {
            $parent = $fk['refTable'];
            if (!isset($pos[$child], $pos[$parent])) {
                continue;
            }
            if ($pos[$parent]['order'] < $pos[$child]['order']) {
                $F->error(
                    $cleanup->name,
                    $pos[$parent]['line'],
                    'CLEANUP-ORDER',
                    "`$parent` is dropped before `$child`, which has a FOREIGN KEY to it; with FOREIGN_KEY_CHECKS on, that DROP fails",
                    "move `$parent`'s DROP after `$child`'s"
                );
            }
        }
    }
}

/** @param array<string,TableDef> $tables */
function checkContracts(array $tables, string $contractsPath, Findings $F): void
{
    $txt = file_get_contents($contractsPath);
    if ($txt === false) {
        $F->error('CONTRACTS.md', 0, 'IO', 'cannot read CONTRACTS.md', '');
        return;
    }
    $lines = preg_split("/\n/", $txt) ?: [];
    $documented = [];
    foreach ($lines as $i => $line) {
        // a markdown table row whose first cell is a backticked table name
        if (preg_match('/^\|\s*`(zdoc_[a-z0-9_]+|form_zdoc_[a-z0-9_]+)`\s*\|/i', $line, $m)) {
            $documented[$m[1]] ??= $i + 1;
        }
    }
    foreach ($documented as $name => $line) {
        if (!isset($tables[$name])) {
            $F->error(
                'CONTRACTS.md',
                $line,
                'CONTRACT-NO-TABLE',
                "CONTRACTS.md documents table `$name` in its table reference, but no CREATE TABLE for it exists in the schema",
                'create the table, or remove it from the contract'
            );
        }
    }
    foreach ($tables as $name => $def) {
        if (!isset($documented[$name])) {
            $F->warn(
                $def->file,
                $def->line,
                'TABLE-UNDOCUMENTED',
                "table `$name` is created but does not appear as a row in CONTRACTS.md's table reference (§2)",
                'add it to the contract, or confirm it is scaffold-internal'
            );
        }
    }
    printf("  CONTRACTS.md documents %d tables; schema creates %d.\n", count($documented), count($tables));
}

/** @param array<string,TableDef> $tables */
function checkVocabularies(array $tables, SqlFile $vocab, Findings $F): void
{
    foreach ($vocab->stmts as $s) {
        $sql = ltrim($s->sql);
        if (preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?([A-Za-z0-9_$]+)`?/is', $sql, $m)) {
            $t = $m[1];
            if (!isset($tables[$t]) && !in_array(strtolower($t), CORE_TABLES, true)) {
                $F->error(
                    $vocab->name,
                    $s->startLine,
                    'VOCAB-TABLE-MISSING',
                    "seed data inserts into `$t`, which the schema does not create",
                    'correct the table name, or add the table to the schema'
                );
            }
            // referenced columns must exist
            if (isset($tables[$t]) && preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?' . preg_quote($t, '/') . '`?\s*\(([^)]*)\)/is', $sql, $mc)) {
                foreach (idents($mc[1]) as $c) {
                    if (!isset($tables[$t]->columns[$c])) {
                        $F->error(
                            $vocab->name,
                            $s->startLine,
                            'VOCAB-COL-MISSING',
                            "seed INSERT sets `$t`.`$c`, which the table does not define",
                            'correct the column name'
                        );
                    }
                }
            }
        }
        // FROM / JOIN targets
        if (preg_match_all('/\b(?:FROM|JOIN|UPDATE)\s+`(zdoc_[a-z0-9_]+)`/i', $sql, $mm)) {
            foreach (array_unique($mm[1]) as $t) {
                if (!isset($tables[$t])) {
                    $F->error($vocab->name, $s->startLine, 'VOCAB-TABLE-MISSING', "seed data reads `$t`, which the schema does not create", 'correct the table name');
                }
            }
        }
    }
}

/** Statements referencing a zdoc_ table that is never created anywhere. */
function checkDanglingReferences(array $tables, SqlFile $f, Findings $F): void
{
    foreach ($f->stmts as $s) {
        if (preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO|REFERENCES)\s+`?(zdoc_[a-z0-9_]+)`?/i', $s->sql, $mm)) {
            foreach (array_unique($mm[1]) as $t) {
                if (!isset($tables[$t])) {
                    $F->error(
                        $f->name,
                        $s->startLine,
                        'REF-MISSING-TABLE',
                        "statement references `$t`, which no CREATE TABLE in this schema defines",
                        'correct the name, or add the table'
                    );
                }
            }
        }
    }
}

/** 5. Parse sanity. */
function checkParseSanity(SqlFile $f, Findings $F): void
{
    foreach ($f->stmts as $s) {
        $sql = trim($s->sql);
        if ($sql === '') {
            continue;
        }
        if (!preg_match('/^(CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|SET|REPLACE|TRUNCATE|RENAME|GRANT|START|COMMIT|ROLLBACK|LOCK|UNLOCK|CALL|ANALYZE|OPTIMIZE|USE|SELECT|DO|PREPARE|EXECUTE|DEALLOCATE|FLUSH|SIGNAL|SHOW)\b/i', $sql)) {
            $F->error(
                $f->name,
                $s->startLine,
                'SQL-MALFORMED',
                'statement does not start with a recognised SQL verb: "' . $s->firstWords(6) . ' …"',
                'check for a stray semicolon splitting the previous statement, or a typo'
            );
            continue;
        }
        // unbalanced parentheses (quote-aware)
        if (parenBalance($sql) !== 0) {
            $F->error($f->name, $s->startLine, 'SQL-PARENS', 'unbalanced parentheses in: "' . $s->firstWords(6) . ' …"', 'fix the parentheses');
        }
        // trailing comma before ')'
        if (preg_match('/,\s*\)/', $sql)) {
            $F->error($f->name, $s->startLine, 'SQL-TRAILING-COMMA', 'trailing comma before a closing parenthesis in: "' . $s->firstWords(6) . ' …"', 'remove the comma');
        }
        if (preg_match('/^CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/is', $sql) && $s->guards === []) {
            $F->warn($f->name, $s->startLine, 'SQL-NO-IFNE', 'CREATE TABLE without IF NOT EXISTS and without a directive guard: ' . $s->firstWords(4), 'add `#IfNotTable <name>` (preferred) or IF NOT EXISTS');
        }
    }
}

function parenBalance(string $s): int
{
    $d = 0;
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            $i++;
            while ($i < $n) {
                if ($s[$i] === '\\' && $q !== '`') {
                    $i += 2;
                    continue;
                }
                if ($s[$i] === $q) {
                    break;
                }
                $i++;
            }
            continue;
        }
        if ($c === '(') {
            $d++;
        }
        if ($c === ')') {
            $d--;
        }
    }
    return $d;
}

/**
 * Line hygiene for a file that will be fed to SQLUpgradeService.
 *
 * The service is line-oriented: it reads a line, decides whether it is a directive,
 * and otherwise appends it to a query buffer which it flushes at a `;`. Two things
 * bite here — more than one statement crammed onto a line, and dynamic SQL, whose
 * effect the directive layer cannot see or guard.
 */
function checkInstallLineHygiene(SqlFile $f, Findings $F): void
{
    foreach ($f->lines as $no => $line) {
        if (str_starts_with($line, '#') || preg_match('/^\s*--/', $line)) {
            continue;
        }
        $stripped = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "''", $line) ?? $line;
        $stripped = preg_replace('/--.*$/', '', $stripped) ?? $stripped;
        if (substr_count($stripped, ';') > 1) {
            $F->warn(
                $f->name,
                $no,
                'INSTALL-MULTI-STMT',
                'more than one statement on a single line; SQLUpgradeService flushes its query buffer at the first `;` on a line, so the remainder can be mis-attributed to the next guard',
                'put one statement per line'
            );
        }
        if (preg_match('/^\s*(PREPARE|EXECUTE|DEALLOCATE)\b/i', $line)) {
            $F->warn(
                $f->name,
                $no,
                'INSTALL-DYNAMIC-SQL',
                'dynamic SQL (PREPARE/EXECUTE) in install SQL: the #If… directives cannot inspect a statement built at runtime, so idempotency rests entirely on the hand-written information_schema test',
                'prefer a directive-guarded static statement where one exists'
            );
        }
    }
}

/**
 * Constraint names that appear anywhere in a file's raw text, including inside
 * dynamic-SQL string literals. Used to catch constraints that live in the
 * consolidated DDL but never reach the install SQL.
 *
 * @return array<string,int> name => line
 */
function collectConstraintNames(string $path): array
{
    $out = [];
    $txt = @file_get_contents($path);
    if ($txt === false) {
        return $out;
    }
    foreach (preg_split("/\r\n|\n|\r/", $txt) ?: [] as $i => $line) {
        if (preg_match_all('/\bCONSTRAINT\s+`([A-Za-z0-9_$]+)`/i', $line, $m)) {
            foreach ($m[1] as $n) {
                $out[$n] ??= $i + 1;
            }
        }
    }
    return $out;
}

/** @return array<string,string> prefix => owner, read from the survey's ownership table */
function loadOwnershipMap(string $path, Findings $F): array
{
    $out = [];
    $txt = @file_get_contents($path);
    if ($txt === false) {
        $F->warn('EXISTING-MODULES-SURVEY.md', 0, 'IO', 'ownership map not readable; prefix-collision check limited to core tables', 'check the path');
        return $out;
    }
    foreach (preg_split("/\n/", $txt) ?: [] as $line) {
        if (!preg_match('/^\|\s*(`[^|]+`)\s*\|\s*([^|]+?)\s*\|/', $line, $m)) {
            continue;
        }
        $owner = trim($m[2]);
        if ($owner === '' || strcasecmp($owner, 'Owner') === 0) {
            continue;
        }
        if (preg_match_all('/`([A-Za-z0-9_*]+)`/', $m[1], $mm)) {
            foreach ($mm[1] as $pfx) {
                $pfx = rtrim($pfx, '*');
                if ($pfx !== '' && !str_starts_with($pfx, 'zdoc')) {
                    $out[$pfx] = $owner;
                }
            }
        }
    }
    return $out;
}

// ===========================================================================
// CLI
// ===========================================================================

function usage(): void
{
    echo <<<TXT
validate-schema.php — static validator for the ZeroDoc module's OpenEMR install SQL.

USAGE
  php validate-schema.php [--install=PATH] [--ddl=PATH] [--cleanup=PATH]
                          [--vocab=PATH] [--contracts=PATH] [--survey=PATH]
                          [--quiet] [--warnings-as-errors] [--help]

Defaults are resolved relative to this file:
  --install    ../module/oe-module-zerodoc-encounter/table.sql   (directive-checked)
  --ddl        ./schema.sql                                      (structure only)
  --cleanup    ../module/oe-module-zerodoc-encounter/cleanup.sql
  --vocab      ./vocabularies.sql
  --contracts  ./CONTRACTS.md
  --survey     ../module/EXISTING-MODULES-SURVEY.md

Pass "--ddl=" (empty) or "--cleanup=" to skip a file.

EXIT STATUS
  0  no errors
  1  errors found
  2  bad invocation

No database is contacted and no input file is written to.

TXT;
}

$opts = getopt('', [
    'install::', 'ddl::', 'cleanup::', 'vocab::', 'contracts::', 'survey::',
    'quiet', 'warnings-as-errors', 'help',
]);
if ($opts === false) {
    usage();
    exit(2);
}
if (isset($opts['help'])) {
    usage();
    exit(0);
}

$here = __DIR__;
$mod = $here . '/../module/oe-module-zerodoc-encounter';

/** getopt() gives `false` for `--opt=`; treat that, and '', as "skip this file". */
$opt = static function (array $opts, string $k, string $default): string {
    if (!array_key_exists($k, $opts)) {
        return $default;
    }
    $v = $opts[$k];
    return is_string($v) ? $v : '';
};

$paths = [
    'install'   => $opt($opts, 'install', "$mod/table.sql"),
    'ddl'       => $opt($opts, 'ddl', "$here/schema.sql"),
    'cleanup'   => $opt($opts, 'cleanup', "$mod/cleanup.sql"),
    'vocab'     => $opt($opts, 'vocab', "$here/vocabularies.sql"),
    'contracts' => $opt($opts, 'contracts', "$here/CONTRACTS.md"),
    'survey'    => $opt($opts, 'survey', "$here/../module/EXISTING-MODULES-SURVEY.md"),
];
$quiet = isset($opts['quiet']);

$F = new Findings();

function say(bool $quiet, string $s): void
{
    if (!$quiet) {
        echo $s;
    }
}

say($quiet, "validate-schema.php — static analysis, no database contacted\n");
say($quiet, str_repeat('=', 78) . "\n");

$ownership = loadOwnershipMap((string) $paths['survey'], $F);
say($quiet, sprintf("  ownership map: %d taken prefixes loaded\n", count($ownership)));

// --- install SQL (directive-aware) -----------------------------------------
$installTables = [];
$installFile = null;
if ($paths['install'] !== '' && is_file((string) $paths['install'])) {
    $installFile = lexFile((string) $paths['install'], basename((string) $paths['install']), $F, true);
    [$installTables] = parseTables($installFile, $F);
    checkGuardCoverage($installFile, $F);
    checkParseSanity($installFile, $F);
    checkInstallLineHygiene($installFile, $F);
    say($quiet, sprintf("  %s: %d statements, %d CREATE TABLE\n", $installFile->name, count($installFile->stmts), count($installTables)));
} elseif ($paths['install'] !== '') {
    $F->error('(install)', 0, 'IO', "install SQL not found at {$paths['install']}", 'pass --install=PATH');
}

// --- consolidated DDL (structure only; it is not run through the directive parser)
$ddlTables = [];
if ($paths['ddl'] !== '' && is_file((string) $paths['ddl'])) {
    $ddlFile = lexFile((string) $paths['ddl'], basename((string) $paths['ddl']), $F, false);
    [$ddlTables] = parseTables($ddlFile, $F);
    checkParseSanity($ddlFile, $F);
    say($quiet, sprintf("  %s: %d statements, %d CREATE TABLE\n", $ddlFile->name, count($ddlFile->stmts), count($ddlTables)));
}

// The authoritative table set for structural checks: the install SQL if present,
// else the consolidated DDL.
$tables = $installTables !== [] ? $installTables : $ddlTables;

checkNaming($tables, $ownership, $F);
checkStructural($tables, $F);
if ($installFile !== null) {
    checkDanglingReferences($tables, $installFile, $F);
}

// install SQL vs consolidated DDL
if ($installTables !== [] && $ddlTables !== []) {
    foreach ($ddlTables as $n => $d) {
        if (!isset($installTables[$n])) {
            $F->error('schema.sql', $d->line, 'DRIFT-INSTALL', "`$n` is defined in schema.sql but is missing from the install SQL, so a real install never creates it", 'drop it into table.sql inside `#IfNotTable` markers');
        }
    }
    foreach ($installTables as $n => $d) {
        if (!isset($ddlTables[$n])) {
            $F->warn($d->file, $d->line, 'DRIFT-DDL', "`$n` is created by the install SQL but is absent from schema.sql", 'fold it into the consolidated DDL, or confirm it is scaffold-owned');
        }
    }

    // constraints present in the consolidated DDL but missing from the install SQL
    $ddlCons = collectConstraintNames((string) $paths['ddl']);
    $instCons = collectConstraintNames((string) $paths['install']);
    foreach ($ddlCons as $cname => $cline) {
        if (!isset($instCons[$cname])) {
            $F->error(
                'schema.sql',
                $cline,
                'DRIFT-CONSTRAINT',
                "constraint `$cname` is declared in schema.sql but appears nowhere in the install SQL, so a real install never creates it",
                'carry the constraint into table.sql, or remove it from the consolidated DDL'
            );
        }
    }

    // column-level drift for tables defined in both files
    foreach ($installTables as $n => $d) {
        if (!isset($ddlTables[$n])) {
            continue;
        }
        $only = array_diff(array_keys($ddlTables[$n]->columns), array_keys($d->columns));
        foreach ($only as $c) {
            $F->error(
                'schema.sql',
                $ddlTables[$n]->columns[$c],
                'DRIFT-COLUMN',
                "`$n`.`$c` exists in schema.sql but not in the install SQL's definition of the same table",
                'reconcile the two definitions — the install SQL is what actually runs'
            );
        }
        $extra = array_diff(array_keys($d->columns), array_keys($ddlTables[$n]->columns));
        foreach ($extra as $c) {
            $F->warn($d->file, $d->columns[$c], 'DRIFT-COLUMN', "`$n`.`$c` exists in the install SQL but not in schema.sql", 'reconcile the two definitions');
        }
    }
}

if ($paths['cleanup'] !== '' && is_file((string) $paths['cleanup'])) {
    $cleanupFile = lexFile((string) $paths['cleanup'], basename((string) $paths['cleanup']), $F, false);
    checkCleanup($tables, $cleanupFile, $F);
    checkParseSanity($cleanupFile, $F);
    say($quiet, sprintf("  %s: %d statements\n", $cleanupFile->name, count($cleanupFile->stmts)));
}

if ($paths['vocab'] !== '' && is_file((string) $paths['vocab'])) {
    $vocabFile = lexFile((string) $paths['vocab'], basename((string) $paths['vocab']), $F, true);
    checkVocabularies($tables, $vocabFile, $F);
    checkGuardCoverage($vocabFile, $F);
    checkParseSanity($vocabFile, $F);
    say($quiet, sprintf("  %s: %d statements\n", $vocabFile->name, count($vocabFile->stmts)));
}

if ($paths['contracts'] !== '' && is_file((string) $paths['contracts'])) {
    checkContracts($tables, (string) $paths['contracts'], $F);
}

// ===========================================================================
// Report
// ===========================================================================

usort($F->items, function ($a, $b) {
    return [$a['sev'] === 'WARNING' ? 1 : 0, $a['code'], $a['file'], $a['line']]
        <=> [$b['sev'] === 'WARNING' ? 1 : 0, $b['code'], $b['file'], $b['line']];
});

$errors = $F->count('ERROR');
$warnings = $F->count('WARNING');

echo "\n" . str_repeat('=', 78) . "\n";
if ($F->items === []) {
    echo "No findings.\n";
}
$lastGroup = null;
foreach ($F->items as $it) {
    $group = $it['sev'] . '|' . $it['code'];
    if ($group !== $lastGroup) {
        echo "\n[{$it['sev']}] {$it['code']}\n";
        $lastGroup = $group;
    }
    $loc = $it['line'] > 0 ? "{$it['file']}:{$it['line']}" : $it['file'];
    echo "  $loc\n";
    echo "      " . $it['msg'] . "\n";
    if ($it['fix'] !== '') {
        echo "      fix: " . $it['fix'] . "\n";
    }
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("SUMMARY: %d error(s), %d warning(s) across %d table(s).\n", $errors, $warnings, count($tables));

// per-code tally
$tally = [];
foreach ($F->items as $it) {
    $tally[$it['sev'] . ' ' . $it['code']] = ($tally[$it['sev'] . ' ' . $it['code']] ?? 0) + 1;
}
ksort($tally);
foreach ($tally as $k => $v) {
    printf("  %-34s %d\n", $k, $v);
}

if ($errors > 0 || (isset($opts['warnings-as-errors']) && $warnings > 0)) {
    exit(1);
}
exit(0);
