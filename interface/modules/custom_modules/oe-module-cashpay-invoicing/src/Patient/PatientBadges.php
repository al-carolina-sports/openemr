<?php

/**
 * Badges beside the patient name on the medical record dashboard: active
 * membership plan codes, award points, and a reminder to collect a card.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Patient;

use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;

final readonly class PatientBadges
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{plans: list<array{code: string, name: string, status: string}>, rewards: bool, needsCard: bool}
     */
    public function for(int $pid): array
    {
        $plans = [];
        foreach (
            $this->db->fetchAll(
                "SELECT p.`code`, p.`name`, m.`status` FROM `cpi_patient_membership` m JOIN `cpi_membership_plan` p ON p.`id` = m.`plan_id`"
                . " WHERE m.`pid` = ? AND m.`status` IN ('active', 'frozen') ORDER BY p.`name`",
                [$pid]
            ) as $row
        ) {
            $plans[] = ['code' => Row::str($row, 'code'), 'name' => Row::str($row, 'name'), 'status' => Row::str($row, 'status')];
        }
        $rewards = $this->db->fetchOne('SELECT `enrolled` FROM `cpi_patient_rewards` WHERE `pid` = ?', [$pid]);
        $card = $this->db->fetchOne(
            'SELECT MAX(`needs_card_on_file`) AS `flag`, MAX(`default_payment_method` IS NOT NULL) AS `has_card` FROM `cpi_stripe_customer` WHERE `pid` = ?',
            [$pid]
        );
        $autopay = $this->db->fetchOne(
            "SELECT 1 AS `x` FROM `cpi_patient_membership` WHERE `pid` = ? AND `status` = 'active' AND `autopay` = 1 LIMIT 1",
            [$pid]
        );
        $hasCard = $card !== null && Row::bool($card, 'has_card');
        return [
            'plans' => $plans,
            'rewards' => $rewards !== null && Row::bool($rewards, 'enrolled'),
            'needsCard' => ($card !== null && Row::bool($card, 'flag')) || ($autopay !== null && !$hasCard),
        ];
    }

    public function html(int $pid, string $patientUrl): string
    {
        $badges = $this->for($pid);
        $parts = [];
        foreach ($badges['plans'] as $plan) {
            $parts[] = '<a class="badge badge-' . ($plan['status'] === 'active' ? 'info' : 'secondary') . ' mr-1" href="'
                . attr($patientUrl . 'membership.php') . '" title="' . attr($plan['name'] . ($plan['status'] === 'frozen' ? ' (' . xl('frozen') . ')' : '')) . '">'
                . text($plan['code']) . '</a>';
        }
        if ($badges['rewards']) {
            $parts[] = '<a class="badge badge-success mr-1" href="' . attr($patientUrl . 'rewards.php') . '">' . xlt('Rewards') . '</a>';
        }
        if ($badges['needsCard']) {
            $parts[] = '<a class="badge badge-warning mr-1" href="' . attr($patientUrl . 'payments.php#stripe') . '" title="'
                . xla('Collect a card on the reader or send a card-on-file link') . '">' . xlt('Needs card on file') . '</a>';
        }
        return $parts === [] ? '' : '<span class="cpi-badges ml-2">' . implode('', $parts) . '</span>';
    }
}
