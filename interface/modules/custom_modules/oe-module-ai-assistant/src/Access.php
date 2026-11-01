<?php

/**
 * Access checks: the module's own objects plus the core rights every tool
 * relies on (calendar and demographics), all for the logged-in user.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Modules\AiAssistant\Tools\Permissions;

final class Access implements Permissions
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

    public function canReadPatients(): bool
    {
        return AclMain::aclCheckCore('patients', 'demo');
    }

    public function canReadCalendar(): bool
    {
        return AclMain::aclCheckCore('patients', 'appt');
    }
}
