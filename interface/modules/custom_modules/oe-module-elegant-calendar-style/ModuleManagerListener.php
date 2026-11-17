<?php

/**
 * Module Manager hooks. On unregister (the Module Manager's "uninstall") the
 * Custom Views and settings tables, remembered selections and per-user
 * Hide Sat/Sun choices are removed, because OpenEMR
 * does not run cleanup.sql itself. Disabling the module keeps the views.
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
            return $this->dropTables($currentActionStatus);
        }
        return $currentActionStatus;
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\ElegantCalendarStyle\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    private function dropTables(string $currentActionStatus): string
    {
        try {
            QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS `oe_module_ecs_view_members`', []);
            QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS `oe_module_ecs_views`', []);
            QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS `oe_module_ecs_settings`', []);
            QueryUtils::sqlStatementThrowException(
                "DELETE FROM `user_settings` WHERE `setting_label` IN ('oe_module_ecs_last_view', 'oe_module_ecs_hide_sat', 'oe_module_ecs_hide_sun')",
                []
            );
        } catch (\Throwable $e) {
            error_log('oe-module-elegant-calendar-style: ' . $e::class . ' while dropping Custom Views tables');
            return 'Custom Views tables could not be removed; run cleanup.sql manually.';
        }
        return $currentActionStatus;
    }
}
