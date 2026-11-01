<?php

/**
 * Module Manager hooks.
 *
 * On enable the module registers its access controls (ACO section
 * "ai_assistant"), grants them to the stock groups and switches on the daily
 * log purge. OpenEMR only runs acl_setup.php for Laminas modules, so a custom
 * module has to do this itself.
 *
 * Unregistering does NOT drop the tables: the audit log must outlive the
 * module until its retention period ends. cleanup.sql removes them by hand.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Core\AbstractModuleActionListener;
use OpenEMR\Modules\AiAssistant\AclRegistrar;
use OpenEMR\Services\Background\BackgroundServiceDefinition;
use OpenEMR\Services\Background\BackgroundServiceRegistry;

class ModuleManagerListener extends AbstractModuleActionListener
{
    private const JOB_NAME = 'ai_assistant_log_purge';

    public function __construct()
    {
        parent::__construct();
    }

    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if ($methodName === 'enable') {
            $status = $this->registerAcl($currentActionStatus);
            $this->setBackgroundService(true);
            return $status;
        }
        if ($methodName === 'disable' || $methodName === 'unregister') {
            $this->setBackgroundService(false);
        }
        return $currentActionStatus;
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\AiAssistant\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    /**
     * Registers the daily purge of chat logs and conversations older than the
     * retention setting, or switches it off when the module is disabled.
     */
    private function setBackgroundService(bool $active): void
    {
        $registry = new BackgroundServiceRegistry();
        if (!$active) {
            if ($registry->exists(self::JOB_NAME)) {
                $registry->setActive(self::JOB_NAME, false);
            }
            return;
        }
        $registry->register(new BackgroundServiceDefinition(
            name: self::JOB_NAME,
            title: 'AI Assistant log purge',
            function: 'OpenEMR\\Modules\\AiAssistant\\Jobs\\runLogPurge',
            requireOnce: '/interface/modules/custom_modules/oe-module-ai-assistant/jobs/log_purge.php',
            executeInterval: 1440,
            sortOrder: 110,
            active: true,
        ));
    }

    private function registerAcl(string $currentActionStatus): string
    {
        // The listener can run before the module's bootstrap has registered its namespace.
        require_once __DIR__ . '/src/AclObject.php';
        require_once __DIR__ . '/src/AclRegistrar.php';
        try {
            (new AclRegistrar())->register();
        } catch (\RuntimeException $e) {
            ServiceContainer::getLogger()->error('AI Assistant access controls could not be registered', ['exception' => $e]);
            return 'AI Assistant access controls could not be registered; check the PHP error log.';
        }
        return $currentActionStatus;
    }
}
