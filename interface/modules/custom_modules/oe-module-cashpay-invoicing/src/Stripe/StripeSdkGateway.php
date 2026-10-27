<?php

/**
 * StripeGateway over the official stripe-php SDK.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Stripe;

use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

final class StripeSdkGateway implements StripeGateway
{
    /** @var array<string, StripeClient> */
    private array $clients = [];

    public function createCustomer(StripeAccount $account, string $name, string $email, int $pid): string
    {
        $params = ['name' => $name, 'metadata' => ['cpi_pid' => (string) $pid]];
        if ($email !== '') {
            $params['email'] = $email;
        }
        return $this->client($account)->customers->create($params)->id;
    }

    public function checkoutForPayment(StripeAccount $account, ?string $customerId, int $amountCents, string $description, array $metadata, string $successUrl, string $cancelUrl): array
    {
        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => 'usd', 'unit_amount' => $amountCents, 'product_data' => ['name' => $description]],
            ]],
            'payment_method_types' => ['card'],
            'payment_intent_data' => ['description' => $description, 'metadata' => $metadata],
            'metadata' => $metadata,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => time() + 23 * 3600,
        ];
        if ($customerId !== null) {
            $params['customer'] = $customerId;
        }
        $session = $this->client($account)->checkout->sessions->create($params);
        return ['id' => $session->id, 'url' => self::str($session->toArray(), 'url')];
    }

    public function checkoutForSetup(StripeAccount $account, string $customerId, array $metadata, string $successUrl, string $cancelUrl): array
    {
        $session = $this->client($account)->checkout->sessions->create([
            'mode' => 'setup',
            'customer' => $customerId,
            'payment_method_types' => ['card'],
            'setup_intent_data' => ['metadata' => $metadata],
            'metadata' => $metadata,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => time() + 23 * 3600,
        ]);
        return ['id' => $session->id, 'url' => self::str($session->toArray(), 'url')];
    }

    public function createPresentIntent(StripeAccount $account, int $amountCents, string $description, array $metadata): string
    {
        return $this->client($account)->paymentIntents->create([
            'amount' => $amountCents,
            'currency' => 'usd',
            'payment_method_types' => ['card_present'],
            'capture_method' => 'automatic',
            'description' => $description,
            'metadata' => $metadata,
        ])->id;
    }

    public function collectOnReader(StripeAccount $account, string $readerId, string $paymentIntentId): void
    {
        // Collect first, confirm later, so the card's funding type is known before the charge is set.
        $this->client($account)->request('post', '/v1/terminal/readers/' . rawurlencode($readerId) . '/collect_payment_method', [
            'payment_intent' => $paymentIntentId,
        ], []);
    }

    public function confirmOnReader(StripeAccount $account, string $readerId, string $paymentIntentId): void
    {
        $this->client($account)->request('post', '/v1/terminal/readers/' . rawurlencode($readerId) . '/confirm_payment_intent', [
            'payment_intent' => $paymentIntentId,
        ], []);
    }

    public function cancelReader(StripeAccount $account, string $readerId): void
    {
        $this->client($account)->terminal->readers->cancelAction($readerId);
    }

    public function updateIntent(StripeAccount $account, string $paymentIntentId, int $amountCents, array $metadata): void
    {
        $this->client($account)->paymentIntents->update($paymentIntentId, ['amount' => $amountCents, 'metadata' => $metadata]);
    }

    public function intent(StripeAccount $account, string $paymentIntentId): IntentView
    {
        $intent = $this->client($account)->paymentIntents->retrieve($paymentIntentId, ['expand' => ['payment_method', 'latest_charge']]);
        return $this->view($intent);
    }

    public function chargeSavedCard(StripeAccount $account, string $customerId, string $paymentMethodId, int $amountCents, string $description, array $metadata): IntentView
    {
        try {
            $intent = $this->client($account)->paymentIntents->create([
                'amount' => $amountCents,
                'currency' => 'usd',
                'customer' => $customerId,
                'payment_method' => $paymentMethodId,
                'off_session' => true,
                'confirm' => true,
                'description' => $description,
                'metadata' => $metadata,
                'expand' => ['payment_method', 'latest_charge'],
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            return new IntentView('', 'failed', $amountCents, $metadata, '', '', '', '', $e->getError()->message ?? 'Card declined');
        }
        return $this->view($intent);
    }

    public function cardOf(StripeAccount $account, string $paymentMethodId): array
    {
        $method = $this->client($account)->paymentMethods->retrieve($paymentMethodId)->toArray();
        $card = self::arr($method, 'card');
        return ['funding' => self::str($card, 'funding') ?: 'unknown', 'brand' => self::str($card, 'brand'), 'last4' => self::str($card, 'last4')];
    }

    public function setDefaultPaymentMethod(StripeAccount $account, string $customerId, string $paymentMethodId): void
    {
        $this->client($account)->customers->update($customerId, ['invoice_settings' => ['default_payment_method' => $paymentMethodId]]);
    }

    public function setupIntentPaymentMethod(StripeAccount $account, string $setupIntentId): string
    {
        $data = $this->client($account)->setupIntents->retrieve($setupIntentId)->toArray();
        $method = $data['payment_method'] ?? null;
        return is_string($method) ? $method : self::str(self::arr($data, 'payment_method'), 'id');
    }

    public function refund(StripeAccount $account, string $paymentIntentId, int $amountCents): string
    {
        return $this->client($account)->refunds->create(['payment_intent' => $paymentIntentId, 'amount' => $amountCents])->id;
    }

    public function readers(StripeAccount $account): array
    {
        $params = ['limit' => 50];
        if ($account->terminalLocationId !== '') {
            $params['location'] = $account->terminalLocationId;
        }
        $out = [];
        foreach ($this->client($account)->terminal->readers->all($params)->data as $reader) {
            $row = $reader->toArray();
            $id = self::str($row, 'id');
            $label = self::str($row, 'label');
            $out[] = ['id' => $id, 'label' => $label !== '' ? $label : $id, 'status' => self::str($row, 'status')];
        }
        return $out;
    }

    public function verifyWebhook(StripeAccount $account, string $payload, string $signature): ?array
    {
        if ($account->webhookSecret === '') {
            return null;
        }
        try {
            $event = Webhook::constructEvent($payload, $signature, $account->webhookSecret);
        } catch (SignatureVerificationException | \UnexpectedValueException) {
            return null;
        }
        $data = $event->toArray();
        return [
            'id' => self::str($data, 'id'),
            'type' => self::str($data, 'type'),
            'object' => self::arr(self::arr($data, 'data'), 'object'),
        ];
    }

    public function ping(StripeAccount $account): void
    {
        $this->client($account)->balance->retrieve();
    }

    private function view(\Stripe\PaymentIntent $intent): IntentView
    {
        $data = $intent->toArray();
        $metadata = [];
        foreach (self::arr($data, 'metadata') as $key => $value) {
            if (is_string($value)) {
                $metadata[$key] = $value;
            }
        }
        $method = self::arr($data, 'payment_method');
        $card = self::arr($method, 'card') ?: self::arr($method, 'card_present');
        $charge = $data['latest_charge'] ?? null;
        return new IntentView(
            id: self::str($data, 'id'),
            status: self::str($data, 'status'),
            amountCents: is_int($data['amount'] ?? null) ? $data['amount'] : 0,
            metadata: $metadata,
            chargeId: is_string($charge) ? $charge : self::str(self::arr($data, 'latest_charge'), 'id'),
            funding: self::str($card, 'funding'),
            brand: self::str($card, 'brand'),
            last4: self::str($card, 'last4'),
            errorMessage: self::str(self::arr($data, 'last_payment_error'), 'message'),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private static function arr(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    private function client(StripeAccount $account): StripeClient
    {
        if (!$account->isConfigured()) {
            throw new \DomainException('Stripe account has no secret key');
        }
        return $this->clients[$account->key] ??= new StripeClient(['api_key' => $account->secretKey]);
    }
}
