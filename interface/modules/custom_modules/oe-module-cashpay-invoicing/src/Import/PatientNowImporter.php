<?php

/**
 * One-time load of the catalog captured from PatientNow
 * (data/patientnow_catalog.json): categories, manufacturers, procedures,
 * products, sales packages, promotional discounts and membership plans.
 *
 * Runs in a single transaction. Rows are marked source = 'patientnow_import'
 * and the import refuses to run a second time, so it can never duplicate
 * the catalog. Lookup lists (categories, manufacturers, reasons) are reused
 * by name if an admin already created them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Import;

use OpenEMR\Modules\CashpayInvoicing\Catalog\PromoScope;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;

final class PatientNowImporter
{
    public const SOURCE = 'patientnow_import';

    /** PatientNow office names in the captured data. */
    public const OFFICES = ['Raleigh', 'Rocky Mount', 'Wilson'];

    /** @var array<string, array<string, int>> table => lower(name) => id */
    private array $nameIds = [];

    /** @var array<string, int> lower(name) => first procedure id */
    private array $procedureIds = [];

    /** @var array<string, int> lower(name) => first product id */
    private array $productIds = [];

    /** @var array<string, int> */
    private array $promoIds = [];

    /**
     * @param array<array-key, mixed> $data decoded catalog JSON
     */
    public function __construct(private readonly Database $db, private readonly array $data)
    {
    }

    public static function fromFile(Database $db, string $path): self
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Catalog file not readable');
        }
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['version'] ?? null) !== 1) {
            throw new \RuntimeException('Catalog file has an unexpected format');
        }
        return new self($db, $data);
    }

    public function alreadyImported(): bool
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS `n` FROM `cpi_procedure` WHERE `source` = ?', [self::SOURCE]);
        return is_numeric($row['n'] ?? null) && (int) $row['n'] > 0;
    }

    /**
     * @return array<string, int> what would be loaded
     */
    public function summary(): array
    {
        $summary = [];
        foreach (['procedures', 'products', 'packages', 'promos', 'memberships'] as $key) {
            $summary[$key] = count(Rec::records($this->data, $key));
        }
        return $summary;
    }

    /**
     * Notes from the capture that need a person to check after import.
     *
     * @return list<string>
     */
    public function reviewNotes(): array
    {
        return Rec::strings($this->data, 'review');
    }

    /**
     * Best guess of which OpenEMR facility is each PatientNow office.
     *
     * @param array<int, string> $facilities id => name
     * @return array<string, int|null> office => facility id
     */
    public static function guessOffices(array $facilities): array
    {
        $map = [];
        foreach (self::OFFICES as $office) {
            $needle = strtolower(str_replace(' ', '', $office));
            $hits = array_keys(array_filter(
                $facilities,
                static fn (string $name): bool => str_contains(strtolower(str_replace(' ', '', $name)), $needle)
            ));
            $map[$office] = count($hits) === 1 ? $hits[0] : null;
        }
        return $map;
    }

    /**
     * @param array<string, int|null> $officeFacility office => facility id (null = not set up in OpenEMR)
     */
    public function run(array $officeFacility): ImportReport
    {
        if ($this->alreadyImported()) {
            throw new \DomainException('The PatientNow catalog has already been imported');
        }
        $report = new ImportReport();
        $this->db->transaction(function () use ($officeFacility, $report): void {
            $this->loadLookups($report);
            $this->loadProcedures($report);
            $this->loadProducts($officeFacility, $report);
            $this->loadPackages($officeFacility, $report);
            $this->loadPromos($officeFacility, $report);
            $this->loadMemberships($report);
        });
        foreach ($officeFacility as $office => $facilityId) {
            if ($facilityId === null) {
                $report->warn('Office "' . $office . '" was not matched to an OpenEMR facility, so its stock rows and office scopes were skipped.');
            }
        }
        return $report;
    }

    private function loadLookups(ImportReport $report): void
    {
        foreach (
            [
                'procedure_categories' => 'cpi_procedure_category',
                'product_categories' => 'cpi_product_category',
                'manufacturers' => 'cpi_manufacturer',
                'vendors' => 'cpi_vendor',
            ] as $key => $table
        ) {
            foreach (Rec::strings($this->data, $key) as $name) {
                $this->idFor($table, $name, $report);
            }
        }
    }

    /**
     * Existing id by name, or a new row. Lists are small, so cache them.
     */
    private function idFor(string $table, string $name, ImportReport $report): ?int
    {
        if ($name === '') {
            return null;
        }
        if (!isset($this->nameIds[$table])) {
            $this->nameIds[$table] = [];
            foreach ($this->db->fetchAll("SELECT `id`, `name` FROM `$table`") as $row) {
                if (is_numeric($row['id'] ?? null) && is_string($row['name'] ?? null)) {
                    $this->nameIds[$table][mb_strtolower($row['name'])] = (int) $row['id'];
                }
            }
        }
        $key = mb_strtolower($name);
        if (!isset($this->nameIds[$table][$key])) {
            $this->nameIds[$table][$key] = $this->db->insert("INSERT INTO `$table` (`name`, `active`) VALUES (?, 1)", [$name]);
            $report->count($table);
        }
        return $this->nameIds[$table][$key];
    }

    private function loadProcedures(ImportReport $report): void
    {
        foreach (Rec::records($this->data, 'procedures') as $p) {
            $name = Rec::str($p, 'name');
            if ($name === '') {
                continue;
            }
            $id = $this->db->insert(
                'INSERT INTO `cpi_procedure` (`name`, `category_id`, `layman_terms`, `price`, `expected_hours`, `cpt_code`, `gl_code`,'
                . ' `tax1`, `tax2`, `award_points_eligible`, `allow_manual_desc`, `active`, `source`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
                [
                    $name,
                    $this->idFor('cpi_procedure_category', Rec::str($p, 'category'), $report),
                    Rec::nullable(Rec::str($p, 'layman_terms')),
                    $this->money(Rec::str($p, 'price')),
                    Decimal::format(Decimal::parse(Rec::str($p, 'expected_hours') ?: '0', 2), 2),
                    Rec::nullable(Rec::str($p, 'cpt_code')),
                    Rec::nullable(Rec::str($p, 'gl_code')),
                    Rec::flag($p, 'tax1'),
                    Rec::flag($p, 'tax2'),
                    Rec::flag($p, 'award_points_eligible'),
                    Rec::flag($p, 'allow_manual_desc'),
                    self::SOURCE,
                ]
            );
            $this->procedureIds[mb_strtolower($name)] ??= $id;
            $report->count('procedures');
        }
    }

    /**
     * @param array<string, int|null> $officeFacility
     */
    private function loadProducts(array $officeFacility, ImportReport $report): void
    {
        foreach (Rec::records($this->data, 'products') as $p) {
            $name = Rec::str($p, 'name');
            if ($name === '') {
                continue;
            }
            $id = $this->db->insert(
                'INSERT INTO `cpi_product` (`name`, `style`, `barcode`, `manufacturer_id`, `category_id`, `cost_to_practice`,'
                . ' `price_to_patient`, `tax1`, `tax2`, `award_points_eligible`, `allow_manual_desc`, `no_print`, `active`, `source`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
                [
                    $name,
                    Rec::nullable(Rec::str($p, 'style')),
                    Rec::nullable(Rec::str($p, 'barcode')),
                    $this->idFor('cpi_manufacturer', Rec::str($p, 'manufacturer'), $report),
                    $this->idFor('cpi_product_category', Rec::str($p, 'category'), $report),
                    $this->money(Rec::str($p, 'cost_to_practice')),
                    $this->money(Rec::str($p, 'price_to_patient')),
                    Rec::flag($p, 'tax1'),
                    Rec::flag($p, 'tax2'),
                    Rec::flag($p, 'award_points_eligible'),
                    Rec::flag($p, 'allow_manual_desc'),
                    Rec::flag($p, 'no_print'),
                    self::SOURCE,
                ]
            );
            $this->productIds[mb_strtolower($name)] ??= $id;
            $report->count('products');
            foreach (Rec::strings($p, 'offices') as $office) {
                $facilityId = $officeFacility[$office] ?? null;
                if ($facilityId === null) {
                    continue;
                }
                // Counts in PatientNow were unreliable (many negative), so every office starts at zero.
                $this->db->execute(
                    'INSERT IGNORE INTO `cpi_stock` (`product_id`, `facility_id`, `on_hand`) VALUES (?, ?, 0)',
                    [$id, $facilityId]
                );
                $report->count('stock rows');
            }
        }
    }

    /**
     * @param array<string, int|null> $officeFacility
     */
    private function loadPackages(array $officeFacility, ImportReport $report): void
    {
        foreach (Rec::records($this->data, 'packages') as $p) {
            $type = Rec::str($p, 'package_type') === 'immediate' ? 'immediate' : 'prepaid';
            $packageId = $this->db->insert(
                'INSERT INTO `cpi_package` (`name`, `package_type`, `income_facility_id`, `start_date`, `end_date`, `allow_trades`,'
                . ' `comments`, `active`, `source`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Rec::str($p, 'name'),
                    $type,
                    $officeFacility[Rec::str($p, 'income_office')] ?? null,
                    $this->date(Rec::str($p, 'start_date')),
                    $this->date(Rec::str($p, 'end_date')),
                    Rec::flag($p, 'allow_trades'),
                    Rec::nullable(Rec::str($p, 'comments')),
                    Rec::flag($p, 'active'),
                    self::SOURCE,
                ]
            );
            $report->count('packages');
            $sort = 0;
            foreach (Rec::records($p, 'items') as $item) {
                $itemType = Rec::str($item, 'type') === 'product' ? 'product' : 'procedure';
                $itemName = Rec::str($item, 'name');
                $itemId = ($itemType === 'product' ? $this->productIds : $this->procedureIds)[mb_strtolower($itemName)] ?? null;
                if ($itemId === null) {
                    $report->warn('Package "' . Rec::str($p, 'name') . '": item "' . $itemName . '" not found, line skipped.');
                    continue;
                }
                $sort += 10;
                $this->db->insert(
                    'INSERT INTO `cpi_package_item` (`package_id`, `item_type`, `item_id`, `qty`, `unit_price`, `discount`, `sort_order`)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $packageId,
                        $itemType,
                        $itemId,
                        Decimal::format(Decimal::parse(Rec::str($item, 'qty') ?: '1', 3), 3),
                        $this->money(Rec::str($item, 'unit_price')),
                        $this->money(Rec::str($item, 'discount')),
                        $sort,
                    ]
                );
                $report->count('package items');
            }
        }
    }

    /**
     * @param array<string, int|null> $officeFacility
     */
    private function loadPromos(array $officeFacility, ImportReport $report): void
    {
        foreach (Rec::records($this->data, 'promos') as $p) {
            $name = Rec::str($p, 'name');
            $ids = [
                'facility_ids' => [],
                'procedure_ids' => [],
                'vendor_ids' => [],
                'manufacturer_ids' => [],
                'product_category_ids' => [],
            ];
            foreach (Rec::strings($p, 'offices') as $office) {
                $facilityId = $officeFacility[$office] ?? null;
                if ($facilityId !== null) {
                    $ids['facility_ids'][] = $facilityId;
                }
            }
            foreach (Rec::strings($p, 'procedures') as $procName) {
                $procId = $this->procedureIds[mb_strtolower($procName)] ?? null;
                if ($procId === null) {
                    $report->warn('Promo "' . $name . '": procedure "' . $procName . '" not found.');
                    continue;
                }
                $ids['procedure_ids'][] = $procId;
            }
            foreach (['vendors' => ['vendor_ids', 'cpi_vendor'], 'manufacturers' => ['manufacturer_ids', 'cpi_manufacturer'],
                'product_categories' => ['product_category_ids', 'cpi_product_category']] as $key => [$dimension, $table]) {
                foreach (Rec::strings($p, $key) as $lookupName) {
                    $lookupId = $this->idFor($table, $lookupName, $report);
                    if ($lookupId !== null) {
                        $ids[$dimension][] = $lookupId;
                    }
                }
            }
            $scope = new PromoScope(
                array_values(array_intersect(Rec::strings($p, 'invoice_types'), array_keys(PromoScope::INVOICE_TYPES))),
                $ids,
                ($p['no_products'] ?? false) === true,
            );
            $kind = Rec::str($p, 'kind');
            $promoId = $this->db->insert(
                'INSERT INTO `cpi_promo_discount` (`name`, `reason_id`, `kind`, `amount`, `members_only`, `start_date`, `end_date`,'
                . ' `scope_json`, `active`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [
                    $name,
                    $this->idFor('cpi_discount_reason', Rec::str($p, 'reason'), $report),
                    in_array($kind, ['percent', 'flat_each', 'flat_total'], true) ? $kind : 'percent',
                    Decimal::format(Decimal::parse(Rec::str($p, 'amount') ?: '0', 4), 4),
                    Rec::flag($p, 'members_only'),
                    $this->date(Rec::str($p, 'start_date')),
                    $this->date(Rec::str($p, 'end_date')),
                    PromoScope::fromJson($scope->toJson())->toJson(),
                ]
            );
            $this->promoIds[mb_strtolower($name)] = $promoId;
            $report->count('promotional discounts');
        }
    }

    private function loadMemberships(ImportReport $report): void
    {
        foreach (Rec::records($this->data, 'memberships') as $m) {
            $code = Rec::str($m, 'code');
            $exists = $this->db->fetchOne('SELECT `id` FROM `cpi_membership_plan` WHERE `code` = ?', [$code]);
            if ($exists !== null) {
                $report->warn('Membership code "' . $code . '" already exists, plan "' . Rec::str($m, 'name') . '" skipped.');
                continue;
            }
            $planId = $this->db->insert(
                'INSERT INTO `cpi_membership_plan` (`name`, `code`, `annual_price`, `monthly_price`, `monthly_points`, `add_package_monthly`,'
                . ' `auto_monthly_invoice`, `tax1`, `tax2`, `notes`, `active`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Rec::str($m, 'name'),
                    $code,
                    $this->money(Rec::str($m, 'annual_price')),
                    $this->money(Rec::str($m, 'monthly_price')),
                    Decimal::format(Decimal::parse(Rec::str($m, 'monthly_points') ?: '0', 2), 2),
                    Rec::flag($m, 'add_package_monthly'),
                    Rec::flag($m, 'auto_monthly_invoice'),
                    Rec::flag($m, 'tax1'),
                    Rec::flag($m, 'tax2'),
                    Rec::nullable(Rec::str($m, 'notes')),
                    Rec::flag($m, 'active'),
                ]
            );
            $report->count('membership plans');
            foreach (Rec::strings($m, 'promos') as $promoName) {
                $promoId = $this->promoIds[mb_strtolower($promoName)] ?? null;
                if ($promoId === null) {
                    $report->warn('Membership "' . Rec::str($m, 'name') . '": discount "' . $promoName . '" not found.');
                    continue;
                }
                $this->db->execute(
                    'INSERT IGNORE INTO `cpi_membership_plan_promo` (`plan_id`, `promo_id`) VALUES (?, ?)',
                    [$planId, $promoId]
                );
            }
        }
    }

    private function money(string $text): string
    {
        return Money::parse($text === '' ? '0' : $text)->toDecimalString();
    }

    private function date(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        return $date !== false && $date->format('Y-m-d') === $text ? $text : null;
    }
}
