<?php

/**
 * How tools present dates and events to the model: ISO values for
 * reasoning plus spelled-out labels it can repeat to staff unchanged, so it
 * never has to work out a weekday itself.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

use OpenEMR\Modules\AiAssistant\Calendar\CalendarEvent;

final class Format
{
    public static function date(\DateTimeImmutable $t): string
    {
        return $t->format('Y-m-d');
    }

    /** "Tue Nov 4, 2026" */
    public static function dayLabel(\DateTimeImmutable $t): string
    {
        return $t->format('D M j, Y');
    }

    /** "10:00 AM" */
    public static function time(\DateTimeImmutable $t): string
    {
        return $t->format('g:i A');
    }

    /** "Tue Nov 4, 2026, 10:00 AM" */
    public static function when(\DateTimeImmutable $t): string
    {
        return self::dayLabel($t) . ', ' . self::time($t);
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(CalendarEvent $e, bool $withPatient, bool $withProvider): array
    {
        $row = [
            'event_id' => $e->id,
            'date' => self::date($e->start),
            'when' => self::dayLabel($e->start),
            'start' => self::time($e->start),
        ];
        if (!$e->kind->isMarker()) {
            $row['end'] = self::time($e->end());
            $row['minutes'] = $e->durationMinutes;
        }
        $row['type'] = $e->kind->label();
        $row['category'] = $e->categoryName;
        if ($e->status !== '' && $e->status !== '-') {
            $row['status'] = $e->statusTitle;
        }
        if ($e->isCancelled()) {
            $row['cancelled'] = true;
        }
        if ($withPatient && $e->patient !== null) {
            $row['patient'] = $e->patient->brief();
        }
        if ($withProvider && $e->providerName !== '') {
            $row['provider'] = ['id' => $e->providerId, 'name' => $e->providerName];
        }
        if ($e->facilityName !== '') {
            $row['facility'] = $e->facilityName;
        }
        return $row;
    }
}
