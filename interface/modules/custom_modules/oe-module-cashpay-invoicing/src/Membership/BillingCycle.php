<?php

/**
 * Membership billing cycle. Backed because it is stored in cpi_patient_membership.billing_cycle.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Membership;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    /**
     * The next bill date. Month ends are kept: Jan 31 bills next on Feb 28
     * (or 29), then Mar 31, because each date is counted from the start date.
     */
    public function periodStart(\DateTimeImmutable $start, int $periods): \DateTimeImmutable
    {
        $months = $this === self::Monthly ? $periods : $periods * 12;
        $year = (int) $start->format('Y');
        $month = (int) $start->format('n') + $months;
        $year += intdiv($month - 1, 12);
        $month = ($month - 1) % 12 + 1;
        $day = min((int) $start->format('j'), (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t'));
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Annual => 'Annual',
        };
    }
}
