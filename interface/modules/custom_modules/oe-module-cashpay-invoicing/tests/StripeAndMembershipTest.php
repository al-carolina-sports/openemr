<?php

/**
 * Stripe flows and membership billing against fakes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests;

use OpenEMR\Modules\CashpayInvoicing\Billing\Document;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentKind;
use OpenEMR\Modules\CashpayInvoicing\Membership\BillingCycle;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Payments\PaymentService;
use OpenEMR\Modules\CashpayInvoicing\Rewards\GiftCardService;
use OpenEMR\Modules\CashpayInvoicing\Rewards\RewardsService;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;
use OpenEMR\Modules\CashpayInvoicing\Stripe\IntentView;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripeAccount;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripeAccountRepository;
use OpenEMR\Modules\CashpayInvoicing\Stripe\StripePayments;
use OpenEMR\Modules\CashpayInvoicing\Stripe\WebhookHandler;
use OpenEMR\Modules\CashpayInvoicing\Tests\Support\FakeCrypto;
use OpenEMR\Modules\CashpayInvoicing\Tests\Support\FakeDatabase;
use OpenEMR\Modules\CashpayInvoicing\Tests\Support\FakeGateway;
use OpenEMR\Modules\CashpayInvoicing\Tests\Support\Holder;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

final class StripeAndMembershipTest extends TestCase
{
    public function testBillingCycleKeepsMonthEnds(): void
    {
        $start = new \DateTimeImmutable('2026-01-31');
        $this->assertSame('2026-02-28', BillingCycle::Monthly->periodStart($start, 1)->format('Y-m-d'));
        $this->assertSame('2026-03-31', BillingCycle::Monthly->periodStart($start, 2)->format('Y-m-d'));
        $this->assertSame('2027-01-31', BillingCycle::Monthly->periodStart($start, 12)->format('Y-m-d'));
        $this->assertSame('2028-02-29', BillingCycle::Annual->periodStart(new \DateTimeImmutable('2024-02-29'), 4)->format('Y-m-d'));
        $this->assertSame('2025-02-28', BillingCycle::Annual->periodStart(new \DateTimeImmutable('2024-02-29'), 1)->format('Y-m-d'));
    }

    public function testParseAllocations(): void
    {
        $parsed = StripePayments::parseAllocations('5:10000, 7:250,junk,8:-1');
        $this->assertSame([5, 7], array_keys($parsed));
        $this->assertSame(10000, $parsed[5]->cents);
        $this->assertSame(250, $parsed[7]->cents);
    }

    public function testAccountsAreEncryptedAndPickedByOffice(): void
    {
        $store = new Holder();
        $db = new FakeDatabase(static function (string $sql) use ($store): array {
            return str_contains($sql, 'FROM `cpi_settings`') && $store->value !== null ? [['setting_value' => $store->value]] : [];
        });
        $repo = new StripeAccountRepository($db, new FakeCrypto());
        $repo->save([
            new StripeAccount('bfm', 'BFM', false, 'pk_test_a', 'sk_test_a', 'whsec_a', '', [1], false),
            new StripeAccount('trm', 'TRM', false, 'pk_test_b', 'sk_test_b', '', '', [2], true),
        ], 1);
        $stored = $db->statements[0][1][1];
        $this->assertIsString($stored);
        $store->value = $stored;
        $this->assertStringNotContainsString('sk_test_a', $stored, 'secret keys are encrypted');
        $this->assertSame('bfm', $repo->forFacility(1)?->key);
        $this->assertSame('trm', $repo->forFacility(2)?->key);
        $this->assertSame('trm', $repo->forFacility(9)?->key, 'unlisted office uses the default');
        $this->assertSame('sk_test_a', $repo->byKey('bfm')?->secretKey);

        // A blank secret on save keeps the stored one.
        $repo->save([new StripeAccount('bfm', 'BFM', false, 'pk_test_a', '', '', '', [1], true)], 1);
        $next = $db->statements[1][1][1];
        $this->assertIsString($next);
        $store->value = $next;
        $this->assertSame('sk_test_a', $repo->byKey('bfm')?->secretKey);
        $this->assertFalse((new StripeAccount('x', 'X', true, '', 'sk_test_1', '', '', [], false))->keyMatchesMode());
    }

    /**
     * @return array{StripePayments, FakeGateway, FakeDatabase}
     */
    private static function stripe(string $fundingSetting = 'true'): array
    {
        $crypto = new FakeCrypto();
        $accounts = json_encode([[
            'key' => 'trm', 'label' => 'TRM', 'live' => false, 'publishable_key' => 'pk_test_1',
            'secret_key' => $crypto->encryptForDatabase('sk_test_1'), 'webhook_secret' => $crypto->encryptForDatabase('whsec_good'),
            'terminal_location_id' => '', 'facility_ids' => [1], 'default' => true,
        ]], JSON_THROW_ON_ERROR);
        $db = new FakeDatabase(static fn (string $sql, array $binds): array => match (true) {
            str_contains($sql, '`setting_value` FROM `cpi_settings` WHERE `setting_key` = ?') => [['setting_value' => $accounts]],
            str_contains($sql, 'FROM `cpi_settings`') => [
                ['setting_key' => 'card_fee_enabled', 'setting_value' => $fundingSetting],
                ['setting_key' => 'card_fee_bp', 'setting_value' => '300'],
            ],
            str_contains($sql, '`stripe_customer_id` FROM `cpi_stripe_customer`') => [['stripe_customer_id' => 'cus_42']],
            str_contains($sql, '`default_payment_method` FROM `cpi_stripe_customer`') => [['stripe_customer_id' => 'cus_42', 'default_payment_method' => 'pm_1']],
            str_contains($sql, 'FROM `cpi_invoice` i WHERE i.`pid`') => [['id' => '5', 'invoice_date' => '2026-10-27', 'name' => 'Core', 'total' => '149.00', 'paid' => '0']],
            str_contains($sql, 'FROM `cpi_payment_method` WHERE `kind`') => [['id' => '3']],
            str_contains($sql, '`award_points_eligible` = 1 THEN') => [['eligible' => '0', 'total' => '149.00']],
            default => [],
        });
        $gateway = new FakeGateway();
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-10-27 10:00:00');
            }
        };
        $settings = new SettingsRepository($db);
        $stripe = new StripePayments($db, new StripeAccountRepository($db, new FakeCrypto()), $gateway, $settings, $clock, 'https://emr/done.php');
        $payments = new PaymentService($db, $settings, new RewardsService($db), new GiftCardService($db, static fn (int $m): int => 0), $stripe);
        $stripe->setPaymentService($payments);
        return [$stripe, $gateway, $db];
    }

    public function testReaderAddsChargeForCreditCardsOnlyAndConfirmsOnce(): void
    {
        [$stripe, $gateway] = self::stripe();
        $intentId = $stripe->startReaderPayment(42, 'tmr_1', Money::parse('100'), [5 => Money::parse('100')], 1);
        $this->assertSame(['collectOnReader', ['tmr_1', 'pi_1']], $gateway->calls[1]);
        $metadata = $gateway->intents[$intentId]->metadata;
        $this->assertSame('5:10000', $metadata['cpi_alloc']);
        $this->assertSame('42', $metadata['cpi_pid']);

        // The card has been read: a credit card.
        $gateway->intents[$intentId] = new IntentView($intentId, 'requires_confirmation', 10000, $metadata, '', 'credit', 'visa', '4242', '');
        $status = $stripe->readerStatus(42, 1, $intentId);
        $this->assertSame('processing', $status['state']);
        $this->assertSame('$3.00', $status['fee']);
        $this->assertSame(['updateIntent', ['pi_1', 10300, array_merge($metadata, ['cpi_fee_cents' => '300', 'cpi_confirmed' => '1'])]], array_values(array_filter($gateway->calls, static fn (array $c): bool => $c[0] === 'updateIntent'))[0]);

        $stripe->readerStatus(42, 1, $intentId);
        $this->assertCount(1, array_filter($gateway->calls, static fn (array $c): bool => $c[0] === 'confirmOnReader'), 'confirmed once');
    }

    public function testReaderDebitCardHasNoCharge(): void
    {
        [$stripe, $gateway] = self::stripe();
        $intentId = $stripe->startReaderPayment(42, 'tmr_1', Money::parse('100'), [], 1);
        $gateway->intents[$intentId] = new IntentView($intentId, 'requires_confirmation', 10000, $gateway->intents[$intentId]->metadata, '', 'debit', 'visa', '4242', '');
        $this->assertSame('', $stripe->readerStatus(42, 1, $intentId)['fee']);
        $this->assertSame(10000, $gateway->intents[$intentId]->amountCents);
        $this->assertSame('0', $gateway->intents[$intentId]->metadata['cpi_fee_cents']);
    }

    public function testReaderRefusesAnotherPatientsIntent(): void
    {
        [$stripe] = self::stripe();
        $intentId = $stripe->startReaderPayment(42, 'tmr_1', Money::parse('100'), [], 1);
        $this->expectException(\OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException::class);
        $stripe->readerStatus(43, 1, $intentId);
    }

    public function testSucceededIntentPostsPaymentWithoutTheCharge(): void
    {
        [$stripe, $gateway, $db] = self::stripe();
        $intent = new IntentView('pi_9', 'succeeded', 10300, ['cpi_pid' => '42', 'cpi_alloc' => '5:10000', 'cpi_fee_cents' => '300', 'cpi_facility' => '1'], 'ch_9', 'credit', 'visa', '4242', '');
        $account = new StripeAccount('trm', 'TRM', false, '', 'sk_test_1', '', '', [], true);
        $stripe->post($account, $intent, 'stripe_webhook');
        $payment = $db->inserts('cpi_payment')[0][1];
        $this->assertSame('100.00', $payment[3], 'payment excludes the card charge');
        $this->assertSame('3.00', $payment[5]);
        $this->assertSame('trm', $payment[6]);
        $this->assertSame('pi_9', $payment[7]);
        $this->assertSame('credit', $payment[11]);
        $this->assertSame([1, 5, '100.00'], $db->inserts('cpi_payment_alloc')[0][1]);
    }

    public function testAutopayChargesSavedCardWithCharge(): void
    {
        [$stripe, $gateway, $db] = self::stripe();
        $invoice = Document::fromRow(DocumentKind::Invoice, ['id' => '5', 'pid' => '42', 'name' => 'Core', 'invoice_date' => '2026-10-27', 'invoice_type' => 'membership', 'income_facility_id' => '1']);
        $this->assertNull($stripe->chargeInvoice($invoice));
        $charge = array_values(array_filter($gateway->calls, static fn (array $c): bool => $c[0] === 'chargeSavedCard'))[0][1];
        $this->assertSame(['cus_42', 'pm_1', 15347], array_slice($charge, 0, 3), '$149 plus 3% = $153.47');
        $this->assertSame('Invoice #5', $charge[3], 'only the invoice number goes to Stripe');
        $this->assertSame('149.00', $db->inserts('cpi_payment')[0][1][3]);

        $gateway->savedCardResult = new IntentView('', 'failed', 15347, [], '', '', '', '', 'Your card was declined.');
        $this->assertSame('Your card was declined.', $stripe->chargeInvoice($invoice));
    }

    public function testPayLinkCarriesOnlyInvoiceNumbersAndAmount(): void
    {
        [$stripe, $gateway, $db] = self::stripe();
        $link = $stripe->requestPayment(42, Money::parse('149'), [5], 1, 9);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_1', $link['url']);
        $call = $gateway->calls[0];
        $this->assertSame('checkoutForPayment', $call[0]);
        $this->assertSame(['cus_42', 14900, 'Invoice #5'], array_slice($call[1], 0, 3));
        $metadata = $call[1][3];
        $this->assertIsArray($metadata);
        $this->assertSame('0', $metadata['cpi_fee_cents'] ?? null, 'pay links carry no card charge');
        $this->assertCount(1, $db->inserts('cpi_pay_request'));
    }

    public function testRefundGoesBackThroughStripe(): void
    {
        [$stripe, $gateway] = self::stripe();
        $this->assertSame('re_1', $stripe->refundPayment('trm', 'pi_9', Money::parse('51.50')));
        $this->assertSame(['refund', ['pi_9', 5150]], $gateway->calls[0]);
    }

    public function testWebhookRejectsBadSignatureAndHandlesEventsOnce(): void
    {
        [$stripe, $gateway] = self::stripe();
        $processed = new Holder();
        $db = new FakeDatabase(static function (string $sql, array $binds) use ($processed): array {
            if (str_contains($sql, 'FROM `cpi_settings` WHERE `setting_key`')) {
                $crypto = new FakeCrypto();
                return [['setting_value' => json_encode([[
                    'key' => 'trm', 'secret_key' => $crypto->encryptForDatabase('sk_test_1'), 'webhook_secret' => $crypto->encryptForDatabase('whsec_good'), 'default' => true,
                ]], JSON_THROW_ON_ERROR)]];
            }
            if (str_contains($sql, '`processed` FROM `cpi_stripe_event`')) {
                return [['processed' => in_array($binds[0], $processed->items, true) ? '1' : '0']];
            }
            return [];
        });
        $handler = new WebhookHandler($db, new StripeAccountRepository($db, new FakeCrypto()), $gateway, $stripe, new NullLogger());
        $this->assertSame(400, $handler->handle('{}', 'sig_unknown'));

        $gateway->events['sig_setup'] = ['id' => 'evt_1', 'type' => 'checkout.session.completed', 'object' => [
            'id' => 'cs_setup', 'mode' => 'setup', 'customer' => 'cus_42', 'setup_intent' => 'seti_1',
        ]];
        $this->assertSame(200, $handler->handle('{}', 'sig_setup'));
        $this->assertContains(['setDefaultPaymentMethod', ['cus_42', 'pm_saved']], $gateway->calls);
        $processed->items[] = 'evt_1';
        $before = count($gateway->calls);
        $this->assertSame(200, $handler->handle('{}', 'sig_setup'));
        $this->assertCount($before, $gateway->calls, 'a repeated event does nothing');
    }

    public function testBillingAPeriodMakesTheInvoiceAndMovesTheDate(): void
    {
        $db = new FakeDatabase(static fn (string $sql): array => match (true) {
            str_contains($sql, 'FROM `cpi_patient_membership` m JOIN `cpi_membership_plan` p ON p.`id` = m.`plan_id` WHERE m.`id` = ?') => [[
                'id' => '3', 'pid' => '42', 'plan_id' => '4', 'billing_cycle' => 'monthly', 'start_date' => '2026-01-31', 'status' => 'active',
                'next_bill_date' => '2026-03-31', 'facility_id' => '1', 'created_by' => '9', 'plan_name' => 'Core', 'monthly_price' => '149.00',
                'annual_price' => '1788.00', 'monthly_points' => '0.00', 'package_id' => null, 'add_package_monthly' => '0',
                'auto_monthly_invoice' => '1', 'tax1' => '0', 'tax2' => '0',
            ]],
            str_contains($sql, 'SELECT * FROM `cpi_invoice` WHERE `id` = ? AND `pid` = ?') => [[
                'id' => '1', 'pid' => '42', 'name' => 'Core membership Mar 2026', 'invoice_date' => '2026-03-31', 'invoice_type' => 'membership', 'income_facility_id' => '1',
            ]],
            str_contains($sql, 'COUNT(*) AS `n` FROM `cpi_invoice` WHERE `membership_id`') => [['n' => '3']],
            str_contains($sql, 'MAX(`sort_order`)') => [['n' => '10']],
            default => [],
        });
        $documents = new \OpenEMR\Modules\CashpayInvoicing\Billing\DocumentRepository($db);
        $settings = new SettingsRepository($db);
        $delivery = new \OpenEMR\Modules\CashpayInvoicing\Billing\DeliveryService($db, $documents, new \OpenEMR\Modules\CashpayInvoicing\Inventory\StockService($db));
        $lines = new \OpenEMR\Modules\CashpayInvoicing\Billing\LineService($db, $documents, new \OpenEMR\Modules\CashpayInvoicing\Pricing\CatalogLookup($db), new \OpenEMR\Modules\CashpayInvoicing\Pricing\PromoRepository($db), $settings, $delivery);
        $service = new \OpenEMR\Modules\CashpayInvoicing\Membership\MembershipService(
            $db,
            new \OpenEMR\Modules\CashpayInvoicing\Billing\DocumentService($db, $documents, $lines, $delivery, $settings),
            $lines,
            new RewardsService($db),
            $settings,
        );
        $invoice = $service->billPeriod(3, null, 0, false);
        $this->assertNotNull($invoice);
        $invoiceInsert = $db->inserts('cpi_invoice')[0][1];
        $this->assertSame('Core membership Mar 2026', $invoiceInsert[1]);
        $this->assertSame('membership', $invoiceInsert[3]);
        $this->assertSame(1, $invoiceInsert[4], 'membership office');
        $line = $db->inserts('cpi_line')[0][1];
        $this->assertSame('149.00', $line[4]);
        $next = array_values(array_filter($db->statements, static fn (array $s): bool => str_contains($s[0], 'SET `next_bill_date`')))[0][1];
        $this->assertSame(['2026-04-30', 3], $next, 'third period from Jan 31 is Apr 30');
    }
}
