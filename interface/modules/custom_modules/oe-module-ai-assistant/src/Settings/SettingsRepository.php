<?php

/**
 * Reads and writes the module's settings (mod_ai_assistant_settings).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Settings;

use OpenEMR\Modules\AiAssistant\Database\Database;

final readonly class SettingsRepository
{
    public function __construct(private Database $db)
    {
    }

    public function load(): Settings
    {
        $stored = [];
        foreach ($this->db->fetchAll('SELECT `setting_key`, `setting_value` FROM `mod_ai_assistant_settings`') as $row) {
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
        return Settings::fromArray($stored);
    }

    public function save(Settings $settings, int $userId): void
    {
        $this->db->transaction(function () use ($settings, $userId): void {
            foreach ($settings->toStorage() as $key => $value) {
                $this->db->execute(
                    'INSERT INTO `mod_ai_assistant_settings` (`setting_key`, `setting_value`, `updated_by`) VALUES (?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_by` = VALUES(`updated_by`)',
                    [$key, json_encode($value, JSON_THROW_ON_ERROR), $userId]
                );
            }
        });
    }
}
