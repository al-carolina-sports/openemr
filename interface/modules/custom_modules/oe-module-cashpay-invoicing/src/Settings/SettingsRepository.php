<?php

/**
 * Reads and writes settings (cpi_settings) and the two tax types (cpi_tax_type).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Settings;

use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Decimal;

final readonly class SettingsRepository
{
    public function __construct(private Database $db)
    {
    }

    public function load(): Settings
    {
        $stored = [];
        foreach ($this->db->fetchAll('SELECT `setting_key`, `setting_value` FROM `cpi_settings`') as $row) {
            $key = $row['setting_key'] ?? null;
            $json = $row['setting_value'] ?? null;
            if (!is_string($key) || !is_string($json)) {
                continue;
            }
            try {
                $stored[$key] = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
        }
        return Settings::fromStored($stored);
    }

    public function save(Settings $settings, int $userId): void
    {
        $this->db->transaction(function () use ($settings, $userId): void {
            foreach ($settings->toStorage() as $key => $value) {
                $this->db->execute(
                    'INSERT INTO `cpi_settings` (`setting_key`, `setting_value`, `updated_by`) VALUES (?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_by` = VALUES(`updated_by`)',
                    [$key, json_encode($value, JSON_THROW_ON_ERROR), $userId]
                );
            }
        });
    }

    /**
     * @return array<int, TaxType> slot => tax
     */
    public function taxTypes(): array
    {
        $taxes = [1 => new TaxType(1, 'Tax 1', 0, false), 2 => new TaxType(2, 'Tax 2', 0, false)];
        foreach ($this->db->fetchAll('SELECT * FROM `cpi_tax_type` WHERE `slot` IN (1, 2)') as $row) {
            $slot = is_numeric($row['slot'] ?? null) ? (int) $row['slot'] : 0;
            if (!isset($taxes[$slot])) {
                continue;
            }
            $taxes[$slot] = new TaxType(
                $slot,
                is_string($row['name'] ?? null) ? $row['name'] : 'Tax ' . $slot,
                is_string($row['rate_pct'] ?? null) ? Decimal::parse($row['rate_pct'], 4) : 0,
                in_array($row['active'] ?? null, [1, '1'], true),
            );
        }
        return $taxes;
    }

    public function saveTaxType(TaxType $tax): void
    {
        $this->db->execute(
            'INSERT INTO `cpi_tax_type` (`slot`, `name`, `rate_pct`, `active`) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `rate_pct` = VALUES(`rate_pct`), `active` = VALUES(`active`)',
            [$tax->slot, $tax->name, Decimal::format($tax->rateTenThousandths, 4), $tax->active ? 1 : 0]
        );
    }
}
