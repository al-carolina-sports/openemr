<?php

/**
 * Module Manager hooks for the ZeroDoc Clinical Encounter Workflow.
 *
 * enable          -> registers ZeroDoc's own ACO section/objects (OpenEMR runs
 *                    acl/acl_setup.php for Laminas modules only) and turns
 *                    ZeroDoc's encounter forms on (registry.state)
 * disable         -> turns the forms off
 * unregister      -> disables the forms and drops ZeroDoc's non-clinical
 *                    bookkeeping tables EXPLICITLY, because OpenEMR never runs
 *                    cleanup.sql itself. Clinical data (form_zdoc_*) is a
 *                    patient record and is never dropped automatically; see
 *                    cleanup.sql.
 *
 * NOTE: this class must stay in the GLOBAL namespace and be named exactly
 * ModuleManagerListener -- core does `require_once` + static calls by that
 * name (InstallerController::notifyModuleListener). Core calls
 * moduleManagerAction twice per action: once as "pre<action>", once as
 * "<action>" after the action has already happened. Exceptions thrown here are
 * swallowed by core, so catch and return a message instead.
 *
 * NOTE: the `openemr:zfc-module` CLI bypasses this listener entirely, so
 * nothing essential to a working install may live only here -- table.sql
 * already sets registry.state = 1.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\AbstractModuleActionListener;
use OpenEMR\Modules\ZeroDoc\AclRegistrar;

class ModuleManagerListener extends AbstractModuleActionListener
{
    /**
     * Encounter form directories owned by ZeroDoc. Kept in sync with
     * \OpenEMR\Modules\ZeroDoc\Forms::ALL and with table.sql. Duplicated as a
     * literal because this listener can run before the module namespace is
     * usable.
     */
    private const FORMDIRS = ['zdoc_encounter'];

    /**
     * Tables that are module bookkeeping, not patient data, and may be dropped
     * on unregister. The real clinical tables (contracts agent) go in
     * cleanup.sql, NOT here.
     */
    private const DISPOSABLE_TABLES = ['zdoc_schema_version'];

    public function __construct()
    {
        parent::__construct();
    }

    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        try {
            if ($methodName === 'enable') {
                $this->setFormState(1);
                $aclStatus = $this->registerAcl($currentActionStatus);
                if ($aclStatus !== $currentActionStatus) {
                    return $aclStatus;
                }
            } elseif ($methodName === 'disable') {
                $this->setFormState(0);
            } elseif ($methodName === 'unregister') {
                $this->setFormState(0);
                foreach (self::DISPOSABLE_TABLES as $table) {
                    QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS `' . $table . '`', []);
                }
            }
        } catch (\Throwable $e) {
            error_log('oe-module-zerodoc-encounter: ' . $e::class . ' during ' . $methodName);
            return 'ZeroDoc could not complete ' . $methodName . '; see cleanup.sql.';
        }
        return $currentActionStatus;
    }

    /** Lets core register OpenEMR\Modules\ZeroDoc\ against src/ before calling us. */
    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\ZeroDoc\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    /**
     * Registers the zerodoc ACO section and grants it to the stock groups.
     * Idempotent. Note this listener can run before the module's bootstrap has
     * registered the namespace, so the classes are required explicitly --
     * core's notifyModuleListener() does register the namespace from
     * getModuleNamespace(), but requiring is cheap and survives that changing.
     */
    private function registerAcl(string $currentActionStatus): string
    {
        require_once __DIR__ . '/src/AclObject.php';
        require_once __DIR__ . '/src/AclRegistrar.php';
        try {
            (new AclRegistrar())->register();
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error(
                'ZeroDoc access controls could not be registered',
                ['exception' => $e]
            );
            return 'ZeroDoc access controls could not be registered; check the PHP error log.';
        }
        return $currentActionStatus;
    }

    private function setFormState(int $state): void
    {
        if (!QueryUtils::existsTable('registry')) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count(self::FORMDIRS), '?'));
        QueryUtils::sqlStatementThrowException(
            'UPDATE `registry` SET `state` = ? WHERE `directory` IN (' . $placeholders . ')',
            array_merge([$state], self::FORMDIRS)
        );
    }
}
