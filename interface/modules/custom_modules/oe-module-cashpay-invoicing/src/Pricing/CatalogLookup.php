<?php

/**
 * Reads catalog items for pricing and for the editor's item search.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Pricing;

use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class CatalogLookup
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param 'procedure'|'product' $type
     */
    public function entry(string $type, int $id): ?CatalogEntry
    {
        return $type === 'procedure' ? $this->procedure($id) : $this->product($id);
    }

    public function procedure(int $id): ?CatalogEntry
    {
        $row = $this->db->fetchOne('SELECT * FROM `cpi_procedure` WHERE `id` = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $fees = [];
        foreach ($this->db->fetchAll('SELECT * FROM `cpi_procedure_fee` WHERE `procedure_id` = ?', [$id]) as $fee) {
            $fees[] = new FeeOverride(Row::intOrNull($fee, 'provider_id'), Row::intOrNull($fee, 'facility_id'), Row::money($fee, 'price'));
        }
        return new CatalogEntry(
            type: 'procedure',
            id: $id,
            name: Row::str($row, 'name'),
            code: Row::strOrNull($row, 'cpt_code'),
            standardPrice: Row::money($row, 'price'),
            unitCost: Money::zero(),
            tax1: Row::bool($row, 'tax1'),
            tax2: Row::bool($row, 'tax2'),
            pointsEligible: Row::bool($row, 'award_points_eligible'),
            allowManualDescription: Row::bool($row, 'allow_manual_desc'),
            categoryId: Row::intOrNull($row, 'category_id'),
            manufacturerId: null,
            vendorIds: [],
            fees: $fees,
            facilityPrices: [],
            active: Row::bool($row, 'active'),
        );
    }

    public function product(int $id): ?CatalogEntry
    {
        $row = $this->db->fetchOne('SELECT * FROM `cpi_product` WHERE `id` = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $prices = [];
        foreach ($this->db->fetchAll('SELECT * FROM `cpi_product_facility_price` WHERE `product_id` = ?', [$id]) as $price) {
            $prices[Row::int($price, 'facility_id')] = Row::money($price, 'price_to_patient');
        }
        $vendors = [];
        foreach ($this->db->fetchAll('SELECT `vendor_id` FROM `cpi_product_vendor` WHERE `product_id` = ?', [$id]) as $vendor) {
            $vendors[] = Row::int($vendor, 'vendor_id');
        }
        return new CatalogEntry(
            type: 'product',
            id: $id,
            name: Row::str($row, 'name'),
            code: Row::strOrNull($row, 'catalog_no'),
            standardPrice: Row::money($row, 'price_to_patient'),
            unitCost: Row::money($row, 'cost_to_practice'),
            tax1: Row::bool($row, 'tax1'),
            tax2: Row::bool($row, 'tax2'),
            pointsEligible: Row::bool($row, 'award_points_eligible'),
            allowManualDescription: Row::bool($row, 'allow_manual_desc'),
            categoryId: Row::intOrNull($row, 'category_id'),
            manufacturerId: Row::intOrNull($row, 'manufacturer_id'),
            vendorIds: $vendors,
            fees: [],
            facilityPrices: $prices,
            active: Row::bool($row, 'active'),
        );
    }

    /**
     * Active procedures, products and packages whose name, code or barcode
     * contains the text. Packages are only offered when available today.
     *
     * @return list<array{type: string, id: int, name: string, detail: string, price: string}>
     */
    public function search(string $text, int $limit = 25): array
    {
        $text = trim($text);
        if (mb_strlen($text) < 2) {
            return [];
        }
        $like = '%' . addcslashes($text, '%_\\') . '%';
        $limit = max(1, min(50, $limit));
        $out = [];
        $rows = $this->db->fetchAll(
            'SELECT p.`id`, p.`name`, p.`price`, p.`cpt_code`, c.`name` AS `category` FROM `cpi_procedure` p'
            . ' LEFT JOIN `cpi_procedure_category` c ON c.`id` = p.`category_id`'
            . ' WHERE p.`active` = 1 AND (p.`name` LIKE ? OR p.`cpt_code` LIKE ? OR p.`barcode` = ?) ORDER BY p.`name` LIMIT ' . $limit,
            [$like, $like, $text]
        );
        foreach ($rows as $row) {
            $out[] = [
                'type' => 'procedure',
                'id' => Row::int($row, 'id'),
                'name' => Row::str($row, 'name'),
                'detail' => trim(Row::str($row, 'category') . ' ' . Row::str($row, 'cpt_code')),
                'price' => Row::money($row, 'price')->format(),
            ];
        }
        $rows = $this->db->fetchAll(
            'SELECT p.`id`, p.`name`, p.`style`, p.`price_to_patient`, m.`name` AS `manufacturer` FROM `cpi_product` p'
            . ' LEFT JOIN `cpi_manufacturer` m ON m.`id` = p.`manufacturer_id`'
            . ' WHERE p.`active` = 1 AND (p.`name` LIKE ? OR p.`catalog_no` LIKE ? OR p.`barcode` = ?) ORDER BY p.`name` LIMIT ' . $limit,
            [$like, $like, $text]
        );
        foreach ($rows as $row) {
            $out[] = [
                'type' => 'product',
                'id' => Row::int($row, 'id'),
                'name' => Row::str($row, 'name'),
                'detail' => trim(Row::str($row, 'manufacturer') . ' ' . Row::str($row, 'style')),
                'price' => Row::money($row, 'price_to_patient')->format(),
            ];
        }
        $rows = $this->db->fetchAll(
            'SELECT k.`id`, k.`name`, k.`package_type` FROM `cpi_package` k'
            . ' WHERE k.`active` = 1 AND k.`name` LIKE ?'
            . ' AND (k.`start_date` IS NULL OR k.`start_date` <= CURDATE()) AND (k.`end_date` IS NULL OR k.`end_date` >= CURDATE())'
            . ' ORDER BY k.`name` LIMIT ' . $limit,
            [$like]
        );
        foreach ($rows as $row) {
            $out[] = [
                'type' => 'package',
                'id' => Row::int($row, 'id'),
                'name' => Row::str($row, 'name'),
                'detail' => Row::str($row, 'package_type') === 'immediate' ? 'Package, immediate sale' : 'Package, prepaid',
                'price' => '',
            ];
        }
        return $out;
    }

    /**
     * @return array{name: string, type: string, items: list<array{type: 'procedure'|'product', id: int, qtyMilli: int, unitPrice: Money, discount: Money}>}|null
     */
    public function package(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM `cpi_package` WHERE `id` = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $items = [];
        foreach ($this->db->fetchAll('SELECT * FROM `cpi_package_item` WHERE `package_id` = ? ORDER BY `sort_order`, `id`', [$id]) as $item) {
            $items[] = [
                'type' => Row::str($item, 'item_type') === 'product' ? 'product' : 'procedure',
                'id' => Row::int($item, 'item_id'),
                'qtyMilli' => Row::scaled($item, 'qty', 3),
                'unitPrice' => Row::money($item, 'unit_price'),
                'discount' => Row::money($item, 'discount'),
            ];
        }
        return ['name' => Row::str($row, 'name'), 'type' => Row::str($row, 'package_type'), 'items' => $items];
    }
}
