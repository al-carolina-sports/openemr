<?php

/**
 * Totals of a sales package: list value, discounts and package price.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Catalog;

use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class PackageTotals
{
    public function __construct(
        public Money $gross,
        public Money $discount,
        public Money $net,
    ) {
    }

    /**
     * @param list<PackageLine> $lines
     */
    public static function of(array $lines): self
    {
        $gross = Money::zero();
        $discount = Money::zero();
        foreach ($lines as $line) {
            $gross = $gross->add($line->gross());
            $discount = $discount->add($line->discount);
        }
        return new self($gross, $discount, $gross->subtract($discount));
    }
}
