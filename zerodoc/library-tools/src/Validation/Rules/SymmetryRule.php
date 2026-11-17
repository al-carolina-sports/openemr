<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation\Rules;

use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Context;
use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Validation\Rule;

/**
 * 06 §3.6 - right/left symmetry linter.
 *
 * "A saved template with paired Right/Left elements MUST fail validation if the
 * option sets differ."
 *
 * Defect class, seen in transcribed content: Soft Tissue Palpation left carries `iliolumbar
 * region` and `gluteus medius`, right carries `sciatic nerve` and neither of
 * those - so right gluteus medius tenderness cannot be documented at all.
 *
 * Pairing: by explicit `pair_key` when present, otherwise by the element label
 * with its side word removed. Comparison ignores side words inside option
 * labels, so "tenderness, right L4" and "tenderness, left L4" match.
 *
 * Severity: error. This is acceptance test 8.
 */
final class SymmetryRule implements Rule
{
    public function code(): string
    {
        return 'SYMMETRY';
    }

    public function describes(): string
    {
        return 'Paired Right/Left elements in a template must carry identical option sets (06 §3.6, test 8)';
    }

    public function check(Library $library, Context $context): array
    {
        $issues = [];
        foreach ($library->templates as $template) {
            $groups = [];
            foreach ($template['elements'] as $element) {
                $side = $element['side'] ?? null;
                $side ??= Context::detectSide($element['label']);
                if ($side === null || $side === 'bilateral') {
                    continue;
                }
                $pair = $element['pair_key'] ?? Context::stripSide($element['label']);
                if ($pair === '') {
                    continue;
                }
                $groups[$pair][$side][] = $element;
            }
            foreach ($groups as $pair => $sides) {
                $issues = array_merge(
                    $issues,
                    $this->comparePair($template, (string) $pair, $sides)
                );
            }
        }
        return $issues;
    }

    /** @return list<Issue> */
    private function comparePair(array $template, string $pair, array $sides): array
    {
        $entry = Context::describe($template);
        $file  = $template['_file'];

        if (!isset($sides['right'], $sides['left'])) {
            $present = array_key_first($sides);
            $missing = $present === 'right' ? 'left' : 'right';
            $label   = $sides[$present][0]['label'];
            return [Issue::error(
                $this->code(), $entry, "element \"{$label}\"",
                "This template has a {$present} element for \"{$pair}\" and no {$missing} counterpart.",
                "Add the {$missing} element with the same option set, or drop the "
                . 'side from the element label and carry the side on the finding '
                . 'instead (06 §3.4).',
                $file
            )];
        }

        $issues = [];
        foreach (['right', 'left'] as $side) {
            if (count($sides[$side]) > 1) {
                $labels = implode('", "', array_column($sides[$side], 'label'));
                $issues[] = Issue::warning(
                    $this->code(), $entry, "elements \"{$labels}\"",
                    "More than one {$side} element reduces to \"{$pair}\"; the pairing is ambiguous.",
                    'Give each element an explicit "pair_key:" so the sides pair unambiguously.',
                    $template['_file']
                );
            }
        }

        $r = $sides['right'][0];
        $l = $sides['left'][0];

        // An element referencing a vocabulary is symmetric by construction.
        if ($r['vocabulary'] !== null || $l['vocabulary'] !== null) {
            if ($r['vocabulary'] !== $l['vocabulary']) {
                $issues[] = Issue::error(
                    $this->code(), $entry, "elements \"{$r['label']}\" / \"{$l['label']}\"",
                    sprintf(
                        'The paired elements draw options from different sources: right uses %s, left uses %s.',
                        $r['vocabulary'] !== null ? "vocabulary \"{$r['vocabulary']}\"" : 'its own option list',
                        $l['vocabulary'] !== null ? "vocabulary \"{$l['vocabulary']}\"" : 'its own option list'
                    ),
                    'Point both sides at the same vocabulary.',
                    $template['_file']
                );
            }
            return $issues;
        }

        $rOpts = $this->optionIndex($r);
        $lOpts = $this->optionIndex($l);

        $onlyRight = array_diff_key($rOpts, $lOpts);
        $onlyLeft  = array_diff_key($lOpts, $rOpts);

        if ($onlyRight !== [] || $onlyLeft !== []) {
            $parts = [];
            if ($onlyRight !== []) {
                $parts[] = 'right only: "' . implode('", "', array_values($onlyRight)) . '"';
            }
            if ($onlyLeft !== []) {
                $parts[] = 'left only: "' . implode('", "', array_values($onlyLeft)) . '"';
            }
            $issues[] = Issue::error(
                $this->code(), $entry, "elements \"{$r['label']}\" / \"{$l['label']}\"",
                'The paired right and left option sets differ - ' . implode('; ', $parts) . '.',
                'Mirror every option onto both sides. An option present on one '
                . 'side only makes that finding undocumentable on the other.',
                $template['_file']
            );
        }

        // Same options, different polarity, is a subtler version of the same bug.
        foreach (array_intersect_key($rOpts, $lOpts) as $normal => $label) {
            $rp = $this->polarityOf($r, (string) $normal);
            $lp = $this->polarityOf($l, (string) $normal);
            if ($rp !== $lp) {
                $issues[] = Issue::error(
                    $this->code(), $entry, "option \"{$label}\"",
                    "The same option is polarity \"{$rp}\" on the right and \"{$lp}\" on the left.",
                    'Polarity is a property of the option, not of the side. Make them agree.',
                    $template['_file']
                );
            }
        }
        return $issues;
    }

    /** @return array<string,string> normalised => original label */
    private function optionIndex(array $element): array
    {
        $out = [];
        foreach ($element['options'] as $o) {
            $key = Context::stripSide($o['label']);
            if ($key === '') {
                $key = strtolower($o['label']);
            }
            $out[$key] = $o['label'];
        }
        return $out;
    }

    private function polarityOf(array $element, string $normalised): string
    {
        foreach ($element['options'] as $o) {
            if (Context::stripSide($o['label']) === $normalised) {
                return $o['polarity'];
            }
        }
        return 'neutral';
    }
}
