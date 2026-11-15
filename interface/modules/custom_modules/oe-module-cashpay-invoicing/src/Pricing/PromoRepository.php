<?php

/**
 * Active promotional discounts and the patient's membership plans.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Pricing;

use OpenEMR\Modules\CashpayInvoicing\Catalog\PromoScope;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;

final class PromoRepository
{
    /** @var list<PromoCandidate>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<PromoCandidate>
     */
    public function activePromos(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $plans = [];
        foreach ($this->db->fetchAll('SELECT `plan_id`, `promo_id` FROM `cpi_membership_plan_promo`') as $link) {
            $plans[Row::int($link, 'promo_id')][] = Row::int($link, 'plan_id');
        }
        $promos = [];
        foreach ($this->db->fetchAll('SELECT * FROM `cpi_promo_discount` WHERE `active` = 1') as $row) {
            $kind = Row::str($row, 'kind');
            if ($kind !== 'percent' && $kind !== 'flat_each' && $kind !== 'flat_total') {
                continue;
            }
            $id = Row::int($row, 'id');
            $promos[] = new PromoCandidate(
                id: $id,
                name: Row::str($row, 'name'),
                kind: $kind,
                amountTenThousandths: Row::scaled($row, 'amount', 4),
                membersOnly: Row::bool($row, 'members_only'),
                startDate: Row::date($row, 'start_date'),
                endDate: Row::date($row, 'end_date'),
                scope: PromoScope::fromJson(Row::strOrNull($row, 'scope_json')),
                reasonId: Row::intOrNull($row, 'reason_id'),
                planIds: $plans[$id] ?? [],
            );
        }
        return $this->cache = $promos;
    }

    /**
     * Plans the patient is an active member of on the given day.
     *
     * @return list<int>
     */
    public function activePlanIds(int $pid, \DateTimeImmutable $date): array
    {
        $day = $date->format('Y-m-d');
        $ids = [];
        foreach (
            $this->db->fetchAll(
                "SELECT `plan_id` FROM `cpi_patient_membership` WHERE `pid` = ? AND `status` = 'active'"
                . ' AND `start_date` <= ? AND (`end_date` IS NULL OR `end_date` >= ?)',
                [$pid, $day, $day]
            ) as $row
        ) {
            $ids[] = Row::int($row, 'plan_id');
        }
        return array_values(array_unique($ids));
    }
}
