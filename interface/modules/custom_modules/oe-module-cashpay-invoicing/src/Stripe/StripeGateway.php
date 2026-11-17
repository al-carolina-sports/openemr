<?php

/**
 * The Stripe calls the module makes. Only invoice numbers and amounts go to
 * Stripe: no procedure names, diagnoses or other clinical detail.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Stripe;

interface StripeGateway
{
    public function createCustomer(StripeAccount $account, string $name, string $email, int $pid): string;

    /**
     * @param array<string, string> $metadata
     * @return array{id: string, url: string}
     */
    public function checkoutForPayment(StripeAccount $account, ?string $customerId, int $amountCents, string $description, array $metadata, string $successUrl, string $cancelUrl): array;

    /**
     * @param array<string, string> $metadata
     * @return array{id: string, url: string}
     */
    public function checkoutForSetup(StripeAccount $account, string $customerId, array $metadata, string $successUrl, string $cancelUrl): array;

    /**
     * @param array<string, string> $metadata
     * @return string the PaymentIntent id
     */
    public function createPresentIntent(StripeAccount $account, int $amountCents, string $description, array $metadata): string;

    public function collectOnReader(StripeAccount $account, string $readerId, string $paymentIntentId): void;

    public function confirmOnReader(StripeAccount $account, string $readerId, string $paymentIntentId): void;

    public function cancelReader(StripeAccount $account, string $readerId): void;

    /**
     * @param array<string, string> $metadata
     */
    public function updateIntent(StripeAccount $account, string $paymentIntentId, int $amountCents, array $metadata): void;

    public function intent(StripeAccount $account, string $paymentIntentId): IntentView;

    /**
     * Charges a saved card with the customer not present (membership autopay).
     *
     * @param array<string, string> $metadata
     */
    public function chargeSavedCard(StripeAccount $account, string $customerId, string $paymentMethodId, int $amountCents, string $description, array $metadata): IntentView;

    /**
     * @return array{funding: string, brand: string, last4: string}
     */
    public function cardOf(StripeAccount $account, string $paymentMethodId): array;

    public function setDefaultPaymentMethod(StripeAccount $account, string $customerId, string $paymentMethodId): void;

    public function setupIntentPaymentMethod(StripeAccount $account, string $setupIntentId): string;

    /**
     * @return string the refund id
     */
    public function refund(StripeAccount $account, string $paymentIntentId, int $amountCents): string;

    /**
     * @return list<array{id: string, label: string, status: string}>
     */
    public function readers(StripeAccount $account): array;

    /**
     * Verifies a webhook and returns the event, or null if the signature does not match this account.
     *
     * @return array{id: string, type: string, object: array<string, mixed>}|null
     */
    public function verifyWebhook(StripeAccount $account, string $payload, string $signature): ?array;

    /** Throws if the key is not accepted. */
    public function ping(StripeAccount $account): void;
}
