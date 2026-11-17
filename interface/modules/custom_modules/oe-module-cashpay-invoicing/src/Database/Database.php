<?php

/**
 * The module's narrow view of the database, so services can be tested
 * without OpenEMR's global connection.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Database;

interface Database
{
    /**
     * @param list<scalar|null> $binds
     * @return list<array<string, scalar|null>>
     */
    public function fetchAll(string $sql, array $binds = []): array;

    /**
     * @param list<scalar|null> $binds
     * @return array<string, scalar|null>|null
     */
    public function fetchOne(string $sql, array $binds = []): ?array;

    /**
     * @param list<scalar|null> $binds
     */
    public function execute(string $sql, array $binds = []): void;

    /**
     * Runs an INSERT and returns the new auto-increment id.
     *
     * @param list<scalar|null> $binds
     */
    public function insert(string $sql, array $binds = []): int;

    /**
     * Runs $work in a transaction; rolls back and rethrows on any failure.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed;
}
