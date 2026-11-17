<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation\Rules;

use ZeroDoc\LibraryTools\Audit\BundleAudit;
use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Context;
use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Validation\Rule;

/**
 * Bundle breadth - spec 06 §1.5, the MUST on bundle transparency.
 *
 * Defect class, and the most serious one in the catalogue: a narrowly named
 * saved finding silently applies templates far beyond its subject. A saved
 * finding named for a single peripheral nerve entrapment pulled in a full
 * general adult exam plus two regional exams, auto-documenting breast, female
 * genitourinary and rectal headers on a 36-year-old male patient, none of which
 * was performed.
 *
 * bundle-audit.php is the full report. This rule is the gate: it fails the
 * import when a bundle is broad enough that the name cannot be telling the
 * truth about it.
 *
 *   - a saved finding whose name points at one region or condition but which
 *     bundles a general / comprehensive template              error
 *   - a sex-specific template inside a bundle whose own entry
 *     is not sex-restricted                                   error
 *   - emitted-finding count over the breadth threshold        warning
 *   - a bundled template with no presets at all               warning
 *     (it inserts blank structure, which is a template's job, not a finding's)
 */
final class BundleRule implements Rule
{
    /** Findings a single saved finding may emit before it needs justifying. */
    public const BREADTH_WARN = 40;

    /** Template names that assert whole-body or multi-system scope. */
    private const BROAD_MARKERS = [
        'general', 'comprehensive', 'complete', 'full', 'whole', 'multisystem',
        'multi-system', 'review of systems', 'head to toe', 'constitutional',
    ];

    /** Templates whose content is sex-specific and must never ride along silently. */
    private const SEX_SPECIFIC_MARKERS = [
        'breast', 'genitourinary', 'gu exam', 'female gu', 'male gu', 'rectal',
        'pelvic', 'prostate', 'testicular', 'obstetric', 'gynecologic',
    ];

    public function code(): string
    {
        return 'BUNDLE';
    }

    public function describes(): string
    {
        return 'A saved finding must not bundle templates beyond what its name claims (06 §1.5)';
    }

    public function check(Library $library, Context $context): array
    {
        $issues = [];
        $audit  = new BundleAudit($library);

        foreach ($audit->rows() as $row) {
            $finding = $row['finding'];
            $entry   = Context::describe($finding);
            $name    = strtolower((string) $finding['name']);
            $nameIsBroad = $this->matches($name, self::BROAD_MARKERS);

            foreach ($row['templates'] as $t) {
                if ($t['missing']) {
                    $issues[] = Issue::error(
                        $this->code(), $entry, "template \"{$t['ref']}\"",
                        'This saved finding bundles a template that is not in the library.',
                        'Transcribe the template, or correct the reference. The picker '
                        . 'cannot show what a finding will apply if the template is absent.',
                        $finding['_file']
                    );
                    continue;
                }

                $tName = strtolower((string) $t['name']);

                if (!$nameIsBroad && $this->matches($tName, self::BROAD_MARKERS)) {
                    $issues[] = Issue::error(
                        $this->code(), $entry, "template \"{$t['name']}\"",
                        sprintf(
                            'A saved finding named "%s" bundles the general-scope template '
                            . '"%s" (%d elements). Applying it would document a '
                            . 'whole-body exam that the name does not announce.',
                            $finding['name'], $t['name'], $t['element_count']
                        ),
                        'Remove the general template from the bundle, or rename the '
                        . 'finding so it says what it applies. A bundle has to be '
                        . 'honest about its breadth before the user commits (06 §1.5).',
                        $finding['_file']
                    );
                }

                if ($this->matches($tName, self::SEX_SPECIFIC_MARKERS)
                    && ($t['applies_to_sex'] ?? 'any') === 'any') {
                    $issues[] = Issue::error(
                        $this->code(), $entry, "template \"{$t['name']}\"",
                        sprintf(
                            'The bundled template "%s" is sex-specific in content but '
                            . 'carries "applies_to_sex: any", so nothing would stop it '
                            . 'documenting on a patient it does not apply to.',
                            $t['name']
                        ),
                        'Set "applies_to_sex" on that template to male or female. '
                        . 'Templates are filtered at application time and the skip is '
                        . 'reported; a template that declares "any" is never skipped.',
                        $finding['_file']
                    );
                }

                if ($t['preset_count'] === 0) {
                    $issues[] = Issue::warning(
                        $this->code(), $entry, "template \"{$t['name']}\"",
                        'This bundled template has no presets, so it contributes blank '
                        . 'structure rather than answers.',
                        'A saved finding is "template(s) plus answers". Either preset '
                        . 'the template\'s elements, or drop it from the bundle and let '
                        . 'the user pick the template directly.',
                        $finding['_file']
                    );
                }
            }

            if ($row['emitted_findings'] > self::BREADTH_WARN) {
                $issues[] = Issue::warning(
                    $this->code(), $entry, 'bundle',
                    sprintf(
                        'Applying this saved finding emits %d findings across %d templates.',
                        $row['emitted_findings'], count($row['templates'])
                    ),
                    'Confirm every one of them was actually examined when this finding '
                    . 'is used. Run bundle-audit.php for the breakdown. Narrow bundles '
                    . 'are easier to attest honestly.',
                    $finding['_file']
                );
            }

            foreach ($row['orphan_presets'] as $orphan) {
                $issues[] = Issue::error(
                    $this->code(), $entry, $orphan['locus'],
                    $orphan['message'],
                    'A preset must name a template the finding bundles, an element that '
                    . 'template has, and an option that element offers. Otherwise the '
                    . 'answer silently does not apply.',
                    $finding['_file']
                );
            }
        }

        return $issues;
    }

    private function matches(string $haystack, array $markers): bool
    {
        foreach ($markers as $m) {
            if (str_contains($haystack, $m)) {
                return true;
            }
        }
        return false;
    }
}
