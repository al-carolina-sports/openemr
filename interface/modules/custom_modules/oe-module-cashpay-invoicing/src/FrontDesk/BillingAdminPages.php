<?php

/**
 * Stripe set-up (accounts, keys, offices) and the membership and card
 * worklist for the front desk.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\FrontDesk;

use OpenEMR\Modules\CashpayInvoicing\AclObject;
use OpenEMR\Modules\CashpayInvoicing\Admin\FormInput;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupRepository;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupSource;
use OpenEMR\Modules\CashpayInvoicing\Admin\Notice;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use OpenEMR\Modules\CashpayInvoicing\Membership\MembershipService;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripeAccount;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripeAccountRepository;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripeGateway;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripePayments;
use OpenEMR\Modules\CashpayInvoicing\Web\PageKit;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class BillingAdminPages
{
    public const WEBHOOK_EVENTS = [
        'checkout.session.completed',
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'charge.refunded',
        'charge.dispute.created',
    ];

    public function __construct(
        private PageKit $kit,
        private LookupRepository $lookups,
        private StripeAccountRepository $accounts,
        private StripeGateway $gateway,
        private StripePayments $stripe,
        private MembershipService $memberships,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $adminUrl,
        private string $webhookUrl,
        private int $userId,
    ) {
    }

    public function stripe(): Response
    {
        if (($denied = $this->kit->deny(AclObject::Settings)) !== null) {
            return $denied;
        }
        $errors = [];
        $tested = null;
        if ($this->kit->isPost()) {
            if (($bad = $this->kit->checkCsrf()) !== null) {
                return $bad;
            }
            $form = new FormInput($this->kit->request->request);
            try {
                if ($form->string('action') === 'test') {
                    $account = $this->accounts->byKey($form->string('key')) ?? throw new ValidationException(['key' => 'Save the account first']);
                    try {
                        $this->gateway->ping($account);
                        $tested = ['key' => $account->key, 'ok' => true];
                    } catch (\Stripe\Exception\ApiErrorException $e) {
                        $this->logger->warning('Stripe key test failed', ['account' => $account->key, 'exception' => $e]);
                        $tested = ['key' => $account->key, 'ok' => false];
                    }
                } else {
                    $this->accounts->save($this->accountsFromForm($form), $this->userId);
                    return $this->kit->redirect($this->adminUrl . 'stripe.php', [], Notice::SettingsSaved);
                }
            } catch (ValidationException $e) {
                $errors = $e->errors;
            }
        }
        $rows = [];
        foreach ($this->accounts->all() as $account) {
            $rows[] = [
                'key' => $account->key,
                'label' => $account->label,
                'live' => $account->live,
                'publishable' => $account->publishableKey,
                'hasSecret' => $account->secretKey !== '',
                'secretHint' => $account->secretKey !== '' ? substr($account->secretKey, 0, 8) . '...' . substr($account->secretKey, -4) : '',
                'hasWebhook' => $account->webhookSecret !== '',
                'location' => $account->terminalLocationId,
                'facilityIds' => $account->facilityIds,
                'default' => $account->isDefault,
                'modeWarning' => !$account->keyMatchesMode(),
            ];
        }
        return $this->kit->render('@cashpay/frontdesk/stripe.html.twig', [
            'accounts' => $rows,
            'facilities' => $this->lookups->options(LookupSource::Facility),
            'errors' => $errors,
            'tested' => $tested,
            'webhookUrl' => $this->webhookUrl,
            'events' => self::WEBHOOK_EVENTS,
        ], $errors !== [] ? 422 : 200);
    }

    public function worklist(): Response
    {
        if (($denied = $this->kit->deny(AclObject::Billing)) !== null) {
            return $denied;
        }
        $today = $this->clock->now()->setTime(0, 0);
        $due = [];
        foreach ($this->memberships->due($today) as $row) {
            $due[] = [
                'pid' => Row::int($row, 'pid'),
                'patient' => Row::str($row, 'patient'),
                'plan' => Row::str($row, 'plan_name'),
                'next' => Row::str($row, 'next_bill_date'),
                'auto' => Row::bool($row, 'auto_monthly_invoice'),
                'autopay' => Row::bool($row, 'autopay'),
                'error' => Row::str($row, 'last_autopay_error'),
            ];
        }
        $cards = [];
        try {
            foreach ($this->stripe->needsCardList() as $row) {
                $cards[] = [
                    'pid' => Row::int($row, 'pid'),
                    'patient' => Row::str($row, 'patient'),
                    'email' => Row::str($row, 'email'),
                    'phone' => Row::str($row, 'phone_cell'),
                    'flagged' => Row::bool($row, 'needs_card_on_file'),
                    'plans' => Row::str($row, 'autopay_plans'),
                    'lastLink' => Row::str($row, 'last_link'),
                ];
            }
        } catch (ValidationException) {
            $cards = [];
        }
        return $this->kit->render('@cashpay/frontdesk/worklist.html.twig', [
            'due' => $due,
            'cards' => $cards,
            'stripeOn' => $this->stripe->enabled(),
            'failures' => $this->memberships->autopayFailures(),
        ]);
    }

    /**
     * @return list<StripeAccount>
     */
    private function accountsFromForm(FormInput $form): array
    {
        $raw = $form->all()['accounts'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $accounts = [];
        $errors = [];
        $defaultKey = $form->string('default_account');
        $seen = [];
        foreach ($raw as $index => $item) {
            if (!is_array($item) || ($item['remove'] ?? '') === '1') {
                continue;
            }
            $key = is_string($item['key'] ?? null) ? strtolower(trim($item['key'])) : '';
            $label = is_string($item['label'] ?? null) ? trim($item['label']) : '';
            if ($key === '' && $label === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9_-]{1,40}$/', $key) !== 1 || isset($seen[$key])) {
                $errors['accounts_' . $index] = 'Each account needs a unique short key (letters, numbers, - or _)';
                continue;
            }
            $seen[$key] = true;
            $publishable = is_string($item['publishable_key'] ?? null) ? trim($item['publishable_key']) : '';
            $secret = is_string($item['secret_key'] ?? null) ? trim($item['secret_key']) : '';
            $webhook = is_string($item['webhook_secret'] ?? null) ? trim($item['webhook_secret']) : '';
            if ($publishable !== '' && preg_match('/^pk_(test|live)_[A-Za-z0-9]+$/', $publishable) !== 1) {
                $errors['accounts_' . $index . '_pk'] = 'The publishable key starts with pk_test_ or pk_live_';
            }
            if ($secret !== '' && preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $secret) !== 1) {
                $errors['accounts_' . $index . '_sk'] = 'The secret key starts with sk_test_, sk_live_, rk_test_ or rk_live_';
            }
            if ($webhook !== '' && preg_match('/^whsec_[A-Za-z0-9]+$/', $webhook) !== 1) {
                $errors['accounts_' . $index . '_wh'] = 'The webhook signing secret starts with whsec_';
            }
            $facilities = [];
            foreach (is_array($item['facility_ids'] ?? null) ? $item['facility_ids'] : [] as $id) {
                if (is_string($id) && preg_match('/^[1-9]\d{0,9}$/', $id) === 1) {
                    $facilities[] = (int) $id;
                }
            }
            $accounts[] = new StripeAccount(
                key: $key,
                label: mb_substr($label, 0, 100),
                live: ($item['live'] ?? '') === '1',
                publishableKey: $publishable,
                secretKey: $secret,
                webhookSecret: $webhook,
                terminalLocationId: is_string($item['terminal_location_id'] ?? null) ? mb_substr(trim($item['terminal_location_id']), 0, 64) : '',
                facilityIds: array_values(array_unique($facilities)),
                isDefault: $key === $defaultKey,
            );
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $accounts;
    }
}
