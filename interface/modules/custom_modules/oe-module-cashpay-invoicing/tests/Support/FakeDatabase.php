<?php

/**
 * In-memory stand-in for the module's Database: records statements, hands
 * out insert ids and answers reads from a callback.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests\Support;

use OpenEMR\Modules\CashpayInvoicing\Database\Database;

final class FakeDatabase implements Database
{
    /** @var list<array{string, list<scalar|null>}> */
    public array $statements = [];

    public int $transactions = 0;

    private int $nextId = 1;

    /** @var \Closure(string, list<scalar|null>): list<array<string, scalar|null>> */
    private \Closure $reader;

    /**
     * @param (callable(string, list<scalar|null>): list<array<string, scalar|null>>)|null $reader
     */
    public function __construct(?callable $reader = null)
    {
        $this->reader = $reader !== null ? $reader(...) : static fn (): array => [];
    }

    public function fetchAll(string $sql, array $binds = []): array
    {
        return ($this->reader)($sql, $binds);
    }

    public function fetchOne(string $sql, array $binds = []): ?array
    {
        return $this->fetchAll($sql, $binds)[0] ?? null;
    }

    public function execute(string $sql, array $binds = []): void
    {
        $this->statements[] = [$sql, $binds];
    }

    public function insert(string $sql, array $binds = []): int
    {
        $this->statements[] = [$sql, $binds];
        return $this->nextId++;
    }

    public function transaction(callable $work): mixed
    {
        $this->transactions++;
        return $work();
    }

    /**
     * @return list<array{string, list<scalar|null>}>
     */
    public function inserts(string $table): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (array $s): bool => str_starts_with($s[0], 'INSERT INTO `' . $table . '`')
                || str_starts_with($s[0], 'INSERT IGNORE INTO `' . $table . '`')
        ));
    }
}
