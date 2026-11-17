<?php

/**
 * Looks up the ACL group titles ("roles") a user belongs to.
 *
 * Pattern from oe-module-appt-hovercard/src/RoleResolver.php. Used for the
 * server-side role gate on asset injection and on every endpoint.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ZeroDoc;

use OpenEMR\Common\Acl\AclExtended;

class RoleResolver
{
    /** @return list<string> */
    public function titlesFor(string $username): array
    {
        if ($username === '') {
            return [];
        }
        $titles = AclExtended::aclGetGroupTitles($username);
        if (!is_array($titles)) {
            return [];
        }
        return array_values(array_filter($titles, 'is_string'));
    }

    /**
     * Every role that can be picked on a config page.
     *
     * @return list<string>
     */
    public function allTitles(): array
    {
        $titles = AclExtended::aclGetGroupTitleList();
        return array_values(array_filter($titles, 'is_string'));
    }
}
