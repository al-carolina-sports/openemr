<?php

/**
 * Line pricing, promos, delivery and stock against an in-memory database.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests;

use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Billing\DeliveryService;
use OpenEMR\Modules\CashpayInvoicing\Billing\Document;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentKind;
use OpenEMR\Modules\CashpayInvoicing\Billing\DocumentRepository;
use OpenEMR\Modules\CashpayInvoicing\Billing\LineDefaults;
use OpenEMR\Modules\CashpayInvoicing\Billing\LineEdit;
use OpenEMR\Modules\CashpayInvoicing\Billing\LineService;
use OpenEMR\Modules\CashpayInvoicing\Inventory\StockService;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Pricing\CatalogLookup;
use OpenEMR\Modules\CashpayInvoicing\Pricing\PromoRepository;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;
use OpenEMR\Modules\CashpayInvoicing\Tests\Support\FakeDatabase;
use PHPUnit\Framework\TestCase;

final class LineServiceTest extends TestCase
{
    private FakeDatabase $db;

    /** @var array<int, array<string, scalar|null>> lines written so far, by id */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->lines = [];
        $this->db = new FakeDatabase(function (string $sql, array $binds): array {
            if (str_contains($sql, 'FROM `cpi_line` WHERE `id`')) {
                $this->captureLines();
            }
            return match (true) {
                str_contains($sql, 'FROM `cpi_procedure` WHERE `id`') => [[
                    'id' => '10', 'name' => 'Hydrafacial', 'cpt_code' => null, 'price' => '200.00', 'tax1' => '0', 'tax2' => '0',
                    'award_points_eligible' => '1', 'allow_manual_desc' => '0', 'category_id' => '1', 'active' => '1',
                ]],
                str_contains($sql, 'FROM `cpi_product` WHERE `id`') => [[
                    'id' => '20', 'name' => 'Serum', 'catalog_no' => 'S-1', 'price_to_patient' => '110.00', 'cost_to_practice' => '55.00',
                    'tax1' => '1', 'tax2' => '0', 'award_points_eligible' => '1', 'allow_manual_desc' => '0', 'category_id' => '2',
                    'manufacturer_id' => '5', 'active' => '1',
                ]],
                str_contains($sql, 'FROM `cpi_procedure_fee`') => [['provider_id' => '7', 'facility_id' => null, 'price' => '180.00']],
                str_contains($sql, 'FROM `cpi_tax_type`') => [['slot' => '1', 'name' => 'NC', 'rate_pct' => '6.7500', 'active' => '1']],
                str_contains($sql, 'FROM `cpi_promo_discount`') => [[
                    'id' => '3', 'name' => 'Facial 10%', 'kind' => 'percent', 'amount' => '10.0000', 'members_only' => '0',
                    'start_date' => null, 'end_date' => null, 'scope_json' => '{"procedure_ids":[10]}', 'reason_id' => '4', 'active' => '1',
                ]],
                str_contains($sql, 'FROM `cpi_procedure_product`') => [['product_id' => '20', 'qty' => '0.500']],
                str_contains($sql, 'MAX(`sort_order`)') => [['n' => '10']],
                str_contains($sql, 'FROM `cpi_line` WHERE `id` = ?') => is_int($binds[0] ?? null) && isset($this->lines[$binds[0]]) ? [$this->lines[$binds[0]]] : [],
                default => [],
            };
        });
    }

    private function service(): LineService
    {
        $documents = new DocumentRepository($this->db);
        return new LineService(
            $this->db,
            $documents,
            new CatalogLookup($this->db),
            new PromoRepository($this->db),
            new SettingsRepository($this->db),
            new DeliveryService($this->db, $documents, new StockService($this->db)),
        );
    }

    private static function document(string $type = 'immediate', bool $locked = false, DocumentKind $kind = DocumentKind::Invoice): Document
    {
        return Document::fromRow($kind, [
            'id' => '1', 'pid' => '42', 'name' => 'Visit', 'invoice_date' => '2026-10-27', 'quote_date' => '2026-10-27',
            'invoice_type' => $type, 'income_facility_id' => '3', 'facility_id' => '3', 'provider_id' => '7', 'seller_id' => '8',
            'comments' => '', 'deposit' => '0.00', 'locked' => $locked ? '1' : '0', 'voided' => '0', 'is_active' => '1',
        ]);
    }

    /**
     * Remembers inserted lines so later reads find them.
     */
    private function captureLines(): void
    {
        foreach ($this->db->inserts('cpi_line') as $index => [, $binds]) {
            $this->lines[$index + 1] = [
                'id' => (string) ($index + 1), 'parent_type' => $binds[0], 'parent_id' => (string) $binds[1], 'item_type' => $binds[2],
                'item_id' => (string) $binds[3], 'package_id' => null, 'description' => $binds[5], 'code' => $binds[6],
                'charge_category' => $binds[7], 'qty' => $binds[8], 'unit_price' => $binds[9], 'unit_cost' => $binds[10],
                'discount_amt' => $binds[11], 'discount_reason_id' => $binds[12] === null ? null : (string) $binds[12], 'promo_id' => null,
                'net' => $binds[14], 'tax1_amt' => $binds[15], 'tax2_amt' => $binds[16], 'award_points_eligible' => '1',
                'service_date' => $binds[18], 'received_date' => $binds[19], 'forfeited' => '0', 'provider_id' => (string) $binds[21],
                'seller_id' => (string) $binds[22], 'service_facility_id' => (string) $binds[23], 'sort_order' => '10',
            ];
        }
    }

    public function testProcedureUsesProviderPriceAndPromo(): void
    {
        $this->service()->addCatalogItem(self::document(), 'procedure', 10, 1000, LineDefaults::of(self::document()), 99);
        [, $binds] = $this->db->inserts('cpi_line')[0];
        $this->assertSame('180.00', $binds[9], 'provider override price');
        $this->assertSame('18.00', $binds[11], '10% promo');
        $this->assertSame(4, $binds[12], 'promo reason');
        $this->assertSame(3, $binds[13], 'promo id');
        $this->assertSame('162.00', $binds[14]);
        $this->assertSame('provider', $binds[7]);
    }

    public function testImmediateSaleTakesStockForProductsAndProcedureSupplies(): void
    {
        $this->service()->addCatalogItem(self::document(), 'product', 20, 2000, LineDefaults::of(self::document()), 99);
        [, $binds] = $this->db->inserts('cpi_line')[0];
        $this->assertSame('220.00', $binds[14]);
        $this->assertSame('14.85', $binds[15], 'tax 1 at 6.75% of 220');
        $this->assertSame('2026-10-27', $binds[19], 'received on sale');
        $stock = $this->db->inserts('cpi_stock_txn');
        $this->assertCount(1, $stock);
        $this->assertSame([20, 3, '-2.000', 'sale'], array_slice($stock[0][1], 0, 4));

        $this->service()->addCatalogItem(self::document(), 'procedure', 10, 3000, LineDefaults::of(self::document()), 99);
        $stock = $this->db->inserts('cpi_stock_txn');
        $this->assertCount(2, $stock);
        $this->assertSame([20, 3, '-1.500', 'procedure_use'], array_slice($stock[1][1], 0, 4), '0.5 per procedure x 3');
    }

    public function testPrepaidAndQuotesDoNotTakeStock(): void
    {
        $this->service()->addCatalogItem(self::document('prepaid'), 'product', 20, 1000, LineDefaults::of(self::document('prepaid')), 99);
        $this->service()->addCatalogItem(self::document('immediate', false, DocumentKind::Quote), 'product', 20, 1000, LineDefaults::of(self::document()), 99);
        $this->assertSame([], $this->db->inserts('cpi_stock_txn'));
        foreach ($this->db->inserts('cpi_line') as [, $binds]) {
            $this->assertNull($binds[19]);
        }
    }

    public function testLockedInvoiceRefusesChanges(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->addCatalogItem(self::document('immediate', true), 'product', 20, 1000, LineDefaults::of(self::document()), 99);
    }

    public function testDiscountNeedsAReason(): void
    {
        $doc = self::document('prepaid');
        $this->service()->addCatalogItem($doc, 'product', 20, 1000, LineDefaults::of($doc), 99);
        $this->captureLines();
        try {
            $this->service()->update($doc, 1, new LineEdit(1000, Money::parse('110'), Money::parse('10'), null, 7, 8, 3, null, null));
            $this->fail('Expected a validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount_reason_id', $e->errors);
        }
        $this->service()->update($doc, 1, new LineEdit(1000, Money::parse('110'), Money::parse('10'), 4, 7, 8, 3, null, null));
        $update = array_values(array_filter($this->db->statements, static fn (array $s): bool => str_starts_with($s[0], 'UPDATE `cpi_line` SET `description`')));
        $this->assertSame('100.00', $update[0][1][6], 'net');
        $this->assertSame('6.75', $update[0][1][7], 'tax recomputed on the new net');
    }

    public function testRemovingADeliveredLineReturnsStock(): void
    {
        $db = new FakeDatabase(static function (string $sql): array {
            return match (true) {
                str_contains($sql, 'FROM `cpi_line` WHERE `id` = ? AND `parent_type`') => [[
                    'id' => '5', 'parent_type' => 'invoice', 'parent_id' => '1', 'item_type' => 'product', 'item_id' => '20',
                    'description' => 'Serum', 'charge_category' => 'product', 'qty' => '2.000', 'unit_price' => '110.00',
                    'received_date' => '2026-10-27', 'service_facility_id' => '3', 'sort_order' => '10',
                ]],
                str_contains($sql, 'SUM(`qty_delta`)') => [['product_id' => '20', 'facility_id' => '3', 'taken' => '-2.000']],
                default => [],
            };
        });
        $documents = new DocumentRepository($db);
        $service = new LineService($db, $documents, new CatalogLookup($db), new PromoRepository($db), new SettingsRepository($db), new DeliveryService($db, $documents, new StockService($db)));
        $service->remove(self::document(), 5, 99);
        $returns = $db->inserts('cpi_stock_txn');
        $this->assertSame([20, 3, '2.000', 'return'], array_slice($returns[0][1], 0, 4));
        $last = $db->statements[count($db->statements) - 1] ?? ['', []];
        $this->assertStringStartsWith('DELETE FROM `cpi_line`', $last[0]);
    }
}
