<?php

/**
 * Builds the handler for each panel.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin\Panels;

use OpenEMR\Modules\CashpayInvoicing\Admin\LookupRepository;
use OpenEMR\Modules\CashpayInvoicing\Admin\Panel;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Inventory\StockService;

final readonly class PanelFactory
{
    public function __construct(
        private Database $db,
        private LookupRepository $lookups,
        private StockService $stock,
    ) {
    }

    public function create(Panel $panel): PanelHandler
    {
        return match ($panel) {
            Panel::ProcedureFees => new ProcedureFeesPanel($this->db, $this->lookups),
            Panel::ProcedureProducts => new ProcedureProductsPanel($this->db, $this->lookups),
            Panel::ProductVendors => new ProductVendorsPanel($this->db, $this->lookups),
            Panel::ProductFacilityPrices => new ProductFacilityPricesPanel($this->db, $this->lookups),
            Panel::ProductStock => new ProductStockPanel($this->db, $this->lookups, $this->stock),
            Panel::PackageItems => new PackageItemsPanel($this->db, $this->lookups),
            Panel::MembershipPromos => new MembershipPromosPanel($this->db),
            Panel::PromoScope => new PromoScopePanel($this->db, $this->lookups),
        };
    }
}
