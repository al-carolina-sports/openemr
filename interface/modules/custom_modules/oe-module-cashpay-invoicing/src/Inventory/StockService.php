<?php

/**
 * Changes on-hand stock and records every change in cpi_stock_txn.
 * Quantities are scaled integers in thousandths (DECIMAL(10,3)).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Inventory;

use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Decimal;

final class StockService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Adds (or with a negative delta removes) stock.
     */
    public function adjust(
        int $productId,
        int $facilityId,
        int $deltaMilli,
        StockTxnKind $kind,
        ?int $reasonId,
        ?int $userId,
        ?int $lineId = null,
    ): void {
        if ($deltaMilli === 0) {
            return;
        }
        $this->db->transaction(function () use ($productId, $facilityId, $deltaMilli, $kind, $reasonId, $userId, $lineId): void {
            $this->ensureRow($productId, $facilityId);
            $delta = Decimal::format($deltaMilli, 3);
            $this->db->execute(
                'UPDATE `cpi_stock` SET `on_hand` = `on_hand` + ? WHERE `product_id` = ? AND `facility_id` = ?',
                [$delta, $productId, $facilityId]
            );
            if ($kind === StockTxnKind::ReceiveOrder) {
                $this->db->execute(
                    'UPDATE `cpi_stock` SET `on_order` = GREATEST(0, `on_order` - ?) WHERE `product_id` = ? AND `facility_id` = ?',
                    [$delta, $productId, $facilityId]
                );
            }
            $this->log($productId, $facilityId, $delta, $kind, $reasonId, $userId, $lineId);
        });
    }

    /**
     * Sets on-hand to a physical count; the difference is logged.
     */
    public function recordCount(int $productId, int $facilityId, int $countedMilli, ?int $reasonId, ?int $userId): void
    {
        if ($countedMilli < 0) {
            throw new \DomainException('A count cannot be negative');
        }
        $this->db->transaction(function () use ($productId, $facilityId, $countedMilli, $reasonId, $userId): void {
            $this->ensureRow($productId, $facilityId);
            $row = $this->db->fetchOne(
                'SELECT `on_hand` FROM `cpi_stock` WHERE `product_id` = ? AND `facility_id` = ? FOR UPDATE',
                [$productId, $facilityId]
            );
            $current = is_string($row['on_hand'] ?? null) ? Decimal::parse($row['on_hand'], 3) : 0;
            $delta = $countedMilli - $current;
            $this->db->execute(
                'UPDATE `cpi_stock` SET `on_hand` = ? WHERE `product_id` = ? AND `facility_id` = ?',
                [Decimal::format($countedMilli, 3), $productId, $facilityId]
            );
            $this->log($productId, $facilityId, Decimal::format($delta, 3), StockTxnKind::Count, $reasonId, $userId, null);
        });
    }

    public function setLevels(int $productId, int $facilityId, int $onOrderMilli, int $lowStockMilli): void
    {
        if ($onOrderMilli < 0 || $lowStockMilli < 0) {
            throw new \DomainException('Levels cannot be negative');
        }
        $this->ensureRow($productId, $facilityId);
        $this->db->execute(
            'UPDATE `cpi_stock` SET `on_order` = ?, `low_stock_level` = ? WHERE `product_id` = ? AND `facility_id` = ?',
            [Decimal::format($onOrderMilli, 3), Decimal::format($lowStockMilli, 3), $productId, $facilityId]
        );
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function recentTransactions(int $productId, int $limit = 25): array
    {
        return $this->db->fetchAll(
            'SELECT t.*, f.`name` AS `facility_name`, r.`name` AS `reason_name`, u.`username`'
            . ' FROM `cpi_stock_txn` t'
            . ' LEFT JOIN `facility` f ON f.`id` = t.`facility_id`'
            . ' LEFT JOIN `cpi_stock_reason` r ON r.`id` = t.`reason_id`'
            . ' LEFT JOIN `users` u ON u.`id` = t.`user_id`'
            . ' WHERE t.`product_id` = ? ORDER BY t.`id` DESC LIMIT ' . max(1, min(200, $limit)),
            [$productId]
        );
    }

    private function ensureRow(int $productId, int $facilityId): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO `cpi_stock` (`product_id`, `facility_id`) VALUES (?, ?)',
            [$productId, $facilityId]
        );
    }

    private function log(int $productId, int $facilityId, string $delta, StockTxnKind $kind, ?int $reasonId, ?int $userId, ?int $lineId): void
    {
        $this->db->execute(
            'INSERT INTO `cpi_stock_txn` (`product_id`, `facility_id`, `qty_delta`, `kind`, `reason_id`, `line_id`, `user_id`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$productId, $facilityId, $delta, $kind->value, $reasonId, $lineId, $userId]
        );
    }
}
