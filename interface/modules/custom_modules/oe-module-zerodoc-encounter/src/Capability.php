<?php

/**
 * One place to ask "is module X installed and switched on right now?".
 *
 * Every module in this install is registered through Module Manager and can be
 * disabled or unregistered by an admin at any time. A direct call into another
 * module's classes is therefore a fatal error waiting for a click (survey risk
 * R11). Every cross-module call ZeroDoc makes must be guarded here and must
 * have a defined degraded behaviour -- for example "authorization status
 * unknown" rather than an exception.
 *
 * Usage:
 *     if (Capability::isEnabled(Capability::PRIOR_AUTH)) { ... } else { degrade }
 *
 * Note this answers "enabled in Module Manager", which is the same condition
 * core uses to load a module's bootstrap and to allow its scripts to run
 * (ModulesApplication::checkModuleScriptPathForEnabledModule). A module whose
 * row is missing altogether reads as not enabled.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ZeroDoc;

use OpenEMR\Common\Database\QueryUtils;

final class Capability
{
    /** Module directory names, as they appear in modules.mod_directory. */
    public const AI_SCRIBE = 'oe-module-elegant-ai-scribe';
    public const CASHPAY = 'oe-module-cashpay-invoicing';
    public const PRIOR_AUTH = 'oe-module-prior-authorizations';
    public const FAXSMS = 'oe-module-faxsms';
    public const WENO = 'oe-module-weno';
    public const DORN = 'oe-module-dorn';
    public const TELEHEALTH = 'oe-module-comlink-telehealth';
    public const EHI_EXPORTER = 'oe-module-ehi-exporter';

    /** @var array<string, bool> per-request cache; module state cannot change mid-request. */
    private static array $cache = [];

    /** True when the module has a row in `modules` and is active. */
    public static function isEnabled(string $moduleDirectory): bool
    {
        if (array_key_exists($moduleDirectory, self::$cache)) {
            return self::$cache[$moduleDirectory];
        }
        $enabled = false;
        try {
            $row = QueryUtils::querySingleRow(
                'SELECT `mod_active` FROM `modules` WHERE `mod_directory` = ? AND `type` != 1',
                [$moduleDirectory]
            );
            $enabled = is_array($row) && (int)$row['mod_active'] === 1;
        } catch (\Throwable $e) {
            error_log('oe-module-zerodoc-encounter: ' . $e::class . ' while checking module ' . $moduleDirectory);
        }
        return self::$cache[$moduleDirectory] = $enabled;
    }

    /**
     * Enabled AND the named class is autoloadable. Use this before calling into
     * another module's code: a module can be enabled while its classes are not
     * on the autoloader (its bootstrap can have failed -- core swallows that).
     *
     * @param class-string $class
     */
    public static function provides(string $moduleDirectory, string $class): bool
    {
        return self::isEnabled($moduleDirectory) && class_exists($class);
    }

    /** Tests only. */
    public static function resetCache(): void
    {
        self::$cache = [];
    }
}
