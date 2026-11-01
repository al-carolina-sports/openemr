<?php

/**
 * Typed reads from a database row (values arrive as strings or null).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Database;

final class Row
{
    /**
     * @param array<array-key, mixed> $row
     */
    public static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException('Column ' . $key . ' is not an integer');
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function intOrNull(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        return $value === null || $value === '' ? null : self::int($row, $key);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    /**
     * A DATE or the date part of a DATETIME; null for empty or zero dates.
     *
     * @param array<array-key, mixed> $row
     */
    public static function date(array $row, string $key): ?\DateTimeImmutable
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        return $date === false ? null : $date;
    }
}
