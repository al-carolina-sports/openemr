<?php

/**
 * Cash-Pay Invoicing module bootstrap.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\CashpayInvoicing\Bootstrap;

$classLoader = new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir());
$classLoader->registerNamespaceIfNotExists(
    'OpenEMR\\Modules\\CashpayInvoicing\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src'
);

$bootstrap = new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher());
$bootstrap->subscribeToEvents();
