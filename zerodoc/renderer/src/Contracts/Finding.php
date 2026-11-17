<?php

declare(strict_types=1);

namespace Aem\Renderer\Contracts;

/**
 * Immutable value object mirroring the `finding` row defined in
 * spec 06 §1.3, column for column. No columns are added.
 *
 * The renderer never loads these; the caller constructs them.
 */
final class Finding
{
    public const POLARITY_POSITIVE = 'positive';
    public const POLARITY_NEGATIVE = 'negative';
    public const POLARITY_NEUTRAL  = 'neutral';

    public const LAT_RIGHT     = 'right';
    public const LAT_LEFT      = 'left';
    public const LAT_BILATERAL = 'bilateral';
    public const LAT_NA        = 'n/a';

    public function __construct(
        public readonly ?int $id = null,
        public readonly ?int $encounter_section_id = null,
        public readonly ?int $template_id = null,
        public readonly ?int $element_id = null,
        public readonly ?int $option_id = null,
        public readonly ?string $value_text = null,
        public readonly int|float|string|null $value_numeric = null,
        public readonly ?string $value_unit = null,
        public readonly string $laterality = self::LAT_NA,
        public readonly ?string $anatomic_level = null,
        public readonly string $polarity = self::POLARITY_NEUTRAL,
        public readonly int $sort_order = 0,
        public readonly ?int $recorded_by = null,
        public readonly ?string $recorded_at = null,
    ) {
    }

    /** Convenience constructor from an associative row. Unknown keys are rejected. */
    public static function fromArray(array $row): self
    {
        $known = [
            'id', 'encounter_section_id', 'template_id', 'element_id', 'option_id',
            'value_text', 'value_numeric', 'value_unit', 'laterality',
            'anatomic_level', 'polarity', 'sort_order', 'recorded_by', 'recorded_at',
        ];
        $unknown = array_diff(array_keys($row), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown finding column(s): ' . implode(', ', $unknown)
                . '. The finding schema is frozen (spec 06 §1.3); the renderer will not accept extensions.'
            );
        }

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            encounter_section_id: isset($row['encounter_section_id']) ? (int) $row['encounter_section_id'] : null,
            template_id: isset($row['template_id']) ? (int) $row['template_id'] : null,
            element_id: isset($row['element_id']) ? (int) $row['element_id'] : null,
            option_id: isset($row['option_id']) ? (int) $row['option_id'] : null,
            value_text: $row['value_text'] ?? null,
            value_numeric: $row['value_numeric'] ?? null,
            value_unit: $row['value_unit'] ?? null,
            laterality: $row['laterality'] ?? self::LAT_NA,
            anatomic_level: $row['anatomic_level'] ?? null,
            polarity: $row['polarity'] ?? self::POLARITY_NEUTRAL,
            sort_order: isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
            recorded_by: isset($row['recorded_by']) ? (int) $row['recorded_by'] : null,
            recorded_at: $row['recorded_at'] ?? null,
        );
    }

    public function isPositive(): bool
    {
        return $this->polarity === self::POLARITY_POSITIVE;
    }

    public function isNegative(): bool
    {
        return $this->polarity === self::POLARITY_NEGATIVE;
    }
}
