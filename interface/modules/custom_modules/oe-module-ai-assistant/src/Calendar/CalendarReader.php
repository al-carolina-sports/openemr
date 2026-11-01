<?php

/**
 * Reads calendar events, with recurring events expanded into occurrences.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Calendar;

interface CalendarReader
{
    /**
     * The provider's events plus clinic-wide events, for dates $from to $to
     * inclusive, ordered by start.
     *
     * @return list<CalendarEvent>
     */
    public function forProvider(int $providerId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * The patient's appointments for dates $from to $to inclusive, ordered by start.
     *
     * @return list<CalendarEvent>
     */
    public function forPatient(int $pid, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
