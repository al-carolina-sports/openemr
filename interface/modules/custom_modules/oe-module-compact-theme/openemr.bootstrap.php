<?php

/**
 * Annie's Compact Themes entry point, loaded by the module manager on every request.
 * Kept to wiring only: parse settings once, hand everything to Bootstrap.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\CompactTheme\Bootstrap;
use OpenEMR\Modules\CompactTheme\ThemeRegistry;
use OpenEMR\Modules\CompactTheme\ThemeSettings;

$globals = OEGlobalsBag::getInstance();
$classLoader = new ModulesClassLoader($globals->getProjectDir());
$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\CompactTheme\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

$registry = new ThemeRegistry(__DIR__ . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'themes');
$bootstrap = new Bootstrap(
    $globals->getKernel()->getEventDispatcher(),
    ThemeSettings::fromGlobals($globals, $registry),
    $registry,
    $globals->getWebRoot()
);
$bootstrap->subscribeToEvents();
