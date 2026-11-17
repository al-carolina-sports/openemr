<?php

declare(strict_types=1);

namespace Aem\Renderer;

use Aem\Renderer\Contracts\Finding;

/**
 * Deterministic ordering (spec 06 §5.5).
 *
 * THE ORDERING RULE, in full:
 *
 *   Findings are sorted by the tuple
 *       (element.sort_order, element_id, KEY, original array index)
 *   where KEY is
 *       template_option.sort_order   when RenderOptions::$orderBy = 'option'  (default)
 *       finding.sort_order           when RenderOptions::$orderBy = 'entry'
 *
 *   * element.sort_order groups the note by template element, in template order.
 *   * element_id breaks ties between elements that share a sort_order, so two
 *     templates applied to one section cannot interleave unpredictably.
 *   * The original array index is the final tiebreak, which makes the sort
 *     stable: equal keys keep the order the caller supplied. PHP's sort is not
 *     guaranteed stable across versions, so stability is enforced explicitly
 *     rather than relied upon.
 *
 * Polarity NEVER participates in this sort. Paragraph mode partitions the
 * already-ordered findings by polarity afterwards (§5.3); list mode does not
 * regroup at all. Nothing in this package reorders findings anywhere else.
 */
final class Ordering
{
    public function __construct(
        private readonly TemplateMap $map,
        private readonly RenderOptions $options = new RenderOptions(),
    ) {
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    public function sort(array $findings): array
    {
        $keyed = [];
        foreach (array_values($findings) as $index => $finding) {
            $element = $this->map->element($finding->element_id);
            $option  = $this->map->option($finding->option_id);

            $inner = $this->options->orderBy === RenderOptions::ORDER_BY_ENTRY
                ? $finding->sort_order
                : ($option?->sort_order ?? $finding->sort_order);

            $keyed[] = [
                'key' => [
                    $element?->sort_order ?? 0,
                    $finding->element_id ?? 0,
                    $inner,
                    $index,
                ],
                'finding' => $finding,
            ];
        }

        usort($keyed, static function (array $a, array $b): int {
            return $a['key'] <=> $b['key'];
        });

        return array_map(static fn (array $row) => $row['finding'], $keyed);
    }

    /**
     * Partition into [positives, rest] preserving relative order.
     *
     * "Rest" is negatives and neutrals together, kept in sort order. §5.6's
     * Constitutional ROS sentence renders "no significant weight change, good
     * appetite, no fever, and no fatigue" — a neutral descriptor sitting
     * between negatives — so neutrals are not hoisted with the positives.
     * See README "Resolved ambiguities", item 4.
     *
     * @param list<Finding> $ordered
     * @return array{0: list<Finding>, 1: list<Finding>}
     */
    public static function partitionByPolarity(array $ordered): array
    {
        $positives = [];
        $rest = [];
        foreach ($ordered as $finding) {
            if ($finding->isPositive()) {
                $positives[] = $finding;
            } else {
                $rest[] = $finding;
            }
        }

        return [$positives, $rest];
    }
}
