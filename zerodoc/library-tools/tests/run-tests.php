#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ZeroDoc Clinical Encounter Workflow - WP-06 test suite.
 *
 *   php tests/run-tests.php
 *
 * What it proves:
 *   1. the clean fixture produces no errors and no warnings
 *   2. every fail fixture fires the validator it was written for
 *   3. a clean library imports, and the row counts are right
 *   4. an import that fails part-way leaves the database untouched (rollback)
 *   5. a library that fails validation writes nothing at all
 *   6. export -> re-import -> export round-trips to the same thing
 *   7. the bundle audit counts templates and emitted findings correctly
 *   8. the YAML subset parser handles what FORMAT.md tells people to write
 */

require __DIR__ . '/../src/autoload.php';

use ZeroDoc\LibraryTools\Audit\BundleAudit;
use ZeroDoc\LibraryTools\Export\Exporter;
use ZeroDoc\LibraryTools\Import\Importer;
use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Validator;
use ZeroDoc\LibraryTools\Yaml\Yaml;

$root     = dirname(__DIR__);
$dataDir  = $root . '/data';
$fixtures = $root . '/tests/fixtures';

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        fwrite(STDOUT, "  PASS  {$what}\n");
        return;
    }
    $failed++;
    fwrite(STDOUT, "  FAIL  {$what}" . ($detail !== '' ? "\n          {$detail}" : '') . "\n");
}

function freshDb(string $root): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    // Strip "--" comments before splitting on ";" - a comment may contain one.
    $sql = (string) file_get_contents($root . '/tests/schema.sqlite.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
    // Triggers go in whole: their bodies contain semicolons.
    $pdo->exec((string) file_get_contents($root . '/tests/schema.sqlite.triggers.sql'));
    return $pdo;
}

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n1. Clean fixture\n");

$validator = new Validator($dataDir);
$clean     = Library::load($fixtures . '/pass/clean-library.yaml');
$report    = $validator->run($clean);

check(
    'clean-library.yaml has no errors',
    $report->errors() === [],
    implode("\n          ", array_map(static fn ($i) => $i->code . ': ' . $i->message, $report->errors()))
);
check(
    'clean-library.yaml has no warnings either',
    $report->warnings() === [],
    implode("\n          ", array_map(static fn ($i) => $i->code . ': ' . $i->message, $report->warnings()))
);
check('clean-library.yaml passes under --strict', $report->passed(true));
check(
    'clean-library.yaml parsed the expected contents',
    $clean->counts() === [
        'templates' => 4, 'saved_findings' => 2, 'diagnosis_sets' => 2,
        'plan_groups' => 6, 'order_sets' => 2,
    ],
    json_encode($clean->counts())
);

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n2. Each validator fires on its fixture\n");

// "blocking" means the fixture must produce an ERROR, so an import is refused
// outright. The two warning-only rules - near-duplicate names and polarity
// tagging - are judgement calls with legitimate exceptions, so they surface for
// review and block only under --strict.
$expectations = [
    'symmetry.yaml'              => ['codes' => ['SYMMETRY'],    'blocking' => true],
    'duplicate-codes.yaml'       => ['codes' => ['DUP_CODE'],    'blocking' => true],
    'vocabulary-drift.yaml'      => ['codes' => ['VOCAB'],       'blocking' => true],
    'spelling.yaml'              => ['codes' => ['SPELLING'],    'blocking' => true],
    'near-duplicate-names.yaml'  => ['codes' => ['NEAR_DUP'],    'blocking' => false],
    'laterality-in-name.yaml'    => ['codes' => ['LAT_IN_NAME'], 'blocking' => true],
    'icd10.yaml'                 => ['codes' => ['ICD10'],       'blocking' => true],
    'missing-mirror.yaml'        => ['codes' => ['MIRROR'],      'blocking' => true],
    'polarity.yaml'              => ['codes' => ['POLARITY'],    'blocking' => false],
    'bundle-overreach.yaml'      => ['codes' => ['BUNDLE'],      'blocking' => true],
    'integrity.yaml'             => ['codes' => ['INTEGRITY'],   'blocking' => true],
    'structure.yaml'             => ['codes' => ['STRUCTURE'],   'blocking' => true],
];

foreach ($expectations as $file => $expect) {
    $lib = Library::load($fixtures . '/fail/' . $file);
    $rep = $validator->run($lib);
    $found = array_keys($rep->byCode());
    foreach ($expect['codes'] as $code) {
        check(
            "{$file} fires {$code}",
            in_array($code, $found, true),
            'got: ' . (implode(', ', $found) ?: 'nothing')
        );
    }
    check(
        $expect['blocking']
            ? "{$file} blocks an import"
            : "{$file} is reported, and blocks under --strict",
        $expect['blocking'] ? !$rep->passed() : ($rep->passed() && !$rep->passed(true))
    );
}

// Specific, not just "something fired": the messages have to name the offender.
$sym = $validator->run(Library::load($fixtures . '/fail/symmetry.yaml'), ['SYMMETRY']);
$symText = implode(' ', array_map(static fn ($i) => $i->message, $sym->issues));
check('symmetry message names the asymmetric options',
    str_contains($symText, 'gluteus medius') && str_contains($symText, 'sciatic notch'),
    $symText);

$icd = $validator->run(Library::load($fixtures . '/fail/icd10.yaml'), ['ICD10']);
$icdText = implode(' ', array_map(static fn ($i) => $i->message, $icd->issues));
check('ICD-10 message names both sides of the specificity mismatch',
    str_contains($icdText, 'M54.16') && str_contains($icdText, 'M54.17'),
    $icdText);

$vocab = $validator->run(Library::load($fixtures . '/fail/vocabulary-drift.yaml'), ['VOCAB']);
$vocabText = implode(' ', array_map(static fn ($i) => $i->message, $vocab->issues));
check('vocabulary message names the missing scale member',
    str_contains($vocabText, 'helped significantly'), $vocabText);

$bundle = $validator->run(Library::load($fixtures . '/fail/bundle-overreach.yaml'), ['BUNDLE']);
$bundleText = implode(' ', array_map(static fn ($i) => $i->message, $bundle->issues));
check('bundle message names the general exam it pulls in',
    str_contains($bundleText, 'General Adult Examination'), $bundleText);

$pol = $validator->run(Library::load($fixtures . '/fail/polarity.yaml'), ['POLARITY']);
check('polarity rule flags all seven mis-tagged options in the fixture',
    count($pol->issues) === 7, (string) count($pol->issues));
check('polarity findings are warnings, never errors', $pol->errors() === []);

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n3. Import\n");

$pdo    = freshDb($root);
$result = (new Importer($pdo, $dataDir))->import($clean);
check('clean library imports', $result['ok'], (string) $result['error']);
check('row counts are right',
    ($result['stats']['templates'] ?? 0) === 4
    && ($result['stats']['saved_findings'] ?? 0) === 2
    && ($result['stats']['order_sets'] ?? 0) === 2
    && ($result['stats']['diagnosis_sets'] ?? 0) === 2
    && ($result['stats']['plan_groups'] ?? 0) === 6,
    json_encode($result['stats']));

$optionCount = (int) $pdo->query('SELECT COUNT(*) FROM zdoc_template_option')->fetchColumn();
check('vocabulary references were expanded into options', $optionCount > 60, (string) $optionCount);
$reflexOptions = (int) $pdo->query(
    "SELECT COUNT(*) FROM zdoc_template_option o
     JOIN zdoc_template_element e ON e.id = o.element_id
     WHERE e.label = 'Deep Tendon Reflexes - Right'"
)->fetchColumn();
check('reflex_grade expanded to its five members', $reflexOptions === 5, (string) $reflexOptions);

$excl = $pdo->query(
    "SELECT excludes_option_ids FROM zdoc_template_option WHERE label = 'improving'"
)->fetchColumn();
check('mutual exclusion resolved to option ids',
    is_string($excl) && preg_match('/^\[\d+\]$/', $excl) === 1, (string) $excl);

$linked = (int) $pdo->query(
    'SELECT COUNT(*) FROM zdoc_diagnosis_set_item WHERE linked_order_set_id IS NOT NULL'
)->fetchColumn();
check('linked order set resolved to an id', $linked === 1, (string) $linked);

check('deferred fields were reported, not dropped silently',
    $result['deferred'] !== []
    && in_array('saved_finding.laterality', array_column($result['deferred'], 'field'), true)
    === false,  // the clean fixture sets no entry-level laterality
    json_encode(array_column($result['deferred'], 'field')));

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n4. Rollback\n");

// A clean library that fails late inside the transaction, by fault injection -
// everything before the failure is already inserted when it happens.
$pdo2 = freshDb($root);
$bad  = Library::load($fixtures . '/rollback/fault-injection.yaml');
check('the rollback fixture itself is valid',
    $validator->run($bad)->passed(true),
    'the fixture must fail at the database, not at validation');
$res2 = (new Importer($pdo2, $dataDir))->import($bad);
check('an import that fails part-way is refused', !$res2['ok'], 'it reported success');
foreach (['zdoc_template', 'zdoc_template_element', 'zdoc_template_option',
          'zdoc_order_set', 'zdoc_order_set_item',
          'zdoc_saved_finding', 'zdoc_saved_finding_template',
          'zdoc_saved_finding_preset'] as $table) {
    $n = (int) $pdo2->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    check("rollback left {$table} empty", $n === 0, "{$n} rows survived");
}
check('the rollback is stated in the log',
    in_array('Rolled back. No rows were written.', $res2['log'], true),
    implode(' | ', $res2['log']));

// A defect that reaches the importer only because a preset value is not
// structurally wrong - caught by a validator before any write.
$pdo2b = freshDb($root);
$res2b = (new Importer($pdo2b, $dataDir))->import(
    Library::load($fixtures . '/rollback/preset-points-nowhere.yaml')
);
check('a preset naming an option that does not exist is refused', !$res2b['ok']);
check('nothing was written for it',
    (int) $pdo2b->query('SELECT COUNT(*) FROM zdoc_template')->fetchColumn() === 0);

$pdo3 = freshDb($root);
$res3 = (new Importer($pdo3, $dataDir))->import(Library::load($fixtures . '/fail/symmetry.yaml'));
check('a library that fails validation is never written', !$res3['ok']);
check('nothing was written for the invalid library',
    (int) $pdo3->query('SELECT COUNT(*) FROM zdoc_template')->fetchColumn() === 0);
check('the refusal says validation failed and nothing was written',
    str_contains((string) $res3['error'], 'Nothing was written'), (string) $res3['error']);

$pdo4 = freshDb($root);
$res4 = (new Importer($pdo4, $dataDir))->import($clean, false, true);
check('a dry run reports success', $res4['ok'], (string) $res4['error']);
check('a dry run writes nothing',
    (int) $pdo4->query('SELECT COUNT(*) FROM zdoc_template')->fetchColumn() === 0);

$res5 = (new Importer($pdo, $dataDir))->import($clean);  // $pdo already holds it
check('importing the same library twice is refused, not merged', !$res5['ok']);
check('the collision message names the code or the entry',
    str_contains((string) $res5['error'], 'already exists'), (string) $res5['error']);

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n5. Round trip\n");

$exportedYaml = (new Exporter($pdo))->toYaml('round trip');
$tmp = sys_get_temp_dir() . '/zdoc-roundtrip-1.yaml';
file_put_contents($tmp, $exportedYaml);

$reloaded = Library::load($tmp);
check('the export parses as valid input', $reloaded->structureIssues === [],
    implode('; ', array_map(static fn ($i) => $i->message, $reloaded->structureIssues)));
check('the export still validates',
    $validator->run($reloaded)->errors() === [],
    implode('; ', array_map(static fn ($i) => $i->code . ' ' . $i->message,
        $validator->run($reloaded)->errors())));
check('the export round-trips the same contents', $reloaded->counts() === $clean->counts(),
    json_encode($reloaded->counts()));

$pdoB = freshDb($root);
$resB = (new Importer($pdoB, $dataDir))->import($reloaded);
check('the export re-imports', $resB['ok'], (string) $resB['error']);
$secondExport = (new Exporter($pdoB))->toArray('round trip');
$firstExport  = (new Exporter($pdo))->toArray('round trip');
unset($firstExport['library'], $secondExport['library']);   // timestamps differ
check('export -> import -> export is stable', $firstExport === $secondExport,
    'the second export differs from the first');
@unlink($tmp);

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n6. Bundle audit\n");

$auditRows = (new BundleAudit($clean))->rows();
check('the audit covers every saved finding', count($auditRows) === 2, (string) count($auditRows));
$lumbar = null;
foreach ($auditRows as $row) {
    if (str_contains($row['finding']['name'], 'Lumbar')) {
        $lumbar = $row;
    }
}
check('the audit found the lumbar finding', $lumbar !== null);
if ($lumbar !== null) {
    check('it reports one bundled template', count($lumbar['templates']) === 1);
    check('it counts the emitted findings', $lumbar['emitted_findings'] === 10,
        (string) $lumbar['emitted_findings']);
    check('it counts the elements left blank',
        $lumbar['templates'][0]['untouched_elements'] !== []);
}

$overreach = (new BundleAudit(Library::load($fixtures . '/fail/bundle-overreach.yaml')))->rows();
check('the audit reports a missing bundled template',
    $overreach[0]['templates'][4]['missing'] === true);
check('the audit reports the broken preset', $overreach[0]['orphan_presets'] !== []);

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n7. YAML subset parser\n");

$parsed = Yaml::parse(<<<'YAML'
library:
  name: Parser cases
  version: 2
  active: true
  retired: false
  owner: ~
templates:
  - name: "Quoted: with a colon"
    section_key: hpi
    tags: [one, two, three]
    meta: {a: 1, b: two}
    elements:
      - label: Severity
        options:
          - label: current __/10
            value_slots:
              - type: numeric
                max: 10
                suffix: /10
      - label: Note   # a trailing comment
        narrative: |-
          first line
          second line
        folded: >-
          one
          two
YAML);

check('scalars are typed', $parsed['library']['version'] === 2
    && $parsed['library']['active'] === true
    && $parsed['library']['retired'] === false
    && $parsed['library']['owner'] === null);
check('quoted keys and colons in values survive',
    $parsed['templates'][0]['name'] === 'Quoted: with a colon');
check('flow sequences parse', $parsed['templates'][0]['tags'] === ['one', 'two', 'three']);
check('flow mappings parse', $parsed['templates'][0]['meta'] === ['a' => 1, 'b' => 'two']);
check('nested sequence-of-mappings parses',
    $parsed['templates'][0]['elements'][0]['options'][0]['value_slots'][0]['max'] === 10);
check('a value with a slash is not mangled',
    $parsed['templates'][0]['elements'][0]['options'][0]['label'] === 'current __/10');
check('trailing comments are stripped',
    $parsed['templates'][0]['elements'][1]['label'] === 'Note');
check('literal block scalars keep their line breaks',
    $parsed['templates'][0]['elements'][1]['narrative'] === "first line\nsecond line");
check('folded block scalars join their lines',
    $parsed['templates'][0]['elements'][1]['folded'] === 'one two');

$errors = [
    "templates:\n\t- name: tabbed"                  => 'tab',
    "templates:\n  - name: \"unterminated\n"        => 'unterminated',
    "templates:\n  - name: a\n    name: b\n"        => 'duplicate key',
    "templates:\n  - name: &anchor x\n"             => 'anchors',
];
foreach ($errors as $yaml => $expect) {
    $msg = '';
    try {
        Yaml::parse($yaml);
    } catch (\ZeroDoc\LibraryTools\Yaml\YamlException $e) {
        $msg = $e->getMessage();
    }
    check("parser rejects: {$expect}", str_contains(strtolower($msg), $expect), $msg ?: 'no error raised');
}

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n8. Housekeeping\n");

// Assembled from parts so the banned string does not appear in this file either.
$bannedWord = 'Patient' . 'Now';
$banned = [$bannedWord, strtolower($bannedWord), strtoupper($bannedWord)];
$offenders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile()) {
        continue;
    }
    if (preg_match('/\.(php|md|yaml|yml|json|txt|sql)$/i', $file->getFilename()) !== 1) {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    foreach ($banned as $needle) {
        if (str_contains($contents, $needle)) {
            $offenders[] = $file->getPathname();
            break;
        }
    }
}
check('the banned product name appears nowhere', $offenders === [], implode(', ', $offenders));

$schemaSrc = (string) file_get_contents($root . '/src/Import/Schema.php');
check('every table name carries the zdoc_ prefix',
    preg_match('/PREFIX\s*=\s*\'zdoc_\'/', $schemaSrc) === 1
    && !str_contains($schemaSrc, 'aem_'));

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n" . str_repeat('=', 58) . "\n");
fwrite(STDOUT, sprintf("%d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
