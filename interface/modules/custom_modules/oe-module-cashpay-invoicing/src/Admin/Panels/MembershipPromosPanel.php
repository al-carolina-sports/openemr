<?php

/**
 * Member-only discounts attached to a membership plan.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin\Panels;

use OpenEMR\Modules\CashpayInvoicing\Admin\FormInput;
use OpenEMR\Modules\CashpayInvoicing\Admin\Notice;
use OpenEMR\Modules\CashpayInvoicing\Admin\Panel;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;

final readonly class MembershipPromosPanel implements PanelHandler
{
    public function __construct(private Database $db)
    {
    }

    public function panel(): Panel
    {
        return Panel::MembershipPromos;
    }

    public function title(): string
    {
        return 'Member discounts';
    }

    public function load(int $ownerId): array
    {
        $linked = [];
        foreach ($this->db->fetchAll('SELECT `promo_id` FROM `cpi_membership_plan_promo` WHERE `plan_id` = ?', [$ownerId]) as $row) {
            if (is_numeric($row['promo_id'] ?? null)) {
                $linked[] = (int) $row['promo_id'];
            }
        }
        return [
            'promos' => $this->db->fetchAll(
                'SELECT `id`, `name`, `kind`, `amount`, `members_only`, `active` FROM `cpi_promo_discount` ORDER BY `active` DESC, `name`'
            ),
            'linked' => $linked,
        ];
    }

    public function handle(int $ownerId, string $op, FormInput $input, int $userId): Notice
    {
        if ($op !== 'save') {
            throw new ValidationException(['op' => 'Unknown action']);
        }
        $ids = $input->idList('promo_ids');
        $this->db->transaction(function () use ($ownerId, $ids): void {
            $this->db->execute('DELETE FROM `cpi_membership_plan_promo` WHERE `plan_id` = ?', [$ownerId]);
            foreach ($ids as $promoId) {
                $this->db->execute(
                    'INSERT INTO `cpi_membership_plan_promo` (`plan_id`, `promo_id`) SELECT ?, `id` FROM `cpi_promo_discount` WHERE `id` = ?',
                    [$ownerId, $promoId]
                );
            }
        });
        return Notice::Saved;
    }
}
