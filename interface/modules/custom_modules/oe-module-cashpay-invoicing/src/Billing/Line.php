<?php

/**
 * One quote or invoice line.
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

final readonly class Line
{
    public function __construct(
        public int $id,
        public DocumentKind $parentKind,
        public int $parentId,
        public string $itemType,
        public ?int $itemId,
        public ?int $packageId,
        public string $description,
        public ?string $code,
        public ChargeCategory $category,
        public int $qtyMilli,
        public Money $unitPrice,
        public Money $unitCost,
        public Money $discount,
        public ?int $discountReasonId,
        public ?int $promoId,
        public Money $net,
        public Money $tax1,
        public Money $tax2,
        public bool $pointsEligible,
        public ?\DateTimeImmutable $serviceDate,
        public ?\DateTimeImmutable $receivedDate,
        public bool $forfeited,
        public ?int $providerId,
        public ?int $sellerId,
        public ?int $facilityId,
        public int $sortOrder,
    ) {
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: Row::int($row, 'id'),
            parentKind: DocumentKind::from(Row::str($row, 'parent_type')),
            parentId: Row::int($row, 'parent_id'),
            itemType: Row::str($row, 'item_type'),
            itemId: Row::intOrNull($row, 'item_id'),
            packageId: Row::intOrNull($row, 'package_id'),
            description: Row::str($row, 'description'),
            code: Row::strOrNull($row, 'code'),
            category: ChargeCategory::tryFrom(Row::str($row, 'charge_category')) ?? ChargeCategory::Other,
            qtyMilli: Row::scaled($row, 'qty', 3),
            unitPrice: Row::money($row, 'unit_price'),
            unitCost: Row::money($row, 'unit_cost'),
            discount: Row::money($row, 'discount_amt'),
            discountReasonId: Row::intOrNull($row, 'discount_reason_id'),
            promoId: Row::intOrNull($row, 'promo_id'),
            net: Row::money($row, 'net'),
            tax1: Row::money($row, 'tax1_amt'),
            tax2: Row::money($row, 'tax2_amt'),
            pointsEligible: Row::bool($row, 'award_points_eligible'),
            serviceDate: Row::date($row, 'service_date'),
            receivedDate: Row::date($row, 'received_date'),
            forfeited: Row::bool($row, 'forfeited'),
            providerId: Row::intOrNull($row, 'provider_id'),
            sellerId: Row::intOrNull($row, 'seller_id'),
            facilityId: Row::intOrNull($row, 'service_facility_id'),
            sortOrder: Row::int($row, 'sort_order'),
        );
    }

    public function gross(): Money
    {
        return $this->unitPrice->multiplyByMilli($this->qtyMilli);
    }

    public function amounts(): LineAmounts
    {
        return new LineAmounts($this->gross(), $this->discount, $this->net, $this->tax1, $this->tax2, $this->unitCost);
    }

    public function total(): Money
    {
        return $this->net->add($this->tax1)->add($this->tax2);
    }

    /** Waiting for delivery: not received and not forfeited. */
    public function isOutstanding(): bool
    {
        return $this->receivedDate === null && !$this->forfeited;
    }
}
