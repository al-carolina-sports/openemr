<?php

/**
 * Renders every admin template with sample data, using stand-ins for
 * OpenEMR's Twig filters, to catch syntax errors and missing variables.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests;

use OpenEMR\Modules\CashpayInvoicing\Admin\EntityRegistry;
use OpenEMR\Modules\CashpayInvoicing\Admin\ListQuery;
use OpenEMR\Modules\CashpayInvoicing\Import\ImportReport;
use OpenEMR\Modules\CashpayInvoicing\Settings\Settings;
use OpenEMR\Modules\CashpayInvoicing\Settings\TaxType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class TemplateRenderTest extends TestCase
{
    private static function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../templates', 'cashpay');
        $twig = new Environment($loader, ['autoescape' => false, 'strict_variables' => true]);
        $esc = static fn (mixed $v): string => htmlspecialchars(is_scalar($v) ? (string) $v : '', ENT_QUOTES);
        foreach (['xlt', 'xla', 'text', 'attr'] as $name) {
            $twig->addFilter(new TwigFilter($name, $esc));
        }
        $twig->addFilter(new TwigFilter('xl', static fn (mixed $v): string => is_scalar($v) ? (string) $v : ''));
        $twig->addFilter(new TwigFilter('xlj', static fn (mixed $v): string => json_encode($v, JSON_THROW_ON_ERROR)));
        $twig->addFilter(new TwigFilter('attr_url', static fn (mixed $v): string => rawurlencode(is_scalar($v) ? (string) $v : '')));
        $twig->addFilter(new TwigFilter('money', static fn (mixed $v): string => is_numeric($v) ? number_format((float) $v, 2) : ''));
        $twig->addFunction(new TwigFunction('setupHeader', static fn (): string => ''));
        return $twig;
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(): array
    {
        return ['adminUrl' => '/a/', 'csrfField' => 'csrf_token_form', 'csrfToken' => 'tok', 'notice' => 'Saved.'];
    }

    public function testIndex(): void
    {
        $html = self::twig()->render('@cashpay/admin/index.html.twig', self::base() + [
            'groups' => ['Catalog' => [['entity' => EntityRegistry::get('procedure'), 'count' => 309]]],
            'canSettings' => true,
        ]);
        $this->assertStringContainsString('list.php?entity=procedure', $html);
        $this->assertStringContainsString('309', $html);
    }

    #[DataProvider('entities')]
    public function testListAndEditRenderForEveryEntity(string $key): void
    {
        $entity = EntityRegistry::get($key);
        $cells = [];
        $values = [];
        foreach ($entity->fields as $field) {
            $cells[$field->name] = 'v';
            $values[$field->name] = $field->default;
        }
        $html = self::twig()->render('@cashpay/admin/list.html.twig', self::base() + [
            'entity' => $entity,
            'fields' => $entity->listFields(),
            'rows' => [['id' => 3, 'active' => false, 'cells' => $cells]],
            'total' => 1,
            'query' => new ListQuery('<b>', true, 1, []),
            'pages' => 2,
            'filterOptions' => [],
            'csvEntity' => true,
        ]);
        $this->assertStringContainsString('edit.php?entity=' . $key . '&amp;id=3', $html);
        $this->assertStringNotContainsString('<b>', $html, 'search text is escaped');

        $html = self::twig()->render('@cashpay/admin/edit.html.twig', self::base() + [
            'entity' => $entity,
            'id' => 0,
            'record' => null,
            'values' => $values,
            'errors' => ['name' => 'Required'],
            'options' => [],
            'panels' => [],
            'openPanel' => '',
        ]);
        $this->assertStringContainsString('name="csrf_token_form"', $html);
        $this->assertStringContainsString('Required', $html);
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function entities(): array
    {
        $out = [];
        foreach (array_keys(EntityRegistry::all()) as $key) {
            $out[$key] = [$key];
        }
        return $out;
    }

    public function testEditWithEveryPanel(): void
    {
        $panels = [
            ['key' => 'procedure_fees', 'title' => 'Fees', 'errors' => [], 'data' => [
                'rows' => [['id' => 1, 'provider_id' => 2, 'facility_id' => null, 'price' => '10.00', 'hours' => null]],
                'providers' => [2 => 'Doe, Jane'], 'facilities' => [1 => 'Raleigh'], 'providerNames' => [2 => 'Doe, Jane'], 'facilityNames' => [],
            ]],
            ['key' => 'procedure_products', 'title' => 'Products', 'errors' => [], 'data' => [
                'rows' => [['product_id' => 4, 'qty' => '1.000', 'name' => 'Syringe']], 'products' => [4 => 'Syringe'],
            ]],
            ['key' => 'product_vendors', 'title' => 'Vendors', 'errors' => [], 'data' => [
                'rows' => [['vendor_id' => 1, 'is_default' => '1', 'name' => 'ZRT'], ['vendor_id' => 2, 'is_default' => '0', 'name' => 'B']], 'vendors' => [1 => 'ZRT'],
            ]],
            ['key' => 'product_facility_prices', 'title' => 'Prices', 'errors' => [], 'data' => [
                'rows' => [['facility_id' => 1, 'price_to_patient' => '5.00']], 'facilities' => [1 => 'Raleigh'], 'facilityNames' => [1 => 'Raleigh'],
            ]],
            ['key' => 'product_stock', 'title' => 'Stock', 'errors' => ['qty' => 'Required'], 'data' => [
                'rows' => [['facility_id' => 1, 'facility_name' => 'Raleigh', 'on_hand' => '2', 'on_order' => '0', 'low_stock_level' => '3', 'is_low' => true]],
                'reasons' => [1 => 'Damaged'],
                'history' => [['created_at' => '2026-10-26 10:00:00', 'facility_name' => 'Raleigh', 'kind' => 'receive_order', 'qty_delta' => '2.000', 'reason_name' => null, 'username' => 'admin']],
            ]],
            ['key' => 'package_items', 'title' => 'Items', 'errors' => [], 'data' => [
                'rows' => [['id' => 9, 'item_type' => 'procedure', 'item_name' => 'Laser', 'list_price' => '150.00', 'unit_price' => '125.00', 'qty' => '6.000', 'discount' => '0.00', 'sort_order' => 10, 'net_display' => '$750.00']],
                'totals' => ['gross' => '$750.00', 'discount' => '$0.00', 'net' => '$750.00'],
                'procedures' => [1 => 'Laser'], 'products' => [],
            ]],
            ['key' => 'membership_promos', 'title' => 'Promos', 'errors' => [], 'data' => [
                'promos' => [['id' => '1', 'name' => 'Tesla Chair', 'kind' => 'flat_total', 'amount' => '37.2500', 'members_only' => '1', 'active' => '1']],
                'linked' => [1],
            ]],
            ['key' => 'promo_scope', 'title' => 'Scope', 'errors' => [], 'data' => [
                'invoiceTypes' => ['membership' => 'Membership'], 'selectedTypes' => ['membership'],
                'dimensions' => [['key' => 'facility_ids', 'label' => 'Offices', 'options' => [1 => 'Raleigh'], 'selected' => [1]]],
                'noProducts' => true,
            ]],
        ];
        $entity = EntityRegistry::get('product');
        $values = [];
        foreach ($entity->fields as $field) {
            $values[$field->name] = '';
        }
        $html = self::twig()->render('@cashpay/admin/edit.html.twig', self::base() + [
            'entity' => $entity, 'id' => 5, 'record' => ['source' => 'patientnow_import'], 'values' => $values,
            'errors' => [], 'options' => [], 'panels' => $panels, 'openPanel' => 'product_stock',
        ]);
        foreach ($panels as $panel) {
            $this->assertStringContainsString('id="' . $panel['key'] . '"', $html);
        }
        $this->assertStringContainsString('Imported from PatientNow', $html);
        $this->assertStringContainsString('Make default', $html);
        $this->assertStringContainsString('$750.00', $html);
    }

    public function testSettings(): void
    {
        $html = self::twig()->render('@cashpay/admin/settings.html.twig', self::base() + [
            'settings' => new Settings(cardFeeEnabled: true, cardFeeBasisPoints: 275),
            'taxes' => [1 => new TaxType(1, 'NC Sales Tax', 67500, true), 2 => new TaxType(2, 'Tax 2', 0, false)],
            'errors' => [],
            'submitted' => [],
            'maxCardFee' => '3.00',
            'exampleFee' => '$2.75',
        ]);
        $this->assertStringContainsString('value="2.75" selected', $html);
        $this->assertStringContainsString('value="6.7500"', $html);
        $this->assertMatchesRegularExpression('/id="card_fee_enabled"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="tax2_active"[^>]*checked/', $html);
    }

    public function testPriceListAndImport(): void
    {
        $html = self::twig()->render('@cashpay/admin/price_list.html.twig', self::base() + [
            'procedures' => ['BHRT' => [['name' => 'Consult', 'cpt_code' => '99203', 'price_display' => '$300.00', 'tax1' => '0', 'tax2' => '0']]],
            'products' => ['Skin' => [['name' => 'Serum', 'style' => '1 oz', 'manufacturer' => 'X', 'price_display' => '$92.00', 'tax1' => '1', 'tax2' => '0']]],
            'packages' => [['name' => 'Laser 6', 'type' => 'Prepaid', 'total' => '$900.00']],
            'memberships' => [['name' => 'Core', 'code' => '1234', 'monthly_price' => '149.00', 'annual_price' => '1788.00']],
            'printedOn' => '2026-10-26',
            'canSetup' => true,
        ]);
        $this->assertStringContainsString('$300.00', $html);
        $this->assertStringContainsString('+tax', $html);

        $report = new ImportReport();
        $report->count('procedures', 309);
        $report->warn('Office "Wilson" was not matched');
        foreach ([false, true] as $done) {
            $html = self::twig()->render('@cashpay/admin/import.html.twig', self::base() + [
                'alreadyImported' => $done,
                'summary' => ['procedures' => 309, 'products' => 504, 'packages' => 37, 'promos' => 10, 'memberships' => 4],
                'review' => ['Check tax flags'],
                'offices' => ['Raleigh', 'Rocky Mount', 'Wilson'],
                'officeGuess' => ['Raleigh' => 1, 'Rocky Mount' => null, 'Wilson' => null],
                'facilities' => [1 => 'Raleigh Office'],
                'report' => $done ? $report : null,
                'errors' => [],
                'csvReport' => $report,
                'csvErrors' => ['row 2: price' => 'Enter an amount like 125.00'],
                'csvEntities' => ['procedure', 'product'],
            ]);
            $this->assertStringContainsString('row 2: price', $html);
            $this->assertSame(!$done, str_contains($html, 'name="office_0"'));
        }
    }
}
