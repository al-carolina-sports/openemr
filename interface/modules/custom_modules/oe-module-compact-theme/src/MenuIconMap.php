<?php

/**
 * Maps top-level main menu items to icon names (files in public/assets/icons/).
 *
 * Matching uses the language-independent `target` and `menu_id` fields from
 * interface/main/tabs/menu/menus/*.json, never the translated label, except
 * where two stock items share a menu_id.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CompactTheme;

final class MenuIconMap
{
    /** Top-level menu links, keyed by their tab target. */
    private const BY_TARGET = [
        'cal' => 'calendar',
        'fin' => 'finder',
        'flb' => 'flow',
        'rcb' => 'recalls',
        'msg' => 'messages',
    ];

    /** Top-level dropdown headers, keyed by menu_id. */
    private const BY_MENU_ID = [
        'patimg' => 'patient',
        'groupimg' => 'groups',
        'feeimg' => 'fees',
        'modimg' => 'modules',
        'invimg' => 'inventory',
        'proimg' => 'procedures',
        'admimg' => 'admin',
        'repimg' => 'reports',
        'misimg' => 'misc',
        'popup' => 'popups',
    ];

    public static function iconFor(\stdClass $item): ?string
    {
        $target = $item->target ?? null;
        if (is_string($target) && isset(self::BY_TARGET[$target])) {
            return self::BY_TARGET[$target];
        }

        $menuId = $item->menu_id ?? null;
        if (!is_string($menuId) || !isset(self::BY_MENU_ID[$menuId])) {
            return null;
        }

        // the stock Ensora eRx header reuses feeimg
        $label = $item->label ?? '';
        if ($menuId === 'feeimg' && is_string($label) && str_contains(strtolower($label), 'erx')) {
            return 'erx';
        }

        return self::BY_MENU_ID[$menuId];
    }
}
