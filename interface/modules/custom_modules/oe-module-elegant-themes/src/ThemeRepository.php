<?php

/**
 * Reads/writes theme choices. user_id 0 holds the site default.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantThemes;

use OpenEMR\Common\Database\QueryUtils;

class ThemeRepository
{
    private const TABLE = 'oe_module_elegant_themes_prefs';

    /** Raw stored value for a user: null = no row. */
    public function getRaw(int $userId): ?string
    {
        $row = QueryUtils::querySingleRow('SELECT `theme` FROM `' . self::TABLE . '` WHERE `user_id` = ?', [$userId]);
        return is_array($row) ? (string)$row['theme'] : null;
    }

    public function getSiteDefault(): string
    {
        $v = $this->getRaw(0) ?? Themes::OFF;
        return Themes::isTheme($v) ? $v : Themes::OFF;
    }

    /** Theme key that applies to the user, or '' for off. */
    public function resolve(int $userId): string
    {
        $raw = $userId > 0 ? $this->getRaw($userId) : null;
        if ($raw === null || $raw === Themes::SITE) {
            return $this->getSiteDefault();
        }
        return Themes::isTheme($raw) ? $raw : Themes::OFF;
    }

    public function save(int $userId, string $theme): void
    {
        if ($theme !== Themes::OFF && $theme !== Themes::SITE && !Themes::isTheme($theme)) {
            throw new \InvalidArgumentException('unknown theme');
        }
        if ($userId === 0 && $theme === Themes::SITE) {
            $theme = Themes::OFF;
        }
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO `' . self::TABLE . '` (`user_id`, `theme`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `theme` = VALUES(`theme`)',
            [$userId, $theme]
        );
    }
}
