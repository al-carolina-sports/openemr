<?php

/**
 * List, read and save rows of any table described by an EntityDefinition.
 * Table and column names come only from the definitions, never from input.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

use OpenEMR\Modules\CashpayInvoicing\Database\Database;

final class CrudRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{rows: list<array<string, scalar|null>>, total: int}
     */
    public function search(EntityDefinition $entity, ListQuery $query): array
    {
        [$where, $binds] = $this->where($entity, $query);
        $countRow = $this->db->fetchOne("SELECT COUNT(*) AS `n` FROM `{$entity->table}`" . $where, $binds);
        $total = is_numeric($countRow['n'] ?? null) ? (int) $countRow['n'] : 0;
        $offset = max(0, ($query->page - 1) * $entity->pageSize);
        $rows = $this->db->fetchAll(
            "SELECT * FROM `{$entity->table}`" . $where
            . ' ORDER BY ' . $this->orderBy($entity)
            . ' LIMIT ' . $entity->pageSize . ' OFFSET ' . $offset,
            $binds
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function find(EntityDefinition $entity, int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM `{$entity->table}` WHERE `id` = ?", [$id]);
    }

    /**
     * Inserts or updates a row and returns its id.
     *
     * @param array<string, string|int|null> $values parsed values keyed by column
     * @throws ValidationException when the unique field is already taken
     */
    public function save(EntityDefinition $entity, ?int $id, array $values): int
    {
        $this->assertUnique($entity, $id, $values);
        $columns = [];
        $binds = [];
        foreach ($entity->fields as $field) {
            if (!array_key_exists($field->name, $values)) {
                continue;
            }
            $columns[] = $field->name;
            $binds[] = $values[$field->name];
        }
        if ($columns === []) {
            throw new \LogicException('Nothing to save');
        }
        if ($id === null) {
            $sql = "INSERT INTO `{$entity->table}` (`" . implode('`, `', $columns) . '`) VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')';
            return $this->db->insert($sql, $binds);
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "`$c` = ?", $columns));
        $binds[] = $id;
        $this->db->execute("UPDATE `{$entity->table}` SET $sets WHERE `id` = ?", $binds);
        return $id;
    }

    public function setActive(EntityDefinition $entity, int $id, bool $active): void
    {
        if (!$entity->hasActive()) {
            throw new \LogicException('Entity has no active flag');
        }
        $this->db->execute("UPDATE `{$entity->table}` SET `active` = ? WHERE `id` = ?", [$active ? 1 : 0, $id]);
    }

    /**
     * @param array<string, string|int|null> $values
     */
    private function assertUnique(EntityDefinition $entity, ?int $id, array $values): void
    {
        $column = $entity->uniqueField;
        if ($column === null || !isset($values[$column])) {
            return;
        }
        $row = $this->db->fetchOne(
            "SELECT `id` FROM `{$entity->table}` WHERE `$column` = ? AND `id` <> ? LIMIT 1",
            [$values[$column], $id ?? 0]
        );
        if ($row !== null) {
            throw new ValidationException([$column => 'Already used by another record']);
        }
    }

    /**
     * @return array{string, list<scalar>}
     */
    private function where(EntityDefinition $entity, ListQuery $query): array
    {
        $clauses = [];
        $binds = [];
        if ($entity->hasActive() && !$query->includeInactive) {
            $clauses[] = '`active` = 1';
        }
        if ($query->search !== '') {
            $searchable = array_values(array_filter(
                $entity->fields,
                static fn (FieldDefinition $f): bool => in_array($f->type, [FieldType::Text, FieldType::LongText], true)
            ));
            $like = '%' . addcslashes($query->search, '%_\\') . '%';
            $parts = [];
            foreach ($searchable as $field) {
                $parts[] = "`{$field->name}` LIKE ?";
                $binds[] = $like;
            }
            if ($parts !== []) {
                $clauses[] = '(' . implode(' OR ', $parts) . ')';
            }
        }
        foreach ($query->filters as $column => $value) {
            $field = $entity->field($column);
            if ($field === null || $field->type !== FieldType::Lookup) {
                continue;
            }
            $clauses[] = "`{$field->name}` = ?";
            $binds[] = $value;
        }
        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $binds];
    }

    private function orderBy(EntityDefinition $entity): string
    {
        $parts = [];
        foreach (explode(',', $entity->orderBy) as $column) {
            $column = trim($column);
            if (preg_match('/^[a-z_0-9]+$/', $column) === 1) {
                $parts[] = "`$column`";
            }
        }
        $parts[] = '`id`';
        return implode(', ', $parts);
    }
}
