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

namespace OpenEMR\Modules\CashpayInvoicing\Database;

use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;

final class Row
{
    /**
     * @param array<string, scalar|null> $row
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
     * @param array<string, scalar|null> $row
     */
    public static function intOrNull(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        return $value === null || $value === '' ? null : self::int($row, $key);
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function strOrNull(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function bool(array $row, string $key): bool
    {
        return in_array($row[$key] ?? null, [1, '1', true], true);
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function money(array $row, string $key): Money
    {
        $value = $row[$key] ?? null;
        return is_string($value) && $value !== '' ? Money::parse($value) : Money::zero();
    }

    /**
     * DECIMAL value scaled to an integer with the given number of places.
     *
     * @param array<string, scalar|null> $row
     */
    public static function scaled(array $row, string $key, int $scale): int
    {
        $value = $row[$key] ?? null;
        return is_string($value) && $value !== '' ? Decimal::parse($value, $scale) : 0;
    }

    /**
     * @param array<string, scalar|null> $row
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
