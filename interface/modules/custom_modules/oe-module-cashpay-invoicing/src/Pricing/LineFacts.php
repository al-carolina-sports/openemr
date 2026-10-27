<?php

/**
 * What a promo needs to know about a line to decide whether it applies.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Pricing;

use OpenEMR\Modules\CashpayInvoicing\Billing\InvoiceType;
use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class LineFacts
{
    /**
     * @param 'procedure'|'product' $itemType
     * @param list<int> $vendorIds
     * @param list<int> $activePlanIds the patient's active membership plans on the line date
     */
    public function __construct(
        public string $itemType,
        public int $itemId,
        public ?int $categoryId,
        public ?int $manufacturerId,
        public array $vendorIds,
        public ?int $facilityId,
        public InvoiceType $invoiceType,
        public \DateTimeImmutable $date,
        public int $qtyMilli,
        public Money $gross,
        public array $activePlanIds,
    ) {
    }
}
