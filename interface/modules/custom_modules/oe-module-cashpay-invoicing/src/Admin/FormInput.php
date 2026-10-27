<?php

/**
 * Typed reads of a submitted form, turning bad values into ValidationException.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;
use Symfony\Component\HttpFoundation\InputBag;

final readonly class FormInput
{
    /**
     * @param InputBag<string> $bag
     */
    public function __construct(private InputBag $bag)
    {
    }

    public function string(string $key): string
    {
        $value = $this->bag->all()[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    public function bool(string $key): bool
    {
        return in_array($this->string($key), ['1', 'on', 'yes', 'true'], true);
    }

    /** Positive id, or null when blank. */
    public function optionalId(string $key): ?int
    {
        $text = $this->string($key);
        if ($text === '' || $text === '0') {
            return null;
        }
        if (preg_match('/^[1-9]\d{0,9}$/', $text) !== 1) {
            throw new ValidationException([$key => 'Invalid selection']);
        }
        return (int) $text;
    }

    public function requiredId(string $key): int
    {
        return $this->optionalId($key) ?? throw new ValidationException([$key => 'Required']);
    }

    public function money(string $key, bool $required = true): ?Money
    {
        $text = $this->string($key);
        if ($text === '') {
            if ($required) {
                throw new ValidationException([$key => 'Required']);
            }
            return null;
        }
        try {
            return Money::parse($text);
        } catch (\InvalidArgumentException) {
            throw new ValidationException([$key => 'Enter an amount like 125.00']);
        }
    }

    /** Quantity in thousandths, or null when blank and not required. */
    public function milli(string $key, bool $required = true): ?int
    {
        $text = $this->string($key);
        if ($text === '') {
            if ($required) {
                throw new ValidationException([$key => 'Required']);
            }
            return null;
        }
        try {
            return Decimal::parse($text, 3);
        } catch (\InvalidArgumentException) {
            throw new ValidationException([$key => 'Enter a number with up to 3 decimal places']);
        }
    }

    /**
     * Positive ids from a multi-select (name="key[]").
     *
     * @return list<int>
     */
    public function idList(string $key): array
    {
        $value = $this->bag->all()[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            if (is_string($item) && preg_match('/^[1-9]\d{0,9}$/', $item) === 1) {
                $ids[] = (int) $item;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->bag->all();
    }
}
