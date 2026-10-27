<?php

/**
 * Money in integer cents. No float arithmetic anywhere in the module.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing;

final readonly class Money
{
    public function __construct(public int $cents)
    {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parses user input or a DECIMAL column value: "1,234.5", "$12", "-3.25".
     * More than two decimal places is rejected rather than silently rounded.
     *
     * @throws \InvalidArgumentException when the text is not an amount
     */
    public static function parse(string $text): self
    {
        $clean = str_replace([',', '$', ' '], '', trim($text));
        if (preg_match('/^(-?)(\d*)(?:\.(\d{0,2}))?$/', $clean, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new \InvalidArgumentException('Not a money amount');
        }
        $whole = $m[2] === '' ? 0 : (int) $m[2];
        $fraction = (int) str_pad($m[3] ?? '', 2, '0');
        $cents = $whole * 100 + $fraction;
        return new self($m[1] === '-' ? -$cents : $cents);
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    /**
     * Multiplies by a quantity held in thousandths (DECIMAL(10,3)),
     * rounding half away from zero to the cent.
     */
    public function multiplyByMilli(int $milli): self
    {
        return new self(self::divideRounded($this->cents * $milli, 1000));
    }

    /**
     * Applies a percentage held in ten-thousandths of a percent
     * (DECIMAL(7,4), so 6.75% is 67500), rounding half away from zero.
     */
    public function percentOf(int $pctTenThousandths): self
    {
        return new self(self::divideRounded($this->cents * $pctTenThousandths, 1_000_000));
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    /** Value for a DECIMAL(12,2) column: "1234.50". */
    public function toDecimalString(): string
    {
        $abs = abs($this->cents);
        return ($this->cents < 0 ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Display value: "$1,234.50" or "-$3.25". */
    public function format(): string
    {
        $abs = abs($this->cents);
        return ($this->cents < 0 ? '-' : '') . '$' . number_format(intdiv($abs, 100)) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function divideRounded(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        if (abs($remainder) * 2 >= $denominator) {
            $quotient += $numerator < 0 ? -1 : 1;
        }
        return $quotient;
    }
}
