<?php

/**
 * Access checks for ZeroDoc's pages and endpoints.
 *
 * Every endpoint asks here, never AclMain directly, so the rule lives in one
 * place. Pattern from oe-module-cashpay-invoicing/src/Access.php.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ZeroDoc;

use OpenEMR\Common\Acl\AclMain;

final class Access
{
    /**
     * Superusers always pass, so the module stays usable if the ACO objects
     * were never registered (enabled from the CLI, or enabled before an
     * upgrade that added a new object).
     */
    public function can(AclObject $object): bool
    {
        return AclMain::aclCheckCore(AclObject::SECTION, $object->value)
            || AclMain::aclCheckCore('admin', 'super');
    }

    /** Core's own clinical-notes write permission, which gates encounter forms. */
    public function canWriteEncounterNotes(): bool
    {
        return AclMain::aclCheckCore('encounters', 'notes', '', 'write')
            || AclMain::aclCheckCore('encounters', 'notes_a', '', 'write');
    }
}
