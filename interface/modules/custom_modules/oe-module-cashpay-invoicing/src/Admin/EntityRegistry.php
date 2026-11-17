<?php

/**
 * Every catalog table the admin pages can list and edit.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

use OpenEMR\Modules\CashpayInvoicing\Admin\FieldType as T;

final class EntityRegistry
{
    /** @var array<string, EntityDefinition>|null */
    private static ?array $cache = null;

    /**
     * @throws \OutOfBoundsException for an unknown key
     */
    public static function get(string $key): EntityDefinition
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new \OutOfBoundsException('Unknown catalog list');
        }
        return $all[$key];
    }

    /**
     * @return array<string, EntityDefinition>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $defs = [
            self::procedure(),
            self::product(),
            self::package(),
            self::membershipPlan(),
            self::promoDiscount(),
            self::nameOnly('procedure_category', 'cpi_procedure_category', 'Procedure Categories', 'Procedure Category'),
            self::nameOnly('product_category', 'cpi_product_category', 'Product Categories', 'Product Category'),
            self::nameOnly('manufacturer', 'cpi_manufacturer', 'Manufacturers', 'Manufacturer'),
            self::vendor(),
            self::nameOnly('discount_reason', 'cpi_discount_reason', 'Discount Reasons', 'Discount Reason', maxLength: 100),
            self::nameOnly('stock_reason', 'cpi_stock_reason', 'Stock Adjustment Reasons', 'Stock Adjustment Reason', null, 100),
            self::paymentMethod(),
        ];
        $map = [];
        foreach ($defs as $def) {
            $map[$def->key] = $def;
        }
        return self::$cache = $map;
    }

    /**
     * @return list<FieldDefinition>
     */
    private static function flags(bool $manualDesc = true): array
    {
        $flags = [
            new FieldDefinition('tax1', 'Charge Tax 1', T::Boolean),
            new FieldDefinition('tax2', 'Charge Tax 2', T::Boolean),
            new FieldDefinition('award_points_eligible', 'Earns award points', T::Boolean, default: '1'),
        ];
        if ($manualDesc) {
            $flags[] = new FieldDefinition('allow_manual_desc', 'Allow manual description on invoice', T::Boolean);
        }
        return $flags;
    }

    private static function active(): FieldDefinition
    {
        return new FieldDefinition('active', 'Active', T::Boolean, inList: true, default: '1');
    }

    private static function procedure(): EntityDefinition
    {
        return new EntityDefinition(
            key: 'procedure',
            table: 'cpi_procedure',
            title: 'Procedures',
            singular: 'Procedure',
            fields: [
                new FieldDefinition('name', 'Name', T::Text, required: true, inList: true),
                new FieldDefinition('category_id', 'Category', T::Lookup, inList: true, lookup: LookupSource::ProcedureCategory),
                new FieldDefinition('price', 'Price', T::Money, inList: true),
                new FieldDefinition('expected_hours', 'Expected hours', T::Decimal, scale: 2),
                new FieldDefinition('cpt_code', 'CPT code', T::Text, inList: true, maxLength: 20),
                new FieldDefinition('gl_code', 'GL code', T::Text, maxLength: 50),
                new FieldDefinition('barcode', 'Barcode', T::Text, maxLength: 64),
                new FieldDefinition('layman_terms', 'Layman terms (printed on quotes)', T::LongText),
                ...self::flags(),
                self::active(),
            ],
            uniqueField: null,
            panels: [Panel::ProcedureFees, Panel::ProcedureProducts],
            group: 'Catalog',
            description: 'Services sold to patients, with price overrides by provider or office.',
        );
    }

    private static function product(): EntityDefinition
    {
        return new EntityDefinition(
            key: 'product',
            table: 'cpi_product',
            title: 'Products',
            singular: 'Product',
            fields: [
                new FieldDefinition('name', 'Name', T::Text, required: true, inList: true),
                new FieldDefinition('style', 'Style / size', T::Text, maxLength: 100),
                new FieldDefinition('manufacturer_id', 'Manufacturer', T::Lookup, inList: true, lookup: LookupSource::Manufacturer),
                new FieldDefinition('category_id', 'Category', T::Lookup, inList: true, lookup: LookupSource::ProductCategory),
                new FieldDefinition('catalog_no', 'Catalog number', T::Text, maxLength: 64),
                new FieldDefinition('barcode', 'Barcode', T::Text, inList: true, maxLength: 64),
                new FieldDefinition('cost_to_practice', 'Cost to practice', T::Money, inList: true),
                new FieldDefinition('price_to_patient', 'Price to patient', T::Money, inList: true),
                ...self::flags(),
                new FieldDefinition('no_print', 'Do not print on receipts', T::Boolean),
                self::active(),
            ],
            uniqueField: null,
            panels: [Panel::ProductStock, Panel::ProductVendors, Panel::ProductFacilityPrices],
            group: 'Catalog',
            description: 'Retail and in-office products, stock by office, vendors.',
        );
    }

    private static function package(): EntityDefinition
    {
        return new EntityDefinition(
            key: 'package',
            table: 'cpi_package',
            title: 'Sales Packages',
            singular: 'Sales Package',
            fields: [
                new FieldDefinition('name', 'Name', T::Text, required: true, inList: true),
                new FieldDefinition('package_type', 'Type', T::Choice, inList: true, choices: [
                    'prepaid' => 'Prepaid (paid now, delivered over time)',
                    'immediate' => 'Immediate sale',
                ]),
                new FieldDefinition('income_facility_id', 'Income facility', T::Lookup, lookup: LookupSource::Facility),
                new FieldDefinition('start_date', 'Available from', T::Date, inList: true),
                new FieldDefinition('end_date', 'Available until', T::Date, inList: true),
                new FieldDefinition('allow_trades', 'Allow trading items', T::Boolean),
                new FieldDefinition('comments', 'Comments', T::LongText),
                self::active(),
            ],
            uniqueField: null,
            panels: [Panel::PackageItems],
            group: 'Catalog',
            description: 'Bundles of procedures and products sold at a package price.',
        );
    }

    private static function membershipPlan(): EntityDefinition
    {
        return new EntityDefinition(
            key: 'membership_plan',
            table: 'cpi_membership_plan',
            title: 'Membership Plans',
            singular: 'Membership Plan',
            fields: [
                new FieldDefinition('name', 'Name', T::Text, required: true, inList: true, maxLength: 150),
                new FieldDefinition('code', 'Code (badge beside patient name)', T::Text, required: true, inList: true, maxLength: 10),
                new FieldDefinition('annual_price', 'Annual price', T::Money, inList: true),
                new FieldDefinition('monthly_price', 'Monthly price', T::Money, inList: true),
                new FieldDefinition('monthly_points', 'Award points added each month', T::Decimal, scale: 2),
                new FieldDefinition('package_id', 'Package included each month', T::Lookup, lookup: LookupSource::Package),
                new FieldDefinition('add_package_monthly', 'Add the package every month', T::Boolean),
                new FieldDefinition('auto_monthly_invoice', 'Create the monthly invoice automatically', T::Boolean),
                new FieldDefinition('tax1', 'Charge Tax 1', T::Boolean),
                new FieldDefinition('tax2', 'Charge Tax 2', T::Boolean),
                new FieldDefinition('notes', 'Notes', T::LongText),
                self::active(),
            ],
            uniqueField: 'code',
            panels: [Panel::MembershipPromos],
            group: 'Catalog',
            description: 'Monthly or annual plans with member-only discounts.',
        );
    }

    private static function promoDiscount(): EntityDefinition
    {
        return new EntityDefinition(
            key: 'promo_discount',
            table: 'cpi_promo_discount',
            title: 'Promotional Discounts',
            singular: 'Promotional Discount',
            fields: [
                new FieldDefinition('name', 'Name', T::Text, required: true, inList: true, maxLength: 150),
                new FieldDefinition('reason_id', 'Discount reason', T::Lookup, lookup: LookupSource::DiscountReason),
                new FieldDefinition('kind', 'Discount type', T::Choice, inList: true, choices: [
                    'percent' => 'Percent off',
                    'flat_each' => 'Dollar amount off each item',
                    'flat_total' => 'Dollar amount off the line total',
                ]),
                new FieldDefinition('amount', 'Amount (percent or dollars)', T::Decimal, inList: true, scale: 4),
                new FieldDefinition('members_only', 'Members only', T::Boolean, inList: true),
                new FieldDefinition('start_date', 'Starts', T::Date, inList: true),
                new FieldDefinition('end_date', 'Ends', T::Date, inList: true),
                new FieldDefinition('notes', 'Notes', T::LongText),
                self::active(),
            ],
            uniqueField: null,
            panels: [Panel::PromoScope],
            group: 'Catalog',
            description: 'Discounts applied by item, office, invoice type or membership.',
        );
    }

    private static function vendor(): EntityDefinition
    {
        return new EntityDefinition(
            key: 'vendor',
            table: 'cpi_vendor',
            title: 'Vendors',
            singular: 'Vendor',
            fields: [
                new FieldDefinition('name', 'Name', T::Text, required: true, inList: true, maxLength: 150),
                new FieldDefinition('contact', 'Contact', T::Text, inList: true, maxLength: 150),
                new FieldDefinition('email', 'Email', T::Text, inList: true, maxLength: 150),
                new FieldDefinition('phone', 'Phone', T::Text, inList: true, maxLength: 40),
                self::active(),
            ],
        );
    }

    private static function paymentMethod(): EntityDefinition
    {
        return new EntityDefinition(
            key: 'payment_method',
            table: 'cpi_payment_method',
            title: 'Payment Methods',
            singular: 'Payment Method',
            fields: [
                new FieldDefinition('name', 'Name', T::Text, required: true, inList: true, maxLength: 100),
                new FieldDefinition('kind', 'Kind', T::Choice, inList: true, choices: [
                    'cash' => 'Cash',
                    'check' => 'Check',
                    'card' => 'Card',
                    'points' => 'Award points',
                    'gift_card' => 'Gift card',
                    'financing' => 'Financing',
                    'other' => 'Other',
                ]),
                new FieldDefinition('is_refund_method', 'Used for refunds', T::Boolean, inList: true),
                new FieldDefinition('sort_order', 'Sort order', T::Integer, inList: true, default: '0'),
                self::active(),
            ],
            orderBy: 'sort_order, name',
        );
    }

    private static function nameOnly(
        string $key,
        string $table,
        string $title,
        string $singular,
        ?string $unique = 'name',
        int $maxLength = 150,
    ): EntityDefinition
    {
        return new EntityDefinition(
            key: $key,
            table: $table,
            title: $title,
            singular: $singular,
            fields: [
                new FieldDefinition('name', 'Name', T::Text, required: true, inList: true, maxLength: $maxLength),
                self::active(),
            ],
            uniqueField: $unique,
        );
    }
}
