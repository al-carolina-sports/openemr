<?php

/**
 * Finds the promotional discount that applies to a line.
 *
 * Rules:
 * - The promo must be inside its dates.
 * - Members-only promos need an active membership; a promo attached to plans
 *   applies only to members of those plans.
 * - Invoice types and offices, when listed, must include the line's.
 * - A promo that names procedures (or procedure categories) but no product
 *   filters applies to those procedures only, and the reverse for products.
 *   With no item filters at all it applies to every item.
 * - Within procedures, the line matches if it is a listed procedure OR in a
 *   listed category. Within products: listed product OR category OR vendor
 *   OR manufacturer.
 * - When several promos apply, the largest discount wins (ties: lowest id).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Pricing;

use OpenEMR\Modules\CashpayInvoicing\Catalog\PromoScope;

final class PromoMatcher
{
    /**
     * @param list<PromoCandidate> $promos
     */
    public static function best(array $promos, LineFacts $line): ?AppliedPromo
    {
        $best = null;
        foreach ($promos as $promo) {
            if (!self::applies($promo, $line)) {
                continue;
            }
            $discount = $promo->discountFor($line->gross, $line->qtyMilli);
            if ($discount->cents <= 0) {
                continue;
            }
            if (
                $best === null
                || $discount->cents > $best->discount->cents
                || ($discount->cents === $best->discount->cents && $promo->id < $best->promo->id)
            ) {
                $best = new AppliedPromo($promo, $discount);
            }
        }
        return $best;
    }

    public static function applies(PromoCandidate $promo, LineFacts $line): bool
    {
        $day = $line->date->format('Y-m-d');
        if ($promo->startDate !== null && $day < $promo->startDate->format('Y-m-d')) {
            return false;
        }
        if ($promo->endDate !== null && $day > $promo->endDate->format('Y-m-d')) {
            return false;
        }
        if ($promo->membersOnly) {
            if ($line->activePlanIds === []) {
                return false;
            }
            if ($promo->planIds !== [] && array_intersect($promo->planIds, $line->activePlanIds) === []) {
                return false;
            }
        }
        $scope = $promo->scope;
        if ($scope->invoiceTypes !== [] && !in_array($line->invoiceType->value, $scope->invoiceTypes, true)) {
            return false;
        }
        $facilities = $scope->ids('facility_ids');
        if ($facilities !== [] && ($line->facilityId === null || !in_array($line->facilityId, $facilities, true))) {
            return false;
        }
        return self::itemMatches($scope, $line);
    }

    private static function itemMatches(PromoScope $scope, LineFacts $line): bool
    {
        $procedureFilters = [$scope->ids('procedure_ids'), $scope->ids('procedure_category_ids')];
        $productFilters = [
            $scope->ids('product_ids'),
            $scope->ids('product_category_ids'),
            $scope->ids('vendor_ids'),
            $scope->ids('manufacturer_ids'),
        ];
        $procedureRestricted = $procedureFilters[0] !== [] || $procedureFilters[1] !== [];
        $productRestricted = array_filter($productFilters, static fn (array $ids): bool => $ids !== []) !== [];

        if ($line->itemType === 'procedure') {
            if (!$procedureRestricted) {
                return !$productRestricted;
            }
            return in_array($line->itemId, $procedureFilters[0], true)
                || ($line->categoryId !== null && in_array($line->categoryId, $procedureFilters[1], true));
        }

        if ($scope->noProducts) {
            return false;
        }
        if (!$productRestricted) {
            return !$procedureRestricted;
        }
        return in_array($line->itemId, $productFilters[0], true)
            || ($line->categoryId !== null && in_array($line->categoryId, $productFilters[1], true))
            || array_intersect($line->vendorIds, $productFilters[2]) !== []
            || ($line->manufacturerId !== null && in_array($line->manufacturerId, $productFilters[3], true));
    }
}
