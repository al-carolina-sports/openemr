<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation\Rules;

use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Context;
use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Validation\Rule;

/**
 * 06 §3.4 - "laterality is never part of the name."
 *
 * Defect class: entry names carrying the side instead of an attribute. Names drift
 * between `Right`/`R` and `Bilateral`/`B` (`Hip Labral Tear R`, `SI Joint B`),
 * leave mirrors missing, and name order sets `509` without the side at all. The
 * fix is one entry with a laterality attribute, rendered as
 * "Hip Labral Tear - Right".
 *
 * This rule flags any entry whose NAME embeds Right / Left / Bilateral / R / L /
 * B, and any order-set or diagnosis-set item whose name carries a side or a
 * spinal level that belongs in `laterality` / `anatomic_level`.
 *
 * Severity: error. Every one of these entries has to be re-shaped before import,
 * and catching it at transcription time is the whole point of the package.
 */
final class LateralityInNameRule implements Rule
{
    public function code(): string
    {
        return 'LAT_IN_NAME';
    }

    public function describes(): string
    {
        return 'Names must not embed Right/Left/Bilateral/R/L/B - laterality is an attribute (06 §3.4)';
    }

    public function check(Library $library, Context $context): array
    {
        $issues = [];

        foreach ($library->allEntries() as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $side = Context::detectSide($name);
            if ($side !== null) {
                $stem = Context::stripSide($name);
                $issues[] = Issue::error(
                    $this->code(), Context::describe($entry), 'name',
                    "The name embeds laterality (\"{$side}\").",
                    $this->fixFor($entry, $stem, $side),
                    $entry['_file']
                );
            }
            $level = $this->detectLevel($name);
            if ($level !== null) {
                $issues[] = Issue::warning(
                    $this->code(), Context::describe($entry), 'name',
                    "The name embeds the anatomical level \"{$level}\".",
                    'Carry the level in "anatomic_level" (templates and set items) or '
                    . 'as a plan-option parameter, so one entry serves every level '
                    . 'instead of one entry per level (06 §3.5, 08 §12.3.1).',
                    $entry['_file']
                );
            }
        }

        foreach ($library->orderSets as $set) {
            foreach ($set['items'] as $item) {
                $display = (string) ($item['display_name'] ?? '');
                if ($display === '') {
                    continue;
                }
                $side = Context::detectSide($display);
                if ($side !== null && $item['laterality'] === 'n/a') {
                    $issues[] = Issue::error(
                        $this->code(), Context::describe($set),
                        "item \"{$display}\"",
                        "The item name says \"{$side}\" but its \"laterality\" field is \"n/a\".",
                        "Set \"laterality: {$side}\" and take the side out of the name. "
                        . 'The renderer and the claim read the field, not the text.',
                        $set['_file']
                    );
                }
            }
        }

        foreach ($library->diagnosisSets as $set) {
            foreach ($set['items'] as $item) {
                $side = Context::detectSide((string) $item['display_name']);
                if ($side !== null && $item['laterality'] === 'n/a' && !$set['laterality_param']) {
                    $issues[] = Issue::error(
                        $this->code(), Context::describe($set),
                        "item \"{$item['display_name']}\"",
                        "The item name says \"{$side}\" but its \"laterality\" field is \"n/a\".",
                        'Either set the item laterality, or give the whole set '
                        . '"laterality_param: true" and let one set serve both sides '
                        . '(08 §12.3.2).',
                        $set['_file']
                    );
                }
            }
        }

        return $issues;
    }

    private function fixFor(array $entry, string $stem, string $side): string
    {
        $stemDisplay = ucwords($stem);
        return match ($entry['_type'] ?? '') {
            'saved_finding' => "Rename to \"{$stemDisplay}\" and set \"laterality: {$side}\". "
                . 'One saved finding with a side attribute replaces the right/left pair '
                . 'and makes a missing mirror structurally impossible.',
            'diagnosis_set' => "Rename to \"{$stemDisplay}\" and set \"laterality_param: true\". "
                . 'The side selector then applies to every code ticked.',
            'order_set'     => "Rename to \"{$stemDisplay}\" and carry the side in each "
                . 'item\'s "laterality" field. This is what collapses the near-identical '
                . '5xx entries into a handful of parameterised sets.',
            'template'      => "Rename to \"{$stemDisplay}\". If elements genuinely differ "
                . 'by side, give the elements a "side:" and keep their option sets mirrored.',
            default         => "Rename to \"{$stemDisplay}\" and carry the side as an attribute.",
        };
    }

    private function detectLevel(string $name): ?string
    {
        if (preg_match('/\b([CTL]\d{1,2}\s*-\s*[CTLS]?\d{1,2})\b/i', $name, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\b([CTL]\d{1,2}|S[12])\b(?!\w)/', $name, $m) === 1) {
            // "L-spine", "T-spine" are region names, not levels - already excluded
            // by requiring a digit.
            return $m[1];
        }
        return null;
    }
}
