<?php

/**
 * Access checks for the module's pages.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing;

use OpenEMR\Common\Acl\AclMain;

final class Access
{
    /**
     * Superusers always pass, so the module stays usable if the ACL
     * objects were not registered (for example, enabled before an upgrade).
     */
    public function can(AclObject $object): bool
    {
        return AclMain::aclCheckCore(AclObject::SECTION, $object->value)
            || AclMain::aclCheckCore('admin', 'super');
    }
}
