<?php

/**
 * Limits a promotional discount to invoice types, offices and items.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin\Panels;

use OpenEMR\Modules\CashpayInvoicing\Admin\FormInput;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupRepository;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupSource;
use OpenEMR\Modules\CashpayInvoicing\Admin\Notice;
use OpenEMR\Modules\CashpayInvoicing\Admin\Panel;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Catalog\PromoScope;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;

final readonly class PromoScopePanel implements PanelHandler
{
    private const SOURCES = [
        'facility_ids' => LookupSource::Facility,
        'procedure_category_ids' => LookupSource::ProcedureCategory,
        'procedure_ids' => LookupSource::Procedure,
        'product_category_ids' => LookupSource::ProductCategory,
        'product_ids' => LookupSource::Product,
        'vendor_ids' => LookupSource::Vendor,
        'manufacturer_ids' => LookupSource::Manufacturer,
    ];

    public function __construct(private Database $db, private LookupRepository $lookups)
    {
    }

    public function panel(): Panel
    {
        return Panel::PromoScope;
    }

    public function title(): string
    {
        return 'Applies to (leave a list empty for all)';
    }

    public function load(int $ownerId): array
    {
        $scope = $this->scopeOf($ownerId);
        $dimensions = [];
        foreach (PromoScope::DIMENSIONS as $key => $label) {
            $dimensions[] = [
                'key' => $key,
                'label' => $label,
                'options' => $this->lookups->names(self::SOURCES[$key]),
                'selected' => $scope->ids($key),
            ];
        }
        return [
            'invoiceTypes' => PromoScope::INVOICE_TYPES,
            'selectedTypes' => $scope->invoiceTypes,
            'dimensions' => $dimensions,
            'noProducts' => $scope->noProducts,
        ];
    }

    public function handle(int $ownerId, string $op, FormInput $input, int $userId): Notice
    {
        if ($op !== 'save') {
            throw new ValidationException(['op' => 'Unknown action']);
        }
        $scope = PromoScope::fromInput($input->all());
        $this->db->execute('UPDATE `cpi_promo_discount` SET `scope_json` = ? WHERE `id` = ?', [$scope->toJson(), $ownerId]);
        return Notice::Saved;
    }

    private function scopeOf(int $promoId): PromoScope
    {
        $row = $this->db->fetchOne('SELECT `scope_json` FROM `cpi_promo_discount` WHERE `id` = ?', [$promoId]);
        $json = $row['scope_json'] ?? null;
        return PromoScope::fromJson(is_string($json) ? $json : null);
    }
}
