<?php

declare(strict_types=1);

namespace Aem\Renderer\Contracts;

/**
 * Read-only descriptor for a `template_element` (spec 06 §1.4):
 * id, template_id, label, sort_order, allows_multi, render_prefix.
 *
 * `group_label` is NOT in spec 06 §1.4. The §5.6 physical-exam output is
 * grouped under region headings ("Constitutional:", "Lumbar Spine:",
 * "Wrist:", "Neurological:") above the element prefixes
 * ("Inspection:", "Bony Palpation:"), which cannot be derived from the
 * four columns the spec defines. The renderer therefore accepts it as an
 * input descriptor field. See README "Fields the renderer needs that the
 * schema does not define" — the schema owner decides whether it becomes
 * `template_element.group_label` or is derived from `template.body_region`.
 */
final class ElementDef
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly ?int $template_id = null,
        public readonly int $sort_order = 0,
        public readonly bool $allows_multi = true,
        public readonly ?string $render_prefix = null,
        public readonly ?string $group_label = null,
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            label: (string) $row['label'],
            template_id: isset($row['template_id']) ? (int) $row['template_id'] : null,
            sort_order: isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
            allows_multi: (bool) ($row['allows_multi'] ?? true),
            render_prefix: $row['render_prefix'] ?? null,
            group_label: $row['group_label'] ?? null,
        );
    }

    /** The label used when prefixing a rendered element line. */
    public function prefix(): string
    {
        return rtrim((string) ($this->render_prefix ?? $this->label), ': ');
    }
}
