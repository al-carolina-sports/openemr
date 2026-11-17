<?php

/**
 * Hide Saturday / Hide Sunday settings (v1.3.1).
 *
 * Site defaults (admin, settings page) live in the module's own settings
 * table, one row per key, like the other Carolina Sports modules. Each user's
 * own choice lives in user_settings (labels oe_module_ecs_hide_sat /
 * oe_module_ecs_hide_sun), like the remembered Custom View. A user's choice
 * overrides the site default; with no user row the site default applies.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantCalendarStyle;

use OpenEMR\Common\Database\QueryUtils;

final class WeekendSettings
{
    public const TABLE = 'oe_module_ecs_settings';
    public const DAYS = ['sat', 'sun'];
    public const USER_LABEL_PREFIX = 'oe_module_ecs_hide_';

    /** Out of the box both weekend days are hidden. */
    private const DEFAULTS = ['sat' => true, 'sun' => true];

    public static function isDay(mixed $day): bool
    {
        return is_string($day) && in_array($day, self::DAYS, true);
    }

    public static function siteKey(string $day): string
    {
        return 'hide_' . ($day === 'sat' ? 'saturday' : 'sunday');
    }

    public static function userLabel(string $day): string
    {
        return self::USER_LABEL_PREFIX . $day;
    }

    /**
     * Site defaults; falls back to the built-in defaults if the table is
     * missing or unreadable so the calendar never breaks.
     *
     * @return array{sat: bool, sun: bool}
     */
    public function siteDefaults(): array
    {
        $result = self::DEFAULTS;
        try {
            $rows = QueryUtils::fetchRecords('SELECT `setting_key`, `setting_value` FROM `' . self::TABLE . '`');
        } catch (\Throwable) {
            return $result;
        }
        foreach (self::DAYS as $day) {
            foreach ($rows as $row) {
                if (($row['setting_key'] ?? null) === self::siteKey($day)) {
                    $result[$day] = ($row['setting_value'] ?? '') === '1';
                }
            }
        }
        return $result;
    }

    public function saveSiteDefaults(bool $hideSat, bool $hideSun): void
    {
        // Same definition as table.sql, so saving works even if the upgrade
        // SQL has not been run yet.
        QueryUtils::sqlStatementThrowException(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
            . '`setting_key` VARCHAR(100) NOT NULL, `setting_value` VARCHAR(255) NOT NULL, '
            . '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY (`setting_key`)) ENGINE=InnoDB COMMENT=\'Elegant Calendar Style site settings\'',
            []
        );
        foreach (['sat' => $hideSat, 'sun' => $hideSun] as $day => $hide) {
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO `' . self::TABLE . '` (`setting_key`, `setting_value`) VALUES (?, ?) '
                . 'ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)',
                [self::siteKey($day), $hide ? '1' : '0']
            );
        }
    }

    /**
     * The user's own choices; null = no choice (site default applies).
     *
     * @return array{sat: ?bool, sun: ?bool}
     */
    public function userOverrides(int $userId): array
    {
        $result = ['sat' => null, 'sun' => null];
        if ($userId <= 0) {
            return $result;
        }
        $rows = QueryUtils::fetchRecords(
            'SELECT `setting_label`, `setting_value` FROM `user_settings` WHERE `setting_user` = ? AND `setting_label` IN (?, ?)',
            [$userId, self::userLabel('sat'), self::userLabel('sun')]
        );
        foreach ($rows as $row) {
            foreach (self::DAYS as $day) {
                if (($row['setting_label'] ?? null) === self::userLabel($day)) {
                    $value = (string)($row['setting_value'] ?? '');
                    $result[$day] = $value === '1' ? true : ($value === '0' ? false : null);
                }
            }
        }
        return $result;
    }

    /** Set (true/false) or clear (null) the user's choice for one day. */
    public function setUserOverride(int $userId, string $day, ?bool $hide): void
    {
        if ($userId <= 0 || !self::isDay($day)) {
            throw new \InvalidArgumentException('day');
        }
        if ($hide === null) {
            QueryUtils::sqlStatementThrowException(
                'DELETE FROM `user_settings` WHERE `setting_user` = ? AND `setting_label` = ?',
                [$userId, self::userLabel($day)]
            );
            return;
        }
        QueryUtils::sqlStatementThrowException(
            'REPLACE INTO `user_settings` (`setting_user`, `setting_label`, `setting_value`) VALUES (?, ?, ?)',
            [$userId, self::userLabel($day), $hide ? '1' : '0']
        );
    }

    /**
     * What the client needs: site default, the user's choice and the
     * effective value per day.
     *
     * @return array<string, array{site: bool, user: ?bool, hide: bool}>
     */
    public function forUser(int $userId): array
    {
        $site = $this->siteDefaults();
        try {
            $user = $this->userOverrides($userId);
        } catch (\Throwable) {
            $user = ['sat' => null, 'sun' => null];
        }
        $out = [];
        foreach (self::DAYS as $day) {
            $out[$day] = ['site' => $site[$day], 'user' => $user[$day], 'hide' => $user[$day] ?? $site[$day]];
        }
        return $out;
    }
}
