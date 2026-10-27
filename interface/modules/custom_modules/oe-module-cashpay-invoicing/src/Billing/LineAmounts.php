<?php

/**
 * The money on one quote or invoice line.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Billing;

use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Settings\TaxType;

final readonly class LineAmounts
{
    public function __construct(
        public Money $gross,
        public Money $discount,
        public Money $net,
        public Money $tax1,
        public Money $tax2,
        public Money $unitCost,
    ) {
    }

    /**
     * Gross = unit price x quantity. The discount is capped at the gross so a
     * line is never negative. Each tax is charged on the net, rounded per line.
     *
     * @param int $qtyMilli quantity in thousandths
     */
    public static function compute(
        Money $unitPrice,
        int $qtyMilli,
        Money $discount,
        ?TaxType $tax1,
        ?TaxType $tax2,
        Money $unitCost = new Money(0),
    ): self {
        if ($qtyMilli <= 0) {
            throw new \DomainException('Quantity must be more than zero');
        }
        if ($unitPrice->isNegative() || $discount->isNegative()) {
            throw new \DomainException('Prices and discounts cannot be negative');
        }
        $gross = $unitPrice->multiplyByMilli($qtyMilli);
        $discount = $discount->cents > $gross->cents ? $gross : $discount;
        $net = $gross->subtract($discount);
        return new self(
            $gross,
            $discount,
            $net,
            $tax1?->taxOn($net) ?? Money::zero(),
            $tax2?->taxOn($net) ?? Money::zero(),
            $unitCost,
        );
    }

    public function total(): Money
    {
        return $this->net->add($this->tax1)->add($this->tax2);
    }
}
