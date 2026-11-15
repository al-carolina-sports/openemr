<?php

/**
 * Module Manager hooks. On unregister (the Module Manager's "uninstall") the
 * module's settings table is dropped, because OpenEMR does not run cleanup.sql
 * itself. Disabling the module keeps the settings.
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
        if ($methodName === 'unregister') {
            return $this->dropSettings($currentActionStatus);
        }
        return $currentActionStatus;
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\ApptHoverCard\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    private function dropSettings(string $currentActionStatus): string
    {
        try {
            QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS `oe_module_appt_hovercard_settings`', []);
        } catch (\Throwable $e) {
            error_log('oe-module-appt-hovercard: ' . $e::class . ' while dropping settings table');
            return 'Hover card settings table could not be removed; drop oe_module_appt_hovercard_settings manually.';
        }
        return $currentActionStatus;
    }
}
