<?php

/**
 * Totals matrix for a quote or invoice: one row per charge category with
 * Tax 1, Tax 2, gross ("cost" in PatientNow), discount and total.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Billing;

use OpenEMR\Modules\CashpayInvoicing\Money;

final class Totals
{
    /** @var array<string, array{gross: Money, discount: Money, net: Money, tax1: Money, tax2: Money}> */
    private array $rows = [];

    /**
     * @param iterable<array{ChargeCategory, LineAmounts}> $lines
     */
    public static function of(iterable $lines): self
    {
        $totals = new self();
        foreach ($lines as [$category, $amounts]) {
            $totals->add($category, $amounts);
        }
        return $totals;
    }

    public function add(ChargeCategory $category, LineAmounts $amounts): void
    {
        $row = $this->rows[$category->value] ?? [
            'gross' => Money::zero(), 'discount' => Money::zero(), 'net' => Money::zero(),
            'tax1' => Money::zero(), 'tax2' => Money::zero(),
        ];
        $this->rows[$category->value] = [
            'gross' => $row['gross']->add($amounts->gross),
            'discount' => $row['discount']->add($amounts->discount),
            'net' => $row['net']->add($amounts->net),
            'tax1' => $row['tax1']->add($amounts->tax1),
            'tax2' => $row['tax2']->add($amounts->tax2),
        ];
    }

    /**
     * Rows in matrix order, only categories that have lines.
     *
     * @return list<array{category: ChargeCategory, gross: Money, discount: Money, net: Money, tax1: Money, tax2: Money, total: Money}>
     */
    public function rows(): array
    {
        $out = [];
        foreach (ChargeCategory::cases() as $category) {
            $row = $this->rows[$category->value] ?? null;
            if ($row === null) {
                continue;
            }
            $out[] = ['category' => $category] + $row + ['total' => $row['net']->add($row['tax1'])->add($row['tax2'])];
        }
        return $out;
    }

    public function gross(): Money
    {
        return $this->sum('gross');
    }

    public function discount(): Money
    {
        return $this->sum('discount');
    }

    public function net(): Money
    {
        return $this->sum('net');
    }

    public function tax1(): Money
    {
        return $this->sum('tax1');
    }

    public function tax2(): Money
    {
        return $this->sum('tax2');
    }

    public function total(): Money
    {
        return $this->net()->add($this->tax1())->add($this->tax2());
    }

    /**
     * @param 'gross'|'discount'|'net'|'tax1'|'tax2' $key
     */
    private function sum(string $key): Money
    {
        $sum = Money::zero();
        foreach ($this->rows as $row) {
            $sum = $sum->add($row[$key]);
        }
        return $sum;
    }
}
