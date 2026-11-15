<?php

/**
 * Reads quotes, invoices and their lines. Every read is scoped to a patient
 * so one chart can never open another patient's documents.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Billing;

use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class DocumentRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(DocumentKind $kind, int $id, int $pid): ?Document
    {
        $row = $this->db->fetchOne("SELECT * FROM `{$kind->table()}` WHERE `id` = ? AND `pid` = ?", [$id, $pid]);
        return $row === null ? null : Document::fromRow($kind, $row);
    }

    /**
     * @return list<Line>
     */
    public function lines(Document $document): array
    {
        $lines = [];
        foreach (
            $this->db->fetchAll(
                'SELECT * FROM `cpi_line` WHERE `parent_type` = ? AND `parent_id` = ? ORDER BY `sort_order`, `id`',
                [$document->kind->value, $document->id]
            ) as $row
        ) {
            $lines[] = Line::fromRow($row);
        }
        return $lines;
    }

    public function line(Document $document, int $lineId): ?Line
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM `cpi_line` WHERE `id` = ? AND `parent_type` = ? AND `parent_id` = ?',
            [$lineId, $document->kind->value, $document->id]
        );
        return $row === null ? null : Line::fromRow($row);
    }

    /**
     * Header grid rows with totals, newest first.
     *
     * @return list<array{document: Document, total: Money, discount: Money, tax: Money, paid: Money, unearned: Money, lineCount: int}>
     */
    public function summaries(DocumentKind $kind, int $pid): array
    {
        $paidJoin = $kind === DocumentKind::Invoice
            ? ' LEFT JOIN (SELECT a.`invoice_id`, SUM(a.`amount`) AS `paid` FROM `cpi_payment_alloc` a'
              . " JOIN `cpi_payment` p ON p.`id` = a.`payment_id` AND p.`status` = 'posted'"
              . ' WHERE p.`pid` = ? GROUP BY a.`invoice_id`) pay ON pay.`invoice_id` = d.`id`'
            : '';
        $paidSelect = $kind === DocumentKind::Invoice ? ', COALESCE(pay.`paid`, 0) AS `paid`' : ', 0 AS `paid`';
        $binds = $kind === DocumentKind::Invoice ? [$pid, $kind->value, $pid] : [$kind->value, $pid];
        $rows = $this->db->fetchAll(
            'SELECT d.*, COALESCE(l.`net`, 0) AS `sum_net`, COALESCE(l.`tax`, 0) AS `sum_tax`, COALESCE(l.`discount`, 0) AS `sum_discount`,'
            . ' COALESCE(l.`unearned`, 0) AS `sum_unearned`, COALESCE(l.`n`, 0) AS `line_count`' . $paidSelect
            . " FROM `{$kind->table()}` d"
            . $paidJoin
            . ' LEFT JOIN (SELECT `parent_id`, SUM(`net`) AS `net`, SUM(`tax1_amt` + `tax2_amt`) AS `tax`, SUM(`discount_amt`) AS `discount`,'
            . ' SUM(CASE WHEN `received_date` IS NULL AND `forfeited` = 0 THEN `net` + `tax1_amt` + `tax2_amt` ELSE 0 END) AS `unearned`, COUNT(*) AS `n`'
            . ' FROM `cpi_line` WHERE `parent_type` = ? GROUP BY `parent_id`) l ON l.`parent_id` = d.`id`'
            . " WHERE d.`pid` = ? ORDER BY d.`{$kind->dateColumn()}` DESC, d.`id` DESC",
            $binds
        );
        $out = [];
        foreach ($rows as $row) {
            $document = Document::fromRow($kind, $row);
            $out[] = [
                'document' => $document,
                'total' => Row::money($row, 'sum_net')->add(Row::money($row, 'sum_tax')),
                'discount' => Row::money($row, 'sum_discount'),
                'tax' => Row::money($row, 'sum_tax'),
                'paid' => Row::money($row, 'paid'),
                // Lines delivered on sale are received when added, so only waiting lines count here.
                'unearned' => $kind === DocumentKind::Invoice && !$document->voided
                    ? Row::money($row, 'sum_unearned') : Money::zero(),
                'lineCount' => Row::int($row, 'line_count'),
            ];
        }
        return $out;
    }

    public function paidOn(int $invoiceId): Money
    {
        $row = $this->db->fetchOne(
            'SELECT COALESCE(SUM(a.`amount`), 0) AS `paid` FROM `cpi_payment_alloc` a'
            . " JOIN `cpi_payment` p ON p.`id` = a.`payment_id` AND p.`status` = 'posted' WHERE a.`invoice_id` = ?",
            [$invoiceId]
        );
        return $row === null ? Money::zero() : Row::money($row, 'paid');
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function lockHistory(int $invoiceId): array
    {
        return $this->db->fetchAll(
            'SELECT h.*, u.`username` FROM `cpi_invoice_lock_log` h LEFT JOIN `users` u ON u.`id` = h.`user_id`'
            . ' WHERE h.`invoice_id` = ? ORDER BY h.`id` DESC',
            [$invoiceId]
        );
    }
}
