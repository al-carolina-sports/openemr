#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ZeroDoc Clinical Encounter Workflow - library validator.
 *
 * Runs every validator against one or more library files. No database, no
 * composer, no configuration: this is the tool the content track runs on its own
 * work before handing anything over.
 *
 *   php bin/validate-library.php hpi-templates.yaml
 *   php bin/validate-library.php library/            # every file in a folder
 *   php bin/validate-library.php lib.yaml --errors-only
 *   php bin/validate-library.php lib.yaml --only SYMMETRY,MIRROR
 *   php bin/validate-library.php lib.yaml --json > issues.json
 *   php bin/validate-library.php --list
 *
 * Exit codes: 0 clean, 1 errors (or warnings under --strict), 2 bad usage.
 */

require __DIR__ . '/../src/autoload.php';

use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Validator;

$dataDir = __DIR__ . '/../data';
$argv    = $_SERVER['argv'];
array_shift($argv);

$paths = [];
$only = $skip = [];
$json = $strict = $errorsOnly = $list = false;

foreach ($argv as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif ($arg === '--strict') {
        $strict = true;
    } elseif ($arg === '--errors-only') {
        $errorsOnly = true;
    } elseif ($arg === '--list') {
        $list = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, <<<TXT
        ZeroDoc Clinical Encounter Workflow - library validator

        Checks a transcribed template library. No database, no installation.
        Format and field reference: FORMAT.md.

          php bin/validate-library.php my-file.yaml
          php bin/validate-library.php library/           every file in a folder
          php bin/validate-library.php lib.yaml --errors-only
          php bin/validate-library.php lib.yaml --only SYMMETRY,MIRROR
          php bin/validate-library.php lib.yaml --skip=SPELLING
          php bin/validate-library.php lib.yaml --strict   warnings block too
          php bin/validate-library.php lib.yaml --json
          php bin/validate-library.php --list             what each check does

        Exit codes: 0 clean, 1 errors (or warnings under --strict), 2 bad usage.

        TXT);
        exit(0);
    } elseif (str_starts_with($arg, '--only=')) {
        $only = array_map('trim', explode(',', substr($arg, 7)));
    } elseif (str_starts_with($arg, '--only')) {
        $only = ['__next__'];
    } elseif (str_starts_with($arg, '--skip=')) {
        $skip = array_map('trim', explode(',', substr($arg, 7)));
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    } elseif ($only === ['__next__']) {
        $only = array_map('trim', explode(',', $arg));
    } else {
        $paths[] = $arg;
    }
}

$validator = new Validator($dataDir);

if ($list) {
    fwrite(STDOUT, "Validators:\n");
    foreach ($validator->rules() as $rule) {
        fwrite(STDOUT, sprintf("  %-12s %s\n", $rule->code(), $rule->describes()));
    }
    fwrite(STDOUT, "  STRUCTURE    Format shape: unknown fields, wrong types, missing required values\n");
    exit(0);
}

if ($paths === []) {
    fwrite(STDERR, "Usage: validate-library.php <file-or-folder>... [--json] [--strict] [--errors-only] [--only CODES] [--skip=CODES] [--list]\n");
    exit(2);
}
foreach ($paths as $p) {
    if (!file_exists($p)) {
        fwrite(STDERR, "No such file or folder: {$p}\n");
        exit(2);
    }
}

$library = Library::load(...$paths);
$report  = $validator->run($library, $only === ['__next__'] ? [] : $only, $skip);

if ($json) {
    fwrite(STDOUT, $report->toJson());
} else {
    $colour = function_exists('posix_isatty') && @posix_isatty(STDOUT);
    fwrite(STDOUT, $report->render($colour, !$errorsOnly));
}

exit($report->passed($strict) ? 0 : 1);
