<?php

/**
 * ZeroDoc's encounter form registry: one entry per OpenEMR form directory.
 *
 * Each entry must agree with three other places or the form will not work:
 *   * a `registry` row in table.sql whose `directory` equals the array key
 *   * a forms/<key>/ folder holding new.php, view.php and report.php, with
 *     report.php defining a global function named "<key>_report"
 *   * ModuleManagerListener::FORMDIRS, so enable/disable toggles it
 *
 * ZeroDoc's clinical sections become additional entries here as the contracts
 * agent's schema lands.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ZeroDoc;

use InvalidArgumentException;

final class Forms
{
    /** formdir => metadata. The formdir is also the registry.directory value. */
    public const ALL = [
        'zdoc_encounter' => [
            // registry.name / forms.form_name. Changing this needs a migration
            // of existing `forms` rows, so treat it as part of the schema.
            'form_name' => 'ZeroDoc Encounter Note',
            'label' => 'Encounter Note',
            'section_key' => 'encounter',
        ],
    ];

    /** @return list<string> */
    public static function formdirs(): array
    {
        return array_keys(self::ALL);
    }

    public static function isOurs(string $formdir): bool
    {
        return isset(self::ALL[$formdir]);
    }

    /** @return array{form_name: string, label: string, section_key: string} */
    public static function meta(string $formdir): array
    {
        if (!self::isOurs($formdir)) {
            throw new InvalidArgumentException('Unknown ZeroDoc form');
        }
        return self::ALL[$formdir];
    }

    /** OpenEMR's fixed one-table-per-form-directory convention. */
    public static function table(string $formdir): string
    {
        if (!self::isOurs($formdir)) {
            throw new InvalidArgumentException('Unknown ZeroDoc form');
        }
        return 'form_' . $formdir;
    }
}
