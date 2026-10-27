<?php

/**
 * A quote or invoice header.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Billing;

use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use OpenEMR\Modules\CashpayInvoicing\Money;

final readonly class Document
{
    public function __construct(
        public DocumentKind $kind,
        public int $id,
        public int $pid,
        public string $name,
        public \DateTimeImmutable $date,
        public InvoiceType $invoiceType,
        public ?int $facilityId,
        public ?int $providerId,
        public ?int $sellerId,
        public string $comments,
        public Money $deposit,
        public bool $locked,
        public bool $voided,
        public ?\DateTimeImmutable $expiresOn,
        public bool $isActive,
        public Money $consultCredit,
        public ?int $convertedInvoiceId,
        public ?int $sourceQuoteId,
        public ?int $signedScanDocId,
    ) {
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function fromRow(DocumentKind $kind, array $row): self
    {
        $isQuote = $kind === DocumentKind::Quote;
        return new self(
            kind: $kind,
            id: Row::int($row, 'id'),
            pid: Row::int($row, 'pid'),
            name: Row::str($row, 'name'),
            date: Row::date($row, $kind->dateColumn()) ?? new \DateTimeImmutable('today'),
            invoiceType: InvoiceType::tryFrom(Row::str($row, 'invoice_type')) ?? InvoiceType::Immediate,
            facilityId: Row::intOrNull($row, $kind->facilityColumn()),
            providerId: Row::intOrNull($row, 'provider_id'),
            sellerId: Row::intOrNull($row, 'seller_id'),
            comments: Row::str($row, 'comments'),
            deposit: Row::money($row, 'deposit'),
            locked: !$isQuote && Row::bool($row, 'locked'),
            voided: !$isQuote && Row::bool($row, 'voided'),
            expiresOn: $isQuote ? Row::date($row, 'expires_on') : null,
            isActive: !$isQuote || Row::bool($row, 'is_active'),
            consultCredit: $isQuote ? Row::money($row, 'consult_credit') : Money::zero(),
            convertedInvoiceId: $isQuote ? Row::intOrNull($row, 'converted_invoice_id') : null,
            sourceQuoteId: $isQuote ? null : Row::intOrNull($row, 'source_quote_id'),
            signedScanDocId: $isQuote ? Row::intOrNull($row, 'signed_scan_doc_id') : null,
        );
    }

    /** Lines can change only on unlocked, unvoided documents. */
    public function editable(): bool
    {
        return !$this->locked && !$this->voided;
    }
}
