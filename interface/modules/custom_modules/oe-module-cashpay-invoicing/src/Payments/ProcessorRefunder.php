<?php

/**
 * Sends a refund back through the processor that took the payment.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Payments;

use OpenEMR\Modules\CashpayInvoicing\Money;

interface ProcessorRefunder
{
    /**
     * Refunds the amount (payment part plus card charge part) and returns the processor's refund id.
     */
    public function refundPayment(string $accountKey, string $paymentIntentId, Money $amount): string;
}
