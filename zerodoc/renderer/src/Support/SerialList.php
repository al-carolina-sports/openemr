<?php

declare(strict_types=1);

namespace Aem\Renderer\Support;

/**
 * Serial-list joining with an explicit conjunction (spec 06 §5.3).
 *
 * The conjunction is chosen by the caller, never inferred here:
 *   - "or"  for the tails distributed under one collapsed negative stem
 *           ("no tenderness of the spinous process, the facet joints, ... or the coccyx")
 *   - "and" for positive items, and for whole independent findings
 *           ("no significant weight change, good appetite, no fever, and no fatigue")
 *
 * $oxford controls the comma before the conjunction in a 3+ item list.
 * Two-item lists never take a comma.
 */
final class SerialList
{
    public static function join(array $items, string $conjunction = 'and', bool $oxford = true): string
    {
        $items = array_values(array_filter(array_map('trim', $items), static fn ($i) => $i !== ''));
        $count = count($items);

        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }
        if ($count === 2) {
            return $items[0] . ' ' . $conjunction . ' ' . $items[1];
        }

        $last = array_pop($items);
        $separator = $oxford ? ', ' : ' ';

        return implode(', ', $items) . $separator . $conjunction . ' ' . $last;
    }

    /** `value; value; value` — the §5.2 list-mode separator. */
    public static function semicolons(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), static fn ($i) => $i !== ''));

        return implode('; ', $items);
    }
}
