<?php

/**
 * Reads and writes module settings in the module's own table.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ApptHoverCard;

use OpenEMR\Common\Database\QueryUtils;

class SettingsRepository
{
    private const TABLE = 'oe_module_appt_hovercard_settings';

    /**
     * Stored values over defaults. Falls back to defaults if the table is
     * missing or unreadable so the calendar is never broken by this module.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        try {
            $rows = QueryUtils::fetchRecords('SELECT `setting_key`, `setting_value` FROM `' . self::TABLE . '`');
        } catch (\Throwable) {
            return Settings::defaults();
        }

        $stored = [];
        foreach ($rows as $row) {
            $key = $row['setting_key'] ?? null;
            $json = $row['setting_value'] ?? null;
            if (!is_string($key) || !is_string($json)) {
                continue;
            }
            $stored[$key] = json_decode($json, true);
        }

        return Settings::normalize($stored + Settings::defaults());
    }

    /**
     * @param array<string, mixed> $input untrusted form input
     * @return array<string, mixed> the settings that were saved
     */
    public function save(array $input): array
    {
        $settings = Settings::normalize($input);
        foreach ($settings as $key => $value) {
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO `' . self::TABLE . '` (`setting_key`, `setting_value`) VALUES (?, ?) '
                . 'ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)',
                [$key, json_encode($value, JSON_THROW_ON_ERROR)]
            );
        }
        return $settings;
    }

    public function restoreDefaults(): void
    {
        QueryUtils::sqlStatementThrowException('DELETE FROM `' . self::TABLE . '`');
    }
}
