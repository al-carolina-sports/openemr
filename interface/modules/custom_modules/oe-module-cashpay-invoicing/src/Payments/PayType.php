<?php

/**
 * Ledger entry types. Backed because the value is stored in cpi_payment.pay_type.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Payments;

enum PayType: string
{
    case Payment = 'payment';
    case Deposit = 'deposit';
    case Refund = 'refund';
    case WriteOff = 'write_off';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payment',
            self::Deposit => 'Deposit',
            self::Refund => 'Refund',
            self::WriteOff => 'Write-off',
        };
    }

    /** Money the patient handed over (as opposed to refunds and write-offs). */
    public function isMoneyIn(): bool
    {
        return $this === self::Payment || $this === self::Deposit;
    }
}
