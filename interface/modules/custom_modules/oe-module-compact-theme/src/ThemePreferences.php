<?php

/**
 * Reads and saves theme choices in OpenEMR's own settings tables, so they are
 * the same values Admin > Config and User Settings show:
 *  - practice default: `globals` rows cmt_*
 *  - a user's override: `user_settings` rows global:cmt_* for that user
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CompactTheme;

use OpenEMR\Common\Database\QueryUtils;

final class ThemePreferences
{
    private const USER_LABEL_PREFIX = 'global:';

    /** @var list<string> */
    private const KEYS = [
        ThemeSettings::KEY_THEME,
        ThemeSettings::KEY_DENSITY,
        ThemeSettings::KEY_MENU_STYLE,
        ThemeSettings::KEY_ACCENT,
    ];

    public function saveForUser(int $userId, ThemeChoice $choice): void
    {
        foreach ($choice->toGlobals() as $name => $value) {
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO `user_settings` (`setting_user`, `setting_label`, `setting_value`) VALUES (?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)',
                [$userId, self::USER_LABEL_PREFIX . $name, $value]
            );
        }
    }

    public function clearForUser(int $userId): void
    {
        foreach (self::KEYS as $name) {
            QueryUtils::sqlStatementThrowException(
                'DELETE FROM `user_settings` WHERE `setting_user` = ? AND `setting_label` = ?',
                [$userId, self::USER_LABEL_PREFIX . $name]
            );
        }
    }

    public function userHasOverride(int $userId): bool
    {
        $placeholders = implode(',', array_fill(0, count(self::KEYS), '?'));
        $labels = array_map(static fn(string $name): string => self::USER_LABEL_PREFIX . $name, self::KEYS);
        $rows = QueryUtils::fetchRecords(
            "SELECT `setting_label` FROM `user_settings` WHERE `setting_user` = ? AND `setting_label` IN ($placeholders)",
            array_merge([$userId], $labels)
        );
        return $rows !== [];
    }

    public function saveAsPracticeDefault(ThemeChoice $choice): void
    {
        foreach ($choice->toGlobals() as $name => $value) {
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO `globals` (`gl_name`, `gl_index`, `gl_value`) VALUES (?, 0, ?)'
                . ' ON DUPLICATE KEY UPDATE `gl_value` = VALUES(`gl_value`)',
                [$name, $value]
            );
        }
    }

    /**
     * @return array<string, string> Global name => practice default value
     */
    public function practiceDefaults(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::KEYS), '?'));
        $rows = QueryUtils::fetchRecords(
            "SELECT `gl_name`, `gl_value` FROM `globals` WHERE `gl_index` = 0 AND `gl_name` IN ($placeholders)",
            self::KEYS
        );
        $defaults = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['gl_name'] ?? null) && is_string($row['gl_value'] ?? null)) {
                $defaults[$row['gl_name']] = $row['gl_value'];
            }
        }
        return $defaults;
    }
}
