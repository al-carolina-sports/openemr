<?php

/**
 * Where a lookup field's options come from.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin;

enum LookupSource
{
    case ProcedureCategory;
    case ProductCategory;
    case Manufacturer;
    case Vendor;
    case DiscountReason;
    case StockReason;
    case Package;
    case Procedure;
    case Product;
    case PromoDiscount;
    /** OpenEMR facility table. */
    case Facility;
    /** OpenEMR users (staff and providers), for sellers. */
    case User;
    /** OpenEMR users marked as providers (authorized). */
    case Provider;

    /**
     * Module table and label column, or null for core tables
     * (handled separately because their columns differ).
     *
     * @return array{string, string}|null
     */
    public function moduleTable(): ?array
    {
        return match ($this) {
            self::ProcedureCategory => ['cpi_procedure_category', 'name'],
            self::ProductCategory => ['cpi_product_category', 'name'],
            self::Manufacturer => ['cpi_manufacturer', 'name'],
            self::Vendor => ['cpi_vendor', 'name'],
            self::DiscountReason => ['cpi_discount_reason', 'name'],
            self::StockReason => ['cpi_stock_reason', 'name'],
            self::Package => ['cpi_package', 'name'],
            self::Procedure => ['cpi_procedure', 'name'],
            self::Product => ['cpi_product', 'name'],
            self::PromoDiscount => ['cpi_promo_discount', 'name'],
            self::Facility, self::User, self::Provider => null,
        };
    }
}
