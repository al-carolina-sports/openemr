<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation\Rules;

use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Context;
use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Validation\Rule;

/**
 * Missing-mirror detection - spec 06 §3.4.
 *
 * Defect class: a saved finding exists for one side and not the other, so the
 * clinician can one-click the left presentation of a condition and has to build
 * the right one by hand. Observed as a left-side entry with no right counterpart
 * for the same condition, and as option text offering only one side
 * ("dorsum left foot" with no right equivalent).
 *
 * The structural fix in §3.4 is one entry with a laterality attribute, which
 * makes a missing mirror impossible. So this rule reports two things:
 *
 *   1. a side-specific entry whose opposite side is absent          error
 *      - "there is a left and no right": the gap itself
 *   2. a complete right/left pair of entries                        warning
 *      - "these two should be one entry with a side attribute"
 *
 * It also checks preset coverage: a saved finding declaring a side whose presets
 * are all recorded against the other side is a transcription slip that is nearly
 * invisible on screen.
 */
final class MissingMirrorRule implements Rule
{
    public function code(): string
    {
        return 'MIRROR';
    }

    public function describes(): string
    {
        return 'A saved finding present for one side must exist for the other (06 §3.4)';
    }

    public function check(Library $library, Context $context): array
    {
        $issues = [];

        foreach ([$library->savedFindings, $library->templates, $library->diagnosisSets] as $collection) {
            $issues = array_merge($issues, $this->checkCollection($collection));
        }

        // Option-level mirrors: an option naming one side inside an element whose
        // other side is not offered.
        foreach ($library->templates as $template) {
            foreach ($template['elements'] as $element) {
                $issues = array_merge($issues, $this->checkOptionMirrors($template, $element));
            }
        }

        // Presets recorded against the wrong side.
        foreach ($library->savedFindings as $finding) {
            if (!in_array($finding['laterality'], ['right', 'left'], true) || $finding['presets'] === []) {
                continue;
            }
            $sides = array_unique(array_column($finding['presets'], 'laterality'));
            $sides = array_values(array_filter($sides, static fn ($s) => $s !== 'n/a'));
            if ($sides === [] || in_array($finding['laterality'], $sides, true)) {
                continue;
            }
            $issues[] = Issue::error(
                $this->code(), Context::describe($finding), 'presets',
                sprintf(
                    'The saved finding declares laterality "%s" but every preset that '
                    . 'carries a side records "%s".',
                    $finding['laterality'], implode('/', $sides)
                ),
                'One of the two was mistyped. Applying this finding would document '
                . 'the opposite side from the one the picker names.',
                $finding['_file']
            );
        }

        return $issues;
    }

    /** @param list<array<string,mixed>> $entries */
    private function checkCollection(array $entries): array
    {
        $issues = [];
        $groups = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            // A side on the entry can come from the attribute (correct) or from
            // the name (flagged separately by LAT_IN_NAME, but still pairs here).
            $side = $entry['laterality'] ?? null;
            $side = in_array($side, ['right', 'left'], true) ? $side : null;
            $side ??= Context::detectSide($name);
            if ($side === null || $side === 'bilateral') {
                continue;
            }
            $stem = Context::stripSide($name);
            if ($stem === '') {
                continue;
            }
            $groups[$stem][$side][] = $entry;
        }

        foreach ($groups as $stem => $sides) {
            $haveRight = isset($sides['right']);
            $haveLeft  = isset($sides['left']);
            if ($haveRight && $haveLeft) {
                $r = $sides['right'][0];
                $l = $sides['left'][0];
                $issues[] = Issue::warning(
                    $this->code(), Context::describe($r), 'name',
                    sprintf(
                        'A complete right/left pair exists for "%s": %s and %s.',
                        $stem, $r['name'], $l['name']
                    ),
                    'Collapse the pair into one entry with a laterality attribute. '
                    . 'Two entries will drift apart the first time one is edited (06 §3.4).',
                    $r['_file']
                );
                continue;
            }
            $present = $haveRight ? 'right' : 'left';
            $missing = $haveRight ? 'left' : 'right';
            $entry   = $sides[$present][0];
            $issues[] = Issue::error(
                $this->code(), Context::describe($entry), 'name',
                sprintf(
                    'A %s-sided %s exists for "%s" and there is no %s counterpart. '
                    . 'The %s presentation of this condition cannot be documented in one click.',
                    $present, Context::typeLabel($entry), $stem, $missing, $missing
                ),
                sprintf(
                    'Preferred: rename to "%s" and carry the side in "laterality", so '
                    . 'one entry serves both. Acceptable: add the %s counterpart with '
                    . 'the mirrored content.',
                    ucwords($stem), $missing
                ),
                $entry['_file']
            );
        }
        return $issues;
    }

    private function checkOptionMirrors(array $template, array $element): array
    {
        if ($element['options'] === []) {
            return [];
        }
        $sided = [];
        foreach ($element['options'] as $option) {
            $side = Context::detectSide($option['label']);
            if ($side === null || $side === 'bilateral') {
                continue;
            }
            $stem = Context::stripSide($option['label']);
            if ($stem === '') {
                continue;
            }
            $sided[$stem][$side] = $option['label'];
        }
        $issues = [];
        foreach ($sided as $stem => $sides) {
            if (isset($sides['right'], $sides['left'])) {
                continue;
            }
            $present = isset($sides['right']) ? 'right' : 'left';
            $missing = $present === 'right' ? 'left' : 'right';
            $issues[] = Issue::error(
                $this->code(), Context::describe($template),
                "element \"{$element['label']}\" / option \"{$sides[$present]}\"",
                sprintf(
                    'This element offers the %s "%s" and not the %s one.',
                    $present, $stem, $missing
                ),
                sprintf(
                    'Add "%s %s", or drop the side from the option label and let the '
                    . 'finding carry laterality. An unmirrored option means that '
                    . 'finding is undocumentable on the other side.',
                    $missing, $stem
                ),
                $template['_file']
            );
        }
        return $issues;
    }
}
