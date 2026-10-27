<?php

/**
 * Award point arithmetic. Points are held in hundredths (DECIMAL(12,2)).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Rewards;

use OpenEMR\Modules\CashpayInvoicing\Money;

final class PointsMath
{
    /**
     * The part of a payment that earns points: the payment applied to an
     * invoice, times the share of that invoice that is eligible net (no tax,
     * no card charge, only items marked "earns award points"). Rounded down.
     */
    public static function eligiblePaid(Money $allocated, Money $eligibleNet, Money $invoiceTotal): Money
    {
        if ($allocated->cents <= 0 || $eligibleNet->cents <= 0 || $invoiceTotal->cents <= 0) {
            return Money::zero();
        }
        $share = min($eligibleNet->cents, $invoiceTotal->cents);
        return new Money(intdiv($allocated->cents * $share, $invoiceTotal->cents));
    }

    /**
     * Points earned for dollars paid. pointsPerDollar is in hundredths (100 = 1 point per $1).
     *
     * @return int points in hundredths, rounded down
     */
    public static function earned(Money $paid, int $pointsPerDollarHundredths): int
    {
        if ($paid->cents <= 0 || $pointsPerDollarHundredths <= 0) {
            return 0;
        }
        return intdiv($paid->cents * $pointsPerDollarHundredths, 100);
    }

    /**
     * Points needed to pay an amount, rounded up so the practice is never short.
     *
     * @return int points in hundredths
     */
    public static function neededFor(Money $amount, Money $pointValue): int
    {
        if ($pointValue->cents <= 0) {
            throw new \DomainException('Points have no redemption value set');
        }
        return intdiv($amount->cents * 100 + $pointValue->cents - 1, $pointValue->cents);
    }

    /** Dollar value of points (hundredths), rounded down. */
    public static function valueOf(int $pointsHundredths, Money $pointValue): Money
    {
        return new Money(intdiv($pointsHundredths * $pointValue->cents, 100));
    }

    /** A share of points in proportion part/whole, rounded down (for partial refunds). */
    public static function proportion(int $pointsHundredths, Money $part, Money $whole): int
    {
        if ($whole->cents <= 0) {
            return 0;
        }
        return intdiv($pointsHundredths * min($part->cents, $whole->cents), $whole->cents);
    }
}
