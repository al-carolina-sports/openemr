<?php

declare(strict_types=1);

namespace Aem\Renderer;

/**
 * Presentation options. These are renderer inputs only — none of them is a
 * column on `finding`, `encounter_section`, `template_element` or
 * `template_option`. `encounter_section.render_mode` stays the two-value
 * enum the spec defines (list | paragraph); $listStyle selects between the
 * two list layouts the spec's own text and reference output require. See
 * README "Resolved ambiguities", item 1.
 */
final class RenderOptions
{
    public const ORDER_BY_OPTION = 'option';   // template_option.sort_order (§5.5 default)
    public const ORDER_BY_ENTRY  = 'entry';    // finding.sort_order

    public const LIST_SIMPLE = 'simple';       // §5.2  "Element: value; value; value"
    public const LIST_EXAM   = 'exam';         // §5.6  "Group: Prefix: value, value. Prefix: ..."

    public function __construct(
        /** Ordering rule — see README "Ordering". Never silently reordered. */
        public readonly string $orderBy = self::ORDER_BY_OPTION,
        public readonly string $listStyle = self::LIST_SIMPLE,
        /** Positives first, then negatives/neutrals. Paragraph mode only (§5.3). */
        public readonly bool $polarityGrouping = true,
        /** Stem-collapse thresholds; see StemCollapser. */
        public readonly int $minStemWords = 2,
        public readonly int $minItemsForShortStem = 3,
        /** 0 disables wrapping. */
        public readonly int $wrapWidth = 79,
        public readonly int $hangingIndent = 0,
        /** Collapse same-option findings differing only in anatomic_level. */
        public readonly bool $aggregateLevels = true,
        /** The verb used to introduce a paragraph-mode clause group. */
        public readonly string $reportingVerb = 'reports',
        /** Use the subject noun for the first clause group, pronoun after (§5.6). */
        public readonly bool $nounThenPronoun = true,
    ) {
    }

    public function with(mixed ...$overrides): self
    {
        $args = [
            'orderBy' => $this->orderBy,
            'listStyle' => $this->listStyle,
            'polarityGrouping' => $this->polarityGrouping,
            'minStemWords' => $this->minStemWords,
            'minItemsForShortStem' => $this->minItemsForShortStem,
            'wrapWidth' => $this->wrapWidth,
            'hangingIndent' => $this->hangingIndent,
            'aggregateLevels' => $this->aggregateLevels,
            'reportingVerb' => $this->reportingVerb,
            'nounThenPronoun' => $this->nounThenPronoun,
        ];

        return new self(...array_merge($args, $overrides));
    }

    /** Defaults for a physical-exam section rendered as in §5.6. */
    public static function examList(): self
    {
        return new self(
            listStyle: self::LIST_EXAM,
            minStemWords: 2,
            minItemsForShortStem: 3,
            hangingIndent: 2,
        );
    }

    /** Defaults for a paragraph section (ROS) as in §5.6. */
    public static function paragraph(): self
    {
        // A bare one-word stem ("no") never collapses in prose: §5.6 renders
        // four such negatives as four full clauses. See README item 3.
        return new self(
            polarityGrouping: true,
            minStemWords: 2,
            minItemsForShortStem: PHP_INT_MAX,
        );
    }
}
