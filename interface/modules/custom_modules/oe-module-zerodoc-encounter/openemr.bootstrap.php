<?php

/**
 * ZeroDoc Clinical Encounter Workflow module bootstrap.
 *
 * Included by OpenEMR core on every page load for each row in `modules` with
 * mod_active = 1 (see ModulesApplication::bootstrapCustomModules). Nothing is
 * returned; the file registers the module namespace and subscribes to events.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ZeroDoc\Bootstrap;

$classLoader = new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir());
$classLoader->registerNamespaceIfNotExists(
    'OpenEMR\\Modules\\ZeroDoc\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src'
);

$bootstrap = new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher());
$bootstrap->subscribeToEvents();
