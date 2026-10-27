<?php

/**
 * Price resolution, promo matching, line amounts and totals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests;

use OpenEMR\Modules\CashpayInvoicing\Billing\ChargeCategory;
use OpenEMR\Modules\CashpayInvoicing\Billing\InvoiceType;
use OpenEMR\Modules\CashpayInvoicing\Billing\LineAmounts;
use OpenEMR\Modules\CashpayInvoicing\Billing\Totals;
use OpenEMR\Modules\CashpayInvoicing\Catalog\PromoScope;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Pricing\FeeOverride;
use OpenEMR\Modules\CashpayInvoicing\Pricing\LineFacts;
use OpenEMR\Modules\CashpayInvoicing\Pricing\PriceResolver;
use OpenEMR\Modules\CashpayInvoicing\Pricing\PromoCandidate;
use OpenEMR\Modules\CashpayInvoicing\Pricing\PromoMatcher;
use OpenEMR\Modules\CashpayInvoicing\Settings\TaxType;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    public function testMostSpecificFeeWins(): void
    {
        $standard = Money::parse('100');
        $fees = [
            new FeeOverride(null, 2, Money::parse('90')),
            new FeeOverride(7, null, Money::parse('120')),
            new FeeOverride(7, 2, Money::parse('130')),
        ];
        $this->assertSame(13000, PriceResolver::procedurePrice($standard, $fees, 7, 2)->cents);
        $this->assertSame(12000, PriceResolver::procedurePrice($standard, $fees, 7, 3)->cents);
        $this->assertSame(9000, PriceResolver::procedurePrice($standard, $fees, 8, 2)->cents);
        $this->assertSame(10000, PriceResolver::procedurePrice($standard, $fees, 8, 3)->cents);
        $this->assertSame(10000, PriceResolver::procedurePrice($standard, $fees, null, null)->cents);
    }

    public function testProductOfficePrice(): void
    {
        $standard = Money::parse('50');
        $this->assertSame(4500, PriceResolver::productPrice($standard, [2 => Money::parse('45')], 2)->cents);
        $this->assertSame(5000, PriceResolver::productPrice($standard, [2 => Money::parse('45')], 3)->cents);
        $this->assertSame(5000, PriceResolver::productPrice($standard, [2 => Money::parse('45')], null)->cents);
    }

    public function testLineAmountsTaxTheNet(): void
    {
        $tax = new TaxType(1, 'NC', 67500, true);
        $amounts = LineAmounts::compute(Money::parse('110'), 2000, Money::parse('20'), $tax, null, Money::parse('55'));
        $this->assertSame(22000, $amounts->gross->cents);
        $this->assertSame(20000, $amounts->net->cents);
        $this->assertSame(1350, $amounts->tax1->cents);
        $this->assertSame(0, $amounts->tax2->cents);
        $this->assertSame(21350, $amounts->total()->cents);
    }

    public function testDiscountIsCappedAtGross(): void
    {
        $amounts = LineAmounts::compute(Money::parse('25'), 1000, Money::parse('40'), null, null);
        $this->assertSame(2500, $amounts->discount->cents);
        $this->assertSame(0, $amounts->net->cents);
    }

    public function testNonPositiveQuantityIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        LineAmounts::compute(Money::parse('25'), 0, Money::zero(), null, null);
    }

    public function testTotalsMatrixByCategory(): void
    {
        $tax = new TaxType(1, 'NC', 67500, true);
        $totals = Totals::of([
            [ChargeCategory::Provider, LineAmounts::compute(Money::parse('300'), 1000, Money::zero(), null, null)],
            [ChargeCategory::Product, LineAmounts::compute(Money::parse('100'), 1000, Money::parse('10'), $tax, null)],
            [ChargeCategory::Provider, LineAmounts::compute(Money::parse('50'), 2000, Money::zero(), null, null)],
            [ChargeCategory::Anesthesia, LineAmounts::compute(Money::parse('400'), 1000, Money::zero(), null, null)],
        ]);
        $rows = $totals->rows();
        $this->assertSame(['provider', 'product', 'anesthesia'], array_map(static fn (array $r): string => $r['category']->value, $rows));
        $this->assertSame(40000, $rows[0]['total']->cents);
        $this->assertSame(608, $rows[1]['tax1']->cents);
        $this->assertSame(9608, $rows[1]['total']->cents);
        $this->assertSame(1000, $totals->discount()->cents);
        $this->assertSame(90000, $totals->gross()->cents);
        $this->assertSame(89608, $totals->total()->cents);
    }

    public function testDeferredInvoiceTypes(): void
    {
        $this->assertTrue(InvoiceType::Prepaid->defersDelivery());
        $this->assertTrue(InvoiceType::Surgery->defersDelivery());
        $this->assertFalse(InvoiceType::Immediate->defersDelivery());
        $this->assertFalse(InvoiceType::Membership->defersDelivery());
        $this->assertArrayNotHasKey('insurance', InvoiceType::choices());
    }

    /**
     * @param 'percent'|'flat_each'|'flat_total' $kind
     * @param list<int> $planIds
     */
    private static function promo(
        int $id,
        string $kind,
        string $amount,
        PromoScope $scope = new PromoScope(),
        bool $membersOnly = false,
        array $planIds = [],
        ?string $start = null,
        ?string $end = null,
    ): PromoCandidate {
        return new PromoCandidate(
            $id,
            'Promo ' . $id,
            $kind,
            (int) round((float) $amount * 10000),
            $membersOnly,
            $start === null ? null : new \DateTimeImmutable($start),
            $end === null ? null : new \DateTimeImmutable($end),
            $scope,
            null,
            $planIds,
        );
    }

    /**
     * @param 'procedure'|'product' $type
     * @param list<int> $plans
     */
    private static function facts(string $type = 'procedure', int $id = 10, ?int $category = 1, array $plans = [], string $gross = '100', int $qty = 1000): LineFacts
    {
        return new LineFacts(
            $type,
            $id,
            $category,
            $type === 'product' ? 5 : null,
            $type === 'product' ? [9] : [],
            3,
            InvoiceType::Immediate,
            new \DateTimeImmutable('2026-10-27'),
            $qty,
            Money::parse($gross),
            $plans,
        );
    }

    public function testPromoDiscountKinds(): void
    {
        $gross = Money::parse('200');
        $this->assertSame(2000, self::promo(1, 'percent', '10')->discountFor($gross, 2000)->cents);
        $this->assertSame(7450, self::promo(1, 'flat_each', '37.25')->discountFor($gross, 2000)->cents);
        $this->assertSame(3725, self::promo(1, 'flat_total', '37.25')->discountFor($gross, 2000)->cents);
        $this->assertSame(20000, self::promo(1, 'flat_total', '475')->discountFor($gross, 1000)->cents, 'capped at gross');
        $this->assertSame(20000, self::promo(1, 'percent', '100')->discountFor($gross, 1000)->cents);
    }

    public function testBestPromoWins(): void
    {
        $best = PromoMatcher::best([self::promo(1, 'percent', '10'), self::promo(2, 'flat_total', '15'), self::promo(3, 'flat_total', '15')], self::facts());
        $this->assertNotNull($best);
        $this->assertSame(2, $best->promo->id, 'largest discount, lowest id on a tie');
        $this->assertSame(1500, $best->discount->cents);
        $this->assertNull(PromoMatcher::best([], self::facts()));
    }

    public function testDatesAndMembership(): void
    {
        $this->assertFalse(PromoMatcher::applies(self::promo(1, 'percent', '10', start: '2026-11-01'), self::facts()));
        $this->assertFalse(PromoMatcher::applies(self::promo(1, 'percent', '10', end: '2026-10-26'), self::facts()));
        $this->assertTrue(PromoMatcher::applies(self::promo(1, 'percent', '10', start: '2026-10-27', end: '2026-10-27'), self::facts()));

        $members = self::promo(1, 'percent', '10', membersOnly: true);
        $this->assertFalse(PromoMatcher::applies($members, self::facts()));
        $this->assertTrue(PromoMatcher::applies($members, self::facts(plans: [4])));

        $corePlanOnly = self::promo(1, 'flat_total', '37.25', membersOnly: true, planIds: [4]);
        $this->assertTrue(PromoMatcher::applies($corePlanOnly, self::facts(plans: [4])));
        $this->assertFalse(PromoMatcher::applies($corePlanOnly, self::facts(plans: [5])));
    }

    public function testInvoiceTypeAndOfficeFilters(): void
    {
        $this->assertFalse(PromoMatcher::applies(self::promo(1, 'percent', '10', new PromoScope(['membership'])), self::facts()));
        $this->assertTrue(PromoMatcher::applies(self::promo(1, 'percent', '10', new PromoScope(['immediate'])), self::facts()));
        $this->assertFalse(PromoMatcher::applies(self::promo(1, 'percent', '10', new PromoScope([], ['facility_ids' => [2]])), self::facts()));
        $this->assertTrue(PromoMatcher::applies(self::promo(1, 'percent', '10', new PromoScope([], ['facility_ids' => [3]])), self::facts()));
    }

    public function testItemFilters(): void
    {
        $procOnly = self::promo(1, 'percent', '10', new PromoScope([], ['procedure_ids' => [10]]));
        $this->assertTrue(PromoMatcher::applies($procOnly, self::facts()));
        $this->assertFalse(PromoMatcher::applies($procOnly, self::facts(id: 11, category: 2)));
        $this->assertFalse(PromoMatcher::applies($procOnly, self::facts('product', 10)), 'procedure promo does not spill onto products');

        $byCategory = self::promo(1, 'percent', '10', new PromoScope([], ['procedure_category_ids' => [1]]));
        $this->assertTrue(PromoMatcher::applies($byCategory, self::facts(id: 99)));

        $productOnly = self::promo(1, 'percent', '10', new PromoScope([], ['manufacturer_ids' => [5]]));
        $this->assertTrue(PromoMatcher::applies($productOnly, self::facts('product', 1)));
        $this->assertFalse(PromoMatcher::applies($productOnly, self::facts()));

        $byVendor = self::promo(1, 'percent', '10', new PromoScope([], ['vendor_ids' => [9]]));
        $this->assertTrue(PromoMatcher::applies($byVendor, self::facts('product', 1)));

        $everything = self::promo(1, 'percent', '10');
        $this->assertTrue(PromoMatcher::applies($everything, self::facts()));
        $this->assertTrue(PromoMatcher::applies($everything, self::facts('product', 1)));

        $noProducts = self::promo(1, 'percent', '10', new PromoScope([], [], true));
        $this->assertTrue(PromoMatcher::applies($noProducts, self::facts()));
        $this->assertFalse(PromoMatcher::applies($noProducts, self::facts('product', 1)));
    }
}
