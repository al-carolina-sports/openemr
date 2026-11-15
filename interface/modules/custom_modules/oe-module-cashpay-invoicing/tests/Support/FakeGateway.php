<?php

/**
 * Records Stripe calls and returns canned answers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests\Support;

use OpenEMR\Modules\CashpayInvoicing\Stripe\IntentView;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripeAccount;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripeGateway;

final class FakeGateway implements StripeGateway
{
    /** @var list<array{string, array<array-key, mixed>}> */
    public array $calls = [];

    /** @var array<string, IntentView> */
    public array $intents = [];

    public string $funding = 'credit';

    public ?IntentView $savedCardResult = null;

    /** @var array<string, array{id: string, type: string, object: array<string, mixed>}> signature => event */
    public array $events = [];

    public function createCustomer(StripeAccount $account, string $name, string $email, int $pid): string
    {
        $this->calls[] = ['createCustomer', [$name, $email, $pid]];
        return 'cus_' . $pid;
    }

    public function checkoutForPayment(StripeAccount $account, ?string $customerId, int $amountCents, string $description, array $metadata, string $successUrl, string $cancelUrl): array
    {
        $this->calls[] = ['checkoutForPayment', [$customerId, $amountCents, $description, $metadata]];
        return ['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'];
    }

    public function checkoutForSetup(StripeAccount $account, string $customerId, array $metadata, string $successUrl, string $cancelUrl): array
    {
        $this->calls[] = ['checkoutForSetup', [$customerId, $metadata]];
        return ['id' => 'cs_setup', 'url' => 'https://checkout.stripe.com/c/setup/cs_setup'];
    }

    public function createPresentIntent(StripeAccount $account, int $amountCents, string $description, array $metadata): string
    {
        $this->calls[] = ['createPresentIntent', [$amountCents, $description, $metadata]];
        $this->intents['pi_1'] = new IntentView('pi_1', 'requires_payment_method', $amountCents, $metadata, '', '', '', '', '');
        return 'pi_1';
    }

    public function collectOnReader(StripeAccount $account, string $readerId, string $paymentIntentId): void
    {
        $this->calls[] = ['collectOnReader', [$readerId, $paymentIntentId]];
    }

    public function confirmOnReader(StripeAccount $account, string $readerId, string $paymentIntentId): void
    {
        $this->calls[] = ['confirmOnReader', [$readerId, $paymentIntentId]];
    }

    public function cancelReader(StripeAccount $account, string $readerId): void
    {
        $this->calls[] = ['cancelReader', [$readerId]];
    }

    public function updateIntent(StripeAccount $account, string $paymentIntentId, int $amountCents, array $metadata): void
    {
        $this->calls[] = ['updateIntent', [$paymentIntentId, $amountCents, $metadata]];
        $old = $this->intents[$paymentIntentId];
        $this->intents[$paymentIntentId] = new IntentView($old->id, $old->status, $amountCents, $metadata, $old->chargeId, $old->funding, $old->brand, $old->last4, '');
    }

    public function intent(StripeAccount $account, string $paymentIntentId): IntentView
    {
        return $this->intents[$paymentIntentId] ?? throw new \RuntimeException('No such intent');
    }

    public function chargeSavedCard(StripeAccount $account, string $customerId, string $paymentMethodId, int $amountCents, string $description, array $metadata): IntentView
    {
        $this->calls[] = ['chargeSavedCard', [$customerId, $paymentMethodId, $amountCents, $description, $metadata]];
        return $this->savedCardResult ?? new IntentView('pi_auto', 'succeeded', $amountCents, $metadata, 'ch_1', $this->funding, 'visa', '4242', '');
    }

    public function cardOf(StripeAccount $account, string $paymentMethodId): array
    {
        return ['funding' => $this->funding, 'brand' => 'visa', 'last4' => '4242'];
    }

    public function setDefaultPaymentMethod(StripeAccount $account, string $customerId, string $paymentMethodId): void
    {
        $this->calls[] = ['setDefaultPaymentMethod', [$customerId, $paymentMethodId]];
    }

    public function setupIntentPaymentMethod(StripeAccount $account, string $setupIntentId): string
    {
        return 'pm_saved';
    }

    public function refund(StripeAccount $account, string $paymentIntentId, int $amountCents): string
    {
        $this->calls[] = ['refund', [$paymentIntentId, $amountCents]];
        return 're_1';
    }

    public function readers(StripeAccount $account): array
    {
        return [['id' => 'tmr_1', 'label' => 'Front desk', 'status' => 'online']];
    }

    public function verifyWebhook(StripeAccount $account, string $payload, string $signature): ?array
    {
        return $account->webhookSecret === 'whsec_good' ? ($this->events[$signature] ?? null) : null;
    }

    public function ping(StripeAccount $account): void
    {
    }
}
