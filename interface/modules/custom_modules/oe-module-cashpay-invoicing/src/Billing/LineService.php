<?php

/**
 * Adds, edits and removes quote and invoice lines, pricing each one.
 *
 * - Procedure prices come from fee overrides (provider + office, provider,
 *   office, standard). Products use the office price if one is set.
 * - The best matching promotional discount is applied when a catalog item is
 *   added. Staff can change or remove it; any discount needs a reason.
 * - Tax 1 and Tax 2 are charged on the net when the item is flagged.
 * - On invoices that are delivered when sold (everything except prepaid and
 *   surgery), lines are received immediately and stock is taken.
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
use OpenEMR\Modules\CashpayInvoicing\Decimal;
use OpenEMR\Modules\CashpayInvoicing\Money;
use OpenEMR\Modules\CashpayInvoicing\Pricing\CatalogEntry;
use OpenEMR\Modules\CashpayInvoicing\Pricing\CatalogLookup;
use OpenEMR\Modules\CashpayInvoicing\Pricing\LineFacts;
use OpenEMR\Modules\CashpayInvoicing\Pricing\PromoMatcher;
use OpenEMR\Modules\CashpayInvoicing\Pricing\PromoRepository;
use OpenEMR\Modules\CashpayInvoicing\Settings\SettingsRepository;
use OpenEMR\Modules\CashpayInvoicing\Settings\TaxType;

final class LineService
{
    /** @var array<int, TaxType>|null */
    private ?array $taxes = null;

    public function __construct(
        private readonly Database $db,
        private readonly DocumentRepository $documents,
        private readonly CatalogLookup $catalog,
        private readonly PromoRepository $promos,
        private readonly SettingsRepository $settings,
        private readonly DeliveryService $delivery,
    ) {
    }

    /**
     * @param 'procedure'|'product' $type
     * @return int the new line id
     */
    public function addCatalogItem(Document $document, string $type, int $itemId, int $qtyMilli, LineDefaults $defaults, int $userId): int
    {
        $this->assertEditable($document);
        $entry = $this->catalog->entry($type, $itemId);
        if ($entry === null || !$entry->active) {
            throw new ValidationException(['item' => 'That item is not in the catalog or is inactive']);
        }
        if ($qtyMilli <= 0) {
            throw new ValidationException(['qty' => 'Quantity must be more than zero']);
        }
        $unitPrice = $entry->priceFor($defaults->providerId, $defaults->facilityId);
        $gross = $unitPrice->multiplyByMilli($qtyMilli);
        $applied = PromoMatcher::best($this->promos->activePromos(), new LineFacts(
            itemType: $entry->type,
            itemId: $entry->id,
            categoryId: $entry->categoryId,
            manufacturerId: $entry->manufacturerId,
            vendorIds: $entry->vendorIds,
            facilityId: $defaults->facilityId,
            invoiceType: $document->invoiceType,
            date: $defaults->serviceDate,
            qtyMilli: $qtyMilli,
            gross: $gross,
            activePlanIds: $this->promos->activePlanIds($document->pid, $defaults->serviceDate),
        ));
        $discount = $applied !== null ? $applied->discount : Money::zero();
        $reasonId = $applied !== null ? $applied->promo->reasonId : null;
        $promoId = $applied !== null ? $applied->promo->id : null;
        return $this->db->transaction(
            fn (): int => $this->insertLine($document, $entry, $qtyMilli, $unitPrice, $discount, $reasonId, $promoId, null, $defaults, $userId)
        );
    }

    /**
     * Explodes a sales package into its lines at the package prices.
     *
     * @return int number of lines added
     */
    public function addPackage(Document $document, int $packageId, LineDefaults $defaults, int $userId): int
    {
        $this->assertEditable($document);
        $package = $this->catalog->package($packageId);
        if ($package === null || $package['items'] === []) {
            throw new ValidationException(['package' => 'That package has no items']);
        }
        $reasonId = $this->packageReasonId();
        return $this->db->transaction(function () use ($document, $package, $packageId, $defaults, $reasonId, $userId): int {
            $count = 0;
            foreach ($package['items'] as $item) {
                $entry = $this->catalog->entry($item['type'], $item['id']);
                if ($entry === null) {
                    continue;
                }
                $this->insertLine(
                    $document,
                    $entry,
                    $item['qtyMilli'],
                    $item['unitPrice'],
                    $item['discount'],
                    $item['discount']->cents > 0 ? $reasonId : null,
                    null,
                    $packageId,
                    $defaults,
                    $userId,
                );
                $count++;
            }
            return $count;
        });
    }

    /**
     * A free-text fee line (OR, anesthesia, recovery, assistant, other).
     */
    public function addFee(Document $document, ChargeCategory $category, string $description, Money $amount, LineDefaults $defaults): int
    {
        $this->assertEditable($document);
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 255) {
            throw new ValidationException(['description' => 'Enter a description (up to 255 characters)']);
        }
        if ($amount->isNegative()) {
            throw new ValidationException(['amount' => 'The amount cannot be negative']);
        }
        $amounts = LineAmounts::compute($amount, 1000, Money::zero(), null, null);
        return $this->db->insert(
            'INSERT INTO `cpi_line` (`parent_type`, `parent_id`, `item_type`, `description`, `charge_category`, `qty`, `unit_price`,'
            . ' `net`, `service_date`, `received_date`, `provider_id`, `seller_id`, `service_facility_id`, `sort_order`)'
            . " VALUES (?, ?, 'other', ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $document->kind->value, $document->id, $description, $category->value,
                $amount->toDecimalString(), $amounts->net->toDecimalString(),
                $defaults->serviceDate->format('Y-m-d'),
                $this->deliveredOnSale($document, null) ? $defaults->serviceDate->format('Y-m-d') : null,
                $defaults->providerId, $defaults->sellerId, $defaults->facilityId, $this->nextSort($document),
            ]
        );
    }

    /**
     * The membership fee line on a membership invoice.
     */
    public function addMembershipFee(Document $document, int $planId, string $description, Money $price, bool $tax1, bool $tax2, LineDefaults $defaults): int
    {
        $amounts = LineAmounts::compute($price, 1000, Money::zero(), $tax1 ? $this->tax(1) : null, $tax2 ? $this->tax(2) : null);
        return $this->db->insert(
            'INSERT INTO `cpi_line` (`parent_type`, `parent_id`, `item_type`, `item_id`, `description`, `charge_category`, `qty`, `unit_price`,'
            . ' `net`, `tax1_amt`, `tax2_amt`, `service_date`, `received_date`, `seller_id`, `service_facility_id`, `sort_order`)'
            . " VALUES (?, ?, 'membership', ?, ?, 'other', 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $document->kind->value, $document->id, $planId, mb_substr($description, 0, 255), $price->toDecimalString(),
                $amounts->net->toDecimalString(), $amounts->tax1->toDecimalString(), $amounts->tax2->toDecimalString(),
                $defaults->serviceDate->format('Y-m-d'), $defaults->serviceDate->format('Y-m-d'),
                $defaults->sellerId, $defaults->facilityId, $this->nextSort($document),
            ]
        );
    }

    /**
     * A gift card line on a gift card invoice. The card itself is issued
     * with its code once the invoice is fully paid.
     */
    public function addGiftCard(Document $document, Money $amount, string $recipient, LineDefaults $defaults): int
    {
        $this->assertEditable($document);
        if ($document->kind !== DocumentKind::Invoice || $document->invoiceType !== InvoiceType::GiftCard) {
            throw new ValidationException(['amount' => 'Gift cards are sold on a Gift Cards invoice']);
        }
        if ($amount->cents <= 0) {
            throw new ValidationException(['amount' => 'Enter the gift card value']);
        }
        $recipient = trim($recipient);
        $description = mb_substr('Gift card' . ($recipient !== '' ? ' for ' . $recipient : ''), 0, 255);
        return $this->db->insert(
            'INSERT INTO `cpi_line` (`parent_type`, `parent_id`, `item_type`, `description`, `charge_category`, `qty`, `unit_price`,'
            . ' `net`, `service_date`, `received_date`, `seller_id`, `service_facility_id`, `sort_order`)'
            . " VALUES ('invoice', ?, 'gift_card', ?, 'other', 1, ?, ?, ?, ?, ?, ?, ?)",
            [
                $document->id, $description, $amount->toDecimalString(), $amount->toDecimalString(),
                $defaults->serviceDate->format('Y-m-d'), $defaults->serviceDate->format('Y-m-d'),
                $defaults->sellerId, $defaults->facilityId, $this->nextSort($document),
            ]
        );
    }

    public function update(Document $document, int $lineId, LineEdit $edit): void
    {
        $this->assertEditable($document);
        $line = $this->documents->line($document, $lineId) ?? throw new ValidationException(['line' => 'Line not found']);
        $this->assertNotIssuedGiftCard($line);
        if ($edit->discount->cents > 0 && $edit->discountReasonId === null) {
            throw new ValidationException(['discount_reason_id' => 'Pick a discount reason']);
        }
        if ($edit->unitPrice->isNegative() || $edit->discount->isNegative()) {
            throw new ValidationException(['unit_price' => 'Prices and discounts cannot be negative']);
        }
        if ($edit->qtyMilli <= 0) {
            throw new ValidationException(['qty' => 'Quantity must be more than zero']);
        }
        if ($line->receivedDate !== null && $edit->qtyMilli !== $line->qtyMilli && $line->itemType !== 'other') {
            throw new ValidationException(['qty' => 'Clear the received flag before changing the quantity of a delivered item']);
        }
        $entry = $line->itemId !== null && ($line->itemType === 'procedure' || $line->itemType === 'product')
            ? $this->catalog->entry($line->itemType, $line->itemId) : null;
        $description = $line->description;
        if ($edit->description !== null && $edit->description !== '' && ($entry === null || $entry->allowManualDescription)) {
            $description = mb_substr($edit->description, 0, 255);
        }
        $amounts = LineAmounts::compute(
            $edit->unitPrice,
            $edit->qtyMilli,
            $edit->discount,
            $entry !== null && $entry->tax1 ? $this->tax(1) : null,
            $entry !== null && $entry->tax2 ? $this->tax(2) : null,
        );
        $this->db->execute(
            'UPDATE `cpi_line` SET `description` = ?, `qty` = ?, `unit_price` = ?, `discount_amt` = ?, `discount_reason_id` = ?,'
            . ' `promo_id` = ?, `net` = ?, `tax1_amt` = ?, `tax2_amt` = ?, `provider_id` = ?, `seller_id` = ?, `service_facility_id` = ?,'
            . ' `service_date` = ? WHERE `id` = ?',
            [
                $description,
                Decimal::format($edit->qtyMilli, 3),
                $edit->unitPrice->toDecimalString(),
                $amounts->discount->toDecimalString(),
                $amounts->discount->cents > 0 ? $edit->discountReasonId : null,
                $amounts->discount->cents === $line->discount->cents ? $line->promoId : null,
                $amounts->net->toDecimalString(),
                $amounts->tax1->toDecimalString(),
                $amounts->tax2->toDecimalString(),
                $edit->providerId,
                $edit->sellerId,
                $edit->facilityId,
                $edit->serviceDate?->format('Y-m-d'),
                $line->id,
            ]
        );
    }

    public function remove(Document $document, int $lineId, int $userId): void
    {
        $this->assertEditable($document);
        $line = $this->documents->line($document, $lineId) ?? throw new ValidationException(['line' => 'Line not found']);
        $this->assertNotIssuedGiftCard($line);
        $this->db->transaction(function () use ($line, $userId): void {
            if ($line->receivedDate !== null) {
                $this->delivery->returnStock($line, $userId);
            }
            $this->db->execute('DELETE FROM `cpi_line` WHERE `id` = ?', [$line->id]);
        });
    }

    /**
     * Sets every line's discount to a percent of its gross (0 clears them).
     *
     * @param int $pctHundredths percent with two decimals, 1000 = 10%
     */
    public function discountAll(Document $document, int $pctHundredths, ?int $reasonId): int
    {
        $this->assertEditable($document);
        if ($pctHundredths < 0 || $pctHundredths > 10000) {
            throw new ValidationException(['pct' => 'Enter 0 to 100 percent']);
        }
        if ($pctHundredths > 0 && $reasonId === null) {
            throw new ValidationException(['discount_reason_id' => 'Pick a discount reason']);
        }
        $lines = $this->documents->lines($document);
        $this->db->transaction(function () use ($document, $lines, $pctHundredths, $reasonId): void {
            foreach ($lines as $line) {
                $discount = $line->gross()->percentOf($pctHundredths * 100);
                $this->update($document, $line->id, new LineEdit(
                    $line->qtyMilli,
                    $line->unitPrice,
                    $discount,
                    $reasonId,
                    $line->providerId,
                    $line->sellerId,
                    $line->facilityId,
                    $line->serviceDate,
                    null,
                ));
            }
        });
        return count($lines);
    }

    /**
     * Copies lines onto another document (quote to invoice, invoice copy),
     * keeping prices and discounts. Delivery is reset.
     */
    public function copyLines(Document $from, Document $to, int $userId): void
    {
        foreach ($this->documents->lines($from) as $line) {
            $receivedNow = $this->deliveredOnSale($to, $line->packageId);
            $id = $this->db->insert(
                'INSERT INTO `cpi_line` (`parent_type`, `parent_id`, `item_type`, `item_id`, `package_id`, `description`, `code`, `charge_category`,'
                . ' `qty`, `unit_price`, `unit_cost`, `discount_amt`, `discount_reason_id`, `promo_id`, `net`, `tax1_amt`, `tax2_amt`,'
                . ' `award_points_eligible`, `service_date`, `received_date`, `received_by`, `provider_id`, `seller_id`, `service_facility_id`, `sort_order`)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $to->kind->value, $to->id, $line->itemType, $line->itemId, $line->packageId, $line->description, $line->code,
                    $line->category->value, Decimal::format($line->qtyMilli, 3), $line->unitPrice->toDecimalString(),
                    $line->unitCost->toDecimalString(), $line->discount->toDecimalString(), $line->discountReasonId, $line->promoId,
                    $line->net->toDecimalString(), $line->tax1->toDecimalString(), $line->tax2->toDecimalString(),
                    $line->pointsEligible ? 1 : 0, $to->date->format('Y-m-d'),
                    $receivedNow ? $to->date->format('Y-m-d') : null, $receivedNow ? $userId : null,
                    $line->providerId ?? $to->providerId, $line->sellerId ?? $to->sellerId, $line->facilityId ?? $to->facilityId,
                    $line->sortOrder,
                ]
            );
            if ($receivedNow) {
                $this->delivery->takeStock($this->lineById($id), $userId);
            }
        }
    }

    private function insertLine(
        Document $document,
        CatalogEntry $entry,
        int $qtyMilli,
        Money $unitPrice,
        Money $discount,
        ?int $reasonId,
        ?int $promoId,
        ?int $packageId,
        LineDefaults $defaults,
        int $userId,
    ): int {
        $amounts = LineAmounts::compute(
            $unitPrice,
            $qtyMilli,
            $discount,
            $entry->tax1 ? $this->tax(1) : null,
            $entry->tax2 ? $this->tax(2) : null,
            $entry->unitCost,
        );
        $receivedNow = $this->deliveredOnSale($document, $packageId);
        $id = $this->db->insert(
            'INSERT INTO `cpi_line` (`parent_type`, `parent_id`, `item_type`, `item_id`, `package_id`, `description`, `code`, `charge_category`,'
            . ' `qty`, `unit_price`, `unit_cost`, `discount_amt`, `discount_reason_id`, `promo_id`, `net`, `tax1_amt`, `tax2_amt`,'
            . ' `award_points_eligible`, `service_date`, `received_date`, `received_by`, `provider_id`, `seller_id`, `service_facility_id`, `sort_order`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $document->kind->value, $document->id, $entry->type, $entry->id, $packageId, mb_substr($entry->name, 0, 255), $entry->code,
                $entry->chargeCategory()->value, Decimal::format($qtyMilli, 3), $unitPrice->toDecimalString(),
                $entry->unitCost->toDecimalString(), $amounts->discount->toDecimalString(),
                $amounts->discount->cents > 0 ? $reasonId : null, $amounts->discount->cents > 0 ? $promoId : null,
                $amounts->net->toDecimalString(), $amounts->tax1->toDecimalString(), $amounts->tax2->toDecimalString(),
                $entry->pointsEligible ? 1 : 0, $defaults->serviceDate->format('Y-m-d'),
                $receivedNow ? $defaults->serviceDate->format('Y-m-d') : null, $receivedNow ? $userId : null,
                $defaults->providerId, $defaults->sellerId, $defaults->facilityId, $this->nextSort($document),
            ]
        );
        if ($receivedNow) {
            $this->delivery->takeStock($this->lineById($id), $userId);
        }
        return $id;
    }

    private function deliveredOnSale(Document $document, ?int $packageId): bool
    {
        return $document->kind === DocumentKind::Invoice && !$document->invoiceType->lineDeferred($packageId);
    }

    private function lineById(int $id): Line
    {
        $row = $this->db->fetchOne('SELECT * FROM `cpi_line` WHERE `id` = ?', [$id]);
        if ($row === null) {
            throw new \RuntimeException('Line just written could not be read back');
        }
        return Line::fromRow($row);
    }

    private function nextSort(Document $document): int
    {
        $row = $this->db->fetchOne(
            'SELECT COALESCE(MAX(`sort_order`), 0) + 10 AS `n` FROM `cpi_line` WHERE `parent_type` = ? AND `parent_id` = ?',
            [$document->kind->value, $document->id]
        );
        return $row === null ? 10 : Row::int($row, 'n');
    }

    private function packageReasonId(): ?int
    {
        $row = $this->db->fetchOne("SELECT `id` FROM `cpi_discount_reason` WHERE `name` = 'Package' AND `active` = 1");
        return $row === null ? null : Row::int($row, 'id');
    }

    private function tax(int $slot): ?TaxType
    {
        $this->taxes ??= $this->settings->taxTypes();
        $tax = $this->taxes[$slot] ?? null;
        return $tax !== null && $tax->active ? $tax : null;
    }

    private function assertNotIssuedGiftCard(Line $line): void
    {
        if ($line->itemType === 'gift_card' && $line->itemId !== null) {
            throw new ValidationException(['line' => 'This gift card has been issued and cannot be changed']);
        }
    }

    private function assertEditable(Document $document): void
    {
        if (!$document->editable()) {
            throw new ValidationException(['document' => 'This invoice is locked. Unlock it to make changes.']);
        }
    }
}
