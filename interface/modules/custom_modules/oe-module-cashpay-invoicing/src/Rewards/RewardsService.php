<?php

/**
 * Award points: enrollment, the points ledger, earning on payments,
 * redeeming as a payment, and reversing on refunds and voids.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Rewards;

use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Settings\Settings;

final readonly class RewardsService
{
    public function __construct(private Database $db)
    {
    }

    public function isEnrolled(int $pid): bool
    {
        $row = $this->db->fetchOne('SELECT `enrolled` FROM `cpi_patient_rewards` WHERE `pid` = ?', [$pid]);
        return $row !== null && Row::bool($row, 'enrolled');
    }

    public function setEnrolled(int $pid, bool $enrolled, int $userId): void
    {
        $this->db->execute(
            'INSERT INTO `cpi_patient_rewards` (`pid`, `enrolled`, `enrolled_by`, `enrolled_at`) VALUES (?, ?, ?, NOW())'
            . ' ON DUPLICATE KEY UPDATE `enrolled` = VALUES(`enrolled`), `enrolled_by` = VALUES(`enrolled_by`), `enrolled_at` = VALUES(`enrolled_at`)',
            [$pid, $enrolled ? 1 : 0, $userId]
        );
    }

    /** Points balance in hundredths. */
    public function balance(int $pid): int
    {
        $row = $this->db->fetchOne('SELECT COALESCE(SUM(`points`), 0) AS `p` FROM `cpi_points_ledger` WHERE `pid` = ?', [$pid]);
        return $row === null ? 0 : Row::scaled($row, 'p', 2);
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function ledger(int $pid): array
    {
        return $this->db->fetchAll(
            'SELECT l.*, u.`username` FROM `cpi_points_ledger` l LEFT JOIN `users` u ON u.`id` = l.`created_by`'
            . ' WHERE l.`pid` = ? ORDER BY l.`txn_date` DESC, l.`id` DESC',
            [$pid]
        );
    }

    /**
     * Admin adjustment with a required reason.
     *
     * @param int $points hundredths, positive to add, negative to remove
     */
    public function adjust(int $pid, int $points, string $reason, Settings $settings, \DateTimeImmutable $date, int $userId): void
    {
        $reason = trim($reason);
        if ($points === 0) {
            throw new ValidationException(['points' => 'Enter the points to add, or a negative number to remove']);
        }
        if ($reason === '') {
            throw new ValidationException(['reason' => 'A reason is required for manual changes']);
        }
        if ($points < 0 && $this->balance($pid) + $points < 0) {
            throw new ValidationException(['points' => 'That would make the balance negative']);
        }
        $this->write($pid, $date, $points > 0 ? 'Manual award' : 'Manual removal', $points, PointsMath::valueOf($points, $settings->pointValue), 'manual', null, null, mb_substr($reason, 0, 255), $userId);
    }

    /**
     * Earns points on a payment applied to one invoice.
     */
    public function earn(int $pid, int $paymentId, int $invoiceId, Money $eligiblePaid, Settings $settings, \DateTimeImmutable $date, int $userId): void
    {
        if (!$settings->rewardsEnabled || !$this->isEnrolled($pid)) {
            return;
        }
        $points = PointsMath::earned($eligiblePaid, $settings->pointsPerDollarHundredths);
        if ($points <= 0) {
            return;
        }
        $this->write($pid, $date, 'Earned on payment for invoice #' . $invoiceId, $points, PointsMath::valueOf($points, $settings->pointValue), 'payment', $paymentId, $invoiceId, null, $userId);
    }

    /**
     * Takes points for a payment made with points. Returns the points used.
     */
    public function redeem(int $pid, Money $amount, Settings $settings, \DateTimeImmutable $date, int $userId): int
    {
        if (!$settings->rewardsEnabled) {
            throw new ValidationException(['method_id' => 'The award points program is turned off']);
        }
        if (!$this->isEnrolled($pid)) {
            throw new ValidationException(['method_id' => 'This patient is not enrolled in award points']);
        }
        $needed = PointsMath::neededFor($amount, $settings->pointValue);
        $balance = $this->balance($pid);
        if ($needed > $balance) {
            throw new ValidationException(['amount' => 'Not enough points: ' . Decimal::format($balance, 2) . ' available, ' . Decimal::format($needed, 2) . ' needed']);
        }
        if ($settings->minRedeemPoints > 0 && $balance < $settings->minRedeemPoints * 100) {
            throw new ValidationException(['amount' => 'At least ' . $settings->minRedeemPoints . ' points are needed before redeeming']);
        }
        return $needed;
    }

    public function recordRedeem(int $pid, int $paymentId, int $points, Money $amount, \DateTimeImmutable $date, int $userId): void
    {
        $this->write($pid, $date, 'Redeemed as payment', -$points, new Money(-$amount->cents), 'redeem', $paymentId, null, null, $userId);
    }

    /**
     * Reverses points tied to a payment: what it earned (taken back) and what it
     * spent (given back), in proportion to the part refunded or voided.
     */
    public function reverseForPayment(int $pid, int $paymentId, Money $part, Money $whole, \DateTimeImmutable $date, int $userId, int $refundPaymentId): void
    {
        $rows = $this->db->fetchAll(
            "SELECT `source`, SUM(`points`) AS `p`, SUM(`dollar_value`) AS `d` FROM `cpi_points_ledger` WHERE `payment_id` = ? AND `source` IN ('payment', 'redeem') GROUP BY `source`",
            [$paymentId]
        );
        foreach ($rows as $row) {
            $points = PointsMath::proportion(Row::scaled($row, 'p', 2), $part, $whole);
            if ($points === 0) {
                continue;
            }
            $dollars = Row::money($row, 'd');
            $dollarShare = $whole->cents > 0 ? new Money(intdiv($dollars->cents * min($part->cents, $whole->cents), $whole->cents)) : Money::zero();
            $this->write(
                $pid,
                $date,
                Row::str($row, 'source') === 'payment' ? 'Points taken back for refunded payment' : 'Points returned for refunded payment',
                -$points,
                new Money(-$dollarShare->cents),
                'reverse',
                $refundPaymentId,
                null,
                null,
                $userId,
            );
        }
    }

    /**
     * Monthly points from a membership plan.
     */
    public function addMembershipPoints(int $pid, int $points, string $planName, Settings $settings, \DateTimeImmutable $date): void
    {
        if ($points <= 0 || !$this->isEnrolled($pid)) {
            return;
        }
        $this->write($pid, $date, 'Membership points: ' . $planName, $points, PointsMath::valueOf($points, $settings->pointValue), 'membership', null, null, null, null);
    }

    private function write(
        int $pid,
        \DateTimeImmutable $date,
        string $description,
        int $points,
        Money $dollars,
        string $source,
        ?int $paymentId,
        ?int $invoiceId,
        ?string $reason,
        ?int $userId,
    ): void {
        $this->db->execute(
            'INSERT INTO `cpi_points_ledger` (`pid`, `txn_date`, `description`, `points`, `dollar_value`, `source`, `payment_id`, `invoice_id`, `reason`, `created_by`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $pid, $date->format('Y-m-d'), mb_substr($description, 0, 255), Decimal::format($points, 2), $dollars->toDecimalString(),
                $source, $paymentId, $invoiceId, $reason, $userId,
            ]
        );
    }
}
