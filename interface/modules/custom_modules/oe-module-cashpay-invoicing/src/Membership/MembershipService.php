<?php

/**
 * Patient memberships: enrolling, freezing, cancelling, autopay, and
 * billing each period (invoice, included package, monthly points).
 *
 * The bill dates are counted from the start date (period 1, 2, 3...) so a
 * membership that starts on the 31st keeps billing at month end.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Membership;

use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Billing\Document;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentKind;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentService;
use OpenEMR\Modules\CashpayInvoicing\Billing\HeaderInput;
use OpenEMR\Modules\CashpayInvoicing\Billing\InvoiceType;
use OpenEMR\Modules\CashpayInvoicing\Billing\LineDefaults;
use OpenEMR\Modules\CashpayInvoicing\Billing\LineService;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Rewards\RewardsService;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;

final readonly class MembershipService
{
    public function __construct(
        private Database $db,
        private DocumentService $documents,
        private LineService $lines,
        private RewardsService $rewards,
        private SettingsRepository $settings,
    ) {
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function plans(): array
    {
        return $this->db->fetchAll('SELECT * FROM `cpi_membership_plan` WHERE `active` = 1 ORDER BY `name`');
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function forPatient(int $pid): array
    {
        return $this->db->fetchAll(
            'SELECT m.*, p.`name` AS `plan_name`, p.`code`, p.`monthly_price`, p.`annual_price`, p.`auto_monthly_invoice`'
            . ' FROM `cpi_patient_membership` m JOIN `cpi_membership_plan` p ON p.`id` = m.`plan_id`'
            . " WHERE m.`pid` = ? ORDER BY FIELD(m.`status`, 'active', 'frozen', 'cancelled'), m.`start_date` DESC",
            [$pid]
        );
    }

    /**
     * Enrolls a patient and bills the first period now.
     *
     * @return array{membershipId: int, invoiceId: int|null}
     */
    public function enroll(int $pid, int $planId, BillingCycle $cycle, \DateTimeImmutable $start, bool $autopay, ?int $facilityId, int $userId): array
    {
        $plan = $this->plan($planId) ?? throw new ValidationException(['plan_id' => 'Pick a membership plan']);
        if (!Row::bool($plan, 'active')) {
            throw new ValidationException(['plan_id' => 'That plan is not active']);
        }
        $existing = $this->db->fetchOne(
            "SELECT `id` FROM `cpi_patient_membership` WHERE `pid` = ? AND `plan_id` = ? AND `status` IN ('active', 'frozen')",
            [$pid, $planId]
        );
        if ($existing !== null) {
            throw new ValidationException(['plan_id' => 'The patient already has this membership']);
        }
        return $this->db->transaction(function () use ($pid, $planId, $cycle, $start, $autopay, $facilityId, $userId): array {
            $id = $this->db->insert(
                'INSERT INTO `cpi_patient_membership` (`pid`, `plan_id`, `billing_cycle`, `start_date`, `status`, `autopay`, `next_bill_date`, `facility_id`, `created_by`)'
                . " VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?)",
                [$pid, $planId, $cycle->value, $start->format('Y-m-d'), $autopay ? 1 : 0, $start->format('Y-m-d'), $facilityId, $userId]
            );
            $invoice = $this->billPeriod($id, $facilityId, $userId, true);
            return ['membershipId' => $id, 'invoiceId' => $invoice?->id];
        });
    }

    public function setStatus(int $pid, int $membershipId, string $status, ?\DateTimeImmutable $endDate): void
    {
        if (!in_array($status, ['active', 'frozen', 'cancelled'], true)) {
            throw new \LogicException('Unknown membership status');
        }
        $row = $this->membership($pid, $membershipId) ?? throw new ValidationException(['membership' => 'Membership not found']);
        if (Row::str($row, 'status') === 'cancelled') {
            throw new ValidationException(['membership' => 'A cancelled membership cannot be changed. Enroll again instead.']);
        }
        $this->db->execute(
            'UPDATE `cpi_patient_membership` SET `status` = ?, `end_date` = ? WHERE `id` = ?',
            [$status, $status === 'cancelled' ? ($endDate ?? new \DateTimeImmutable('today'))->format('Y-m-d') : null, $membershipId]
        );
    }

    /**
     * Active memberships whose last autopay charge failed.
     *
     * @return list<array<string, scalar|null>>
     */
    public function autopayFailures(): array
    {
        return $this->db->fetchAll(
            'SELECT m.`id`, m.`pid`, m.`last_autopay_error`, p.`name` AS `plan_name`, TRIM(CONCAT(pd.`fname`, \' \', pd.`lname`)) AS `patient`'
            . ' FROM `cpi_patient_membership` m JOIN `cpi_membership_plan` p ON p.`id` = m.`plan_id` LEFT JOIN `patient_data` pd ON pd.`pid` = m.`pid`'
            . " WHERE m.`status` = 'active' AND m.`last_autopay_error` IS NOT NULL ORDER BY `patient`"
        );
    }

    public function recordAutopayResult(int $membershipId, ?string $error): void
    {
        $this->db->execute(
            'UPDATE `cpi_patient_membership` SET `last_autopay_error` = ? WHERE `id` = ?',
            [$error === null ? null : mb_substr($error, 0, 255), $membershipId]
        );
    }

    public function setAutopay(int $pid, int $membershipId, bool $autopay): void
    {
        $this->membership($pid, $membershipId) ?? throw new ValidationException(['membership' => 'Membership not found']);
        $this->db->execute('UPDATE `cpi_patient_membership` SET `autopay` = ? WHERE `id` = ?', [$autopay ? 1 : 0, $membershipId]);
    }

    /**
     * Memberships whose next bill date has come.
     *
     * @return list<array<string, scalar|null>>
     */
    public function due(\DateTimeImmutable $today): array
    {
        return $this->db->fetchAll(
            'SELECT m.*, p.`name` AS `plan_name`, p.`auto_monthly_invoice`, TRIM(CONCAT(pd.`fname`, \' \', pd.`lname`)) AS `patient`'
            . ' FROM `cpi_patient_membership` m JOIN `cpi_membership_plan` p ON p.`id` = m.`plan_id`'
            . ' LEFT JOIN `patient_data` pd ON pd.`pid` = m.`pid`'
            . " WHERE m.`status` = 'active' AND m.`next_bill_date` IS NOT NULL AND m.`next_bill_date` <= ?"
            . ' AND (m.`end_date` IS NULL OR m.`end_date` >= m.`next_bill_date`) ORDER BY m.`next_bill_date`, m.`id`',
            [$today->format('Y-m-d')]
        );
    }

    /**
     * Bills the period starting on the membership's next bill date: creates
     * the membership invoice (with the plan's monthly package when set),
     * adds the plan's monthly points, and moves the next bill date on.
     *
     * Plans without "create the monthly invoice automatically" are only
     * billed by staff ($manual = true); the job leaves them on the worklist.
     */
    public function billPeriod(int $membershipId, ?int $facilityId, int $userId, bool $manual): ?Document
    {
        $row = $this->db->fetchOne(
            'SELECT m.*, p.`name` AS `plan_name`, p.`monthly_price`, p.`annual_price`, p.`monthly_points`, p.`package_id`,'
            . ' p.`add_package_monthly`, p.`auto_monthly_invoice`, p.`tax1`, p.`tax2`'
            . ' FROM `cpi_patient_membership` m JOIN `cpi_membership_plan` p ON p.`id` = m.`plan_id` WHERE m.`id` = ?',
            [$membershipId]
        ) ?? throw new ValidationException(['membership' => 'Membership not found']);
        if (Row::str($row, 'status') !== 'active') {
            throw new ValidationException(['membership' => 'Only active memberships are billed']);
        }
        if (!$manual && !Row::bool($row, 'auto_monthly_invoice')) {
            return null;
        }
        $billDate = Row::date($row, 'next_bill_date') ?? throw new ValidationException(['membership' => 'No bill date set']);
        $start = Row::date($row, 'start_date') ?? $billDate;
        $cycle = BillingCycle::tryFrom(Row::str($row, 'billing_cycle')) ?? BillingCycle::Monthly;
        $pid = Row::int($row, 'pid');
        $planName = Row::str($row, 'plan_name');
        $price = $cycle === BillingCycle::Annual ? Row::money($row, 'annual_price') : Row::money($row, 'monthly_price');

        return $this->db->transaction(function () use ($row, $membershipId, $facilityId, $userId, $billDate, $start, $cycle, $pid, $planName, $price): Document {
            $invoice = $this->documents->create(DocumentKind::Invoice, $pid, new HeaderInput(
                name: mb_substr($planName . ' membership ' . $billDate->format('M Y'), 0, 255),
                date: $billDate,
                invoiceType: InvoiceType::Membership,
                facilityId: $facilityId ?? Row::intOrNull($row, 'facility_id'),
                providerId: null,
                sellerId: Row::intOrNull($row, 'created_by'),
                comments: '',
                deposit: Money::zero(),
            ), $userId);
            $this->db->execute(
                "UPDATE `cpi_invoice` SET `membership_id` = ?, `source` = ? WHERE `id` = ?",
                [$membershipId, $userId === 0 ? 'membership_job' : 'manual', $invoice->id]
            );
            $this->lines->addMembershipFee($invoice, Row::int($row, 'plan_id'), $planName . ' membership (' . strtolower($cycle->label()) . ')', $price, Row::bool($row, 'tax1'), Row::bool($row, 'tax2'), LineDefaults::of($invoice));
            $packageId = Row::intOrNull($row, 'package_id');
            if ($packageId !== null && Row::bool($row, 'add_package_monthly')) {
                $this->lines->addPackage($invoice, $packageId, LineDefaults::of($invoice), $userId);
            }
            $points = Row::scaled($row, 'monthly_points', 2);
            if ($points > 0) {
                $this->rewards->addMembershipPoints($pid, $points, $planName, $this->settings->load(), $billDate);
            }
            $periods = $this->periodsBilled($membershipId) ;
            $this->db->execute(
                'UPDATE `cpi_patient_membership` SET `next_bill_date` = ? WHERE `id` = ?',
                [$cycle->periodStart($start, $periods)->format('Y-m-d'), $membershipId]
            );
            return $invoice;
        });
    }

    /**
     * @return array<string, scalar|null>|null
     */
    private function plan(int $planId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `cpi_membership_plan` WHERE `id` = ?', [$planId]);
    }

    /**
     * @return array<string, scalar|null>|null
     */
    private function membership(int $pid, int $membershipId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `cpi_patient_membership` WHERE `id` = ? AND `pid` = ?', [$membershipId, $pid]);
    }

    private function periodsBilled(int $membershipId): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS `n` FROM `cpi_invoice` WHERE `membership_id` = ? AND `voided` = 0', [$membershipId]);
        return $row === null ? 1 : max(1, Row::int($row, 'n'));
    }

    /**
     * Price text for lists, e.g. "$149.00 / month".
     *
     * @param array<string, scalar|null> $row
     */
    public static function priceText(array $row): string
    {
        $cycle = BillingCycle::tryFrom(Row::str($row, 'billing_cycle')) ?? BillingCycle::Monthly;
        return $cycle === BillingCycle::Annual
            ? Row::money($row, 'annual_price')->format() . ' / year'
            : Row::money($row, 'monthly_price')->format() . ' / month';
    }

    /** Points text for lists. */
    public static function pointsText(int $hundredths): string
    {
        return Decimal::display(Decimal::format($hundredths, 2));
    }
}
