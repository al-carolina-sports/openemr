<?php

/**
 * Sub-sections shown under the main form on an edit page.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

enum Panel
{
    case ProcedureFees;
    case ProcedureProducts;
    case ProductVendors;
    case ProductFacilityPrices;
    case ProductStock;
    case PackageItems;
    case MembershipPromos;
    case PromoScope;

    public function key(): string
    {
        return match ($this) {
            self::ProcedureFees => 'procedure_fees',
            self::ProcedureProducts => 'procedure_products',
            self::ProductVendors => 'product_vendors',
            self::ProductFacilityPrices => 'product_facility_prices',
            self::ProductStock => 'product_stock',
            self::PackageItems => 'package_items',
            self::MembershipPromos => 'membership_promos',
            self::PromoScope => 'promo_scope',
        };
    }

    public static function fromKey(string $key): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->key() === $key) {
                return $case;
            }
        }
        return null;
    }
}
