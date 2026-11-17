<?php

/**
 * Payments, refunds, points, gift cards, notes and the cash drawer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests;

use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Patient\FinancialNotes;
use OpenEMR\Modules\CashpayInvoicing\Payments\CashDrawerService;
use OpenEMR\Modules\CashpayInvoicing\Payments\MethodKind;
use OpenEMR\Modules\CashpayInvoicing\Payments\PaymentInput;
use OpenEMR\Modules\CashpayInvoicing\Payments\PaymentService;
use OpenEMR\Modules\CashpayInvoicing\Payments\PayType;
use OpenEMR\Modules\CashpayInvoicing\Rewards\GiftCardService;
use OpenEMR\Modules\CashpayInvoicing\Rewards\PointsMath;
use OpenEMR\Modules\CashpayInvoicing\Rewards\RewardsService;
use OpenEMR\Modules\CashpayInvoicing\Settings\CardFunding;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;
use OpenEMR\Modules\CashpayInvoicing\Tests\Support\FakeDatabase;
use PHPUnit\Framework\TestCase;

final class PaymentsTest extends TestCase
{
    public function testPointsMath(): void
    {
        // $100 applied to a $120 invoice whose eligible net is $90: 75% earns.
        $this->assertSame(7500, PointsMath::eligiblePaid(Money::parse('100'), Money::parse('90'), Money::parse('120'))->cents);
        $this->assertSame(0, PointsMath::eligiblePaid(Money::parse('100'), Money::zero(), Money::parse('120'))->cents);
        $this->assertSame(5000, PointsMath::earned(Money::parse('50'), 100), '1 point per $1');
        $this->assertSame(2500, PointsMath::earned(Money::parse('50'), 50), 'half a point per $1');
        $this->assertSame(5000, PointsMath::neededFor(Money::parse('50'), Money::parse('1')), '$50 at $1 a point');
        $this->assertSame(334, PointsMath::neededFor(Money::parse('0.10'), Money::parse('0.03')), 'rounded up');
        $this->assertSame(5000, PointsMath::valueOf(5000, Money::parse('1'))->cents);
        $this->assertSame(2500, PointsMath::proportion(5000, Money::parse('25'), Money::parse('50')));
        $this->expectException(\DomainException::class);
        PointsMath::neededFor(Money::parse('1'), Money::zero());
    }

    public function testOnlyPointsAndGiftCardsDoNotEarn(): void
    {
        $this->assertFalse(MethodKind::Points->earnsPoints());
        $this->assertFalse(MethodKind::GiftCard->earnsPoints());
        $this->assertTrue(MethodKind::Cash->earnsPoints());
        $this->assertTrue(MethodKind::Card->earnsPoints());
    }

    /**
     * A database with one open $120 invoice (#5, $90 eligible net), the
     * given method, and the given settings.
     *
     * @param array<string, string> $settings JSON-encoded settings values
     */
    private static function db(string $kind, array $settings = [], bool $enrolled = true, string $points = '0'): FakeDatabase
    {
        return new FakeDatabase(static function (string $sql) use ($kind, $settings, $enrolled, $points): array {
            return match (true) {
                str_contains($sql, 'FROM `cpi_payment_method` WHERE `id`') => [['id' => '3', 'name' => 'M', 'kind' => $kind, 'is_refund_method' => '0', 'active' => '1']],
                str_contains($sql, 'FROM `cpi_invoice` i WHERE i.`pid`') => [['id' => '5', 'invoice_date' => '2026-10-01', 'name' => 'Visit', 'total' => '120.00', 'paid' => '0.00']],
                str_contains($sql, 'FROM `cpi_settings`') => array_map(static fn (string $k, string $v): array => ['setting_key' => $k, 'setting_value' => $v], array_keys($settings), $settings),
                str_contains($sql, '`award_points_eligible` = 1 THEN') => [['eligible' => '90.00', 'total' => '120.00']],
                str_contains($sql, 'FROM `cpi_patient_rewards`') => [['enrolled' => $enrolled ? '1' : '0']],
                str_contains($sql, 'SUM(`points`)') => [['p' => $points]],
                str_contains($sql, "SELECT `invoice_type` FROM `cpi_invoice`") => [['invoice_type' => 'immediate']],
                default => [],
            };
        });
    }

    private static function service(FakeDatabase $db): PaymentService
    {
        return new PaymentService($db, new SettingsRepository($db), new RewardsService($db), new GiftCardService($db, static fn (int $max): int => 0));
    }

    /**
     * @param array<int, string> $alloc
     */
    private static function input(string $amount, array $alloc = [5 => '100'], ?CardFunding $funding = null, string $tip = '0'): PaymentInput
    {
        return new PaymentInput(
            PayType::Payment,
            new \DateTimeImmutable('2026-10-27'),
            3,
            Money::parse($amount),
            Money::parse($tip),
            1,
            '',
            '',
            self::moneyMap($alloc),
            $funding,
        );
    }

    /**
     * @param array<int, string> $alloc
     * @return array<int, Money>
     */
    private static function moneyMap(array $alloc): array
    {
        $out = [];
        foreach ($alloc as $invoiceId => $amount) {
            $out[$invoiceId] = Money::parse($amount);
        }
        return $out;
    }

    public function testCashPaymentAllocatesAndEarnsPoints(): void
    {
        $db = self::db('cash', ['rewards_enabled' => 'true', 'points_per_dollar_x100' => '100', 'point_value_cents' => '100']);
        self::service($db)->apply(42, self::input('150'), 9);
        $payment = $db->inserts('cpi_payment')[0][1];
        $this->assertSame('150.00', $payment[4]);
        $this->assertSame('0.00', $payment[7], 'no card charge on cash');
        $alloc = $db->inserts('cpi_payment_alloc');
        $this->assertSame([1, 5, '100.00'], $alloc[0][1]);
        $points = $db->inserts('cpi_points_ledger');
        $this->assertCount(1, $points);
        $this->assertSame('75.00', $points[0][1][3], '$100 x 90/120 eligible = $75 = 75 points');
        $this->assertSame(1, $db->transactions);
    }

    public function testNoPointsWhenNotEnrolledOrProgramOff(): void
    {
        $db = self::db('cash', ['rewards_enabled' => 'true'], false);
        self::service($db)->apply(42, self::input('100'), 9);
        $this->assertSame([], $db->inserts('cpi_points_ledger'));
        $db = self::db('cash', ['rewards_enabled' => 'false'], true);
        self::service($db)->apply(42, self::input('100'), 9);
        $this->assertSame([], $db->inserts('cpi_points_ledger'));
    }

    public function testCardChargeOnlyOnCreditCards(): void
    {
        $settings = ['card_fee_enabled' => 'true', 'card_fee_bp' => '300'];
        $db = self::db('card', $settings);
        self::service($db)->apply(42, self::input('100', [5 => '100'], CardFunding::Credit, '10'), 9);
        $payment = $db->inserts('cpi_payment')[0][1];
        $this->assertSame('3.0000', $payment[6]);
        $this->assertSame('3.30', $payment[7], '3% of amount plus gratuity');
        $this->assertSame('credit', $payment[8]);
        $this->assertSame('100.00', $db->inserts('cpi_payment_alloc')[0][1][2], 'the charge is not applied to the invoice');

        foreach ([CardFunding::Debit, CardFunding::Prepaid] as $funding) {
            $db = self::db('card', $settings);
            self::service($db)->apply(42, self::input('100', [5 => '100'], $funding), 9);
            $this->assertSame('0.00', $db->inserts('cpi_payment')[0][1][7], $funding->value);
        }
    }

    public function testCardPaymentNeedsFundingType(): void
    {
        $this->expectException(ValidationException::class);
        self::service(self::db('card'))->apply(42, self::input('100'), 9);
    }

    public function testCannotOverApply(): void
    {
        try {
            self::service(self::db('cash'))->apply(42, self::input('200', [5 => '130']), 9);
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('alloc_5', $e->errors);
        }
        try {
            self::service(self::db('cash'))->apply(42, self::input('50', [5 => '100']), 9);
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors);
        }
        try {
            self::service(self::db('cash'))->apply(42, self::input('50', [6 => '10']), 9);
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('alloc_6', $e->errors, 'invoice not open for this patient');
        }
    }

    public function testPayingWithPointsUsesPointsAndEarnsNothing(): void
    {
        $db = self::db('points', ['rewards_enabled' => 'true', 'points_per_dollar_x100' => '100', 'point_value_cents' => '100'], true, '80.00');
        self::service($db)->apply(42, self::input('50', [5 => '50']), 9);
        $ledger = $db->inserts('cpi_points_ledger');
        $this->assertCount(1, $ledger);
        $this->assertSame('-50.00', $ledger[0][1][3]);
        $this->assertSame('redeem', $ledger[0][1][5]);

        $this->expectException(ValidationException::class);
        self::service(self::db('points', ['rewards_enabled' => 'true', 'point_value_cents' => '100'], true, '20.00'))->apply(42, self::input('50', [5 => '50']), 9);
    }

    public function testRefundTakesUnappliedCreditFirstThenNewestInvoice(): void
    {
        $db = new FakeDatabase(static function (string $sql): array {
            return match (true) {
                str_contains($sql, "p.`pay_type` IN ('payment', 'deposit')") && str_contains($sql, 'p.`id` = ?') => [[
                    'id' => '1', 'pid' => '42', 'amount' => '150.00', 'card_fee_amt' => '4.50', 'kind' => 'card', 'facility_id' => '1',
                ]],
                str_contains($sql, 'FROM `cpi_payment_method` WHERE `id`') => [['id' => '8', 'name' => 'Refund to Card', 'kind' => 'card', 'is_refund_method' => '1', 'active' => '1']],
                str_contains($sql, 'SELECT `amount` FROM `cpi_payment` WHERE `id`') => [['amount' => '150.00']],
                str_contains($sql, 'FROM `cpi_payment_alloc` WHERE `payment_id` = ? ORDER BY `invoice_id` DESC') => [
                    ['invoice_id' => '7', 'amount' => '60.00'],
                    ['invoice_id' => '5', 'amount' => '40.00'],
                ],
                str_contains($sql, 'SUM(`points`), SUM') || str_contains($sql, "`source` IN ('payment', 'redeem')") => [['source' => 'payment', 'p' => '100.00', 'd' => '100.00']],
                default => [],
            };
        });
        self::service($db)->refund(42, 1, Money::parse('90'), 8, 'Changed mind', new \DateTimeImmutable('2026-10-27'), 9);
        $changes = array_values(array_filter($db->statements, static fn (array $s): bool => str_contains($s[0], 'cpi_payment_alloc')));
        // $50 unapplied credit covers the first $50; the other $40 comes off invoice #7 (newest).
        $this->assertStringStartsWith('UPDATE `cpi_payment_alloc`', $changes[0][0]);
        $this->assertSame(['20.00', 1, 7], $changes[0][1]);
        $this->assertCount(1, $changes);
        $refund = $db->inserts('cpi_payment')[0][1];
        $this->assertSame('90.00', $refund[3]);
        $this->assertSame('2.70', $refund[4], 'card charge refunded in proportion: 4.50 x 90/150');
        $points = $db->inserts('cpi_points_ledger')[0][1];
        $this->assertSame('-60.00', $points[3], 'points taken back in proportion: 100 x 90/150');
    }

    public function testRefundNeedsAReasonAndLimit(): void
    {
        $db = new FakeDatabase(static fn (string $sql): array => match (true) {
            str_contains($sql, "p.`pay_type` IN ('payment', 'deposit')") => [['id' => '1', 'pid' => '42', 'amount' => '50.00', 'card_fee_amt' => '0', 'kind' => 'cash']],
            str_contains($sql, 'FROM `cpi_payment_method` WHERE `id`') => [['id' => '8', 'name' => 'Refund Cash', 'kind' => 'cash', 'is_refund_method' => '1', 'active' => '1']],
            str_contains($sql, "`refund_of_payment_id` = ? AND `pay_type` = 'refund'") => [['r' => '30.00']],
            default => [],
        });
        try {
            self::service($db)->refund(42, 1, Money::parse('25'), 8, 'x', new \DateTimeImmutable('2026-10-27'), 9);
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertSame('Enter up to $20.00', $e->errors['amount']);
        }
        $this->expectException(ValidationException::class);
        self::service($db)->refund(42, 1, Money::parse('5'), 8, ' ', new \DateTimeImmutable('2026-10-27'), 9);
    }

    public function testVoidRefusesRefundsAndRefundedPayments(): void
    {
        $refund = new FakeDatabase(static fn (string $sql): array => str_contains($sql, "p.`status` = 'posted'") ? [['id' => '2', 'pid' => '42', 'pay_type' => 'refund', 'amount' => '5.00']] : []);
        try {
            self::service($refund)->void(42, 2, 'oops', new \DateTimeImmutable('2026-10-27'), 9);
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Refunds cannot be voided', $e->errors['payment']);
        }
        $paid = new FakeDatabase(static fn (string $sql): array => match (true) {
            str_contains($sql, "p.`status` = 'posted'") => [['id' => '1', 'pid' => '42', 'pay_type' => 'payment', 'amount' => '50.00', 'kind' => 'gift_card', 'comments' => '']],
            str_contains($sql, 'SUM(`amount`), 0) AS `r`') => [['r' => '0']],
            str_contains($sql, "`action` = 'redeem' LIMIT 1") => [['gift_card_id' => '4']],
            default => [],
        });
        self::service($paid)->void(42, 1, 'Wrong patient', new \DateTimeImmutable('2026-10-27'), 9);
        $statements = array_column($paid->statements, 0);
        $this->assertStringStartsWith("UPDATE `cpi_payment` SET `status` = 'void'", $statements[0]);
        $this->assertContains('UPDATE `cpi_gift_card` SET `balance` = `balance` + ? WHERE `id` = ?', $statements, 'gift card value goes back');
    }

    public function testGiftCardCodesAndIssue(): void
    {
        $db = new FakeDatabase(static fn (string $sql): array => str_contains($sql, "`item_type` = 'gift_card'")
            ? [['id' => '11', 'net' => '100.00', 'description' => 'Gift card for Jane Doe']] : []);
        $sequence = [3, 1, 4, 1, 5, 9, 2, 6, 5, 3, 5, 8];
        $i = 0;
        $service = new GiftCardService($db, static function (int $max) use (&$sequence, &$i): int {
            return $sequence[$i++ % count($sequence)] % ($max + 1);
        });
        $codes = $service->issueForPaidInvoice(9, 42, new \DateTimeImmutable('2026-10-27'), 1);
        $this->assertCount(1, $codes);
        $this->assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/', $codes[0]);
        $card = $db->inserts('cpi_gift_card')[0][1];
        $this->assertSame('Jane Doe', $card[2]);
        $this->assertSame('100.00', $card[3]);
        $this->assertSame('ab12cd34', strtolower(GiftCardService::normalizeCode('ab-12 cd34')));
    }

    public function testGiftCardRedeemChecksBalance(): void
    {
        $db = new FakeDatabase(static fn (): array => [['id' => '4', 'code' => 'AAAA-BBBB-CCCC', 'balance' => '20.00', 'active' => '1']]);
        $service = new GiftCardService($db, static fn (int $max): int => 0);
        $this->expectException(ValidationException::class);
        $service->redeem('aaaa bbbb cccc', Money::parse('25'), 42, new \DateTimeImmutable('2026-10-27'), 1);
    }

    public function testFinancialNotesAreSanitized(): void
    {
        $clean = FinancialNotes::clean('<p onclick="x()">Paid <b>half</b></p><script>alert(1)</script><a href="javascript:x()">l</a><img src=x>');
        $this->assertStringContainsString('<b>half</b>', $clean);
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript', $clean);
        $this->assertStringNotContainsString('<img', $clean);
    }

    public function testCashDrawerMath(): void
    {
        $db = new FakeDatabase(static fn (string $sql): array => match (true) {
            str_contains($sql, 'FROM `cpi_cash_drawer` d') => [['id' => '1', 'opening_balance' => '200.00', 'adjustment' => '-5.00', 'closing_balance' => '400.00', 'opened_name' => 'a', 'closed_name' => 'b', 'opened_at' => 'x', 'closed_at' => 'y']],
            str_contains($sql, "m.`kind` = 'cash'") => [
                ['pay_type' => 'payment', 'amount' => '150.00', 'gratuity' => '10.00', 'status' => 'posted'],
                ['pay_type' => 'deposit', 'amount' => '75.00', 'gratuity' => '0', 'status' => 'posted'],
                ['pay_type' => 'refund', 'amount' => '25.00', 'gratuity' => '0', 'status' => 'posted'],
                ['pay_type' => 'payment', 'amount' => '999.00', 'gratuity' => '0', 'status' => 'void'],
            ],
            default => [],
        });
        $day = (new CashDrawerService($db))->day(1, new \DateTimeImmutable('2026-10-27'));
        $this->assertSame(23500, $day['cashIn']->cents);
        $this->assertSame(2500, $day['cashOut']->cents);
        $this->assertSame(40500, $day['expected']->cents, '200 + 235 - 25 - 5');
        $this->assertSame(-500, $day['variance']?->cents, 'counted 400, expected 405: $5 short');
    }
}
