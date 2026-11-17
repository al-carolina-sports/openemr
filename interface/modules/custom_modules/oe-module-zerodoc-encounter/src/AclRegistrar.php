<?php

/**
 * Creates ZeroDoc's ACO section and objects and grants them to the stock
 * groups. Safe to run repeatedly: AclExtended checks before each insert.
 *
 * Called from ModuleManagerListener on enable. OpenEMR never runs
 * acl/acl_setup.php for a custom module, so this is the only path.
 * Pattern from oe-module-cashpay-invoicing/src/AclRegistrar.php.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ZeroDoc;

use OpenEMR\Common\Acl\AclExtended;

final class AclRegistrar
{
    public function register(): void
    {
        // AclExtended reports progress with echo; keep it out of the Module
        // Manager's JSON response.
        ob_start();
        try {
            AclExtended::addObjectSectionAcl(AclObject::SECTION, AclObject::SECTION_TITLE);
            foreach (AclObject::cases() as $object) {
                AclExtended::addObjectAcl(
                    AclObject::SECTION,
                    AclObject::SECTION_TITLE,
                    $object->value,
                    $object->title()
                );
            }
            foreach (AclObject::cases() as $object) {
                foreach ($object->defaultGroups() as $group) {
                    $aclId = AclExtended::getAclIdNumber($group, 'write');
                    if (count($aclId) !== 1) {
                        continue;
                    }
                    AclExtended::updateAcl(
                        $aclId,
                        $group,
                        AclObject::SECTION,
                        AclObject::SECTION_TITLE,
                        $object->value,
                        $object->title(),
                        'write'
                    );
                }
            }
        } finally {
            ob_end_clean();
        }
    }
}
