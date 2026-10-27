<?php

/**
 * Posts payments, deposits, refunds and write-offs and keeps invoice
 * allocations, award points and gift card balances in step.
 *
 * - A payment can be spread across open invoices; any part not applied is
 *   patient credit and can be applied later.
 * - Card payments carry the credit card service charge when the card is a
 *   credit card (never debit or prepaid). The charge is not applied to invoices.
 * - Points are earned on the eligible share of what is applied to invoices;
 *   payments made with points or gift cards earn nothing.
 * - Refunds come out of unapplied credit first, then the newest invoices;
 *   they refund the card charge and take back points in proportion.
 * - Nothing is ever deleted: a mistaken entry is voided.
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
use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Rewards\GiftCardService;
use OpenEMR\Modules\CashpayInvoicing\Rewards\PointsMath;
use OpenEMR\Modules\CashpayInvoicing\Rewards\RewardsService;
use OpenEMR\Modules\CashpayInvoicing\Settings\CardFunding;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;

final readonly class PaymentService
{
    public function __construct(
        private Database $db,
        private SettingsRepository $settings,
        private RewardsService $rewards,
        private GiftCardService $giftCards,
    ) {
    }

    public function method(int $id): ?PaymentMethod
    {
        $row = $this->db->fetchOne('SELECT * FROM `cpi_payment_method` WHERE `id` = ?', [$id]);
        return $row === null ? null : PaymentMethod::fromRow($row);
    }

    /**
     * @return list<PaymentMethod>
     */
    public function methods(bool $refund): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT * FROM `cpi_payment_method` WHERE `active` = 1 AND `is_refund_method` = ? ORDER BY `sort_order`, `name`', [$refund ? 1 : 0]) as $row) {
            $out[] = PaymentMethod::fromRow($row);
        }
        return $out;
    }

    /**
     * @return int the new payment id
     */
    public function apply(int $pid, PaymentInput $input, int $userId): int
    {
        if (!$input->payType->isMoneyIn()) {
            throw new \LogicException('Use refund() or writeOff()');
        }
        $method = $this->method($input->methodId);
        if ($method === null || !$method->active || $method->isRefundMethod) {
            throw new ValidationException(['method_id' => 'Pick a payment method']);
        }
        if ($input->amount->cents <= 0) {
            throw new ValidationException(['amount' => 'Enter an amount more than zero']);
        }
        if ($input->gratuity->isNegative()) {
            throw new ValidationException(['gratuity' => 'Gratuity cannot be negative']);
        }
        $this->checkAllocations($pid, $input->allocations, $input->amount);

        $settings = $this->settings->load();
        $fee = Money::zero();
        $funding = null;
        if ($method->kind === MethodKind::Card) {
            $funding = $input->funding ?? throw new ValidationException(['funding' => 'Say whether the card is credit, debit or prepaid']);
            $fee = $settings->cardFeeOn($input->amount->add($input->gratuity), $funding);
        }

        return $this->db->transaction(function () use ($pid, $input, $method, $settings, $fee, $funding, $userId): int {
            $pointsUsed = 0;
            $cardId = null;
            if ($method->kind === MethodKind::Points) {
                $pointsUsed = $this->rewards->redeem($pid, $input->amount, $settings, $input->date, $userId);
            } elseif ($method->kind === MethodKind::GiftCard) {
                $cardId = $this->giftCards->redeem($input->giftCardCode, $input->amount, $pid, $input->date, $userId);
            }
            $paymentId = $this->db->insert(
                'INSERT INTO `cpi_payment` (`pid`, `pay_date`, `pay_type`, `method_id`, `amount`, `gratuity`, `card_fee_pct`, `card_fee_amt`,'
                . ' `processor`, `card_funding`, `reference`, `comments`, `status`, `source`, `facility_id`, `created_by`)'
                . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'none', ?, ?, ?, 'posted', 'manual', ?, ?)",
                [
                    $pid, $input->date->format('Y-m-d'), $input->payType->value, $method->id, $input->amount->toDecimalString(),
                    $input->gratuity->toDecimalString(),
                    Decimal::format($fee->cents > 0 ? $settings->cardFeeBasisPoints * 100 : 0, 4),
                    $fee->toDecimalString(), $funding?->value, mb_substr($input->reference, 0, 100) ?: null,
                    mb_substr($input->comments, 0, 255) ?: null, $input->facilityId, $userId,
                ]
            );
            if ($pointsUsed > 0) {
                $this->rewards->recordRedeem($pid, $paymentId, $pointsUsed, $input->amount, $input->date, $userId);
            }
            if ($cardId !== null) {
                $this->giftCards->linkPayment($cardId, $paymentId);
            }
            foreach ($input->allocations as $invoiceId => $amount) {
                $this->allocate($paymentId, $invoiceId, $amount);
                if ($method->kind->earnsPoints()) {
                    $this->rewards->earn($pid, $paymentId, $invoiceId, $this->eligiblePaid($invoiceId, $amount), $settings, $input->date, $userId);
                }
                $this->afterAllocation($invoiceId, $pid, $input->date, $userId);
            }
            return $paymentId;
        });
    }

    /**
     * Applies unapplied patient credit to an invoice, oldest credit first.
     */
    public function applyCredit(int $pid, int $invoiceId, Money $amount, int $userId, \DateTimeImmutable $date): void
    {
        if ($amount->cents <= 0) {
            throw new ValidationException(['amount' => 'Enter an amount more than zero']);
        }
        $this->checkAllocations($pid, [$invoiceId => $amount], $this->creditBalance($pid));
        $settings = $this->settings->load();
        $this->db->transaction(function () use ($pid, $invoiceId, $amount, $userId, $date, $settings): void {
            $left = $amount;
            foreach ($this->unappliedPayments($pid) as [$paymentId, $available, $kind]) {
                if ($left->cents <= 0) {
                    break;
                }
                $take = $available->cents < $left->cents ? $available : $left;
                $this->allocate($paymentId, $invoiceId, $take);
                if ($kind->earnsPoints()) {
                    $this->rewards->earn($pid, $paymentId, $invoiceId, $this->eligiblePaid($invoiceId, $take), $settings, $date, $userId);
                }
                $left = $left->subtract($take);
            }
            $this->afterAllocation($invoiceId, $pid, $date, $userId);
        });
    }

    /**
     * @return int the refund entry id
     */
    public function refund(int $pid, int $paymentId, Money $amount, int $methodId, string $reason, \DateTimeImmutable $date, int $userId): int
    {
        $original = $this->db->fetchOne(
            "SELECT p.*, m.`kind` FROM `cpi_payment` p LEFT JOIN `cpi_payment_method` m ON m.`id` = p.`method_id`"
            . " WHERE p.`id` = ? AND p.`pid` = ? AND p.`status` = 'posted' AND p.`pay_type` IN ('payment', 'deposit')",
            [$paymentId, $pid]
        ) ?? throw new ValidationException(['payment' => 'Only posted payments can be refunded']);
        $method = $this->method($methodId);
        if ($method === null || !$method->active) {
            throw new ValidationException(['method_id' => 'Pick how the money goes back']);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'Say why the payment is refunded']);
        }
        $paid = Row::money($original, 'amount');
        $refundable = $paid->subtract($this->refunded($paymentId));
        if ($amount->cents <= 0 || $amount->cents > $refundable->cents) {
            throw new ValidationException(['amount' => 'Enter up to ' . $refundable->format()]);
        }
        $originalFee = Row::money($original, 'card_fee_amt');
        $feeBack = $paid->cents > 0 ? new Money(intdiv($originalFee->cents * $amount->cents + intdiv($paid->cents, 2), $paid->cents)) : Money::zero();
        $kind = MethodKind::tryFrom(Row::str($original, 'kind')) ?? MethodKind::Other;

        return $this->db->transaction(function () use ($pid, $paymentId, $amount, $method, $reason, $date, $userId, $paid, $feeBack, $kind, $original): int {
            $this->unallocate($paymentId, $amount);
            $refundId = $this->db->insert(
                'INSERT INTO `cpi_payment` (`pid`, `pay_date`, `pay_type`, `method_id`, `amount`, `card_fee_amt`, `refund_of_payment_id`, `processor`,'
                . " `comments`, `status`, `source`, `facility_id`, `created_by`) VALUES (?, ?, 'refund', ?, ?, ?, ?, 'none', ?, 'posted', 'manual', ?, ?)",
                [
                    $pid, $date->format('Y-m-d'), $method->id, $amount->toDecimalString(), $feeBack->toDecimalString(), $paymentId,
                    mb_substr(trim($reason), 0, 255), Row::intOrNull($original, 'facility_id'), $userId,
                ]
            );
            $this->rewards->reverseForPayment($pid, $paymentId, $amount, $paid, $date, $userId, $refundId);
            if ($kind === MethodKind::GiftCard) {
                $this->giftCards->restoreForPayment($paymentId, $amount, $pid, $date, $userId);
            }
            return $refundId;
        });
    }

    public function writeOff(int $pid, int $invoiceId, Money $amount, string $reason, \DateTimeImmutable $date, int $userId): int
    {
        if ($amount->cents <= 0) {
            throw new ValidationException(['amount' => 'Enter an amount more than zero']);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'Say why the balance is written off']);
        }
        $this->checkAllocations($pid, [$invoiceId => $amount], $amount);
        return $this->db->transaction(function () use ($pid, $invoiceId, $amount, $reason, $date, $userId): int {
            $id = $this->db->insert(
                'INSERT INTO `cpi_payment` (`pid`, `pay_date`, `pay_type`, `amount`, `processor`, `comments`, `status`, `source`, `created_by`)'
                . " VALUES (?, ?, 'write_off', ?, 'none', ?, 'posted', 'manual', ?)",
                [$pid, $date->format('Y-m-d'), $amount->toDecimalString(), mb_substr(trim($reason), 0, 255), $userId]
            );
            $this->allocate($id, $invoiceId, $amount);
            return $id;
        });
    }

    /**
     * Voids a payment, deposit or write-off entered by mistake. Its
     * allocations stop counting, points it earned or spent are reversed and
     * gift card value goes back. Refunds cannot be voided.
     */
    public function void(int $pid, int $paymentId, string $reason, \DateTimeImmutable $date, int $userId): void
    {
        $row = $this->db->fetchOne(
            "SELECT p.*, m.`kind` FROM `cpi_payment` p LEFT JOIN `cpi_payment_method` m ON m.`id` = p.`method_id`"
            . " WHERE p.`id` = ? AND p.`pid` = ? AND p.`status` = 'posted'",
            [$paymentId, $pid]
        ) ?? throw new ValidationException(['payment' => 'Only posted entries can be voided']);
        if (Row::str($row, 'pay_type') === PayType::Refund->value) {
            throw new ValidationException(['payment' => 'Refunds cannot be voided. Record a new payment instead.']);
        }
        if ($this->refunded($paymentId)->cents > 0) {
            throw new ValidationException(['payment' => 'This payment has refunds. It cannot be voided.']);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'Say why the entry is voided']);
        }
        $amount = Row::money($row, 'amount');
        $kind = MethodKind::tryFrom(Row::str($row, 'kind')) ?? MethodKind::Other;
        $this->db->transaction(function () use ($pid, $paymentId, $reason, $date, $userId, $amount, $kind, $row): void {
            $this->db->execute(
                "UPDATE `cpi_payment` SET `status` = 'void', `comments` = ? WHERE `id` = ?",
                [mb_substr(trim(Row::str($row, 'comments') . ' [Void: ' . trim($reason) . ']'), 0, 255), $paymentId]
            );
            $this->rewards->reverseForPayment($pid, $paymentId, $amount, $amount, $date, $userId, $paymentId);
            if ($kind === MethodKind::GiftCard) {
                $this->giftCards->restoreForPayment($paymentId, $amount, $pid, $date, $userId);
            }
        });
    }

    /**
     * Ledger rows, newest first, with the invoices each payment went to.
     *
     * @return list<array<string, mixed>>
     */
    public function ledger(int $pid): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.*, m.`name` AS `method_name`, m.`kind`, u.`username`, f.`name` AS `facility_name`'
            . ' FROM `cpi_payment` p LEFT JOIN `cpi_payment_method` m ON m.`id` = p.`method_id`'
            . ' LEFT JOIN `users` u ON u.`id` = p.`created_by` LEFT JOIN `facility` f ON f.`id` = p.`facility_id`'
            . ' WHERE p.`pid` = ? ORDER BY p.`pay_date` DESC, p.`id` DESC',
            [$pid]
        );
        $allocs = [];
        foreach (
            $this->db->fetchAll(
                'SELECT a.* FROM `cpi_payment_alloc` a JOIN `cpi_payment` p ON p.`id` = a.`payment_id` WHERE p.`pid` = ? ORDER BY a.`invoice_id`',
                [$pid]
            ) as $alloc
        ) {
            $allocs[Row::int($alloc, 'payment_id')][] = ['invoice_id' => Row::int($alloc, 'invoice_id'), 'amount' => Row::money($alloc, 'amount')->format()];
        }
        $refunded = [];
        foreach ($rows as $row) {
            $of = Row::intOrNull($row, 'refund_of_payment_id');
            if ($of !== null && Row::str($row, 'status') === 'posted') {
                $refunded[$of] = ($refunded[$of] ?? Money::zero())->add(Row::money($row, 'amount'));
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $id = Row::int($row, 'id');
            $type = PayType::tryFrom(Row::str($row, 'pay_type')) ?? PayType::Payment;
            $amount = Row::money($row, 'amount');
            $applied = Money::zero();
            foreach ($this->db->fetchAll('SELECT `amount` FROM `cpi_payment_alloc` WHERE `payment_id` = ?', [$id]) as $a) {
                $applied = $applied->add(Row::money($a, 'amount'));
            }
            $refundable = $type->isMoneyIn() ? $amount->subtract($refunded[$id] ?? Money::zero()) : Money::zero();
            $out[] = [
                'id' => $id,
                'date' => Row::str($row, 'pay_date'),
                'type' => $type->label(),
                'typeValue' => $type->value,
                'method' => Row::str($row, 'method_name'),
                'amount' => $amount->format(),
                'gratuity' => Row::money($row, 'gratuity')->cents > 0 ? Row::money($row, 'gratuity')->format() : '',
                'cardFee' => Row::money($row, 'card_fee_amt')->cents > 0 ? Row::money($row, 'card_fee_amt')->format() : '',
                'funding' => Row::str($row, 'card_funding'),
                'reference' => Row::str($row, 'reference'),
                'comments' => Row::str($row, 'comments'),
                'status' => Row::str($row, 'status'),
                'allocations' => $allocs[$id] ?? [],
                'unapplied' => $type->isMoneyIn() && Row::str($row, 'status') === 'posted'
                    ? $amount->subtract($applied)->subtract($refunded[$id] ?? Money::zero())->format() : '',
                'refundable' => $refundable->cents > 0 && Row::str($row, 'status') === 'posted' ? $refundable->toDecimalString() : '',
                'refundOf' => Row::intOrNull($row, 'refund_of_payment_id'),
                'user' => Row::str($row, 'username'),
                'facility' => Row::str($row, 'facility_name'),
            ];
        }
        return $out;
    }

    /**
     * @return array{balance: Money, credit: Money, lifetime: Money, since: string}
     */
    public function totals(int $pid): array
    {
        $balance = Money::zero();
        foreach ($this->openInvoices($pid) as $invoice) {
            $balance = $balance->add($invoice['due']);
        }
        $life = $this->db->fetchOne(
            "SELECT COALESCE(SUM(CASE WHEN `pay_type` IN ('payment', 'deposit') THEN `amount` WHEN `pay_type` = 'refund' THEN -`amount` ELSE 0 END), 0) AS `life`,"
            . " MIN(`pay_date`) AS `first_pay` FROM `cpi_payment` WHERE `pid` = ? AND `status` = 'posted'",
            [$pid]
        );
        $firstInvoice = $this->db->fetchOne('SELECT MIN(`invoice_date`) AS `d` FROM `cpi_invoice` WHERE `pid` = ?', [$pid]);
        $dates = array_filter([$life !== null ? Row::str($life, 'first_pay') : '', $firstInvoice !== null ? Row::str($firstInvoice, 'd') : '']);
        return [
            'balance' => $balance,
            'credit' => $this->creditBalance($pid),
            'lifetime' => $life !== null ? Row::money($life, 'life') : Money::zero(),
            'since' => $dates === [] ? '' : min($dates),
        ];
    }

    /**
     * Unvoided invoices with something still owed.
     *
     * @return list<array{id: int, date: string, name: string, total: Money, paid: Money, due: Money}>
     */
    public function openInvoices(int $pid): array
    {
        $rows = $this->db->fetchAll(
            'SELECT i.`id`, i.`invoice_date`, i.`name`,'
            . " (SELECT COALESCE(SUM(l.`net` + l.`tax1_amt` + l.`tax2_amt`), 0) FROM `cpi_line` l WHERE l.`parent_type` = 'invoice' AND l.`parent_id` = i.`id`) AS `total`,"
            . " (SELECT COALESCE(SUM(a.`amount`), 0) FROM `cpi_payment_alloc` a JOIN `cpi_payment` p ON p.`id` = a.`payment_id` AND p.`status` = 'posted' WHERE a.`invoice_id` = i.`id`) AS `paid`"
            . ' FROM `cpi_invoice` i WHERE i.`pid` = ? AND i.`voided` = 0 ORDER BY i.`invoice_date`, i.`id`',
            [$pid]
        );
        $out = [];
        foreach ($rows as $row) {
            $total = Row::money($row, 'total');
            $paid = Row::money($row, 'paid');
            $due = $total->subtract($paid);
            if ($due->cents > 0) {
                $out[] = ['id' => Row::int($row, 'id'), 'date' => Row::str($row, 'invoice_date'), 'name' => Row::str($row, 'name'), 'total' => $total, 'paid' => $paid, 'due' => $due];
            }
        }
        return $out;
    }

    public function creditBalance(int $pid): Money
    {
        $credit = Money::zero();
        foreach ($this->unappliedPayments($pid) as [, $available]) {
            $credit = $credit->add($available);
        }
        return $credit;
    }

    /**
     * @param array<int, Money> $allocations
     */
    private function checkAllocations(int $pid, array $allocations, Money $limit): void
    {
        $sum = Money::zero();
        $open = [];
        foreach ($this->openInvoices($pid) as $invoice) {
            $open[$invoice['id']] = $invoice['due'];
        }
        foreach ($allocations as $invoiceId => $amount) {
            if ($amount->cents <= 0) {
                throw new ValidationException(['alloc_' . $invoiceId => 'Amounts applied must be more than zero']);
            }
            $due = $open[$invoiceId] ?? throw new ValidationException(['alloc_' . $invoiceId => 'Invoice #' . $invoiceId . ' has nothing due']);
            if ($amount->cents > $due->cents) {
                throw new ValidationException(['alloc_' . $invoiceId => 'Invoice #' . $invoiceId . ' only has ' . $due->format() . ' due']);
            }
            $sum = $sum->add($amount);
        }
        if ($sum->cents > $limit->cents) {
            throw new ValidationException(['amount' => 'More is applied to invoices (' . $sum->format() . ') than is available (' . $limit->format() . ')']);
        }
    }

    private function allocate(int $paymentId, int $invoiceId, Money $amount): void
    {
        $this->db->execute(
            'INSERT INTO `cpi_payment_alloc` (`payment_id`, `invoice_id`, `amount`) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `amount` = `amount` + VALUES(`amount`)',
            [$paymentId, $invoiceId, $amount->toDecimalString()]
        );
    }

    /**
     * Takes a refund out of a payment: unapplied money first, then the
     * newest invoice allocations.
     */
    private function unallocate(int $paymentId, Money $amount): void
    {
        $payment = $this->db->fetchOne('SELECT `amount` FROM `cpi_payment` WHERE `id` = ?', [$paymentId]);
        $paid = $payment === null ? Money::zero() : Row::money($payment, 'amount');
        $allocs = $this->db->fetchAll('SELECT `invoice_id`, `amount` FROM `cpi_payment_alloc` WHERE `payment_id` = ? ORDER BY `invoice_id` DESC', [$paymentId]);
        $applied = Money::zero();
        foreach ($allocs as $alloc) {
            $applied = $applied->add(Row::money($alloc, 'amount'));
        }
        $unapplied = $paid->subtract($applied)->subtract($this->refunded($paymentId));
        $left = $amount->subtract($unapplied->cents > 0 ? $unapplied : Money::zero());
        foreach ($allocs as $alloc) {
            if ($left->cents <= 0) {
                return;
            }
            $current = Row::money($alloc, 'amount');
            $take = $current->cents < $left->cents ? $current : $left;
            $remaining = $current->subtract($take);
            if ($remaining->cents === 0) {
                $this->db->execute('DELETE FROM `cpi_payment_alloc` WHERE `payment_id` = ? AND `invoice_id` = ?', [$paymentId, Row::int($alloc, 'invoice_id')]);
            } else {
                $this->db->execute(
                    'UPDATE `cpi_payment_alloc` SET `amount` = ? WHERE `payment_id` = ? AND `invoice_id` = ?',
                    [$remaining->toDecimalString(), $paymentId, Row::int($alloc, 'invoice_id')]
                );
            }
            $left = $left->subtract($take);
        }
    }

    private function refunded(int $paymentId): Money
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(`amount`), 0) AS `r` FROM `cpi_payment` WHERE `refund_of_payment_id` = ? AND `pay_type` = 'refund' AND `status` = 'posted'",
            [$paymentId]
        );
        return $row === null ? Money::zero() : Row::money($row, 'r');
    }

    /**
     * Posted payments and deposits with money not yet applied, oldest first.
     *
     * @return list<array{int, Money, MethodKind}>
     */
    private function unappliedPayments(int $pid): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.`id`, p.`amount`, m.`kind`,'
            . ' (SELECT COALESCE(SUM(a.`amount`), 0) FROM `cpi_payment_alloc` a WHERE a.`payment_id` = p.`id`) AS `applied`,'
            . " (SELECT COALESCE(SUM(r.`amount`), 0) FROM `cpi_payment` r WHERE r.`refund_of_payment_id` = p.`id` AND r.`pay_type` = 'refund' AND r.`status` = 'posted') AS `refunded`"
            . ' FROM `cpi_payment` p LEFT JOIN `cpi_payment_method` m ON m.`id` = p.`method_id`'
            . " WHERE p.`pid` = ? AND p.`status` = 'posted' AND p.`pay_type` IN ('payment', 'deposit') ORDER BY p.`pay_date`, p.`id`",
            [$pid]
        );
        $out = [];
        foreach ($rows as $row) {
            $available = Row::money($row, 'amount')->subtract(Row::money($row, 'applied'))->subtract(Row::money($row, 'refunded'));
            if ($available->cents > 0) {
                $out[] = [Row::int($row, 'id'), $available, MethodKind::tryFrom(Row::str($row, 'kind')) ?? MethodKind::Other];
            }
        }
        return $out;
    }

    private function eligiblePaid(int $invoiceId, Money $allocated): Money
    {
        $row = $this->db->fetchOne(
            'SELECT COALESCE(SUM(CASE WHEN `award_points_eligible` = 1 THEN `net` ELSE 0 END), 0) AS `eligible`,'
            . " COALESCE(SUM(`net` + `tax1_amt` + `tax2_amt`), 0) AS `total` FROM `cpi_line` WHERE `parent_type` = 'invoice' AND `parent_id` = ?",
            [$invoiceId]
        );
        return $row === null ? Money::zero() : PointsMath::eligiblePaid($allocated, Row::money($row, 'eligible'), Row::money($row, 'total'));
    }

    /** Gift cards are issued once their invoice is fully paid. */
    private function afterAllocation(int $invoiceId, int $pid, \DateTimeImmutable $date, int $userId): void
    {
        $invoice = $this->db->fetchOne("SELECT `invoice_type` FROM `cpi_invoice` WHERE `id` = ?", [$invoiceId]);
        if ($invoice === null || Row::str($invoice, 'invoice_type') !== 'gift_card') {
            return;
        }
        foreach ($this->openInvoices($pid) as $open) {
            if ($open['id'] === $invoiceId) {
                return;
            }
        }
        $this->giftCards->issueForPaidInvoice($invoiceId, $pid, $date, $userId);
    }

    /** The card funding to record for a manual card payment. */
    public static function funding(string $value): ?CardFunding
    {
        return CardFunding::tryFrom($value);
    }
}
