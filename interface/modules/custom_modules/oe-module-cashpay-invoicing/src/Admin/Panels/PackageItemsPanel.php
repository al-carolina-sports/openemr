<?php

/**
 * Lines of a sales package and the resulting package price.
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
use OpenEMR\Modules\CashpayInvoicing\Catalog\PackageLine;
use OpenEMR\Modules\CashpayInvoicing\Catalog\PackageTotals;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class PackageItemsPanel implements PanelHandler
{
    public function __construct(private Database $db, private LookupRepository $lookups)
    {
    }

    public function panel(): Panel
    {
        return Panel::PackageItems;
    }

    public function title(): string
    {
        return 'Package items';
    }

    public function load(int $ownerId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT i.*, COALESCE(pr.`name`, pd.`name`) AS `item_name`, COALESCE(pr.`price`, pd.`price_to_patient`) AS `list_price`'
            . ' FROM `cpi_package_item` i'
            . " LEFT JOIN `cpi_procedure` pr ON i.`item_type` = 'procedure' AND pr.`id` = i.`item_id`"
            . " LEFT JOIN `cpi_product` pd ON i.`item_type` = 'product' AND pd.`id` = i.`item_id`"
            . ' WHERE i.`package_id` = ? ORDER BY i.`sort_order`, i.`id`',
            [$ownerId]
        );
        $lines = [];
        $view = [];
        foreach ($rows as $row) {
            $line = self::lineOf($row);
            $lines[] = $line;
            $view[] = $row + [
                'gross_display' => $line->gross()->format(),
                'net_display' => $line->net()->format(),
            ];
        }
        $totals = PackageTotals::of($lines);
        return [
            'rows' => $view,
            'totals' => [
                'gross' => $totals->gross->format(),
                'discount' => $totals->discount->format(),
                'net' => $totals->net->format(),
            ],
            'procedures' => $this->lookups->options(LookupSource::Procedure),
            'products' => $this->lookups->options(LookupSource::Product),
        ];
    }

    public function handle(int $ownerId, string $op, FormInput $input, int $userId): Notice
    {
        return match ($op) {
            'add' => $this->add($ownerId, $input),
            'update' => $this->update($ownerId, $input),
            'delete' => $this->delete($ownerId, $input->requiredId('item_row_id')),
            default => throw new ValidationException(['op' => 'Unknown action']),
        };
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function lineOf(array $row): PackageLine
    {
        $qty = is_string($row['qty'] ?? null) ? Decimal::parse($row['qty'], 3) : 0;
        $unit = is_string($row['unit_price'] ?? null) ? Money::parse($row['unit_price']) : Money::zero();
        $discount = is_string($row['discount'] ?? null) ? Money::parse($row['discount']) : Money::zero();
        return new PackageLine($qty, $unit, $discount);
    }

    private function add(int $packageId, FormInput $input): Notice
    {
        $type = $input->string('item_type');
        if (!in_array($type, ['procedure', 'product'], true)) {
            throw new ValidationException(['item_type' => 'Pick procedure or product']);
        }
        $itemId = $input->requiredId($type === 'procedure' ? 'procedure_id' : 'product_id');
        $qty = $input->milli('qty') ?? 0;
        if ($qty <= 0) {
            throw new ValidationException(['qty' => 'Quantity must be more than zero']);
        }
        $unit = $input->money('unit_price', false) ?? $this->catalogPrice($type, $itemId);
        $discount = $input->money('discount', false) ?? Money::zero();
        $next = $this->db->fetchOne('SELECT COALESCE(MAX(`sort_order`), 0) + 10 AS `n` FROM `cpi_package_item` WHERE `package_id` = ?', [$packageId]);
        $this->db->insert(
            'INSERT INTO `cpi_package_item` (`package_id`, `item_type`, `item_id`, `qty`, `unit_price`, `discount`, `sort_order`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $packageId, $type, $itemId, Decimal::format($qty, 3), $unit->toDecimalString(), $discount->toDecimalString(),
                is_numeric($next['n'] ?? null) ? (int) $next['n'] : 10,
            ]
        );
        return Notice::Added;
    }

    private function update(int $packageId, FormInput $input): Notice
    {
        $qty = $input->milli('qty') ?? 0;
        if ($qty <= 0) {
            throw new ValidationException(['qty' => 'Quantity must be more than zero']);
        }
        $sort = $input->string('sort_order');
        if (preg_match('/^\d{1,6}$/', $sort) !== 1) {
            throw new ValidationException(['sort_order' => 'Enter a whole number']);
        }
        $this->db->execute(
            'UPDATE `cpi_package_item` SET `qty` = ?, `unit_price` = ?, `discount` = ?, `sort_order` = ? WHERE `id` = ? AND `package_id` = ?',
            [
                Decimal::format($qty, 3),
                ($input->money('unit_price') ?? Money::zero())->toDecimalString(),
                ($input->money('discount', false) ?? Money::zero())->toDecimalString(),
                (int) $sort,
                $input->requiredId('item_row_id'),
                $packageId,
            ]
        );
        return Notice::Updated;
    }

    private function delete(int $packageId, int $rowId): Notice
    {
        $this->db->execute('DELETE FROM `cpi_package_item` WHERE `id` = ? AND `package_id` = ?', [$rowId, $packageId]);
        return Notice::Removed;
    }

    private function catalogPrice(string $type, int $itemId): Money
    {
        $row = $type === 'procedure'
            ? $this->db->fetchOne('SELECT `price` AS `p` FROM `cpi_procedure` WHERE `id` = ?', [$itemId])
            : $this->db->fetchOne('SELECT `price_to_patient` AS `p` FROM `cpi_product` WHERE `id` = ?', [$itemId]);
        if ($row === null) {
            throw new ValidationException(['item_id' => 'Item not found']);
        }
        return is_string($row['p']) ? Money::parse($row['p']) : Money::zero();
    }
}
