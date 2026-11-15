<?php

/**
 * Stock by office: on hand, on order, low-stock level, and changes.
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
use OpenEMR\Modules\CashpayInvoicing\Inventory\StockService;
use OpenEMR\Modules\CashpayInvoicing\Inventory\StockTxnKind;

final readonly class ProductStockPanel implements PanelHandler
{
    public function __construct(
        private Database $db,
        private LookupRepository $lookups,
        private StockService $stock,
    ) {
    }

    public function panel(): Panel
    {
        return Panel::ProductStock;
    }

    public function title(): string
    {
        return 'Stock by office';
    }

    public function load(int $ownerId): array
    {
        $stored = [];
        foreach ($this->db->fetchAll('SELECT * FROM `cpi_stock` WHERE `product_id` = ?', [$ownerId]) as $row) {
            if (is_numeric($row['facility_id'] ?? null)) {
                $stored[(int) $row['facility_id']] = $row;
            }
        }
        $rows = [];
        foreach ($this->lookups->options(LookupSource::Facility) as $facilityId => $name) {
            $row = $stored[$facilityId] ?? ['on_hand' => '0.000', 'on_order' => '0.000', 'low_stock_level' => '0.000'];
            $rows[] = [
                'facility_id' => $facilityId,
                'facility_name' => $name,
                'on_hand' => Decimal::display($row['on_hand']),
                'on_order' => Decimal::display($row['on_order']),
                'low_stock_level' => Decimal::display($row['low_stock_level']),
                'is_low' => self::isLow($row['on_hand'] ?? null, $row['low_stock_level'] ?? null),
            ];
        }
        return [
            'rows' => $rows,
            'reasons' => $this->lookups->options(LookupSource::StockReason),
            'history' => $this->stock->recentTransactions($ownerId),
        ];
    }

    public static function isLow(mixed $onHand, mixed $lowLevel): bool
    {
        if (!is_string($onHand) || !is_string($lowLevel)) {
            return false;
        }
        $low = Decimal::parse($lowLevel, 3);
        return $low > 0 && Decimal::parse($onHand, 3) <= $low;
    }

    public function handle(int $ownerId, string $op, FormInput $input, int $userId): Notice
    {
        $facilityId = $input->requiredId('facility_id');
        switch ($op) {
            case 'levels':
                $this->stock->setLevels($ownerId, $facilityId, $input->milli('on_order') ?? 0, $input->milli('low_stock_level') ?? 0);
                return Notice::Saved;
            case 'adjust':
                $delta = $input->milli('qty') ?? 0;
                if ($delta === 0) {
                    throw new ValidationException(['qty' => 'Enter a quantity to add or (with a minus sign) remove']);
                }
                $reasonId = $input->optionalId('reason_id') ?? throw new ValidationException(['reason_id' => 'Pick a reason']);
                $this->stock->adjust($ownerId, $facilityId, $delta, StockTxnKind::Adjust, $reasonId, $userId);
                return Notice::StockAdjusted;
            case 'receive':
                $qty = $input->milli('qty') ?? 0;
                if ($qty <= 0) {
                    throw new ValidationException(['qty' => 'Enter the quantity received']);
                }
                $this->stock->adjust($ownerId, $facilityId, $qty, StockTxnKind::ReceiveOrder, null, $userId);
                return Notice::OrderReceived;
            case 'count':
                $counted = $input->milli('qty') ?? 0;
                if ($counted < 0) {
                    throw new ValidationException(['qty' => 'A count cannot be negative']);
                }
                $this->stock->recordCount($ownerId, $facilityId, $counted, $input->optionalId('reason_id'), $userId);
                return Notice::CountRecorded;
            default:
                throw new ValidationException(['op' => 'Unknown action']);
        }
    }
}
