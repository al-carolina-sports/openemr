<?php

/**
 * Module Manager hooks.
 *
 * On enable the module registers its access controls (ACO section "cashpay")
 * and grants them to the stock groups. OpenEMR only runs acl_setup.php for
 * Laminas modules, so a custom module has to do this itself.
 *
 * Unregistering does NOT drop the cpi_ tables: they hold invoices, payments
 * and commission history that must outlive the module. cleanup.sql removes
 * them by hand when that is really wanted.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Core\AbstractModuleActionListener;
use OpenEMR\Modules\CashpayInvoicing\AclRegistrar;

class ModuleManagerListener extends AbstractModuleActionListener
{
    public function __construct()
    {
        parent::__construct();
    }

    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if ($methodName === 'enable') {
            return $this->registerAcl($currentActionStatus);
        }
        return $currentActionStatus;
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\CashpayInvoicing\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    private function registerAcl(string $currentActionStatus): string
    {
        // The listener can run before the module's bootstrap has registered its namespace.
        require_once __DIR__ . '/src/AclObject.php';
        require_once __DIR__ . '/src/AclRegistrar.php';
        try {
            (new AclRegistrar())->register();
        } catch (\Throwable $e) {
            error_log('oe-module-cashpay-invoicing: ' . $e::class . ' while registering access controls');
            return 'Cash-Pay access controls could not be registered; check the PHP error log.';
        }
        return $currentActionStatus;
    }
}
