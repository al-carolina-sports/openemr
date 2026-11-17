<?php

/**
 * Daily cash drawer per office: opening balance, cash taken and refunded
 * that day, adjustments, and the counted closing balance.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Payments;

use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class CashDrawerService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{open: bool, closed: bool, opening: Money, cashIn: Money, cashOut: Money, adjustment: Money, expected: Money,
     *     closing: ?Money, variance: ?Money, openedBy: string, closedBy: string, openedAt: string, closedAt: string,
     *     entries: list<array<string, scalar|null>>}
     */
    public function day(int $facilityId, \DateTimeImmutable $date): array
    {
        $day = $date->format('Y-m-d');
        $drawer = $this->db->fetchOne(
            'SELECT d.*, o.`username` AS `opened_name`, c.`username` AS `closed_name` FROM `cpi_cash_drawer` d'
            . ' LEFT JOIN `users` o ON o.`id` = d.`opened_by` LEFT JOIN `users` c ON c.`id` = d.`closed_by`'
            . ' WHERE d.`facility_id` = ? AND d.`business_date` = ?',
            [$facilityId, $day]
        );
        $entries = $this->db->fetchAll(
            'SELECT p.`id`, p.`pid`, p.`pay_type`, p.`amount`, p.`gratuity`, p.`status`, p.`created_at`, m.`name` AS `method_name`,'
            . " TRIM(CONCAT(pd.`fname`, ' ', pd.`lname`)) AS `patient`, u.`username`"
            . ' FROM `cpi_payment` p JOIN `cpi_payment_method` m ON m.`id` = p.`method_id`'
            . ' LEFT JOIN `patient_data` pd ON pd.`pid` = p.`pid` LEFT JOIN `users` u ON u.`id` = p.`created_by`'
            . " WHERE m.`kind` = 'cash' AND p.`facility_id` = ? AND p.`pay_date` = ? ORDER BY p.`id`",
            [$facilityId, $day]
        );
        $in = Money::zero();
        $out = Money::zero();
        foreach ($entries as $entry) {
            if (Row::str($entry, 'status') !== 'posted') {
                continue;
            }
            $amount = Row::money($entry, 'amount')->add(Row::money($entry, 'gratuity'));
            if (Row::str($entry, 'pay_type') === PayType::Refund->value) {
                $out = $out->add($amount);
            } elseif (in_array(Row::str($entry, 'pay_type'), [PayType::Payment->value, PayType::Deposit->value], true)) {
                $in = $in->add($amount);
            }
        }
        $opening = $drawer === null ? Money::zero() : Row::money($drawer, 'opening_balance');
        $adjustment = $drawer === null ? Money::zero() : Row::money($drawer, 'adjustment');
        $expected = $opening->add($in)->subtract($out)->add($adjustment);
        $closing = $drawer !== null && Row::strOrNull($drawer, 'closing_balance') !== null ? Row::money($drawer, 'closing_balance') : null;
        return [
            'open' => $drawer !== null,
            'closed' => $closing !== null,
            'opening' => $opening,
            'cashIn' => $in,
            'cashOut' => $out,
            'adjustment' => $adjustment,
            'expected' => $expected,
            'closing' => $closing,
            'variance' => $closing?->subtract($expected),
            'openedBy' => $drawer === null ? '' : Row::str($drawer, 'opened_name'),
            'closedBy' => $drawer === null ? '' : Row::str($drawer, 'closed_name'),
            'openedAt' => $drawer === null ? '' : Row::str($drawer, 'opened_at'),
            'closedAt' => $drawer === null ? '' : Row::str($drawer, 'closed_at'),
            'entries' => $entries,
        ];
    }

    public function open(int $facilityId, \DateTimeImmutable $date, Money $opening, int $userId): void
    {
        if ($opening->isNegative()) {
            throw new ValidationException(['opening' => 'The opening balance cannot be negative']);
        }
        if ($this->row($facilityId, $date) !== null) {
            throw new ValidationException(['opening' => 'The drawer is already open for that day']);
        }
        $this->db->execute(
            'INSERT INTO `cpi_cash_drawer` (`facility_id`, `business_date`, `opening_balance`, `opened_by`, `opened_at`) VALUES (?, ?, ?, ?, NOW())',
            [$facilityId, $date->format('Y-m-d'), $opening->toDecimalString(), $userId]
        );
    }

    public function close(int $facilityId, \DateTimeImmutable $date, Money $counted, Money $adjustment, int $userId): void
    {
        $row = $this->row($facilityId, $date) ?? throw new ValidationException(['counted' => 'Open the drawer first']);
        if (Row::strOrNull($row, 'closing_balance') !== null) {
            throw new ValidationException(['counted' => 'The drawer is already closed for that day']);
        }
        if ($counted->isNegative()) {
            throw new ValidationException(['counted' => 'The counted amount cannot be negative']);
        }
        $this->db->execute(
            'UPDATE `cpi_cash_drawer` SET `closing_balance` = ?, `adjustment` = ?, `closed_by` = ?, `closed_at` = NOW() WHERE `id` = ?',
            [$counted->toDecimalString(), $adjustment->toDecimalString(), $userId, Row::int($row, 'id')]
        );
    }

    /** Reopens a closed day (needs the refunds-and-unlock permission). */
    public function reopen(int $facilityId, \DateTimeImmutable $date): void
    {
        $row = $this->row($facilityId, $date) ?? throw new ValidationException(['counted' => 'No drawer for that day']);
        $this->db->execute(
            'UPDATE `cpi_cash_drawer` SET `closing_balance` = NULL, `closed_by` = NULL, `closed_at` = NULL WHERE `id` = ?',
            [Row::int($row, 'id')]
        );
    }

    /**
     * @return array<string, scalar|null>|null
     */
    private function row(int $facilityId, \DateTimeImmutable $date): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM `cpi_cash_drawer` WHERE `facility_id` = ? AND `business_date` = ?',
            [$facilityId, $date->format('Y-m-d')]
        );
    }
}
