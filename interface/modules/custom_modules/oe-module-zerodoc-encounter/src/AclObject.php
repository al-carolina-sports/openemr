<?php

/**
 * Access control objects of the "zerodoc" ACO section.
 *
 * ZeroDoc owns its own permissions rather than borrowing another module's.
 * Pattern copied from oe-module-cashpay-invoicing (src/AclObject.php), which is
 * the only custom module in this install that registers module-owned ACOs --
 * OpenEMR runs acl/acl_setup.php for Laminas modules only, so a custom module
 * must register them itself from the enable hook.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ZeroDoc;

/**
 * Backed because the value is stored in phpGACL.
 */
enum AclObject: string
{
    case View = 'view';
    case Document = 'document';
    case Sign = 'sign';
    case Dispatch = 'dispatch';
    case Setup = 'setup';
    case Settings = 'settings';

    public const SECTION = 'zerodoc';
    public const SECTION_TITLE = 'ZeroDoc';

    public function title(): string
    {
        return match ($this) {
            self::View => 'ZeroDoc: view encounter documentation',
            self::Document => 'ZeroDoc: write encounter documentation',
            self::Sign => 'ZeroDoc: attest and sign a note',
            self::Dispatch => 'ZeroDoc: work the order queue and dispatch orders',
            self::Setup => 'ZeroDoc: templates, order types, saved findings and macros',
            self::Settings => 'ZeroDoc: module settings',
        };
    }

    /**
     * Stock OpenEMR groups that receive this object on first enable.
     * Administrators get everything; admins can change grants later under
     * Admin > ACL.
     *
     * @return list<string>
     */
    public function defaultGroups(): array
    {
        return match ($this) {
            self::View => ['Administrators', 'Physicians', 'Clinicians', 'Front Office'],
            self::Document => ['Administrators', 'Physicians', 'Clinicians'],
            self::Sign => ['Administrators', 'Physicians'],
            self::Dispatch => ['Administrators', 'Clinicians', 'Front Office'],
            self::Setup, self::Settings => ['Administrators'],
        };
    }
}
