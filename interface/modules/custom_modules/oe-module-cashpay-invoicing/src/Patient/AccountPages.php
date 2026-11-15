<?php

/**
 * Patient chart pages for money: Payments, Rewards (award points) and
 * Financial Notes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Patient;

use OpenEMR\Modules\CashpayInvoicing\AclObject;
use OpenEMR\Modules\CashpayInvoicing\Admin\FormInput;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupRepository;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupSource;
use OpenEMR\Modules\CashpayInvoicing\Admin\Notice;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Membership\BillingCycle;
use OpenEMR\Modules\CashpayInvoicing\Membership\MembershipService;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Payments\PaymentInput;
use OpenEMR\Modules\CashpayInvoicing\Payments\PaymentMethod;
use OpenEMR\Modules\CashpayInvoicing\Payments\PaymentService;
use OpenEMR\Modules\CashpayInvoicing\Payments\PayType;
use OpenEMR\Modules\CashpayInvoicing\Rewards\GiftCardService;
use OpenEMR\Modules\CashpayInvoicing\Rewards\PointsMath;
use OpenEMR\Modules\CashpayInvoicing\Rewards\RewardsService;
use OpenEMR\Modules\CashpayInvoicing\Settings\CardFunding;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripePayments;
use OpenEMR\Modules\CashpayInvoicing\Web\PageKit;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class AccountPages
{
    public function __construct(
        private PageKit $kit,
        private Database $db,
        private LookupRepository $lookups,
        private PaymentService $payments,
        private RewardsService $rewards,
        private GiftCardService $giftCards,
        private FinancialNotes $notes,
        private SettingsRepository $settings,
        private MembershipService $memberships,
        private ?StripePayments $stripe,
        private PatientMailer $mailer,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $patientUrl,
        private int $pid,
        private int $userId,
    ) {
    }

    public function payments(): Response
    {
        if (($denied = $this->kit->deny(AclObject::View)) !== null) {
            return $denied;
        }
        $patient = PatientInfo::load($this->db, $this->pid);
        if ($patient === null) {
            return new Response(xlt('Select a patient first.'), 400);
        }
        $errors = [];
        if ($this->kit->isPost()) {
            if (($denied = $this->kit->deny(AclObject::Billing)) !== null) {
                return $denied;
            }
            if (($bad = $this->kit->checkCsrf()) !== null) {
                return $bad;
            }
            try {
                $form = new FormInput($this->kit->request->request);
                if (in_array($form->string('action'), ['pay_link', 'card_link'], true)) {
                    $requestId = $this->handleLink($form);
                    return $this->kit->redirect($this->patientUrl . 'payments.php', ['link' => $requestId], Notice::LinkCreated, 'stripe');
                }
                $notice = $this->handlePayment($form);
                return $this->kit->redirect($this->patientUrl . 'payments.php', [], $notice);
            } catch (ValidationException $e) {
                $errors = $e->errors;
            } catch (\Stripe\Exception\ApiErrorException $e) {
                $this->logger->error('Stripe request failed', ['exception' => $e]);
                $errors = ['stripe' => 'Stripe did not accept the request. Check the Stripe settings and try again.'];
            }
        }
        $settings = $this->settings->load();
        $totals = $this->payments->totals($this->pid);
        $enrolled = $this->rewards->isEnrolled($this->pid);
        $points = $this->rewards->balance($this->pid);
        return $this->kit->render('@cashpay/patient/payments.html.twig', [
            'patient' => $patient,
            'navTab' => 'payments',
            'errors' => $errors,
            'submitted' => $this->kit->isPost() ? $this->kit->request->request->all() : [],
            'totals' => [
                'balance' => $totals['balance']->format(),
                'credit' => $totals['credit']->format(),
                'creditRaw' => $totals['credit']->toDecimalString(),
                'lifetime' => $totals['lifetime']->format(),
                'since' => $totals['since'],
            ],
            'openInvoices' => array_map(static fn (array $i): array => [
                'id' => $i['id'], 'date' => $i['date'], 'name' => $i['name'],
                'total' => $i['total']->format(), 'paid' => $i['paid']->format(), 'due' => $i['due']->format(), 'dueRaw' => $i['due']->toDecimalString(),
            ], $this->payments->openInvoices($this->pid)),
            'focusInvoice' => $this->kit->request->query->getInt('invoice'),
            'ledger' => $this->payments->ledger($this->pid),
            'methods' => array_map(self::methodView(...), $this->payments->methods(false)),
            'refundMethods' => array_map(self::methodView(...), $this->payments->methods(true)),
            'facilities' => $this->lookups->options(LookupSource::Facility),
            'defaultFacility' => $this->defaultFacility(),
            'today' => $this->today()->format('Y-m-d'),
            'cardFee' => ['enabled' => $settings->cardFeeEnabled, 'bp' => $settings->cardFeeBasisPoints, 'label' => $settings->cardFeeLabel, 'pct' => $settings->cardFeePercentText()],
            'rewards' => [
                'on' => $settings->rewardsEnabled && $enrolled,
                'points' => Decimal::display(Decimal::format($points, 2)),
                'value' => PointsMath::valueOf($points, $settings->pointValue)->format(),
            ],
            'canBill' => $this->kit->access->can(AclObject::Billing),
            'canRefund' => $this->kit->access->can(AclObject::Refunds),
            'stripe' => $this->stripeView(),
        ], $errors !== [] ? 422 : 200);
    }

    /**
     * JSON for the card reader panel: starts a reader payment, reports its
     * progress, or cancels it.
     */
    public function reader(): Response
    {
        if (!$this->kit->access->can(AclObject::Billing) || $this->stripe === null) {
            return new JsonResponse(['state' => 'failed', 'message' => 'Not allowed'], 403);
        }
        if (($bad = $this->kit->checkCsrf()) !== null) {
            return new JsonResponse(['state' => 'failed', 'message' => 'Authentication error'], 403);
        }
        $form = new FormInput($this->kit->request->request);
        $facilityId = $form->optionalId('facility_id');
        try {
            switch ($form->string('action')) {
                case 'start':
                    $amount = $form->money('amount') ?? Money::zero();
                    $allocations = [];
                    $invoiceId = $form->optionalId('invoice_id');
                    if ($invoiceId !== null) {
                        $allocations[$invoiceId] = $amount;
                    }
                    $intent = $this->stripe->startReaderPayment($this->pid, $form->string('reader_id'), $amount, $allocations, $facilityId);
                    return new JsonResponse(['state' => 'waiting', 'message' => 'Waiting for the card on the reader', 'intent' => $intent]);
                case 'status':
                    return new JsonResponse($this->stripe->readerStatus($this->pid, $facilityId, $form->string('intent')));
                case 'cancel':
                    $this->stripe->cancelReader($facilityId, $form->string('reader_id'));
                    return new JsonResponse(['state' => 'canceled', 'message' => 'Cancelled']);
                default:
                    return new JsonResponse(['state' => 'failed', 'message' => 'Unknown action'], 400);
            }
        } catch (ValidationException $e) {
            return new JsonResponse(['state' => 'failed', 'message' => implode(' ', $e->errors)], 422);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logger->error('Stripe reader request failed', ['exception' => $e]);
            return new JsonResponse(['state' => 'failed', 'message' => 'Stripe did not accept the request'], 502);
        }
    }

    public function membership(): Response
    {
        if (($denied = $this->kit->deny(AclObject::View)) !== null) {
            return $denied;
        }
        $patient = PatientInfo::load($this->db, $this->pid);
        if ($patient === null) {
            return new Response(xlt('Select a patient first.'), 400);
        }
        $errors = [];
        if ($this->kit->isPost()) {
            if (($denied = $this->kit->deny(AclObject::Billing)) !== null) {
                return $denied;
            }
            if (($bad = $this->kit->checkCsrf()) !== null) {
                return $bad;
            }
            $form = new FormInput($this->kit->request->request);
            try {
                $notice = $this->handleMembership($form);
                return $this->kit->redirect($this->patientUrl . 'membership.php', [], $notice);
            } catch (ValidationException $e) {
                $errors = $e->errors;
            }
        }
        $rows = [];
        foreach ($this->memberships->forPatient($this->pid) as $row) {
            $rows[] = [
                'id' => Row::int($row, 'id'),
                'plan' => Row::str($row, 'plan_name'),
                'code' => Row::str($row, 'code'),
                'cycle' => (BillingCycle::tryFrom(Row::str($row, 'billing_cycle')) ?? BillingCycle::Monthly)->label(),
                'price' => MembershipService::priceText($row),
                'start' => Row::str($row, 'start_date'),
                'end' => Row::str($row, 'end_date'),
                'status' => Row::str($row, 'status'),
                'autopay' => Row::bool($row, 'autopay'),
                'next' => Row::str($row, 'next_bill_date'),
                'autoInvoice' => Row::bool($row, 'auto_monthly_invoice'),
                'facility' => $this->lookups->names(LookupSource::Facility)[Row::intOrNull($row, 'facility_id') ?? 0] ?? '',
                'autopayError' => Row::str($row, 'last_autopay_error'),
            ];
        }
        return $this->kit->render('@cashpay/patient/membership.html.twig', [
            'patient' => $patient,
            'navTab' => 'membership',
            'errors' => $errors,
            'memberships' => $rows,
            'plans' => array_map(static fn (array $p): array => [
                'id' => Row::int($p, 'id'), 'name' => Row::str($p, 'name'), 'code' => Row::str($p, 'code'),
                'monthly' => Row::money($p, 'monthly_price')->format(), 'annual' => Row::money($p, 'annual_price')->format(),
            ], $this->memberships->plans()),
            'facilities' => $this->lookups->options(LookupSource::Facility),
            'defaultFacility' => $this->defaultFacility(),
            'today' => $this->today()->format('Y-m-d'),
            'hasCard' => $this->stripe?->hasCardOnFile($this->pid, $this->defaultFacility()) ?? false,
            'stripeOn' => $this->stripe?->enabled($this->defaultFacility()) ?? false,
            'canBill' => $this->kit->access->can(AclObject::Billing),
        ], $errors !== [] ? 422 : 200);
    }

    private function handleMembership(FormInput $form): Notice
    {
        $today = $this->today();
        switch ($form->string('action')) {
            case 'enroll':
                $cycle = BillingCycle::tryFrom($form->string('billing_cycle')) ?? BillingCycle::Monthly;
                $startText = $form->string('start_date');
                $start = $startText === '' ? $today : \DateTimeImmutable::createFromFormat('!Y-m-d', $startText);
                if ($start === false) {
                    throw new ValidationException(['start_date' => 'Enter a date as YYYY-MM-DD']);
                }
                $this->memberships->enroll($this->pid, $form->requiredId('plan_id'), $cycle, $start, $form->bool('autopay'), $form->optionalId('facility_id'), $this->userId);
                return Notice::MembershipEnrolled;
            case 'freeze':
                $this->memberships->setStatus($this->pid, $form->requiredId('membership_id'), 'frozen', null);
                return Notice::Updated;
            case 'unfreeze':
                $this->memberships->setStatus($this->pid, $form->requiredId('membership_id'), 'active', null);
                return Notice::Updated;
            case 'cancel':
                $this->memberships->setStatus($this->pid, $form->requiredId('membership_id'), 'cancelled', $today);
                return Notice::Updated;
            case 'autopay_on':
            case 'autopay_off':
                $this->memberships->setAutopay($this->pid, $form->requiredId('membership_id'), $form->string('action') === 'autopay_on');
                return Notice::Updated;
            case 'needs_card_on':
            case 'needs_card_off':
                $stripe = $this->stripe ?? throw new ValidationException(['stripe' => 'Stripe is not set up yet']);
                $stripe->setNeedsCard($this->pid, $form->string('action') === 'needs_card_on', $this->defaultFacility());
                return Notice::Updated;
            case 'bill_now':
                $this->memberships->billPeriod($form->requiredId('membership_id'), null, $this->userId, true);
                return Notice::Created;
            default:
                throw new ValidationException(['form' => 'Unknown action']);
        }
    }

    private function handleLink(FormInput $form): int
    {
        $stripe = $this->stripe ?? throw new ValidationException(['stripe' => 'Stripe is not set up yet']);
        $patient = PatientInfo::load($this->db, $this->pid) ?? throw new ValidationException(['form' => 'Patient not found']);
        $facilityId = $form->optionalId('facility_id');
        if ($form->string('action') === 'pay_link') {
            $amount = $form->money('amount') ?? Money::zero();
            $link = $stripe->requestPayment($this->pid, $amount, $form->idList('invoice_ids'), $facilityId, $this->userId);
            $subject = 'Payment request';
            $intro = 'Please use the secure link below to pay ' . $amount->format() . '.';
        } else {
            $link = $stripe->requestCardOnFile($this->pid, $facilityId, $this->userId);
            $subject = 'Please update your card on file';
            $intro = 'Please use the secure link below to save the card we will use for your membership.';
        }
        if ($form->bool('send_email')) {
            try {
                $this->mailer->sendLink($patient, $subject, $intro, $link['url']);
            } catch (\RuntimeException $e) {
                $this->logger->error('Link email failed', ['exception' => $e]);
                throw new ValidationException(['email' => 'The link was made but the email could not be sent. Copy the link below.']);
            }
            $stripe->markSent($link['requestId'], $patient->email);
        }
        return $link['requestId'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stripeView(): ?array
    {
        if ($this->stripe === null || !$this->stripe->enabled($this->defaultFacility())) {
            return null;
        }
        $requests = [];
        $highlight = $this->kit->request->query->getInt('link');
        foreach ($this->stripe->requests($this->pid) as $row) {
            $requests[] = [
                'id' => Row::int($row, 'id'),
                'purpose' => Row::str($row, 'purpose'),
                'amount' => Row::money($row, 'amount')->format(),
                'status' => Row::str($row, 'status'),
                'url' => Row::str($row, 'url'),
                'sentTo' => Row::str($row, 'sent_to'),
                'created' => Row::str($row, 'created_at'),
                'highlight' => Row::int($row, 'id') === $highlight,
            ];
        }
        try {
            $readers = $this->stripe->readers($this->defaultFacility());
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logger->warning('Stripe readers could not be listed', ['exception' => $e]);
            $readers = [];
        }
        return [
            'readers' => $readers,
            'hasCard' => $this->stripe->hasCardOnFile($this->pid, $this->defaultFacility()),
            'requests' => $requests,
            'readerUrl' => $this->patientUrl . 'reader.php',
        ];
    }

    public function rewards(): Response
    {
        if (($denied = $this->kit->deny(AclObject::View)) !== null) {
            return $denied;
        }
        $patient = PatientInfo::load($this->db, $this->pid);
        if ($patient === null) {
            return new Response(xlt('Select a patient first.'), 400);
        }
        $settings = $this->settings->load();
        $errors = [];
        if ($this->kit->isPost()) {
            if (($denied = $this->kit->deny(AclObject::Settings)) !== null) {
                return $denied;
            }
            if (($bad = $this->kit->checkCsrf()) !== null) {
                return $bad;
            }
            $form = new FormInput($this->kit->request->request);
            try {
                switch ($form->string('action')) {
                    case 'enroll':
                        $this->rewards->setEnrolled($this->pid, true, $this->userId);
                        return $this->kit->redirect($this->patientUrl . 'rewards.php', [], Notice::Enrolled);
                    case 'unenroll':
                        $this->rewards->setEnrolled($this->pid, false, $this->userId);
                        return $this->kit->redirect($this->patientUrl . 'rewards.php', [], Notice::Unenrolled);
                    case 'adjust':
                        try {
                            $points = Decimal::parse($form->string('points'), 2);
                        } catch (\InvalidArgumentException) {
                            throw new ValidationException(['points' => 'Enter points like 25 or -10']);
                        }
                        $this->rewards->adjust($this->pid, $points, $form->string('reason'), $settings, $this->today(), $this->userId);
                        return $this->kit->redirect($this->patientUrl . 'rewards.php', [], Notice::PointsAdjusted);
                    default:
                        throw new ValidationException(['form' => 'Unknown action']);
                }
            } catch (ValidationException $e) {
                $errors = $e->errors;
            }
        }
        $balance = $this->rewards->balance($this->pid);
        return $this->kit->render('@cashpay/patient/rewards.html.twig', [
            'patient' => $patient,
            'navTab' => 'rewards',
            'errors' => $errors,
            'programOn' => $settings->rewardsEnabled,
            'enrolled' => $this->rewards->isEnrolled($this->pid),
            'points' => Decimal::display(Decimal::format($balance, 2)),
            'value' => PointsMath::valueOf($balance, $settings->pointValue)->format(),
            'rate' => $settings->pointsPerDollarText(),
            'pointValue' => $settings->pointValue->format(),
            'ledger' => array_map(static fn (array $r): array => [
                'date' => Row::str($r, 'txn_date'),
                'description' => Row::str($r, 'description'),
                'points' => Decimal::display(Row::str($r, 'points')),
                'dollars' => Row::money($r, 'dollar_value')->format(),
                'source' => Row::str($r, 'source'),
                'reason' => Row::str($r, 'reason'),
                'user' => Row::str($r, 'username'),
            ], $this->rewards->ledger($this->pid)),
            'giftCards' => array_map(static fn (array $g): array => [
                'code' => Row::str($g, 'code'),
                'initial' => Row::money($g, 'initial_value')->format(),
                'balance' => Row::money($g, 'balance')->format(),
                'issued' => Row::str($g, 'issued_date'),
                'recipient' => Row::str($g, 'recipient_name'),
                'purchaser' => Row::intOrNull($g, 'purchaser_pid'),
            ], $this->giftCards->forPatient($this->pid)),
            'canAdmin' => $this->kit->access->can(AclObject::Settings),
        ], $errors !== [] ? 422 : 200);
    }

    public function notes(): Response
    {
        if (($denied = $this->kit->deny(AclObject::View)) !== null) {
            return $denied;
        }
        $patient = PatientInfo::load($this->db, $this->pid);
        if ($patient === null) {
            return new Response(xlt('Select a patient first.'), 400);
        }
        if ($this->kit->isPost()) {
            if (($denied = $this->kit->deny(AclObject::Billing)) !== null) {
                return $denied;
            }
            if (($bad = $this->kit->checkCsrf()) !== null) {
                return $bad;
            }
            $body = $this->kit->request->request->getString('body_html');
            $this->notes->save($this->pid, $body, $this->userId);
            return $this->kit->redirect($this->patientUrl . 'notes.php', [], Notice::Saved);
        }
        return $this->kit->render('@cashpay/patient/notes.html.twig', [
            'patient' => $patient,
            'navTab' => 'notes',
            'note' => $this->notes->load($this->pid),
            'canBill' => $this->kit->access->can(AclObject::Billing),
        ]);
    }

    private function handlePayment(FormInput $form): Notice
    {
        $today = $this->today();
        switch ($form->string('action')) {
            case 'pay':
                $this->payments->apply($this->pid, $this->paymentInput($form, $today), $this->userId);
                return Notice::PaymentPosted;
            case 'apply_credit':
                $this->payments->applyCredit($this->pid, $form->requiredId('invoice_id'), $form->money('amount') ?? Money::zero(), $this->userId, $today);
                return Notice::CreditApplied;
            case 'refund':
                $this->requireRefunds();
                $this->payments->refund(
                    $this->pid,
                    $form->requiredId('payment_id'),
                    $form->money('amount') ?? Money::zero(),
                    $form->requiredId('method_id'),
                    $form->string('reason'),
                    $today,
                    $this->userId,
                );
                return Notice::Refunded;
            case 'write_off':
                $this->requireRefunds();
                $this->payments->writeOff($this->pid, $form->requiredId('invoice_id'), $form->money('amount') ?? Money::zero(), $form->string('reason'), $today, $this->userId);
                return Notice::WrittenOff;
            case 'void':
                $this->requireRefunds();
                $this->payments->void($this->pid, $form->requiredId('payment_id'), $form->string('reason'), $today, $this->userId);
                return Notice::Voided;
            default:
                throw new ValidationException(['form' => 'Unknown action']);
        }
    }

    private function paymentInput(FormInput $form, \DateTimeImmutable $today): PaymentInput
    {
        $allocations = [];
        $raw = $form->all()['alloc'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $invoiceId => $text) {
                if (!is_string($text) || trim($text) === '' || !is_int($invoiceId)) {
                    continue;
                }
                try {
                    $amount = Money::parse($text);
                } catch (\InvalidArgumentException) {
                    throw new ValidationException(['alloc_' . $invoiceId => 'Enter an amount like 25.00']);
                }
                if ($amount->cents !== 0) {
                    $allocations[$invoiceId] = $amount;
                }
            }
        }
        $dateText = $form->string('pay_date');
        $date = $dateText === '' ? $today : \DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
        if ($date === false || ($dateText !== '' && $date->format('Y-m-d') !== $dateText)) {
            throw new ValidationException(['pay_date' => 'Enter a date as YYYY-MM-DD']);
        }
        return new PaymentInput(
            payType: $form->string('pay_type') === PayType::Deposit->value ? PayType::Deposit : PayType::Payment,
            date: $date,
            methodId: $form->requiredId('method_id'),
            amount: $form->money('amount') ?? Money::zero(),
            gratuity: $form->money('gratuity', false) ?? Money::zero(),
            facilityId: $form->optionalId('facility_id'),
            reference: $form->string('reference'),
            comments: $form->string('comments'),
            allocations: $allocations,
            funding: CardFunding::tryFrom($form->string('funding')),
            giftCardCode: $form->string('gift_card_code'),
        );
    }

    private function requireRefunds(): void
    {
        if (!$this->kit->access->can(AclObject::Refunds)) {
            throw new ValidationException(['form' => 'Refunds, write-offs and voids need the Cash-Pay refunds permission']);
        }
    }

    private function defaultFacility(): ?int
    {
        $row = $this->db->fetchOne('SELECT `facility_id` FROM `users` WHERE `id` = ?', [$this->userId]);
        return $row === null ? null : Row::intOrNull($row, 'facility_id');
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }

    /**
     * @return array{id: int, name: string, kind: string}
     */
    private static function methodView(PaymentMethod $method): array
    {
        return ['id' => $method->id, 'name' => $method->name, 'kind' => $method->kind->value];
    }
}
