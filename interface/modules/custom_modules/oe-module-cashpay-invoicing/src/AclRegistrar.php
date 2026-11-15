<?php

/**
 * Creates the module's ACO section and objects and grants them to the
 * stock groups. Safe to run repeatedly: every step checks first.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing;

use OpenEMR\Common\Acl\AclExtended;

final class AclRegistrar
{
    public function register(): void
    {
        // AclExtended reports progress with echo; keep it out of the Module Manager response.
        ob_start();
        try {
            AclExtended::addObjectSectionAcl(AclObject::SECTION, AclObject::SECTION_TITLE);
            foreach (AclObject::cases() as $object) {
                AclExtended::addObjectAcl(AclObject::SECTION, AclObject::SECTION_TITLE, $object->value, $object->title());
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
