#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ZeroDoc Clinical Encounter Workflow - transactional library importer.
 *
 *   php bin/import-library.php lib.yaml --dsn="mysql:host=localhost;dbname=openemr" --user=root --pass=secret
 *   php bin/import-library.php lib.yaml --dsn="sqlite:/tmp/library.db" --dry-run
 *   php bin/import-library.php lib.yaml --dsn=... --strict
 *
 * Validation runs first and in full. One error and nothing is written. Beyond
 * that, the whole load is one transaction: any failure rolls everything back, so
 * the database is either the library before the import or the library after it,
 * never half of each.
 *
 * Options:
 *   --dsn=       PDO DSN (required)
 *   --user=      database user
 *   --pass=      database password
 *   --dry-run    do the entire import inside a transaction, then roll back
 *   --strict     treat warnings as blocking too
 *   --deferred=  where to write the deferred-fields report (default
 *                deferred-fields.json beside the first input file)
 *
 * Exit codes: 0 imported, 1 refused or rolled back, 2 bad usage.
 */

require __DIR__ . '/../src/autoload.php';

use ZeroDoc\LibraryTools\Import\Importer;
use ZeroDoc\LibraryTools\Model\Library;

$argv = $_SERVER['argv'];
array_shift($argv);

$paths = [];
$dsn = $user = $pass = $deferredPath = null;
$dryRun = $strict = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dsn=')) {
        $dsn = substr($arg, 6);
    } elseif (str_starts_with($arg, '--user=')) {
        $user = substr($arg, 7);
    } elseif (str_starts_with($arg, '--pass=')) {
        $pass = substr($arg, 7);
    } elseif (str_starts_with($arg, '--deferred=')) {
        $deferredPath = substr($arg, 11);
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--strict') {
        $strict = true;
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    } else {
        $paths[] = $arg;
    }
}

if ($paths === [] || $dsn === null) {
    fwrite(STDERR, "Usage: import-library.php <file-or-folder>... --dsn=<pdo-dsn> [--user=] [--pass=] [--dry-run] [--strict]\n");
    exit(2);
}

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Cannot connect: ' . $e->getMessage() . "\n");
    exit(1);
}
if (str_starts_with($dsn, 'sqlite:')) {
    $pdo->exec('PRAGMA foreign_keys = ON');
}

$library = Library::load(...$paths);
$result  = (new Importer($pdo, __DIR__ . '/../data'))->import($library, $strict, $dryRun);

fwrite(STDOUT, $result['report']->render(false, true));

if (!$result['ok']) {
    fwrite(STDERR, "\nIMPORT REFUSED\n" . str_repeat('=', 58) . "\n");
    fwrite(STDERR, ($result['error'] ?? 'unknown failure') . "\n");
    foreach ($result['log'] as $line) {
        fwrite(STDERR, '  ' . $line . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "\nIMPORT " . ($dryRun ? 'DRY RUN' : 'COMPLETE') . "\n" . str_repeat('=', 58) . "\n");
foreach ($result['stats'] as $what => $n) {
    fwrite(STDOUT, sprintf("  %-26s %d\n", $what, $n));
}
foreach ($result['log'] as $line) {
    fwrite(STDOUT, '  ' . $line . "\n");
}

if ($result['deferred'] !== []) {
    $deferredPath ??= dirname($paths[0]) . '/deferred-fields.json';
    @file_put_contents($deferredPath, json_encode([
        'generated_at' => gmdate('c'),
        'explanation'  => 'These values are carried by the import format but have no '
            . 'column in the schema, so they were not written to the database. The '
            . 'schema is owned by the contracts package; see NOTES.md for the gaps.',
        'fields' => $result['deferred'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $byGap = [];
    foreach ($result['deferred'] as $d) {
        $byGap[$d['gap']][] = $d['field'];
    }
    fwrite(STDOUT, "\nNOT PERSISTED - schema gaps\n" . str_repeat('-', 58) . "\n");
    fwrite(STDOUT, "  " . count($result['deferred']) . " value(s) in the file have no column to go in.\n");
    foreach ($byGap as $gap => $fields) {
        fwrite(STDOUT, sprintf("  gap %-3s %s\n", $gap, implode(', ', array_unique($fields))));
    }
    fwrite(STDOUT, "  Written to: {$deferredPath}\n");
    fwrite(STDOUT, "  These are not transcription errors. Keep the YAML as the source of\n");
    fwrite(STDOUT, "  truth until the columns exist, then re-import.\n");
}

exit(0);
