<?php

/**
 * Background service "cashpay_membership_billing": bills memberships that
 * are due and charges autopay. Registered when the module is enabled and
 * run by OpenEMR's background service runner.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Jobs;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\CashpayInvoicing\ModuleServices;

function runMembershipBilling(): void
{
    // The runner may load this file before the module's bootstrap has run.
    (new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir()))->registerNamespaceIfNotExists(
        'OpenEMR\\Modules\\CashpayInvoicing\\',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src'
    );
    $result = (new ModuleServices())->membershipJob()->run(ServiceContainer::getClock()->now()->setTime(0, 0));
    ServiceContainer::getLogger()->info('Cash-pay membership billing ran', $result);
}
