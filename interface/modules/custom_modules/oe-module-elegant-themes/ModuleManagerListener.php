<?php

/**
 * Module Manager hooks. unregister drops the preferences table.
 * enable/disable need nothing: a disabled module is not bootstrapped, so no theme is injected.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\AbstractModuleActionListener;

class ModuleManagerListener extends AbstractModuleActionListener
{
    public function __construct()
    {
        parent::__construct();
    }

    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        try {
            if ($methodName === 'unregister') {
                QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS `oe_module_elegant_themes_prefs`', []);
            }
        } catch (\Throwable $e) {
            error_log('oe-module-elegant-themes: ' . $e::class . ' during ' . $methodName);
            return 'Elegant Themes could not complete ' . $methodName . '; see cleanup.sql.';
        }
        return $currentActionStatus;
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\ElegantThemes\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }
}
