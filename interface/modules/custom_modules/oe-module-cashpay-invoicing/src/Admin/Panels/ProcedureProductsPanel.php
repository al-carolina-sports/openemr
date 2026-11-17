<?php

/**
 * Products a procedure uses; stock is taken when the procedure is delivered.
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
use OpenEMR\Modules\CashpayInvoicing\Decimal;

final readonly class ProcedureProductsPanel implements PanelHandler
{
    public function __construct(private Database $db, private LookupRepository $lookups)
    {
    }

    public function panel(): Panel
    {
        return Panel::ProcedureProducts;
    }

    public function title(): string
    {
        return 'Products used by this procedure';
    }

    public function load(int $ownerId): array
    {
        return [
            'rows' => $this->db->fetchAll(
                'SELECT pp.`product_id`, pp.`qty`, p.`name` FROM `cpi_procedure_product` pp'
                . ' JOIN `cpi_product` p ON p.`id` = pp.`product_id` WHERE pp.`procedure_id` = ? ORDER BY p.`name`',
                [$ownerId]
            ),
            'products' => $this->lookups->options(LookupSource::Product),
        ];
    }

    public function handle(int $ownerId, string $op, FormInput $input, int $userId): Notice
    {
        $productId = $input->requiredId('product_id');
        if ($op === 'delete') {
            $this->db->execute(
                'DELETE FROM `cpi_procedure_product` WHERE `procedure_id` = ? AND `product_id` = ?',
                [$ownerId, $productId]
            );
            return Notice::Removed;
        }
        if ($op !== 'save') {
            throw new ValidationException(['op' => 'Unknown action']);
        }
        $qty = $input->milli('qty') ?? 0;
        if ($qty <= 0) {
            throw new ValidationException(['qty' => 'Quantity must be more than zero']);
        }
        $this->db->execute(
            'INSERT INTO `cpi_procedure_product` (`procedure_id`, `product_id`, `qty`) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `qty` = VALUES(`qty`)',
            [$ownerId, $productId, Decimal::format($qty, 3)]
        );
        return Notice::Saved;
    }
}
