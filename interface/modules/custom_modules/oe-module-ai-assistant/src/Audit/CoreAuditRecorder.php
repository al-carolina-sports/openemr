<?php

/**
 * PatientAccessRecorder over OpenEMR's audit log (Admin > Reports > Audit Log).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Audit;

use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Modules\AiAssistant\Agent\StaffUser;

final class CoreAuditRecorder implements PatientAccessRecorder
{
    public function record(StaffUser $user, int $pid, string $comment): void
    {
        EventAuditLogger::getInstance()->newEvent('view', $user->username, $user->group, 1, $comment, $pid);
    }
}
