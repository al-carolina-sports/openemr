<?php

/**
 * What the module needs to know about a PaymentIntent.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Stripe;

final readonly class IntentView
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public string $id,
        public string $status,
        public int $amountCents,
        public array $metadata,
        public string $chargeId,
        public string $funding,
        public string $brand,
        public string $last4,
        public string $errorMessage,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
