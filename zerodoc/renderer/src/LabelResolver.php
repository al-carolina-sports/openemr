<?php

declare(strict_types=1);

namespace Aem\Renderer;

use Aem\Renderer\Contracts\Finding;

/**
 * Turns one finding into its clause text.
 *
 * The option label may carry placeholders, filled from the finding's own
 * columns and nothing else:
 *
 *   {numeric}  finding.value_numeric
 *   {unit}     finding.value_unit
 *   {text}     finding.value_text
 *   {level}    finding.anatomic_level
 *   {levels}   the aggregated anatomic levels of a collapsed level group
 *   {lat}      finding.laterality, rendered per self::LATERALITY
 *
 * A finding with no option_id renders its value_text verbatim (free entry).
 * A placeholder whose column is null renders as an empty string and the
 * surrounding whitespace is normalised, so a missing value never prints
 * "{numeric}".
 */
final class LabelResolver
{
    public const LATERALITY = [
        Finding::LAT_RIGHT     => 'right',
        Finding::LAT_LEFT      => 'left',
        Finding::LAT_BILATERAL => 'bilaterally',
        Finding::LAT_NA        => '',
    ];

    public function __construct(private readonly TemplateMap $map)
    {
    }

    /** @param list<string> $levels aggregated levels, when this is a level group */
    public function text(Finding $finding, array $levels = []): string
    {
        $option = $this->map->option($finding->option_id);
        $label = $option?->label ?? (string) $finding->value_text;

        $numeric = $finding->value_numeric;
        if (is_float($numeric) && $numeric === floor($numeric)) {
            $numeric = (int) $numeric;
        }

        $replacements = [
            '{numeric}' => $numeric === null ? '' : (string) $numeric,
            '{unit}'    => (string) $finding->value_unit,
            '{text}'    => (string) $finding->value_text,
            '{level}'   => (string) $finding->anatomic_level,
            '{levels}'  => $levels === []
                ? (string) $finding->anatomic_level
                : implode(', ', $levels),
            '{lat}'     => self::LATERALITY[$finding->laterality] ?? '',
        ];

        $label = strtr($label, $replacements);

        // Normalise whitespace left behind by empty placeholders, and tidy
        // punctuation that ended up adjacent to a gap.
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;
        $label = preg_replace('/\s+([,;.])/', '$1', $label) ?? $label;

        return trim($label);
    }

    /**
     * Group consecutive findings that share element_id, option_id and polarity
     * and differ only in anatomic_level, so that four motor-strength rows
     * render as one clause ("L1, L2-L4, L5, S1 - 5/5 right and left").
     *
     * @param list<Finding> $ordered
     * @return list<array{findings:list<Finding>,levels:list<string>}>
     */
    public static function groupLevels(array $ordered, bool $enabled = true): array
    {
        $groups = [];
        foreach ($ordered as $finding) {
            $last = $groups === [] ? null : $groups[count($groups) - 1];
            $mergeable = $enabled
                && $last !== null
                && $finding->option_id !== null
                && $last['findings'][0]->option_id === $finding->option_id
                && $last['findings'][0]->element_id === $finding->element_id
                && $last['findings'][0]->polarity === $finding->polarity
                && $last['findings'][0]->laterality === $finding->laterality
                && $finding->anatomic_level !== null
                && $last['findings'][0]->anatomic_level !== null;

            if ($mergeable) {
                $groups[count($groups) - 1]['findings'][] = $finding;
                $groups[count($groups) - 1]['levels'][] = $finding->anatomic_level;
                continue;
            }

            $groups[] = [
                'findings' => [$finding],
                'levels' => $finding->anatomic_level === null ? [] : [$finding->anatomic_level],
            ];
        }

        return $groups;
    }
}
