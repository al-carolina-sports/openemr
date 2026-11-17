<?php
declare(strict_types=1);

namespace ZeroDoc\LibraryTools\Validation\Rules;

use ZeroDoc\LibraryTools\Model\Library;
use ZeroDoc\LibraryTools\Validation\Context;
use ZeroDoc\LibraryTools\Validation\Issue;
use ZeroDoc\LibraryTools\Validation\Rule;

/**
 * 06 §3.8 - near-duplicate name detection. Spec 06 Appendix A items 13, 14 and 21.
 *
 * Defect class, seen in transcribed content:
 *   - `L-spine` vs `Pain Management L-spine`, `Lumbar Spine` vs `CSS Lumbar
 *     Spine`: overlapping stems, so the picker shows two entries and nobody
 *     knows which is current.
 *   - `Assessments` vs `Assessment / Plan`: near-identical section names.
 *   - `COVID-19 Symptoms March 2020` / `May 2020`: two stale templates that are
 *     the same template with a date in the name.
 *
 * Three detectors, in increasing looseness:
 *   1. identical after normalisation                         error
 *   2. one name contains the other's full token set (stem)   warning
 *   3. high token overlap or small edit distance             warning
 * Plus: a date or year embedded in a name, which is the versioning-by-name
 * pattern that produced the stale COVID pair.
 */
final class NearDuplicateNameRule implements Rule
{
    public function code(): string
    {
        return 'NEAR_DUP';
    }

    public function describes(): string
    {
        return 'Near-duplicate and date-stamped names across the library (06 §3.8)';
    }

    public function check(Library $library, Context $context): array
    {
        $issues  = [];
        $entries = $library->allEntries();

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (preg_match('/\b(19|20)\d{2}\b/', $name) === 1
                || preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+(19|20)?\d{2}\b/i', $name) === 1) {
                $issues[] = Issue::warning(
                    $this->code(), Context::describe($entry), 'name',
                    'The name embeds a date. Dating a library entry in its name is '
                    . 'how two versions of the same entry end up side by side, both live.',
                    'Drop the date from the name. Retire the superseded entry with '
                    . '"is_active: false" rather than keeping a dated twin (06 §3.8).',
                    $entry['_file']
                );
            }
        }

        $n = count($entries);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $entries[$i];
                $b = $entries[$j];
                $issue = $this->compare($a, $b);
                if ($issue !== null) {
                    $issues[] = $issue;
                }
            }
        }
        return $issues;
    }

    private function compare(array $a, array $b): ?Issue
    {
        $nameA = (string) ($a['name'] ?? '');
        $nameB = (string) ($b['name'] ?? '');
        if ($nameA === '' || $nameB === '') {
            return null;
        }
        $normA = Context::normaliseName($nameA);
        $normB = Context::normaliseName($nameB);
        if ($normA === '' || $normB === '') {
            return null;
        }

        $other = Context::describe($b) . ' in ' . $b['_file'];
        $sameType = ($a['_type'] ?? '') === ($b['_type'] ?? '');

        if ($normA === $normB) {
            // Two entries differing only by side are a laterality duplicate, and
            // LateralityInNameRule says so more usefully; still an error here.
            return Issue::error(
                $this->code(), Context::describe($a), 'name',
                "This name is indistinguishable from {$other}.",
                $sameType
                    ? 'Merge them, or rename one so the picker can tell them apart. '
                      . 'If they differ only by side or level, make that a parameter '
                      . 'rather than a name (06 §3.4).'
                    : 'Two different kinds of entry sharing a name is workable but '
                      . 'confusing in a picker; qualify one of the names.',
                $a['_file']
            );
        }

        $tokensA = explode(' ', $normA);
        $tokensB = explode(' ', $normB);

        // Stem containment: "l spine" inside "pain management l spine".
        $contained = array_diff($tokensA, $tokensB) === [] || array_diff($tokensB, $tokensA) === [];
        if ($contained && $sameType) {
            return Issue::warning(
                $this->code(), Context::describe($a), 'name',
                "This name is a subset of {$other} - overlapping stems.",
                'Decide which one the practice uses and retire the other with '
                . '"is_active: false". Two live entries with overlapping stems means '
                . 'the picker offers both and the choice is a coin toss (06 §3.8).',
                $a['_file']
            );
        }

        $inter = count(array_intersect($tokensA, $tokensB));
        $union = count(array_unique(array_merge($tokensA, $tokensB)));
        $jaccard = $union > 0 ? $inter / $union : 0.0;
        $dist = levenshtein($normA, $normB);
        $maxLen = max(strlen($normA), strlen($normB));
        $editRatio = $maxLen > 0 ? $dist / $maxLen : 1.0;

        if ($sameType && ($jaccard >= 0.7 || ($editRatio <= 0.2 && $maxLen >= 8))) {
            return Issue::warning(
                $this->code(), Context::describe($a), 'name',
                sprintf('This name is near-identical to %s (%d%% of the words in common).',
                    $other, (int) round($jaccard * 100)),
                'If they are the same thing, merge them. If they are genuinely '
                . 'different, make the difference visible in the name.',
                $a['_file']
            );
        }
        return null;
    }
}
