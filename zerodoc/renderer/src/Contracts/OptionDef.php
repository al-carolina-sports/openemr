<?php

declare(strict_types=1);

namespace Aem\Renderer\Contracts;

/**
 * Read-only descriptor for a `template_option` (spec 06 §1.4).
 *
 * A `finding` row carries only `option_id`; it does not carry the option's
 * label. The renderer performs no database access, so the caller supplies
 * the resolved option descriptors alongside the findings. These are inputs,
 * not storage — nothing here adds a column to `finding`.
 *
 * `label` may contain value slots, written as the printf-ish placeholders
 * `{numeric}`, `{unit}` and `{text}`, which are filled from the finding's
 * `value_numeric`, `value_unit` and `value_text`. Spec 06 §1.4 stores slots
 * in `template_option.value_slots`; see README "Value slots".
 */
final class OptionDef
{
    public function __construct(
        public readonly int $id,
        public readonly int $element_id,
        public readonly string $label,
        public readonly string $polarity = Finding::POLARITY_NEUTRAL,
        public readonly int $sort_order = 0,
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            element_id: (int) $row['element_id'],
            label: (string) $row['label'],
            polarity: $row['polarity'] ?? Finding::POLARITY_NEUTRAL,
            sort_order: isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
        );
    }
}
