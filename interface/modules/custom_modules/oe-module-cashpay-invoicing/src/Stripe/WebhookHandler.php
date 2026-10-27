<?php

/**
 * Handles Stripe webhooks. The signature is checked against each account's
 * signing secret; each event is handled once (cpi_stripe_event).
 *
 * - checkout.session.completed: posts a pay-link payment, or saves the card
 *   from a card-on-file link.
 * - payment_intent.succeeded: posts reader and autopay payments the screen
 *   did not post (for example, the browser was closed).
 * - payment_intent.payment_failed, charge.dispute.created: logged for staff.
 * - charge.refunded: refunds made in the Stripe dashboard are logged for
 *   staff to record; refunds made from the module are already recorded.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Stripe;

use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use Psr\Log\LoggerInterface;

final readonly class WebhookHandler
{
    public function __construct(
        private Database $db,
        private StripeAccountRepository $accounts,
        private StripeGateway $gateway,
        private StripePayments $stripe,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int HTTP status for Stripe: 200 handled or ignored, 400 bad signature
     */
    public function handle(string $payload, string $signature): int
    {
        $account = null;
        $event = null;
        foreach ($this->accounts->all() as $candidate) {
            $event = $this->gateway->verifyWebhook($candidate, $payload, $signature);
            if ($event !== null) {
                $account = $candidate;
                break;
            }
        }
        if ($account === null || $event === null) {
            $this->logger->warning('Stripe webhook with an unknown signature was rejected');
            return 400;
        }
        $this->db->execute(
            'INSERT IGNORE INTO `cpi_stripe_event` (`event_id`, `event_type`) VALUES (?, ?)',
            [$event['id'], mb_substr($event['type'], 0, 64)]
        );
        $seen = $this->db->fetchOne('SELECT `processed` FROM `cpi_stripe_event` WHERE `event_id` = ?', [$event['id']]);
        if ($seen !== null && in_array($seen['processed'] ?? null, [1, '1'], true)) {
            return 200;
        }
        $object = $event['object'];
        match ($event['type']) {
            'checkout.session.completed' => $this->checkoutCompleted($account, $object),
            'payment_intent.succeeded' => $this->intentSucceeded($account, $object),
            'payment_intent.payment_failed' => $this->logger->warning('Stripe payment failed', [
                'payment_intent' => self::str($object, 'id'),
                'pid' => self::metadata($object)['cpi_pid'] ?? '',
            ]),
            'charge.refunded' => $this->chargeRefunded($object),
            'charge.dispute.created' => $this->logger->warning('Stripe dispute opened: answer it in the Stripe dashboard', [
                'charge' => self::str($object, 'charge'),
                'amount_cents' => $object['amount'] ?? null,
            ]),
            default => null,
        };
        $this->db->execute('UPDATE `cpi_stripe_event` SET `processed` = 1 WHERE `event_id` = ?', [$event['id']]);
        return 200;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function checkoutCompleted(StripeAccount $account, array $session): void
    {
        $sessionId = self::str($session, 'id');
        if (self::str($session, 'mode') === 'setup') {
            $this->stripe->saveCardFromSetup($account, self::str($session, 'customer'), self::str($session, 'setup_intent'), $sessionId);
            return;
        }
        if (self::str($session, 'payment_status') !== 'paid') {
            return;
        }
        $intentId = self::str($session, 'payment_intent');
        if ($intentId === '') {
            return;
        }
        $paymentId = $this->stripe->post($account, $this->gateway->intent($account, $intentId), 'stripe_webhook');
        $this->stripe->closeRequest($sessionId, $paymentId);
    }

    /**
     * @param array<string, mixed> $intent
     */
    private function intentSucceeded(StripeAccount $account, array $intent): void
    {
        $metadata = self::metadata($intent);
        if (($metadata['cpi_pid'] ?? '') === '') {
            return;
        }
        $this->stripe->post($account, $this->gateway->intent($account, self::str($intent, 'id')), 'stripe_webhook');
    }

    /**
     * @param array<string, mixed> $charge
     */
    private function chargeRefunded(array $charge): void
    {
        $intent = self::str($charge, 'payment_intent');
        $recorded = $this->db->fetchOne(
            "SELECT COUNT(*) AS `n` FROM `cpi_payment` WHERE `stripe_payment_intent` = ? AND `pay_type` = 'refund'",
            [$intent]
        );
        if ($recorded === null || in_array($recorded['n'] ?? null, [0, '0'], true)) {
            $this->logger->warning('A refund was made in the Stripe dashboard. Record it on the patient Payments page.', [
                'payment_intent' => $intent,
                'amount_refunded_cents' => $charge['amount_refunded'] ?? null,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $object
     * @return array<string, string>
     */
    private static function metadata(array $object): array
    {
        $out = [];
        foreach (is_array($object['metadata'] ?? null) ? $object['metadata'] : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function str(array $object, string $key): string
    {
        $value = $object[$key] ?? '';
        return is_string($value) ? $value : '';
    }
}
