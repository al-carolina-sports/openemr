<?php

/**
 * Tax 1 or Tax 2: a true tax applied to items flagged for it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Settings;

use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class TaxType
{
    /** Rates above 25% are almost certainly typing mistakes. */
    public const MAX_RATE = 250000;

    /**
     * @param int $rateTenThousandths percent in ten-thousandths, 6.75% = 67500
     */
    public function __construct(
        public int $slot,
        public string $name,
        public int $rateTenThousandths,
        public bool $active,
    ) {
        if ($slot !== 1 && $slot !== 2) {
            throw new \DomainException('Tax slot must be 1 or 2');
        }
        if ($rateTenThousandths < 0 || $rateTenThousandths > self::MAX_RATE) {
            throw new \DomainException('Tax rate out of range');
        }
    }

    public function rateText(): string
    {
        return Decimal::format($this->rateTenThousandths, 4);
    }

    public function taxOn(Money $taxable): Money
    {
        return $this->active ? $taxable->percentOf($this->rateTenThousandths) : Money::zero();
    }
}
