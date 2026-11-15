<?php

/**
 * Typed reads from decoded JSON or CSV records.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Import;

final class Rec
{
    /**
     * @param array<array-key, mixed> $rec
     */
    public static function str(array $rec, string $key): string
    {
        $value = $rec[$key] ?? '';
        return is_string($value) ? trim($value) : (is_int($value) ? (string) $value : '');
    }

    /**
     * @param array<array-key, mixed> $rec
     */
    public static function flag(array $rec, string $key): int
    {
        $value = $rec[$key] ?? 0;
        return $value === 1 || $value === true || in_array($value, ['1', 'yes', 'Yes', 'Y', 'y', 'true', 'TRUE'], true) ? 1 : 0;
    }

    /**
     * @param array<array-key, mixed> $rec
     * @return list<string>
     */
    public static function strings(array $rec, string $key): array
    {
        $value = $rec[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? trim($v) : '',
            $value
        ), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @param array<array-key, mixed> $rec
     * @return list<array<array-key, mixed>>
     */
    public static function records(array $rec, string $key): array
    {
        $value = $rec[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, is_array(...)));
    }

    /** Empty string to null, for nullable columns. */
    public static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
