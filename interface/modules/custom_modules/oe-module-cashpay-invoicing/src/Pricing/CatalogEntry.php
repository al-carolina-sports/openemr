<?php

/**
 * A procedure or product with everything needed to price a line.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Pricing;

use OpenEMR\Modules\CashpayInvoicing\Billing\ChargeCategory;
use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class CatalogEntry
{
    /**
     * @param 'procedure'|'product' $type
     * @param list<int> $vendorIds
     * @param list<FeeOverride> $fees procedure overrides
     * @param array<int, Money> $facilityPrices product office prices
     */
    public function __construct(
        public string $type,
        public int $id,
        public string $name,
        public ?string $code,
        public Money $standardPrice,
        public Money $unitCost,
        public bool $tax1,
        public bool $tax2,
        public bool $pointsEligible,
        public bool $allowManualDescription,
        public ?int $categoryId,
        public ?int $manufacturerId,
        public array $vendorIds,
        public array $fees,
        public array $facilityPrices,
        public bool $active,
    ) {
    }

    public function priceFor(?int $providerId, ?int $facilityId): Money
    {
        return $this->type === 'procedure'
            ? PriceResolver::procedurePrice($this->standardPrice, $this->fees, $providerId, $facilityId)
            : PriceResolver::productPrice($this->standardPrice, $this->facilityPrices, $facilityId);
    }

    public function chargeCategory(): ChargeCategory
    {
        return $this->type === 'product' ? ChargeCategory::Product : ChargeCategory::Provider;
    }
}
