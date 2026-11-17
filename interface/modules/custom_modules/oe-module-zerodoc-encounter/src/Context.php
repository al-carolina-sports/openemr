<?php

/**
 * Request context for ZeroDoc's endpoints and form pages: the logged-in user,
 * the patient and encounter open in this session, and the write ACL.
 *
 * Nothing here trusts $_GET or $_POST. Endpoints 403 when current() is null.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ZeroDoc;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;

final class Context
{
    private function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $provider,
        public readonly int $pid,
        public readonly int $encounter,
        public readonly int $authorized,
    ) {
    }

    /**
     * Null when not logged in, when no patient/encounter is open, or when the
     * user may not write encounter notes.
     */
    public static function current(): ?self
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $username = $session->get('authUser');
        $userId = (int)$session->get('authUserID');
        if (!is_string($username) || $username === '' || $userId <= 0) {
            return null;
        }
        $pid = PatientSessionUtil::getPid();
        $encounter = EncounterSessionUtil::getEncounter();
        if ($pid <= 0 || $encounter <= 0) {
            return null;
        }
        if (
            !AclMain::aclCheckCore('encounters', 'notes', '', 'write')
            && !AclMain::aclCheckCore('encounters', 'notes_a', '', 'write')
        ) {
            return null;
        }
        return new self(
            $userId,
            $username,
            (string)$session->get('authProvider'),
            $pid,
            $encounter,
            (int)PatientSessionUtil::getUserAuthorized(),
        );
    }
}
