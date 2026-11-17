<?php

/**
 * Request context shared by the endpoints: logged-in user, the patient and encounter open in this session, ACL.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;

final class Context
{
    private function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly int $pid,
        public readonly int $encounter
    ) {
    }

    /** Null when not logged in, no patient/encounter is open, or the user may not write encounter notes. */
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
        if (!AclMain::aclCheckCore('encounters', 'notes', '', 'write') && !AclMain::aclCheckCore('encounters', 'notes_a', '', 'write')) {
            return null;
        }
        return new self($userId, $username, $pid, $encounter);
    }
}
