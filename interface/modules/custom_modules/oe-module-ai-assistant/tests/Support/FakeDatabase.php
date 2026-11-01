<?php

/**
 * In-memory Database: canned SELECT results by SQL fragment, and a record
 * of every write.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tests\Support;

use OpenEMR\Modules\AiAssistant\Database\Database;

final class FakeDatabase implements Database
{
    /** @var array<string, list<array<string, scalar|null>>> */
    private array $results = [];
    /** @var list<array{sql: string, binds: list<scalar|null>}> */
    public array $writes = [];
    /** @var list<array{sql: string, binds: list<scalar|null>}> */
    public array $reads = [];

    /**
     * @param list<array<string, scalar|null>> $rows
     */
    public function on(string $sqlFragment, array $rows): self
    {
        $this->results[$sqlFragment] = $rows;
        return $this;
    }

    public function fetchAll(string $sql, array $binds = []): array
    {
        $this->reads[] = ['sql' => $sql, 'binds' => $binds];
        foreach ($this->results as $fragment => $rows) {
            if (str_contains($sql, $fragment)) {
                return $rows;
            }
        }
        return [];
    }

    public function fetchOne(string $sql, array $binds = []): ?array
    {
        return $this->fetchAll($sql, $binds)[0] ?? null;
    }

    public function execute(string $sql, array $binds = []): void
    {
        $this->writes[] = ['sql' => $sql, 'binds' => $binds];
    }

    public function insert(string $sql, array $binds = []): int
    {
        $this->writes[] = ['sql' => $sql, 'binds' => $binds];
        return count($this->writes);
    }

    public function transaction(callable $work): mixed
    {
        return $work();
    }

    /**
     * @return list<list<scalar|null>> binds of the writes whose SQL contains $fragment
     */
    public function writesTo(string $fragment): array
    {
        $out = [];
        foreach ($this->writes as $write) {
            if (str_contains($write['sql'], $fragment)) {
                $out[] = $write['binds'];
            }
        }
        return $out;
    }
}
