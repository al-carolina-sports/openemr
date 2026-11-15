<?php

/**
 * Picks the price for a line: provider and office, then provider only,
 * then office only, then the standard price.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Pricing;

use OpenEMR\Modules\CashpayInvoicing\Money;

final class PriceResolver
{
    /**
     * @param list<FeeOverride> $overrides
     */
    public static function procedurePrice(Money $standard, array $overrides, ?int $providerId, ?int $facilityId): Money
    {
        $byProvider = null;
        $byFacility = null;
        foreach ($overrides as $fee) {
            if ($fee->providerId !== null && $fee->facilityId !== null) {
                if ($fee->providerId === $providerId && $fee->facilityId === $facilityId) {
                    return $fee->price;
                }
                continue;
            }
            if ($fee->providerId !== null && $fee->providerId === $providerId) {
                $byProvider = $fee->price;
            } elseif ($fee->facilityId !== null && $fee->facilityId === $facilityId) {
                $byFacility = $fee->price;
            }
        }
        return $byProvider ?? $byFacility ?? $standard;
    }

    /**
     * @param array<int, Money> $facilityPrices facility id => price
     */
    public static function productPrice(Money $standard, array $facilityPrices, ?int $facilityId): Money
    {
        return $facilityId !== null && isset($facilityPrices[$facilityId]) ? $facilityPrices[$facilityId] : $standard;
    }
}
