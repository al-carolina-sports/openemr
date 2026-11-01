<?php

/**
 * The core rights tools check for the logged-in user before reading.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

interface Permissions
{
    /** Demographics (patients/demo): needed to look patients up. */
    public function canReadPatients(): bool;

    /** Appointments (patients/appt): needed to read schedules. */
    public function canReadCalendar(): bool;
}
