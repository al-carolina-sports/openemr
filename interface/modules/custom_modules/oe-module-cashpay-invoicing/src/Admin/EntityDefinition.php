<?php

/**
 * Describes one catalog table for the generic list and edit pages.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

final readonly class EntityDefinition
{
    /**
     * @param list<FieldDefinition> $fields
     * @param list<Panel> $panels
     */
    public function __construct(
        public string $key,
        public string $table,
        public string $title,
        public string $singular,
        public array $fields,
        public string $orderBy = 'name',
        public ?string $uniqueField = 'name',
        public array $panels = [],
        public int $pageSize = 100,
        public string $group = 'Lists',
        public string $description = '',
    ) {
        foreach ($fields as $field) {
            if (preg_match('/^[a-z_0-9]+$/', $field->name) !== 1) {
                throw new \LogicException('Bad column name: ' . $field->name);
            }
        }
        if (preg_match('/^cpi_[a-z_]+$/', $table) !== 1) {
            throw new \LogicException('Bad table name: ' . $table);
        }
    }

    public function field(string $name): ?FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }
        return null;
    }

    public function hasActive(): bool
    {
        return $this->field('active') !== null;
    }

    /**
     * @return list<FieldDefinition>
     */
    public function listFields(): array
    {
        return array_values(array_filter($this->fields, static fn (FieldDefinition $f): bool => $f->inList));
    }
}
