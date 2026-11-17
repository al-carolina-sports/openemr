<?php

/**
 * Delivery of invoice lines: receiving prepaid items, forfeiting them, and
 * the stock movements that go with delivery.
 *
 * Stock comes out of the line's service office: the product itself for a
 * product line, and the products a procedure uses for a procedure line.
 * Every movement records the line id, so clearing a delivery puts back
 * exactly what was taken.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Billing;

use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use OpenEMR\Modules\CashpayInvoicing\Inventory\StockService;
use OpenEMR\Modules\CashpayInvoicing\Inventory\StockTxnKind;

final readonly class DeliveryService
{
    public function __construct(
        private Database $db,
        private DocumentRepository $documents,
        private StockService $stock,
    ) {
    }

    public function receive(Document $invoice, int $lineId, \DateTimeImmutable $date, ?int $providerId, ?int $facilityId, int $userId): void
    {
        $line = $this->outstandingLine($invoice, $lineId);
        $this->db->transaction(function () use ($line, $date, $providerId, $facilityId, $userId): void {
            $this->db->execute(
                'UPDATE `cpi_line` SET `received_date` = ?, `received_by` = ?, `provider_id` = COALESCE(?, `provider_id`),'
                . ' `service_facility_id` = COALESCE(?, `service_facility_id`) WHERE `id` = ?',
                [$date->format('Y-m-d'), $userId, $providerId, $facilityId, $line->id]
            );
            $row = $this->db->fetchOne('SELECT * FROM `cpi_line` WHERE `id` = ?', [$line->id]);
            if ($row !== null) {
                $this->takeStock(Line::fromRow($row), $userId);
            }
        });
    }

    /** Changes the delivery date or provider of a received line. */
    public function editReceived(Document $invoice, int $lineId, \DateTimeImmutable $date, ?int $providerId, int $userId): void
    {
        $this->assertInvoice($invoice);
        $line = $this->documents->line($invoice, $lineId);
        if ($line === null || $line->receivedDate === null) {
            throw new ValidationException(['line' => 'That line has not been received']);
        }
        $this->db->execute(
            'UPDATE `cpi_line` SET `received_date` = ?, `received_by` = ?, `provider_id` = COALESCE(?, `provider_id`) WHERE `id` = ?',
            [$date->format('Y-m-d'), $userId, $providerId, $line->id]
        );
    }

    public function clearReceived(Document $invoice, int $lineId, int $userId): void
    {
        $this->assertInvoice($invoice);
        $line = $this->documents->line($invoice, $lineId);
        if ($line === null || $line->receivedDate === null) {
            throw new ValidationException(['line' => 'That line has not been received']);
        }
        if (!$invoice->invoiceType->lineDeferred($line->packageId)) {
            throw new ValidationException(['line' => 'Only prepaid, surgery and membership package lines can be un-received']);
        }
        $this->db->transaction(function () use ($line, $userId): void {
            $this->returnStock($line, $userId);
            $this->db->execute('UPDATE `cpi_line` SET `received_date` = NULL, `received_by` = NULL WHERE `id` = ?', [$line->id]);
        });
    }

    public function forfeit(Document $invoice, int $lineId, bool $forfeit): void
    {
        $this->assertInvoice($invoice);
        $line = $this->documents->line($invoice, $lineId);
        if ($line === null || $line->receivedDate !== null) {
            throw new ValidationException(['line' => 'Received lines cannot be forfeited']);
        }
        if ($line->forfeited === $forfeit) {
            return;
        }
        $this->db->execute('UPDATE `cpi_line` SET `forfeited` = ? WHERE `id` = ?', [$forfeit ? 1 : 0, $line->id]);
    }

    public function takeStock(Line $line, int $userId): void
    {
        if ($line->facilityId === null || $line->itemId === null || $line->parentKind !== DocumentKind::Invoice) {
            return;
        }
        if ($line->itemType === 'product') {
            $this->stock->adjust($line->itemId, $line->facilityId, -$line->qtyMilli, StockTxnKind::Sale, null, $userId, $line->id);
            return;
        }
        if ($line->itemType !== 'procedure') {
            return;
        }
        foreach ($this->db->fetchAll('SELECT `product_id`, `qty` FROM `cpi_procedure_product` WHERE `procedure_id` = ?', [$line->itemId]) as $use) {
            $perUnit = Row::scaled($use, 'qty', 3);
            // Both quantities are in thousandths; keep the product of them in thousandths.
            $used = intdiv($perUnit * $line->qtyMilli + 500, 1000);
            if ($used > 0) {
                $this->stock->adjust(Row::int($use, 'product_id'), $line->facilityId, -$used, StockTxnKind::ProcedureUse, null, $userId, $line->id);
            }
        }
    }

    /** Puts back whatever stock the line took. */
    public function returnStock(Line $line, int $userId): void
    {
        $moves = $this->db->fetchAll(
            'SELECT `product_id`, `facility_id`, SUM(`qty_delta`) AS `taken` FROM `cpi_stock_txn` WHERE `line_id` = ?'
            . ' GROUP BY `product_id`, `facility_id`',
            [$line->id]
        );
        foreach ($moves as $move) {
            $net = Row::scaled($move, 'taken', 3);
            if ($net !== 0) {
                $this->stock->adjust(
                    Row::int($move, 'product_id'),
                    Row::int($move, 'facility_id'),
                    -$net,
                    StockTxnKind::ReturnToStock,
                    null,
                    $userId,
                    $line->id,
                );
            }
        }
    }

    private function outstandingLine(Document $invoice, int $lineId): Line
    {
        $this->assertInvoice($invoice);
        $line = $this->documents->line($invoice, $lineId);
        if ($line === null || !$line->isOutstanding()) {
            throw new ValidationException(['line' => 'That line is already received or forfeited']);
        }
        return $line;
    }

    private function assertInvoice(Document $document): void
    {
        if ($document->kind !== DocumentKind::Invoice || $document->voided) {
            throw new ValidationException(['document' => 'Only open invoices have deliveries']);
        }
    }
}
