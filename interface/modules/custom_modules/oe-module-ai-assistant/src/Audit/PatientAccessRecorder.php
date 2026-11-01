<?php

/**
 * Records that the assistant read a patient's data for a user.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Audit;

use OpenEMR\Modules\AiAssistant\Agent\StaffUser;

interface PatientAccessRecorder
{
    public function record(StaffUser $user, int $pid, string $comment): void;
}
