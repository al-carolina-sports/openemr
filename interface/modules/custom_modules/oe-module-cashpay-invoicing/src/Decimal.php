<?php

/**
 * Fixed-point parsing for DECIMAL columns that are not money
 * (quantities, hours, percentages), held as scaled integers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing;

final class Decimal
{
    /**
     * Parses "12.5" with at most $scale decimal places into a scaled
     * integer: parse("12.5", 3) === 12500.
     *
     * @throws \InvalidArgumentException when the text is not a number or has too many places
     */
    public static function parse(string $text, int $scale): int
    {
        $clean = str_replace([',', ' ', '%'], '', trim($text));
        if (preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $clean, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new \InvalidArgumentException('Not a number');
        }
        $fractionText = rtrim($m[3] ?? '', '0');
        if (strlen($fractionText) > $scale) {
            throw new \InvalidArgumentException('Too many decimal places');
        }
        $factor = 10 ** $scale;
        $value = ($m[2] === '' ? 0 : (int) $m[2]) * $factor + (int) str_pad($fractionText, $scale, '0');
        return $m[1] === '-' ? -$value : $value;
    }

    /** Formats a scaled integer back to a DECIMAL column string. */
    public static function format(int $scaled, int $scale): string
    {
        if ($scale === 0) {
            return (string) $scaled;
        }
        $factor = 10 ** $scale;
        $abs = abs($scaled);
        return ($scaled < 0 ? '-' : '') . intdiv($abs, $factor) . '.' . str_pad((string) ($abs % $factor), $scale, '0', STR_PAD_LEFT);
    }

    /**
     * Short display of a DECIMAL column value: "12.500" => "12.5", "3.000" => "3".
     */
    public static function display(mixed $value): string
    {
        if (!is_string($value) || !is_numeric($value)) {
            return '';
        }
        if (!str_contains($value, '.')) {
            return $value;
        }
        $trimmed = rtrim(rtrim($value, '0'), '.');
        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
