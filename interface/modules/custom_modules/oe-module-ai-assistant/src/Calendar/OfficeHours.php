<?php

/**
 * When a provider is assumed to be working on a day that has no
 * "In Office" / "Out Of Office" markers on the calendar.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Calendar;

final readonly class OfficeHours
{
    /**
     * @param int $startMinute minutes after midnight (from the calendar's "Calendar Starting Hour")
     * @param int $endMinute minutes after midnight (from "Calendar Ending Hour")
     * @param int $stepMinutes the calendar's appointment interval
     * @param bool $assumeWhenUnmarked use these hours on working days with no markers
     * @param list<int> $workingDays ISO weekdays, 1 = Monday
     */
    public function __construct(
        public int $startMinute,
        public int $endMinute,
        public int $stepMinutes,
        public bool $assumeWhenUnmarked,
        public array $workingDays,
    ) {
        if ($startMinute < 0 || $endMinute > 1440 || $endMinute <= $startMinute) {
            throw new \DomainException('Office hours must start before they end');
        }
        if ($stepMinutes < 5 || $stepMinutes > 240) {
            throw new \DomainException('The appointment step must be between 5 and 240 minutes');
        }
    }
}
