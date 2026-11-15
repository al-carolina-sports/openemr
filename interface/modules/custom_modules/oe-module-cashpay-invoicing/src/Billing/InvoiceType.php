<?php

/**
 * Invoice types. Backed because the value is stored in cpi_invoice.invoice_type.
 * There is deliberately no insurance type: insurance stays in OpenEMR billing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Billing;

enum InvoiceType: string
{
    case Immediate = 'immediate';
    case Prepaid = 'prepaid';
    case Surgery = 'surgery';
    case GiftCard = 'gift_card';
    case Membership = 'membership';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'Immediate Sale',
            self::Prepaid => 'Prepaid Packages',
            self::Surgery => 'Surgery',
            self::GiftCard => 'Gift Cards',
            self::Membership => 'Membership',
        };
    }

    /**
     * Prepaid and surgery lines are paid now and delivered later, so their
     * revenue is unearned until each line is received. Other types are
     * delivered when sold.
     */
    public function defersDelivery(): bool
    {
        return match ($this) {
            self::Prepaid, self::Surgery => true,
            self::Immediate, self::GiftCard, self::Membership => false,
        };
    }

    /**
     * Whether one line waits for delivery. On top of prepaid and surgery
     * invoices, the package included with a membership each month is
     * redeemed later, so those lines wait too.
     */
    public function lineDeferred(?int $packageId): bool
    {
        return $this->defersDelivery() || ($this === self::Membership && $packageId !== null);
    }

    /**
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }
        return $out;
    }
}
