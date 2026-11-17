<?php

/**
 * Creates and changes quote and invoice headers: save, quote to invoice,
 * copy, lock and unlock (with history), and delete.
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
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;

final readonly class DocumentService
{
    public function __construct(
        private Database $db,
        private DocumentRepository $documents,
        private LineService $lines,
        private DeliveryService $delivery,
        private SettingsRepository $settings,
    ) {
    }

    public function create(DocumentKind $kind, int $pid, HeaderInput $input, int $userId): Document
    {
        if ($kind === DocumentKind::Quote) {
            $expires = $input->expiresOn ?? $input->date->modify('+' . $this->settings->load()->quoteValidDays . ' days');
            $id = $this->db->insert(
                'INSERT INTO `cpi_quote` (`pid`, `name`, `quote_date`, `facility_id`, `invoice_type`, `provider_id`, `seller_id`, `comments`,'
                . ' `expires_on`, `is_active`, `consult_credit`, `deposit`, `created_by`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $pid, $input->name, $input->date->format('Y-m-d'), $input->facilityId, $input->invoiceType->value,
                    $input->providerId, $input->sellerId, $input->comments, $expires->format('Y-m-d'), $input->isActive ? 1 : 0,
                    $input->consultCredit->toDecimalString(), $input->deposit->toDecimalString(), $userId,
                ]
            );
        } else {
            $id = $this->db->insert(
                'INSERT INTO `cpi_invoice` (`pid`, `name`, `invoice_date`, `invoice_type`, `income_facility_id`, `provider_id`, `seller_id`,'
                . ' `comments`, `deposit`, `created_by`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $pid, $input->name, $input->date->format('Y-m-d'), $input->invoiceType->value, $input->facilityId,
                    $input->providerId, $input->sellerId, $input->comments, $input->deposit->toDecimalString(), $userId,
                ]
            );
        }
        return $this->documents->find($kind, $id, $pid) ?? throw new \RuntimeException('New document could not be read back');
    }

    public function saveHeader(Document $document, HeaderInput $input): void
    {
        if (!$document->editable()) {
            throw new ValidationException(['document' => 'This invoice is locked. Unlock it to make changes.']);
        }
        if ($input->invoiceType !== $document->invoiceType && $document->kind === DocumentKind::Invoice && $this->hasLines($document)) {
            throw new ValidationException(['invoice_type' => 'Remove the lines before changing the invoice type']);
        }
        $this->db->transaction(function () use ($document, $input): void {
            if ($document->kind === DocumentKind::Quote) {
                $this->db->execute(
                    'UPDATE `cpi_quote` SET `name` = ?, `quote_date` = ?, `facility_id` = ?, `invoice_type` = ?, `provider_id` = ?, `seller_id` = ?,'
                    . ' `comments` = ?, `expires_on` = ?, `is_active` = ?, `consult_credit` = ?, `deposit` = ? WHERE `id` = ?',
                    [
                        $input->name, $input->date->format('Y-m-d'), $input->facilityId, $input->invoiceType->value, $input->providerId,
                        $input->sellerId, $input->comments, $input->expiresOn?->format('Y-m-d'), $input->isActive ? 1 : 0,
                        $input->consultCredit->toDecimalString(), $input->deposit->toDecimalString(), $document->id,
                    ]
                );
            } else {
                $this->db->execute(
                    'UPDATE `cpi_invoice` SET `name` = ?, `invoice_date` = ?, `invoice_type` = ?, `income_facility_id` = ?, `provider_id` = ?,'
                    . ' `seller_id` = ?, `comments` = ?, `deposit` = ? WHERE `id` = ?',
                    [
                        $input->name, $input->date->format('Y-m-d'), $input->invoiceType->value, $input->facilityId, $input->providerId,
                        $input->sellerId, $input->comments, $input->deposit->toDecimalString(), $document->id,
                    ]
                );
            }
            if ($input->applyToLines) {
                $this->db->execute(
                    'UPDATE `cpi_line` SET `provider_id` = ?, `seller_id` = ?, `service_facility_id` = COALESCE(?, `service_facility_id`)'
                    . ' WHERE `parent_type` = ? AND `parent_id` = ? AND `received_date` IS NULL',
                    [$input->providerId, $input->sellerId, $input->facilityId, $document->kind->value, $document->id]
                );
                $this->db->execute(
                    'UPDATE `cpi_line` SET `seller_id` = ? WHERE `parent_type` = ? AND `parent_id` = ?',
                    [$input->sellerId, $document->kind->value, $document->id]
                );
            }
        });
    }

    /**
     * Creates an invoice from a quote, copying its lines.
     */
    public function convertQuote(Document $quote, \DateTimeImmutable $today, int $userId): Document
    {
        if ($quote->kind !== DocumentKind::Quote) {
            throw new \LogicException('Not a quote');
        }
        if ($quote->convertedInvoiceId !== null && $this->documents->find(DocumentKind::Invoice, $quote->convertedInvoiceId, $quote->pid) !== null) {
            throw new ValidationException(['quote' => 'This quote already has an invoice']);
        }
        return $this->db->transaction(function () use ($quote, $today, $userId): Document {
            $invoice = $this->create(DocumentKind::Invoice, $quote->pid, new HeaderInput(
                name: $quote->name,
                date: $today,
                invoiceType: $quote->invoiceType,
                facilityId: $quote->facilityId,
                providerId: $quote->providerId,
                sellerId: $quote->sellerId,
                comments: $quote->comments,
                deposit: $quote->deposit,
            ), $userId);
            $this->db->execute('UPDATE `cpi_invoice` SET `source_quote_id` = ? WHERE `id` = ?', [$quote->id, $invoice->id]);
            $this->db->execute('UPDATE `cpi_quote` SET `converted_invoice_id` = ? WHERE `id` = ?', [$invoice->id, $quote->id]);
            $this->lines->copyLines($quote, $invoice, $userId);
            return $this->documents->find(DocumentKind::Invoice, $invoice->id, $quote->pid) ?? $invoice;
        });
    }

    public function copy(Document $document, \DateTimeImmutable $today, int $userId): Document
    {
        return $this->db->transaction(function () use ($document, $today, $userId): Document {
            $copy = $this->create($document->kind, $document->pid, new HeaderInput(
                name: mb_substr($document->name . ' (copy)', 0, 255),
                date: $today,
                invoiceType: $document->invoiceType,
                facilityId: $document->facilityId,
                providerId: $document->providerId,
                sellerId: $document->sellerId,
                comments: $document->comments,
                deposit: $document->deposit,
                isActive: true,
                consultCredit: $document->consultCredit,
            ), $userId);
            $this->lines->copyLines($document, $copy, $userId);
            return $copy;
        });
    }

    public function setLocked(Document $invoice, bool $locked, int $userId, string $reason): void
    {
        if ($invoice->kind !== DocumentKind::Invoice) {
            throw new \LogicException('Only invoices are locked');
        }
        if ($invoice->locked === $locked) {
            return;
        }
        if (!$locked && trim($reason) === '') {
            throw new ValidationException(['reason' => 'Say why the invoice is being unlocked']);
        }
        $this->db->transaction(function () use ($invoice, $locked, $userId, $reason): void {
            $this->db->execute(
                'UPDATE `cpi_invoice` SET `locked` = ?, `locked_by` = ?, `locked_at` = NOW() WHERE `id` = ?',
                [$locked ? 1 : 0, $userId, $invoice->id]
            );
            $this->db->execute(
                'INSERT INTO `cpi_invoice_lock_log` (`invoice_id`, `action`, `user_id`, `reason`) VALUES (?, ?, ?, ?)',
                [$invoice->id, $locked ? 'lock' : 'unlock', $userId, mb_substr(trim($reason), 0, 255) ?: null]
            );
        });
    }

    /**
     * Deletes a quote, or an unlocked invoice with no payments. Stock taken
     * by delivered lines goes back.
     */
    public function delete(Document $document, int $userId): void
    {
        if ($document->kind === DocumentKind::Invoice) {
            if ($document->locked) {
                throw new ValidationException(['document' => 'Unlock the invoice before deleting it']);
            }
            $alloc = $this->db->fetchOne('SELECT 1 AS `x` FROM `cpi_payment_alloc` WHERE `invoice_id` = ? LIMIT 1', [$document->id]);
            if ($alloc !== null) {
                throw new ValidationException(['document' => 'This invoice has payments. Refund or move them first.']);
            }
        }
        $this->db->transaction(function () use ($document, $userId): void {
            foreach ($this->documents->lines($document) as $line) {
                if ($line->receivedDate !== null) {
                    $this->delivery->returnStock($line, $userId);
                }
            }
            $this->db->execute('DELETE FROM `cpi_line` WHERE `parent_type` = ? AND `parent_id` = ?', [$document->kind->value, $document->id]);
            $this->db->execute("DELETE FROM `{$document->kind->table()}` WHERE `id` = ?", [$document->id]);
            if ($document->kind === DocumentKind::Invoice) {
                $this->db->execute('UPDATE `cpi_quote` SET `converted_invoice_id` = NULL WHERE `converted_invoice_id` = ?', [$document->id]);
            }
        });
    }

    public function markQuote(Document $quote, string $what): void
    {
        $column = match ($what) {
            'printed' => 'printed_at',
            'emailed' => 'emailed_at',
            default => throw new \LogicException('Unknown quote mark'),
        };
        $this->db->execute("UPDATE `cpi_quote` SET `$column` = NOW() WHERE `id` = ?", [$quote->id]);
    }

    public function attachSignedScan(Document $quote, int $documentId): void
    {
        $this->db->execute('UPDATE `cpi_quote` SET `signed_scan_doc_id` = ? WHERE `id` = ?', [$documentId, $quote->id]);
    }

    private function hasLines(Document $document): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 AS `x` FROM `cpi_line` WHERE `parent_type` = ? AND `parent_id` = ? LIMIT 1',
            [$document->kind->value, $document->id]
        ) !== null;
    }
}
