<?php

/**
 * A payment as entered at the front desk.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Payments;

use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Settings\CardFunding;

final readonly class PaymentInput
{
    /**
     * @param array<int, Money> $allocations invoice id => amount applied
     */
    public function __construct(
        public PayType $payType,
        public \DateTimeImmutable $date,
        public int $methodId,
        public Money $amount,
        public Money $gratuity,
        public ?int $facilityId,
        public string $reference,
        public string $comments,
        public array $allocations,
        public ?CardFunding $funding = null,
        public string $giftCardCode = '',
    ) {
    }
}
