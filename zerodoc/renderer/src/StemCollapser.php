<?php

declare(strict_types=1);

namespace Aem\Renderer;

use Aem\Renderer\Contracts\Finding;
use Aem\Renderer\Support\SerialList;

/**
 * Stem collapsing (spec 06 §5.3) — the core behaviour of this package.
 *
 * Repeated findings in one element that share a leading stem collapse into a
 * single clause:
 *
 *   no tenderness of the spinous process
 *   no tenderness of the facet joints      ->  no tenderness of the spinous
 *   no tenderness of the sacroiliac joint(s)   process, the facet joints, the
 *   no tenderness of the coccyx                sacroiliac joint(s), or the coccyx
 *
 * Rules, all derived from the reference output in §5.6:
 *
 *  1. Collapsing operates only WITHIN one element and only across a maximal
 *     run of CONSECUTIVE items of the SAME polarity, in the already-decided
 *     render order. It never reorders and never crosses an element boundary.
 *
 *  2. The stem is the longest common leading word sequence of the run, with
 *     trailing determiners ("the", "a", "an") handed back to the tails. This
 *     is what produces "no tenderness of" + "the spinous process", matching
 *     §5.6 exactly rather than "no tenderness of the" + "spinous process".
 *
 *  3. The conjunction before the last tail is "or" when the run is negative
 *     and "and" otherwise (§5.3).
 *
 *  4. Thresholds (CollapsePolicy): a stem of >= $minStemWords words collapses
 *     a run of >= 2 items; a shorter stem (in practice the bare negation
 *     particle "no") needs >= $minItemsForShortStem items. §5.6 requires
 *     different thresholds in list and paragraph mode — see README
 *     "Resolved ambiguities", item 3.
 */
final class StemCollapser
{
    private const DETERMINERS = ['the', 'a', 'an'];

    public function __construct(
        private readonly int $minStemWords = 2,
        private readonly int $minItemsForShortStem = 3,
    ) {
    }

    /**
     * @param list<array{text:string,polarity:string}> $items
     * @return list<array{text:string,polarity:string,collapsed:bool}>
     *         One entry per emitted segment, in input order.
     */
    public function collapse(array $items): array
    {
        $out = [];
        $i = 0;
        $count = count($items);

        while ($i < $count) {
            $polarity = $items[$i]['polarity'];
            $j = $i;
            while ($j + 1 < $count && $items[$j + 1]['polarity'] === $polarity) {
                $j++;
            }

            $run = array_slice($items, $i, $j - $i + 1);
            foreach ($this->collapseRun($run, $polarity) as $segment) {
                $out[] = $segment;
            }
            $i = $j + 1;
        }

        return $out;
    }

    /**
     * @param list<array{text:string,polarity:string}> $run
     * @return list<array{text:string,polarity:string,collapsed:bool}>
     */
    private function collapseRun(array $run, string $polarity): array
    {
        $passthrough = static function (array $run) use ($polarity): array {
            return array_map(
                static fn (array $item) => [
                    'text' => $item['text'],
                    'polarity' => $polarity,
                    'collapsed' => false,
                ],
                $run
            );
        };

        if (count($run) < 2) {
            return $passthrough($run);
        }

        $labels = array_map(static fn (array $item) => $item['text'], $run);
        [$stem, $tails] = $this->splitStem($labels);

        if ($stem === null) {
            return $passthrough($run);
        }

        $stemWords = count(preg_split('/\s+/', $stem) ?: []);
        $required = $stemWords >= $this->minStemWords ? 2 : $this->minItemsForShortStem;

        if (count($run) < $required) {
            return $passthrough($run);
        }

        $conjunction = $polarity === Finding::POLARITY_NEGATIVE ? 'or' : 'and';

        return [[
            'text' => $stem . ' ' . SerialList::join($tails, $conjunction),
            'polarity' => $polarity,
            'collapsed' => true,
        ]];
    }

    /**
     * @param list<string> $labels
     * @return array{0: ?string, 1: list<string>} [stem, tails] or [null, []]
     */
    private function splitStem(array $labels): array
    {
        $tokenised = array_map(
            static fn (string $label) => preg_split('/\s+/', trim($label)) ?: [],
            $labels
        );

        $shortest = min(array_map('count', $tokenised));
        $common = 0;
        for ($pos = 0; $pos < $shortest; $pos++) {
            $word = $tokenised[0][$pos];
            foreach ($tokenised as $tokens) {
                if (strcasecmp($tokens[$pos], $word) !== 0) {
                    break 2;
                }
            }
            $common++;
        }

        // Hand trailing determiners back to the tails (rule 2).
        while ($common > 0 && in_array(strtolower($tokenised[0][$common - 1]), self::DETERMINERS, true)) {
            $common--;
        }

        if ($common === 0) {
            return [null, []];
        }

        $tails = [];
        foreach ($tokenised as $tokens) {
            $tail = trim(implode(' ', array_slice($tokens, $common)));
            if ($tail === '') {
                // An item that is exactly the stem cannot be a tail; refuse to
                // collapse rather than emit a dangling clause.
                return [null, []];
            }
            $tails[] = $tail;
        }

        return [implode(' ', array_slice($tokenised[0], 0, $common)), $tails];
    }
}
