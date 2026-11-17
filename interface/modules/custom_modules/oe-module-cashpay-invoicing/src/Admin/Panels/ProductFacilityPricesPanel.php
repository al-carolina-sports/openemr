<?php

/**
 * Office-specific retail prices that replace the product's standard price.
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
use OpenEMR\Modules\CashpayInvoicing\Database\Database;

final readonly class ProductFacilityPricesPanel implements PanelHandler
{
    public function __construct(private Database $db, private LookupRepository $lookups)
    {
    }

    public function panel(): Panel
    {
        return Panel::ProductFacilityPrices;
    }

    public function title(): string
    {
        return 'Office prices';
    }

    public function load(int $ownerId): array
    {
        return [
            'rows' => $this->db->fetchAll(
                'SELECT * FROM `cpi_product_facility_price` WHERE `product_id` = ? ORDER BY `facility_id`',
                [$ownerId]
            ),
            'facilities' => $this->lookups->options(LookupSource::Facility),
            'facilityNames' => $this->lookups->names(LookupSource::Facility),
        ];
    }

    public function handle(int $ownerId, string $op, FormInput $input, int $userId): Notice
    {
        $facilityId = $input->requiredId('facility_id');
        if ($op === 'delete') {
            $this->db->execute(
                'DELETE FROM `cpi_product_facility_price` WHERE `product_id` = ? AND `facility_id` = ?',
                [$ownerId, $facilityId]
            );
            return Notice::Removed;
        }
        if ($op !== 'save') {
            throw new ValidationException(['op' => 'Unknown action']);
        }
        $price = $input->money('price_to_patient');
        $this->db->execute(
            'INSERT INTO `cpi_product_facility_price` (`product_id`, `facility_id`, `price_to_patient`) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `price_to_patient` = VALUES(`price_to_patient`)',
            [$ownerId, $facilityId, $price?->toDecimalString()]
        );
        return Notice::Saved;
    }
}
