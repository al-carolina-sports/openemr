<?php

/**
 * Module Manager hooks for Annie's Compact Themes.
 *
 * Settings live in `globals` (practice default) and `user_settings` (per-user
 * overrides). Install seeds the defaults; nothing here ever overwrites a value
 * an admin or user has saved, so re-installing, upgrading the module or
 * upgrading OpenEMR keeps everyone's choices.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\AbstractModuleActionListener;

class ModuleManagerListener extends AbstractModuleActionListener
{
    /** Practice defaults, inserted only where no value exists yet. */
    private const DEFAULTS = [
        'cmt_theme' => 'paper',
        'cmt_density' => 'compact',
        'cmt_menu_style' => 'both',
        'cmt_accent' => '',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if ($methodName === 'install' || $methodName === 'enable') {
            self::seedDefaults();
        }
        return $currentActionStatus;
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\CompactTheme\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    private static function seedDefaults(): void
    {
        foreach (self::DEFAULTS as $name => $value) {
            QueryUtils::sqlStatementThrowException(
                'INSERT IGNORE INTO `globals` (`gl_name`, `gl_index`, `gl_value`) VALUES (?, 0, ?)',
                [$name, $value]
            );
        }
    }
}
