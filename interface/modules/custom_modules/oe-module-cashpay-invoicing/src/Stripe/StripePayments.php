<?php

/**
 * Card payments through Stripe: pay links, card-on-file links, the
 * in-office reader flow, membership autopay, refunds, and posting what
 * Stripe confirms.
 *
 * The credit card service charge is added only when the card is known to be
 * a credit card: on the reader (after the card is read, before it is
 * charged) and on saved cards. Pay links are charged without it, because
 * Checkout cannot tell the card type before the patient pays.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Stripe;

use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Billing\Document;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use OpenEMR\Modules\CashpayInvoicing\Membership\AutopayCharger;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Patient\PatientInfo;
use OpenEMR\Modules\CashpayInvoicing\Payments\PaymentService;
use OpenEMR\Modules\CashpayInvoicing\Payments\ProcessorPayment;
use OpenEMR\Modules\CashpayInvoicing\Payments\ProcessorRefunder;
use OpenEMR\Modules\CashpayInvoicing\Settings\CardFunding;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;
use Psr\Clock\ClockInterface;

final class StripePayments implements AutopayCharger, ProcessorRefunder
{
    private ?PaymentService $payments = null;

    public function __construct(
        private readonly Database $db,
        private readonly StripeAccountRepository $accounts,
        private readonly StripeGateway $gateway,
        private readonly SettingsRepository $settings,
        private readonly ClockInterface $clock,
        private readonly string $returnUrl,
    ) {
    }

    /** PaymentService needs this object as its refunder, so it is set after both exist. */
    public function setPaymentService(PaymentService $payments): void
    {
        $this->payments = $payments;
    }

    public function enabled(?int $facilityId = null): bool
    {
        return $this->accounts->forFacility($facilityId) !== null;
    }

    /**
     * Creates a Stripe Checkout link for an amount, applied to the given
     * invoices when paid.
     *
     * @param list<int> $invoiceIds
     * @return array{url: string, requestId: int}
     */
    public function requestPayment(int $pid, Money $amount, array $invoiceIds, ?int $facilityId, int $userId): array
    {
        if ($amount->cents < 50) {
            throw new ValidationException(['amount' => 'Stripe needs at least $0.50']);
        }
        $account = $this->account($facilityId);
        $customerId = $this->customerId($account, $pid);
        $metadata = $this->metadata($pid, $facilityId, $this->allocationsFor($pid, $invoiceIds, $amount), $amount, Money::zero());
        $description = $invoiceIds === [] ? 'Payment on account' : 'Invoice #' . implode(', #', $invoiceIds);
        $session = $this->gateway->checkoutForPayment($account, $customerId, $amount->cents, $description, $metadata, $this->returnUrl . '?paid=1', $this->returnUrl . '?cancelled=1');
        $requestId = $this->db->insert(
            'INSERT INTO `cpi_pay_request` (`pid`, `purpose`, `account_key`, `checkout_session_id`, `url`, `amount`, `invoice_ids`, `facility_id`, `created_by`)'
            . " VALUES (?, 'payment', ?, ?, ?, ?, ?, ?, ?)",
            [$pid, $account->key, $session['id'], $session['url'], $amount->toDecimalString(), implode(',', $invoiceIds) ?: null, $facilityId, $userId]
        );
        return ['url' => $session['url'], 'requestId' => $requestId];
    }

    /**
     * A link for the patient to save a card for autopay.
     *
     * @return array{url: string, requestId: int}
     */
    public function requestCardOnFile(int $pid, ?int $facilityId, int $userId): array
    {
        $account = $this->account($facilityId);
        $customerId = $this->customerId($account, $pid);
        $session = $this->gateway->checkoutForSetup($account, $customerId, ['cpi_pid' => (string) $pid, 'cpi_purpose' => 'card_on_file'], $this->returnUrl . '?saved=1', $this->returnUrl . '?cancelled=1');
        $requestId = $this->db->insert(
            'INSERT INTO `cpi_pay_request` (`pid`, `purpose`, `account_key`, `checkout_session_id`, `url`, `facility_id`, `created_by`)'
            . " VALUES (?, 'setup', ?, ?, ?, ?, ?)",
            [$pid, $account->key, $session['id'], $session['url'], $facilityId, $userId]
        );
        return ['url' => $session['url'], 'requestId' => $requestId];
    }

    public function markSent(int $requestId, string $to): void
    {
        $this->db->execute('UPDATE `cpi_pay_request` SET `sent_to` = ? WHERE `id` = ?', [mb_substr($to, 0, 150), $requestId]);
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function requests(int $pid): array
    {
        return $this->db->fetchAll('SELECT * FROM `cpi_pay_request` WHERE `pid` = ? ORDER BY `id` DESC LIMIT 20', [$pid]);
    }

    /**
     * @return list<array{id: string, label: string, status: string}>
     */
    public function readers(?int $facilityId): array
    {
        $account = $this->accounts->forFacility($facilityId);
        return $account === null ? [] : $this->gateway->readers($account);
    }

    /**
     * Starts a reader payment: creates the PaymentIntent and asks the reader
     * to read the card. The charge is set once the card type is known.
     *
     * @param array<int, Money> $allocations
     */
    public function startReaderPayment(int $pid, string $readerId, Money $amount, array $allocations, ?int $facilityId): string
    {
        if ($amount->cents < 50) {
            throw new ValidationException(['amount' => 'Stripe needs at least $0.50']);
        }
        $account = $this->account($facilityId);
        $metadata = $this->metadata($pid, $facilityId, $allocations, $amount, Money::zero()) + ['cpi_reader' => $readerId];
        $description = $allocations === [] ? 'Payment on account' : 'Invoice #' . implode(', #', array_keys($allocations));
        $intentId = $this->gateway->createPresentIntent($account, $amount->cents, $description, $metadata);
        $this->gateway->collectOnReader($account, $readerId, $intentId);
        return $intentId;
    }

    /**
     * Moves a reader payment along. Once the card is read, adds the card
     * charge for credit cards and confirms; once confirmed, posts it.
     *
     * @return array{state: string, message: string, fee: string, total: string, paymentId: int|null}
     */
    public function readerStatus(int $pid, ?int $facilityId, string $intentId): array
    {
        $account = $this->account($facilityId);
        $intent = $this->gateway->intent($account, $intentId);
        if (($intent->metadata['cpi_pid'] ?? '') !== (string) $pid) {
            throw new ValidationException(['payment' => 'That payment belongs to another patient']);
        }
        $base = new Money((int) ($intent->metadata['cpi_base_cents'] ?? $intent->amountCents));
        if ($intent->succeeded()) {
            $paymentId = $this->post($account, $intent, 'manual');
            return ['state' => 'succeeded', 'message' => 'Paid', 'fee' => '', 'total' => (new Money($intent->amountCents))->format(), 'paymentId' => $paymentId];
        }
        if ($intent->status === 'requires_confirmation' && ($intent->metadata['cpi_confirmed'] ?? '') !== '1') {
            $funding = CardFunding::tryFrom($intent->funding) ?? CardFunding::Unknown;
            $fee = $this->settings->load()->cardFeeOn($base, $funding);
            $metadata = $intent->metadata;
            $metadata['cpi_fee_cents'] = (string) $fee->cents;
            // Marked first so a second poll never confirms twice.
            $metadata['cpi_confirmed'] = '1';
            $this->gateway->updateIntent($account, $intentId, $base->cents + $fee->cents, $metadata);
            $this->gateway->confirmOnReader($account, $metadata['cpi_reader'] ?? '', $intentId);
            return ['state' => 'processing', 'message' => 'Charging the card', 'fee' => $fee->cents > 0 ? $fee->format() : '', 'total' => $base->add($fee)->format(), 'paymentId' => null];
        }
        if ($intent->status === 'requires_confirmation' || $intent->status === 'processing') {
            $fee = new Money((int) ($intent->metadata['cpi_fee_cents'] ?? 0));
            return ['state' => 'processing', 'message' => 'Charging the card', 'fee' => $fee->cents > 0 ? $fee->format() : '', 'total' => (new Money($intent->amountCents))->format(), 'paymentId' => null];
        }
        if ($intent->status === 'canceled') {
            return ['state' => 'canceled', 'message' => 'Cancelled', 'fee' => '', 'total' => '', 'paymentId' => null];
        }
        if ($intent->errorMessage !== '') {
            return ['state' => 'failed', 'message' => $intent->errorMessage, 'fee' => '', 'total' => '', 'paymentId' => null];
        }
        return ['state' => 'waiting', 'message' => 'Waiting for the card on the reader', 'fee' => '', 'total' => '', 'paymentId' => null];
    }

    public function cancelReader(?int $facilityId, string $readerId): void
    {
        $this->gateway->cancelReader($this->account($facilityId), $readerId);
    }

    /**
     * Membership autopay: charges the invoice balance to the saved card.
     */
    public function chargeInvoice(Document $invoice): ?string
    {
        $payments = $this->payments ?? throw new \LogicException('Payment service not set');
        $due = Money::zero();
        foreach ($payments->openInvoices($invoice->pid) as $open) {
            if ($open['id'] === $invoice->id) {
                $due = $open['due'];
            }
        }
        if ($due->cents <= 0) {
            return null;
        }
        $account = $this->accounts->forFacility($invoice->facilityId);
        if ($account === null) {
            return 'Stripe is not set up';
        }
        $card = $this->savedCard($account, $invoice->pid);
        if ($card === null) {
            $this->flagNeedsCard($invoice->pid, $account->key);
            return 'No card on file';
        }
        $funding = CardFunding::tryFrom($this->gateway->cardOf($account, $card['paymentMethod'])['funding']) ?? CardFunding::Unknown;
        $fee = $this->settings->load()->cardFeeOn($due, $funding);
        $metadata = $this->metadata($invoice->pid, $invoice->facilityId, [$invoice->id => $due], $due, $fee) + ['cpi_purpose' => 'autopay'];
        $intent = $this->gateway->chargeSavedCard($account, $card['customer'], $card['paymentMethod'], $due->cents + $fee->cents, 'Invoice #' . $invoice->id, $metadata);
        if (!$intent->succeeded()) {
            return $intent->errorMessage !== '' ? $intent->errorMessage : 'Card was not charged (' . $intent->status . ')';
        }
        $this->post($account, $intent, 'stripe_webhook');
        return null;
    }

    public function refundPayment(string $accountKey, string $paymentIntentId, Money $amount): string
    {
        $account = $this->accounts->byKey($accountKey) ?? throw new ValidationException(['payment' => 'The Stripe account for this payment is no longer set up']);
        return $this->gateway->refund($account, $paymentIntentId, $amount->cents);
    }

    /**
     * Posts a succeeded PaymentIntent the module created (from metadata).
     * Returns null for intents that are not the module's.
     */
    public function post(StripeAccount $account, IntentView $intent, string $source): ?int
    {
        $payments = $this->payments ?? throw new \LogicException('Payment service not set');
        $pid = (int) ($intent->metadata['cpi_pid'] ?? 0);
        if ($pid <= 0 || !$intent->succeeded()) {
            return null;
        }
        $fee = new Money(max(0, (int) ($intent->metadata['cpi_fee_cents'] ?? 0)));
        $amount = new Money($intent->amountCents - $fee->cents);
        $facility = (int) ($intent->metadata['cpi_facility'] ?? 0);
        $id = $payments->recordProcessorPayment(new ProcessorPayment(
            pid: $pid,
            date: $this->clock->now()->setTime(0, 0),
            accountKey: $account->key,
            paymentIntentId: $intent->id,
            chargeId: $intent->chargeId,
            amount: $amount,
            cardFee: $fee,
            cardFeeBasisPoints: $this->settings->load()->cardFeeBasisPoints,
            funding: CardFunding::tryFrom($intent->funding) ?? CardFunding::Unknown,
            brand: $intent->brand,
            last4: $intent->last4,
            allocations: self::parseAllocations($intent->metadata['cpi_alloc'] ?? ''),
            facilityId: $facility > 0 ? $facility : null,
            source: $source,
            userId: null,
        ));
        return $id;
    }

    /**
     * Saves the card a patient entered through a card-on-file link.
     */
    public function saveCardFromSetup(StripeAccount $account, string $customerId, string $setupIntentId, string $sessionId): void
    {
        $paymentMethod = $this->gateway->setupIntentPaymentMethod($account, $setupIntentId);
        if ($paymentMethod === '') {
            return;
        }
        $this->gateway->setDefaultPaymentMethod($account, $customerId, $paymentMethod);
        $this->db->execute(
            'UPDATE `cpi_stripe_customer` SET `default_payment_method` = ?, `needs_card_on_file` = 0 WHERE `stripe_customer_id` = ?',
            [$paymentMethod, $customerId]
        );
        $this->closeRequest($sessionId, null);
    }

    public function closeRequest(string $sessionId, ?int $paymentId): void
    {
        $this->db->execute(
            "UPDATE `cpi_pay_request` SET `status` = 'paid', `payment_id` = ?, `completed_at` = NOW() WHERE `checkout_session_id` = ?",
            [$paymentId, $sessionId]
        );
    }

    public function hasCardOnFile(int $pid, ?int $facilityId): bool
    {
        $account = $this->accounts->forFacility($facilityId);
        return $account !== null && $this->savedCard($account, $pid) !== null;
    }

    public function flagNeedsCard(int $pid, string $accountKey): void
    {
        $this->db->execute(
            'UPDATE `cpi_stripe_customer` SET `needs_card_on_file` = 1 WHERE `pid` = ? AND `account_key` = ?',
            [$pid, $accountKey]
        );
    }

    /**
     * Patients flagged to give a card (PatientNow migration) or on autopay without a card.
     *
     * @return list<array<string, scalar|null>>
     */
    public function needsCardList(): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT pd.`pid`, TRIM(CONCAT(pd.`fname`, ' ', pd.`lname`)) AS `patient`, pd.`email`, pd.`phone_cell`,"
            . ' sc.`needs_card_on_file`, sc.`default_payment_method`,'
            . " (SELECT GROUP_CONCAT(p.`code`) FROM `cpi_patient_membership` m JOIN `cpi_membership_plan` p ON p.`id` = m.`plan_id`"
            . "   WHERE m.`pid` = pd.`pid` AND m.`status` = 'active' AND m.`autopay` = 1) AS `autopay_plans`,"
            . " (SELECT MAX(r.`created_at`) FROM `cpi_pay_request` r WHERE r.`pid` = pd.`pid` AND r.`purpose` = 'setup') AS `last_link`"
            . ' FROM `patient_data` pd LEFT JOIN `cpi_stripe_customer` sc ON sc.`pid` = pd.`pid`'
            . ' WHERE sc.`needs_card_on_file` = 1'
            . " OR (EXISTS (SELECT 1 FROM `cpi_patient_membership` m WHERE m.`pid` = pd.`pid` AND m.`status` = 'active' AND m.`autopay` = 1)"
            . '     AND (sc.`default_payment_method` IS NULL))'
            . ' ORDER BY `patient`'
        );
    }

    public function setNeedsCard(int $pid, bool $needs, ?int $facilityId): void
    {
        $account = $this->account($facilityId);
        $this->customerId($account, $pid);
        $this->db->execute(
            'UPDATE `cpi_stripe_customer` SET `needs_card_on_file` = ? WHERE `pid` = ? AND `account_key` = ?',
            [$needs ? 1 : 0, $pid, $account->key]
        );
    }

    /**
     * Turns "invoice id => amount" into Stripe metadata ("5:10000,7:2000").
     *
     * @param array<int, Money> $allocations
     * @return array<string, string>
     */
    private function metadata(int $pid, ?int $facilityId, array $allocations, Money $base, Money $fee): array
    {
        $parts = [];
        foreach ($allocations as $invoiceId => $amount) {
            $parts[] = $invoiceId . ':' . $amount->cents;
        }
        return [
            'cpi_pid' => (string) $pid,
            'cpi_facility' => (string) ($facilityId ?? 0),
            'cpi_alloc' => implode(',', $parts),
            'cpi_base_cents' => (string) $base->cents,
            'cpi_fee_cents' => (string) $fee->cents,
        ];
    }

    /**
     * @return array<int, Money>
     */
    public static function parseAllocations(string $text): array
    {
        $out = [];
        foreach (explode(',', $text) as $part) {
            if (preg_match('/^(\d{1,10}):(\d{1,12})$/', trim($part), $m) === 1) {
                $out[(int) $m[1]] = new Money((int) $m[2]);
            }
        }
        return $out;
    }

    /**
     * Spreads an amount over the given open invoices, oldest first.
     *
     * @param list<int> $invoiceIds
     * @return array<int, Money>
     */
    private function allocationsFor(int $pid, array $invoiceIds, Money $amount): array
    {
        $payments = $this->payments ?? throw new \LogicException('Payment service not set');
        $left = $amount;
        $out = [];
        foreach ($payments->openInvoices($pid) as $open) {
            if (!in_array($open['id'], $invoiceIds, true) || $left->cents <= 0) {
                continue;
            }
            $take = $open['due']->cents < $left->cents ? $open['due'] : $left;
            $out[$open['id']] = $take;
            $left = $left->subtract($take);
        }
        return $out;
    }

    private function account(?int $facilityId): StripeAccount
    {
        return $this->accounts->forFacility($facilityId)
            ?? throw new ValidationException(['stripe' => 'Stripe is not set up yet. Add the keys in Cash-Pay Settings > Stripe.']);
    }

    private function customerId(StripeAccount $account, int $pid): string
    {
        $row = $this->db->fetchOne('SELECT `stripe_customer_id` FROM `cpi_stripe_customer` WHERE `pid` = ? AND `account_key` = ?', [$pid, $account->key]);
        if ($row !== null) {
            return Row::str($row, 'stripe_customer_id');
        }
        $patient = PatientInfo::load($this->db, $pid) ?? throw new ValidationException(['patient' => 'Patient not found']);
        $id = $this->gateway->createCustomer($account, $patient->name, $patient->email, $pid);
        $this->db->execute(
            'INSERT INTO `cpi_stripe_customer` (`pid`, `account_key`, `stripe_customer_id`) VALUES (?, ?, ?)',
            [$pid, $account->key, $id]
        );
        return $id;
    }

    /**
     * @return array{customer: string, paymentMethod: string}|null
     */
    private function savedCard(StripeAccount $account, int $pid): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT `stripe_customer_id`, `default_payment_method` FROM `cpi_stripe_customer` WHERE `pid` = ? AND `account_key` = ?',
            [$pid, $account->key]
        );
        $method = $row === null ? null : Row::strOrNull($row, 'default_payment_method');
        return $row === null || $method === null ? null : ['customer' => Row::str($row, 'stripe_customer_id'), 'paymentMethod' => $method];
    }
}
