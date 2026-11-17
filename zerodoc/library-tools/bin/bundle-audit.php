#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ZeroDoc Clinical Encounter Workflow - saved-finding bundle audit.
 *
 * Spec 06 §1.5: "An admin report MUST list, for every saved finding, which
 * templates it bundles and how many findings it would emit."
 *
 * This is the report that catches the defect the whole transparency requirement
 * exists for: a saved finding named for one narrow condition that silently
 * applies a full general physical exam, including sex-specific sections, on a
 * patient who had none of it done. The name tells you nothing about the breadth;
 * this table does.
 *
 *   php bin/bundle-audit.php pe-saved-findings.yaml
 *   php bin/bundle-audit.php library/ --flagged
 *   php bin/bundle-audit.php library/ --csv > bundles.csv
 *   php bin/bundle-audit.php --dsn="sqlite:/tmp/library.db"
 *
 * Options:
 *   --flagged      only the findings that need a human look
 *   --csv          one row per bundled template, for a spreadsheet review
 *   --threshold=N  emitted-finding count above which a bundle is flagged
 *                  (default 40)
 *   --dsn=         audit what is already in the database instead of a file
 *
 * Exit codes: 0 nothing flagged, 1 something flagged, 2 bad usage.
 */

require __DIR__ . '/../src/autoload.php';

use ZeroDoc\LibraryTools\Audit\BundleAudit;
use ZeroDoc\LibraryTools\Export\Exporter;
use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Rules\BundleRule;

$argv = $_SERVER['argv'];
array_shift($argv);

$paths = [];
$dsn = $user = $pass = null;
$csv = $flaggedOnly = false;
$threshold = BundleRule::BREADTH_WARN;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dsn=')) {
        $dsn = substr($arg, 6);
    } elseif (str_starts_with($arg, '--user=')) {
        $user = substr($arg, 7);
    } elseif (str_starts_with($arg, '--pass=')) {
        $pass = substr($arg, 7);
    } elseif (str_starts_with($arg, '--threshold=')) {
        $threshold = max(1, (int) substr($arg, 12));
    } elseif ($arg === '--csv') {
        $csv = true;
    } elseif ($arg === '--flagged') {
        $flaggedOnly = true;
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    } else {
        $paths[] = $arg;
    }
}

if ($paths === [] && $dsn === null) {
    fwrite(STDERR, "Usage: bundle-audit.php <file-or-folder>... | --dsn=<pdo-dsn> [--flagged] [--csv] [--threshold=N]\n");
    exit(2);
}

$temp = null;
if ($dsn !== null) {
    try {
        $pdo  = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $temp = tempnam(sys_get_temp_dir(), 'zdoc-audit-') . '.yaml';
        file_put_contents($temp, (new Exporter($pdo))->toYaml('database'));
        $paths[] = $temp;
    } catch (PDOException $e) {
        fwrite(STDERR, 'Cannot read the database: ' . $e->getMessage() . "\n");
        exit(2);
    }
}

$library = Library::load(...$paths);
$rows    = (new BundleAudit($library))->rows();
if ($temp !== null) {
    @unlink($temp);
}

/** A bundle needs a human look when any of these is true. */
$flagsFor = static function (array $row) use ($threshold): array {
    $flags = [];
    if ($row['emitted_findings'] > $threshold) {
        $flags[] = 'BREADTH';
    }
    if (count($row['templates']) > 2) {
        $flags[] = 'MULTI-TEMPLATE';
    }
    if ($row['sex_specific'] !== []) {
        $flags[] = 'SEX-SPECIFIC';
    }
    if ($row['orphan_presets'] !== []) {
        $flags[] = 'BROKEN-PRESET';
    }
    foreach ($row['templates'] as $t) {
        if ($t['missing']) {
            $flags[] = 'MISSING-TEMPLATE';
        }
        if ($t['preset_count'] === 0 && !$t['missing']) {
            $flags[] = 'BLANK-TEMPLATE';
        }
        $name = strtolower((string) $t['name']);
        foreach (['general', 'comprehensive', 'complete', 'full'] as $broad) {
            if (str_contains($name, $broad)
                && !str_contains(strtolower((string) $row['finding']['name']), $broad)) {
                $flags[] = 'GENERAL-EXAM';
            }
        }
    }
    return array_values(array_unique($flags));
};

if ($csv) {
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'saved_finding', 'section', 'laterality', 'bundled_template', 'template_section',
        'applies_to_sex', 'elements', 'options', 'presets_resolved',
        'elements_left_blank', 'finding_emitted_total', 'flags',
    ]);
    foreach ($rows as $row) {
        $flags = $flagsFor($row);
        if ($flaggedOnly && $flags === []) {
            continue;
        }
        foreach ($row['templates'] as $t) {
            fputcsv($out, [
                $row['finding']['name'], $row['finding']['section_key'],
                $row['finding']['laterality'],
                $t['missing'] ? $t['ref'] . ' (MISSING)' : $t['name'],
                $t['section_key'], $t['applies_to_sex'],
                $t['element_count'], $t['option_count'], $t['preset_count'],
                count($t['untouched_elements']),
                $row['emitted_findings'], implode(' ', $flags),
            ]);
        }
    }
    fclose($out);
    exit(0);
}

$flaggedCount = 0;
$lines = [];
$lines[] = 'ZeroDoc Clinical Encounter Workflow - saved-finding bundle audit';
$lines[] = str_repeat('=', 70);
$lines[] = 'Source:    ' . implode(', ', array_map('basename', $library->files));
$lines[] = 'Findings:  ' . count($rows);
$lines[] = 'Threshold: a bundle emitting more than ' . $threshold . ' findings is flagged';
$lines[] = '';
$lines[] = 'What to look for: a finding whose name names one thing and whose bundle';
$lines[] = 'documents many. Every template listed here is documented when the finding';
$lines[] = 'is applied, whether or not it was examined.';
$lines[] = '';

foreach ($rows as $row) {
    $finding = $row['finding'];
    $flags   = $flagsFor($row);
    if ($flags !== []) {
        $flaggedCount++;
    }
    if ($flaggedOnly && $flags === []) {
        continue;
    }

    $title = sprintf(
        '%s  [%s]%s',
        $finding['name'],
        $finding['section_key'],
        $finding['laterality'] !== 'n/a' ? '  laterality: ' . $finding['laterality'] : ''
    );
    $lines[] = $title;
    $lines[] = str_repeat('-', min(70, strlen($title)));
    $lines[] = sprintf(
        '  Bundles %d template(s); emits %d finding(s) across %d element(s).',
        count($row['templates']), $row['emitted_findings'], $row['total_elements']
    );
    if ($flags !== []) {
        $lines[] = '  FLAGGED: ' . implode(', ', $flags);
    }
    $lines[] = '';
    $lines[] = sprintf(
        '    %-34s %-7s %5s %5s %7s %6s',
        'template', 'section', 'elem', 'opts', 'presets', 'blank'
    );
    foreach ($row['templates'] as $t) {
        $lines[] = sprintf(
            '    %-34s %-7s %5s %5s %7s %6s%s',
            substr($t['missing'] ? $t['ref'] : (string) $t['name'], 0, 34),
            substr((string) $t['section_key'], 0, 7),
            $t['missing'] ? '-' : (string) $t['element_count'],
            $t['missing'] ? '-' : (string) $t['option_count'],
            (string) $t['preset_count'],
            $t['missing'] ? '-' : (string) count($t['untouched_elements']),
            $t['missing'] ? '   << NOT IN LIBRARY'
                : (($t['applies_to_sex'] ?? 'any') !== 'any' ? '   << ' . $t['applies_to_sex'] . ' only' : '')
        );
    }
    if ($row['sex_specific'] !== []) {
        $lines[] = '';
        $lines[] = '  Sex-specific templates in this bundle: ' . implode(', ', $row['sex_specific']);
        $lines[] = '  They are skipped for a patient they do not apply to, and the skip is';
        $lines[] = '  reported - but check they belong in this bundle at all.';
    }
    foreach ($row['templates'] as $t) {
        if (!$t['missing'] && $t['untouched_elements'] !== [] && $t['preset_count'] > 0) {
            $lines[] = '';
            $lines[] = sprintf(
                '  "%s": %d element(s) left blank: %s',
                $t['name'], count($t['untouched_elements']),
                implode(', ', array_slice($t['untouched_elements'], 0, 8))
                . (count($t['untouched_elements']) > 8 ? ', ...' : '')
            );
        }
    }
    if ($row['orphan_presets'] !== []) {
        $lines[] = '';
        $lines[] = '  Presets that would apply nothing:';
        foreach ($row['orphan_presets'] as $o) {
            $lines[] = '    - ' . $o['locus'] . ': ' . $o['message'];
        }
    }
    $lines[] = '';
}

$lines[] = str_repeat('=', 70);
$lines[] = sprintf('%d of %d saved finding(s) flagged for review.', $flaggedCount, count($rows));
if ($flaggedCount > 0) {
    $lines[] = '';
    $lines[] = 'Flags:';
    $lines[] = '  BREADTH          emits more findings than the threshold';
    $lines[] = '  MULTI-TEMPLATE   bundles more than two templates';
    $lines[] = '  GENERAL-EXAM     bundles a general/comprehensive template without saying so';
    $lines[] = '  SEX-SPECIFIC     bundles a template restricted by sex';
    $lines[] = '  BLANK-TEMPLATE   bundles a template it presets nothing in';
    $lines[] = '  BROKEN-PRESET    a preset names an element or option that does not exist';
    $lines[] = '  MISSING-TEMPLATE bundles a template that is not in the library';
}

fwrite(STDOUT, implode("\n", $lines) . "\n");
exit($flaggedCount > 0 ? 1 : 0);
