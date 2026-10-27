<?php

/**
 * Promo scopes, package totals, stock levels, settings and taxes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests;

use OpenEMR\Modules\CashpayInvoicing\Admin\FormInput;
use OpenEMR\Modules\CashpayInvoicing\Admin\Panels\PackageItemsPanel;
use OpenEMR\Modules\CashpayInvoicing\Admin\Panels\ProductStockPanel;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Catalog\PackageTotals;
use OpenEMR\Modules\CashpayInvoicing\Catalog\PromoScope;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Settings\CardFunding;
use OpenEMR\Modules\CashpayInvoicing\Settings\Settings;
use OpenEMR\Modules\CashpayInvoicing\Settings\TaxType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class CatalogRulesTest extends TestCase
{
    public function testPromoScopeRoundTrips(): void
    {
        $scope = new PromoScope(['membership'], ['facility_ids' => [3], 'procedure_ids' => [12, 5]], true);
        $again = PromoScope::fromJson($scope->toJson());
        $this->assertSame(['membership'], $again->invoiceTypes);
        $this->assertSame([3], $again->ids('facility_ids'));
        $this->assertSame([5, 12], $again->ids('procedure_ids'));
        $this->assertTrue($again->noProducts);
        $this->assertFalse($again->isUnrestricted());
    }

    public function testPromoScopeIgnoresJunk(): void
    {
        $this->assertTrue(PromoScope::fromJson(null)->isUnrestricted());
        $this->assertTrue(PromoScope::fromJson('not json')->isUnrestricted());
        $scope = PromoScope::fromJson('{"invoice_types":["prepaid","bogus"],"product_ids":["7","x",-1,0,"7"]}');
        $this->assertSame(['prepaid'], $scope->invoiceTypes);
        $this->assertSame([7], $scope->ids('product_ids'));
    }

    public function testPromoScopeFromForm(): void
    {
        $scope = PromoScope::fromInput(['invoice_types' => ['immediate'], 'vendor_ids' => ['4'], 'no_products' => '1', 'other' => 'x']);
        $this->assertSame(['immediate'], $scope->invoiceTypes);
        $this->assertSame([4], $scope->ids('vendor_ids'));
        $this->assertTrue($scope->noProducts);
    }

    public function testPackageTotalsMatchPatientNow(): void
    {
        // Full Back Laser Pkg 4 get 2 free: 6 x 266.67 less 0.02 = 1600.00
        $line = PackageItemsPanel::lineOf(['qty' => '6.000', 'unit_price' => '266.67', 'discount' => '0.02']);
        $this->assertSame(160000, $line->net()->cents);
        // Male Pellet 1yr Program: 3 x 697 less 394 = 1697
        $totals = PackageTotals::of([PackageItemsPanel::lineOf(['qty' => '3.000', 'unit_price' => '697.00', 'discount' => '394.00'])]);
        $this->assertSame(209100, $totals->gross->cents);
        $this->assertSame(39400, $totals->discount->cents);
        $this->assertSame(169700, $totals->net->cents);
        $this->assertSame(0, PackageTotals::of([])->net->cents);
    }

    public function testLowStockNeedsALevel(): void
    {
        $this->assertTrue(ProductStockPanel::isLow('2.000', '2.000'));
        $this->assertTrue(ProductStockPanel::isLow('-1.000', '1.000'));
        $this->assertFalse(ProductStockPanel::isLow('3.000', '2.000'));
        $this->assertFalse(ProductStockPanel::isLow('0.000', '0.000'));
        $this->assertFalse(ProductStockPanel::isLow(null, '1.000'));
    }

    public function testCardFeeAppliesToCreditCardsOnly(): void
    {
        $settings = new Settings(cardFeeEnabled: true, cardFeeBasisPoints: 300);
        $amount = Money::parse('250.00');
        $this->assertSame(750, $settings->cardFeeOn($amount, CardFunding::Credit)->cents);
        $this->assertSame(0, $settings->cardFeeOn($amount, CardFunding::Debit)->cents);
        $this->assertSame(0, $settings->cardFeeOn($amount, CardFunding::Prepaid)->cents);
        $this->assertSame(0, $settings->cardFeeOn($amount, CardFunding::Unknown)->cents);
        $this->assertSame(0, (new Settings(cardFeeEnabled: false, cardFeeBasisPoints: 300))->cardFeeOn($amount, CardFunding::Credit)->cents);
        $this->assertSame(0, $settings->cardFeeOn(Money::parse('-10'), CardFunding::Credit)->cents);
    }

    public function testCardFeeIsCappedAtThreePercent(): void
    {
        $this->expectException(\DomainException::class);
        new Settings(cardFeeBasisPoints: 301);
    }

    public function testStoredSettingsFallBackToDefaults(): void
    {
        $settings = Settings::fromStored(['card_fee_bp' => 999, 'rewards_enabled' => 'yes', 'quote_valid_days' => 0, 'card_fee_label' => 'Card fee']);
        $this->assertSame(0, $settings->cardFeeBasisPoints);
        $this->assertFalse($settings->rewardsEnabled);
        $this->assertSame(1, $settings->quoteValidDays);
        $this->assertSame('Card fee', $settings->cardFeeLabel);
        $round = Settings::fromStored((new Settings(true, 250, 'Fee', true, 150, new Money(5), 100, 45, 'Q', 'R'))->toStorage());
        $this->assertSame(250, $round->cardFeeBasisPoints);
        $this->assertSame(150, $round->pointsPerDollarHundredths);
        $this->assertSame(5, $round->pointValue->cents);
        $this->assertSame('2.50', $round->cardFeePercentText());
        $this->assertSame('1.50', $round->pointsPerDollarText());
    }

    public function testSettingsFromForm(): void
    {
        $settings = Settings::fromInput(new FormInput(new InputBag([
            'card_fee_enabled' => '1', 'card_fee_pct' => '3.00', 'card_fee_label' => '',
            'rewards_enabled' => '1', 'points_per_dollar' => '1', 'point_value' => '0.05',
            'min_redeem_points' => '100', 'quote_valid_days' => '30', 'quote_footer' => 'Info only',
        ])));
        $this->assertTrue($settings->cardFeeEnabled);
        $this->assertSame(300, $settings->cardFeeBasisPoints);
        $this->assertSame('Credit card service charge', $settings->cardFeeLabel);
        $this->assertSame(5, $settings->pointValue->cents);
        $this->assertSame(100, $settings->minRedeemPoints);
    }

    public function testSettingsFormRejectsOverCap(): void
    {
        try {
            Settings::fromInput(new FormInput(new InputBag(['card_fee_pct' => '3.5', 'point_value' => '-1', 'quote_valid_days' => '0'])));
            $this->fail('Expected validation errors');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('card_fee_pct', $e->errors);
            $this->assertArrayHasKey('point_value', $e->errors);
            $this->assertArrayHasKey('quote_valid_days', $e->errors);
        }
    }

    public function testEnablingAZeroCardFeeLeavesItOff(): void
    {
        $settings = Settings::fromInput(new FormInput(new InputBag(['card_fee_enabled' => '1', 'card_fee_pct' => '0'])));
        $this->assertFalse($settings->cardFeeEnabled);
    }

    public function testTaxTypes(): void
    {
        $tax = new TaxType(1, 'NC Sales Tax', 67500, true);
        $this->assertSame('6.7500', $tax->rateText());
        $this->assertSame(675, $tax->taxOn(Money::parse('100'))->cents);
        $this->assertSame(0, (new TaxType(2, 'Tax 2', 67500, false))->taxOn(Money::parse('100'))->cents);
        $this->expectException(\DomainException::class);
        new TaxType(3, 'Tax 3', 0, true);
    }

    public function testFormInputTypes(): void
    {
        $input = new FormInput(new InputBag(['a' => ' 5 ', 'b' => '', 'c' => 'x', 'm' => '12.5', 'q' => '2.25', 'ids' => ['3', 'x', '3', '9']]));
        $this->assertSame(5, $input->requiredId('a'));
        $this->assertNull($input->optionalId('b'));
        $this->assertSame(1250, $input->money('m')?->cents);
        $this->assertSame(2250, $input->milli('q'));
        $this->assertSame([3, 9], $input->idList('ids'));
        $this->assertNull($input->money('b', false));
        $this->expectException(ValidationException::class);
        $input->optionalId('c');
    }
}
