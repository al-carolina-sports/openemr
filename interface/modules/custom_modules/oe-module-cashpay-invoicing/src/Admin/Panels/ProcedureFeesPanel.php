<?php

/**
 * Price and hours overrides for a procedure by provider and/or office.
 * The most specific match wins when pricing an invoice line.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Admin\Panels;

use OpenEMR\Modules\CashpayInvoicing\Admin\FormInput;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupRepository;
use OpenEMR\Modules\CashpayInvoicing\Admin\LookupSource;
use OpenEMR\Modules\CashpayInvoicing\Admin\Notice;
use OpenEMR\Modules\CashpayInvoicing\Admin\Panel;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Decimal;

final readonly class ProcedureFeesPanel implements PanelHandler
{
    public function __construct(private Database $db, private LookupRepository $lookups)
    {
    }

    public function panel(): Panel
    {
        return Panel::ProcedureFees;
    }

    public function title(): string
    {
        return 'Price overrides by provider or office';
    }

    public function load(int $ownerId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM `cpi_procedure_fee` WHERE `procedure_id` = ? ORDER BY `provider_id`, `facility_id`',
            [$ownerId]
        );
        return [
            'rows' => $rows,
            'providers' => $this->lookups->options(LookupSource::Provider),
            'facilities' => $this->lookups->options(LookupSource::Facility),
            'providerNames' => $this->lookups->names(LookupSource::Provider),
            'facilityNames' => $this->lookups->names(LookupSource::Facility),
        ];
    }

    public function handle(int $ownerId, string $op, FormInput $input, int $userId): Notice
    {
        if ($op === 'delete') {
            $this->db->execute(
                'DELETE FROM `cpi_procedure_fee` WHERE `id` = ? AND `procedure_id` = ?',
                [$input->requiredId('fee_id'), $ownerId]
            );
            return Notice::Removed;
        }
        if ($op !== 'save') {
            throw new ValidationException(['op' => 'Unknown action']);
        }
        $providerId = $input->optionalId('provider_id');
        $facilityId = $input->optionalId('facility_id');
        if ($providerId === null && $facilityId === null) {
            throw new ValidationException(['provider_id' => 'Pick a provider, an office, or both']);
        }
        $price = $input->money('price');
        $hours = $input->string('hours');
        try {
            $hoursValue = $hours === '' ? null : Decimal::format(Decimal::parse($hours, 2), 2);
        } catch (\InvalidArgumentException) {
            throw new ValidationException(['hours' => 'Enter hours like 1.5']);
        }
        // The unique key does not stop duplicates when a column is NULL, so match explicitly.
        $existing = $this->db->fetchOne(
            'SELECT `id` FROM `cpi_procedure_fee` WHERE `procedure_id` = ? AND `provider_id` <=> ? AND `facility_id` <=> ?',
            [$ownerId, $providerId, $facilityId]
        );
        if ($existing !== null && is_numeric($existing['id'])) {
            $this->db->execute(
                'UPDATE `cpi_procedure_fee` SET `price` = ?, `hours` = ? WHERE `id` = ?',
                [$price?->toDecimalString(), $hoursValue, (int) $existing['id']]
            );
            return Notice::Updated;
        }
        $this->db->insert(
            'INSERT INTO `cpi_procedure_fee` (`procedure_id`, `provider_id`, `facility_id`, `price`, `hours`) VALUES (?, ?, ?, ?, ?)',
            [$ownerId, $providerId, $facilityId, $price?->toDecimalString(), $hoursValue]
        );
        return Notice::Added;
    }
}
