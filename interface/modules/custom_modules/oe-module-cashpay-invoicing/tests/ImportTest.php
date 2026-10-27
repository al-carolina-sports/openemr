<?php

/**
 * The bundled PatientNow catalog and the CSV round trip.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Tests;

use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Catalog\PackageLine;
use OpenEMR\Modules\CashpayInvoicing\Catalog\PackageTotals;
use OpenEMR\Modules\CashpayInvoicing\Catalog\PromoScope;
use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Import\CsvCatalog;
use OpenEMR\Modules\CashpayInvoicing\Import\PatientNowImporter;
use OpenEMR\Modules\CashpayInvoicing\Import\Rec;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Tests\Support\FakeDatabase;
use PHPUnit\Framework\TestCase;

final class ImportTest extends TestCase
{
    private const CATALOG = __DIR__ . '/../data/patientnow_catalog.json';

    private static function emptyDb(): FakeDatabase
    {
        return new FakeDatabase(static fn (string $sql): array => str_contains($sql, 'COUNT(*)') ? [['n' => '0']] : []);
    }

    public function testCatalogFileLoadsEverything(): void
    {
        $db = self::emptyDb();
        $importer = PatientNowImporter::fromFile($db, self::CATALOG);
        $this->assertFalse($importer->alreadyImported());
        $this->assertSame(['procedures' => 309, 'products' => 504, 'packages' => 37, 'promos' => 10, 'memberships' => 4], $importer->summary());

        $report = $importer->run(['Raleigh' => 1, 'Rocky Mount' => 2, 'Wilson' => null]);

        $this->assertSame(1, $db->transactions);
        $this->assertSame(309, $report->counts['procedures']);
        $this->assertSame(504, $report->counts['products']);
        $this->assertSame(37, $report->counts['packages']);
        $this->assertSame(4, $report->counts['membership plans']);
        $this->assertSame(10, $report->counts['promotional discounts']);
        // Every captured package line resolves to an item.
        $this->assertSame([], array_filter($report->warnings, static fn (string $w): bool => str_contains($w, 'not found')));
        $this->assertStringContainsString('Wilson', implode(' ', $report->warnings));
        foreach ($db->inserts('cpi_procedure') as [, $binds]) {
            $this->assertSame(PatientNowImporter::SOURCE, $binds[11]);
        }
        foreach ($db->inserts('cpi_stock') as [, $binds]) {
            $this->assertContains($binds[1], [1, 2], 'stock rows only for mapped offices');
        }
        // Core: seven device promos; Wellness and Regenerative Wellness: one each.
        $this->assertCount(7 + 1 + 1, $db->inserts('cpi_membership_plan_promo'));
    }

    public function testImportedPackagePricesMatchPatientNow(): void
    {
        $data = json_decode((string) file_get_contents(self::CATALOG), true, 16, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $byName = [];
        foreach (Rec::records($data, 'packages') as $package) {
            $lines = [];
            foreach (Rec::records($package, 'items') as $item) {
                $lines[] = new PackageLine(
                    Decimal::parse(Rec::str($item, 'qty'), 3),
                    Money::parse(Rec::str($item, 'unit_price')),
                    Money::parse(Rec::str($item, 'discount')),
                );
            }
            $byName[Rec::str($package, 'name')] = PackageTotals::of($lines)->net->cents;
        }
        $this->assertSame(240000, $byName['Advanced Sculpt']);
        $this->assertSame(160000, $byName['Full Back Laser Pkg 4 get 2 free']);
        $this->assertSame(169700, $byName['Male Pellet 1yr Program (3 Pellets)']);
        $this->assertSame(78900, $byName['Tirzepatide 4 Week package']);
    }

    public function testPromoScopesAreValid(): void
    {
        $db = self::emptyDb();
        PatientNowImporter::fromFile($db, self::CATALOG)->run(['Raleigh' => 1, 'Rocky Mount' => 2, 'Wilson' => 3]);
        foreach ($db->inserts('cpi_promo_discount') as [, $binds]) {
            $scope = PromoScope::fromJson(is_string($binds[7]) ? $binds[7] : null);
            $this->assertSame($binds[7], $scope->toJson());
        }
    }

    public function testRefusesSecondImport(): void
    {
        $db = new FakeDatabase(static fn (): array => [['n' => '309']]);
        $importer = PatientNowImporter::fromFile($db, self::CATALOG);
        $this->assertTrue($importer->alreadyImported());
        $this->expectException(\DomainException::class);
        $importer->run([]);
    }

    public function testGuessesOfficesByName(): void
    {
        $guess = PatientNowImporter::guessOffices([4 => 'Raleigh Office', 7 => 'RockyMount Clinic', 9 => 'Wilson A', 10 => 'Wilson B']);
        $this->assertSame(['Raleigh' => 4, 'Rocky Mount' => 7, 'Wilson' => null], $guess);
    }

    public function testCsvParsingHandlesQuotesAndGuards(): void
    {
        $csv = new CsvCatalog(self::emptyDb());
        $rows = $csv->parseCsv("\xEF\xBB\xBFName,Price\n\"Peel, \"\"deep\"\"\",10\n\"two\nlines\",5\n'=SUM(A1),1\n\n");
        $this->assertSame([
            ['name' => 'Peel, "deep"', 'price' => '10'],
            ['name' => "two\nlines", 'price' => '5'],
            ['name' => '=SUM(A1)', 'price' => '1'],
        ], $rows);
    }

    public function testCsvExportGuardsFormulas(): void
    {
        $db = new FakeDatabase(static fn (string $sql): array => str_contains($sql, 'FROM `cpi_product`')
            ? [['id' => '1', 'name' => '=HYPERLINK("x")', 'price_to_patient' => '10.00', 'category_id' => '2', 'manufacturer_id' => null]]
            : (str_contains($sql, 'cpi_product_category') ? [['id' => '2', 'name' => 'Skin']] : []));
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);
        (new CsvCatalog($db))->export('product', $out);
        rewind($out);
        $text = (string) stream_get_contents($out);
        $this->assertStringStartsWith('id,name,style,manufacturer,category,', $text);
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\"", $text);
        $this->assertStringContainsString(',Skin,', $text);
    }

    public function testCsvDryRunCountsAndRejectsBadRows(): void
    {
        $db = new FakeDatabase(static fn (string $sql, array $binds): array => str_contains($sql, 'WHERE `id` = ?') && $binds === [5]
            ? [['id' => '5', 'name' => 'Old', 'price' => '10.00', 'tax1' => '0', 'active' => '1']]
            : []);
        $csv = new CsvCatalog($db);
        $report = $csv->import('procedure', "id,name,price,tax1\n5,Old,12.00,1\n,New one,99,0\n", true);
        $this->assertSame(['updated' => 1, 'added' => 1], $report->counts);
        $this->assertSame([], $db->statements, 'dry run writes nothing');

        try {
            $csv->import('procedure', "id,name,price\n,Bad,abc\n77,Missing,1\n", true);
            $this->fail('Expected validation errors');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('row 2: price', $e->errors);
            $this->assertArrayHasKey('row 3', $e->errors);
        }
    }

    public function testCsvApplyWritesInOneTransaction(): void
    {
        $db = new FakeDatabase(static fn (string $sql, array $binds): array => str_contains($sql, 'cpi_procedure_category') ? [['id' => '3']] : []);
        (new CsvCatalog($db))->import('procedure', "name,category,price,active\nHydrafacial,Facial Services,199,1\n", false);
        $this->assertSame(1, $db->transactions);
        $insert = $db->inserts('cpi_procedure');
        $this->assertCount(1, $insert);
        $this->assertContains(3, $insert[0][1]);
        $this->assertContains('199.00', $insert[0][1]);
    }

    public function testCsvRejectsUnknownColumns(): void
    {
        $this->expectException(ValidationException::class);
        (new CsvCatalog(self::emptyDb()))->import('product', "name,colour\nX,red\n", true);
    }
}
