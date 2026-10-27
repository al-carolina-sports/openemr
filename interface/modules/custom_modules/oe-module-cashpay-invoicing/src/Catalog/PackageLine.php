<?php

/**
 * One line of a sales package, for totals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Catalog;

use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class PackageLine
{
    /**
     * @param int $qtyMilli quantity in thousandths (DECIMAL(10,3))
     * @param Money $discount discount on the whole line, not per unit
     */
    public function __construct(
        public int $qtyMilli,
        public Money $unitPrice,
        public Money $discount,
    ) {
    }

    public function gross(): Money
    {
        return $this->unitPrice->multiplyByMilli($this->qtyMilli);
    }

    public function net(): Money
    {
        return $this->gross()->subtract($this->discount);
    }
}
