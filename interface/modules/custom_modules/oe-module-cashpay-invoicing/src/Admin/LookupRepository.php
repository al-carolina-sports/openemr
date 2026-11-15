<?php

/**
 * Options for lookup fields and id => name maps for list pages.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

use OpenEMR\Modules\CashpayInvoicing\Database\Database;

final class LookupRepository
{
    /** @var array<string, array<int, string>> */
    private array $nameCache = [];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Active options, plus the currently stored id even if it is inactive
     * so editing an old record never silently changes its value.
     *
     * @return array<int, string> id => label
     */
    public function options(LookupSource $source, ?int $includeId = null): array
    {
        [$sql, $binds] = $this->query($source, true, $includeId);
        return $this->toMap($this->db->fetchAll($sql, $binds));
    }

    /**
     * Every row, active or not, for showing names in lists.
     *
     * @return array<int, string>
     */
    public function names(LookupSource $source): array
    {
        $key = $source->name;
        if (!isset($this->nameCache[$key])) {
            [$sql, $binds] = $this->query($source, false, null);
            $this->nameCache[$key] = $this->toMap($this->db->fetchAll($sql, $binds));
        }
        return $this->nameCache[$key];
    }

    /**
     * @return array{string, list<int>}
     */
    private function query(LookupSource $source, bool $activeOnly, ?int $includeId): array
    {
        $binds = [];
        $table = $source->moduleTable();
        if ($table !== null) {
            [$tableName, $labelColumn] = $table;
            $sql = "SELECT `id`, `$labelColumn` AS `label` FROM `$tableName`";
            if ($activeOnly) {
                $sql .= ' WHERE (`active` = 1' . ($includeId !== null ? ' OR `id` = ?' : '') . ')';
            }
            $sql .= " ORDER BY `$labelColumn`";
        } elseif ($source === LookupSource::Facility) {
            $sql = 'SELECT `id`, `name` AS `label` FROM `facility`';
            if ($activeOnly) {
                $sql .= ' WHERE (`inactive` = 0' . ($includeId !== null ? ' OR `id` = ?' : '') . ')';
            }
            $sql .= ' ORDER BY `name`';
        } else {
            $sql = "SELECT `id`, TRIM(CONCAT(`lname`, ', ', `fname`, ' ', COALESCE(`suffix`, ''))) AS `label` FROM `users`"
                . " WHERE `username` <> '' AND `username` IS NOT NULL"
                . ($source === LookupSource::Provider ? ' AND `authorized` = 1' : '');
            if ($activeOnly) {
                $sql .= ' AND (`active` = 1' . ($includeId !== null ? ' OR `id` = ?' : '') . ')';
            }
            $sql .= ' ORDER BY `lname`, `fname`';
        }
        if ($activeOnly && $includeId !== null) {
            $binds[] = $includeId;
        }
        return [$sql, $binds];
    }

    /**
     * @param list<array<string, scalar|null>> $rows
     * @return array<int, string>
     */
    private function toMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (is_numeric($row['id'] ?? null)) {
                $label = $row['label'] ?? '';
                $map[(int) $row['id']] = is_string($label) ? $label : '';
            }
        }
        return $map;
    }
}
