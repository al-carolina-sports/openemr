<?php

/**
 * Database adapter over OpenEMR's QueryUtils.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Database;

use OpenEMR\Common\Database\QueryUtils;

final class QueryUtilsDatabase implements Database
{
    public function fetchAll(string $sql, array $binds = []): array
    {
        $rows = [];
        foreach (QueryUtils::fetchRecords($sql, $binds) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ($row as $key => $value) {
                if (is_string($key) && (is_scalar($value) || $value === null)) {
                    $clean[$key] = $value;
                }
            }
            $rows[] = $clean;
        }
        return $rows;
    }

    public function fetchOne(string $sql, array $binds = []): ?array
    {
        return $this->fetchAll($sql, $binds)[0] ?? null;
    }

    public function execute(string $sql, array $binds = []): void
    {
        QueryUtils::sqlStatementThrowException($sql, $binds);
    }

    public function insert(string $sql, array $binds = []): int
    {
        $id = QueryUtils::sqlInsert($sql, $binds);
        if (!is_numeric($id)) {
            throw new \RuntimeException('Insert did not return an id');
        }
        return (int) $id;
    }

    public function transaction(callable $work): mixed
    {
        QueryUtils::startTransaction();
        try {
            $result = $work();
            QueryUtils::commitTransaction();
            return $result;
        } catch (\Throwable $e) {
            QueryUtils::rollbackTransaction();
            throw $e;
        }
    }
}
