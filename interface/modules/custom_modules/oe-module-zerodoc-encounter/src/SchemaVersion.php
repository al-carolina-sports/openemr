<?php

/**
 * Reads and writes zdoc_schema_version, the placeholder bookkeeping table that
 * records which revision of the ZeroDoc clinical schema is installed.
 *
 * This is the hook the contracts agent's schema uses to report itself: add a
 * component row per schema area in table.sql (or in an upgrade script) and the
 * admin status page will show it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ZeroDoc;

use OpenEMR\Common\Database\QueryUtils;

final class SchemaVersion
{
    public const TABLE = 'zdoc_schema_version';

    public static function installed(): bool
    {
        return QueryUtils::existsTable(self::TABLE);
    }

    /** @return list<array{component: string, version: int, applied_at: string, note: ?string}> */
    public static function all(): array
    {
        if (!self::installed()) {
            return [];
        }
        $rows = QueryUtils::fetchRecords(
            'SELECT `component`, `version`, `applied_at`, `note` FROM `' . self::TABLE . '` ORDER BY `component`'
        );
        return array_map(static fn(array $r): array => [
            'component' => (string)$r['component'],
            'version' => (int)$r['version'],
            'applied_at' => (string)$r['applied_at'],
            'note' => $r['note'] === null ? null : (string)$r['note'],
        ], $rows);
    }

    public static function of(string $component): int
    {
        if (!self::installed()) {
            return 0;
        }
        $row = QueryUtils::querySingleRow(
            'SELECT `version` FROM `' . self::TABLE . '` WHERE `component` = ?',
            [$component]
        );
        return is_array($row) ? (int)$row['version'] : 0;
    }

    public static function set(string $component, int $version, ?string $note = null): void
    {
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO `' . self::TABLE . '` (`component`, `version`, `note`) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE `version` = VALUES(`version`), `note` = VALUES(`note`)',
            [$component, $version, $note]
        );
    }
}
