#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ZeroDoc Clinical Encounter Workflow - library exporter.
 *
 *   php bin/export-library.php --dsn="sqlite:/tmp/library.db" > library.yaml
 *   php bin/export-library.php --dsn=... --name="CSS PE library" --out=review.yaml
 *   php bin/export-library.php --dsn=... --json
 *
 * The output is valid input: export, then validate or re-import it.
 *
 * Exit codes: 0 fine, 1 database problem, 2 bad usage.
 */

require __DIR__ . '/../src/autoload.php';

use ZeroDoc\LibraryTools\Export\Exporter;

$argv = $_SERVER['argv'];
array_shift($argv);

$dsn = $user = $pass = $out = null;
$name = 'exported library';
$json = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dsn=')) {
        $dsn = substr($arg, 6);
    } elseif (str_starts_with($arg, '--user=')) {
        $user = substr($arg, 7);
    } elseif (str_starts_with($arg, '--pass=')) {
        $pass = substr($arg, 7);
    } elseif (str_starts_with($arg, '--name=')) {
        $name = substr($arg, 7);
    } elseif (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
    } elseif ($arg === '--json') {
        $json = true;
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
}

if ($dsn === null) {
    fwrite(STDERR, "Usage: export-library.php --dsn=<pdo-dsn> [--user=] [--pass=] [--name=] [--out=file] [--json]\n");
    exit(2);
}

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $exporter = new Exporter($pdo);
    $text = $json
        ? json_encode($exporter->toArray($name), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        : $exporter->toYaml($name);
} catch (PDOException $e) {
    fwrite(STDERR, 'Export failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($out !== null) {
    file_put_contents($out, $text);
    fwrite(STDOUT, "Written to {$out} (" . strlen($text) . " bytes)\n");
} else {
    fwrite(STDOUT, $text);
}
exit(0);
