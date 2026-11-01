<?php

/**
 * Background service "ai_assistant_log_purge": deletes chat logs and
 * conversations older than the retention setting. Registered when the
 * module is enabled and run daily by OpenEMR's background service runner.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Jobs;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\AiAssistant\ModuleServices;

function runLogPurge(): void
{
    // The runner may load this file before the module's bootstrap has run.
    (new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir()))->registerNamespaceIfNotExists(
        'OpenEMR\\Modules\\AiAssistant\\',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src'
    );
    $services = new ModuleServices();
    $days = $services->settings()->logRetentionDays;
    $services->audit()->purgeOlderThan($days);
    ServiceContainer::getLogger()->info('AI Assistant log purge ran', ['retention_days' => $days]);
}
