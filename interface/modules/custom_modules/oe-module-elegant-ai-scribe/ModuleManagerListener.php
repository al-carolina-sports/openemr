<?php

/**
 * Module Manager hooks.
 *
 * enable/disable  -> turns the "AI Scribe Note" encounter form on/off (registry.state)
 * unregister      -> drops the settings and session tables and disables the form.
 * Saved notes (form_elegant_ai_scribe) are clinical records and are kept.
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
            if ($methodName === 'enable') {
                $this->setFormState(1);
            } elseif ($methodName === 'disable') {
                $this->setFormState(0);
            } elseif ($methodName === 'unregister') {
                $this->setFormState(0);
                QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS `oe_module_eas_sessions`', []);
                QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS `oe_module_eas_settings`', []);
            }
        } catch (\Throwable $e) {
            error_log('oe-module-elegant-ai-scribe: ' . $e::class . ' during ' . $methodName);
            return 'AI Scribe could not complete ' . $methodName . '; see cleanup.sql.';
        }
        return $currentActionStatus;
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\ElegantAiScribe\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    private function setFormState(int $state): void
    {
        if (QueryUtils::existsTable('registry')) {
            QueryUtils::sqlStatementThrowException("UPDATE `registry` SET `state` = ? WHERE `directory` IN ('elegant_ai_scribe', 'elegant_ai_scribe_soap')", [$state]);
        }
    }
}
