<?php

/**
 * A promotional discount as the matcher sees it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Pricing;

use OpenEMR\Modules\CashpayInvoicing\Catalog\PromoScope;
use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class PromoCandidate
{
    /**
     * @param 'percent'|'flat_each'|'flat_total' $kind
     * @param int $amountTenThousandths percent or dollars, DECIMAL(12,4) scaled
     * @param list<int> $planIds membership plans that carry this promo
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $kind,
        public int $amountTenThousandths,
        public bool $membersOnly,
        public ?\DateTimeImmutable $startDate,
        public ?\DateTimeImmutable $endDate,
        public PromoScope $scope,
        public ?int $reasonId,
        public array $planIds,
    ) {
    }

    /**
     * Discount for a line with the given gross and quantity, never more than the gross.
     */
    public function discountFor(Money $gross, int $qtyMilli): Money
    {
        $discount = match ($this->kind) {
            'percent' => $gross->percentOf($this->amountTenThousandths),
            'flat_each' => self::dollars($this->amountTenThousandths)->multiplyByMilli($qtyMilli),
            'flat_total' => self::dollars($this->amountTenThousandths),
        };
        if ($discount->isNegative()) {
            return Money::zero();
        }
        return $discount->cents > $gross->cents ? $gross : $discount;
    }

    private static function dollars(int $tenThousandths): Money
    {
        // 37.2500 dollars => 372500 => 3725 cents, rounding half away from zero.
        return (new Money($tenThousandths))->multiplyByMilli(10);
    }
}
