<?php

/**
 * A card payment confirmed by the processor (Stripe).
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

final readonly class ProcessorPayment
{
    /**
     * @param array<int, Money> $allocations invoice id => amount wanted on it
     * @param Money $amount the payment without the card charge
     */
    public function __construct(
        public int $pid,
        public \DateTimeImmutable $date,
        public string $accountKey,
        public string $paymentIntentId,
        public string $chargeId,
        public Money $amount,
        public Money $cardFee,
        public int $cardFeeBasisPoints,
        public CardFunding $funding,
        public string $brand,
        public string $last4,
        public array $allocations,
        public ?int $facilityId,
        public string $source,
        public ?int $userId,
    ) {
    }
}
