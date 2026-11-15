<?php

/**
 * Changes to one line from the editor.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Billing;

use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class LineEdit
{
    public function __construct(
        public int $qtyMilli,
        public Money $unitPrice,
        public Money $discount,
        public ?int $discountReasonId,
        public ?int $providerId,
        public ?int $sellerId,
        public ?int $facilityId,
        public ?\DateTimeImmutable $serviceDate,
        public ?string $description,
    ) {
    }
}
