<?php

/**
 * Kinds of payment method. Backed because the value is stored in cpi_payment_method.kind.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Payments;

enum MethodKind: string
{
    case Cash = 'cash';
    case Check = 'check';
    case Card = 'card';
    case Points = 'points';
    case GiftCard = 'gift_card';
    case Financing = 'financing';
    case Other = 'other';

    /** Payments made with points or gift cards never earn points. */
    public function earnsPoints(): bool
    {
        return match ($this) {
            self::Points, self::GiftCard => false,
            self::Cash, self::Check, self::Card, self::Financing, self::Other => true,
        };
    }
}
