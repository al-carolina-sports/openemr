<?php

/**
 * Finds free start times for a provider. Pure: it only looks at the events
 * it is given.
 *
 * A day's working time comes from its "In Office" / "Out Of Office" markers
 * (In opens, Out closes, as on OpenEMR's calendar). With no markers, the
 * calendar's start and end hours are used on working days, if allowed.
 * Every non-cancelled appointment, provider block, clinic closure and group
 * session takes its time out, whatever facility it is at.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Calendar;

final readonly class OpenSlotFinder
{
    public function __construct(private OfficeHours $hours)
    {
    }

    /**
     * @param list<CalendarEvent> $events the provider's events overlapping the dates
     * @param \DateTimeImmutable $from first date (time ignored), in the clinic's time zone
     * @param \DateTimeImmutable $to last date, inclusive
     */
    public function find(
        array $events,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $durationMinutes,
        ?int $facilityId,
        \DateTimeImmutable $now,
        int $limit,
    ): SlotSearch {
        $slots = [];
        $closedDays = [];
        $truncated = false;
        $busy = [];
        foreach ($events as $event) {
            if ($event->blocksTime()) {
                $busy[] = [$event->start, $event->end()];
            }
        }

        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            $windows = $this->windows($day, $events, $facilityId);
            if ($windows === []) {
                $closedDays[] = $day;
                continue;
            }
            foreach ($windows as [$open, $close]) {
                for ($t = $this->alignUp($day, $open); $t->modify('+' . $durationMinutes . ' minutes') <= $close; $t = $t->modify('+' . $this->hours->stepMinutes . ' minutes')) {
                    $end = $t->modify('+' . $durationMinutes . ' minutes');
                    if ($t <= $now || self::overlaps($t, $end, $busy)) {
                        continue;
                    }
                    if (count($slots) >= $limit) {
                        $truncated = true;
                        break 3;
                    }
                    $slots[] = $t;
                }
            }
        }
        return new SlotSearch($slots, $truncated, $closedDays);
    }

    /**
     * The day's working windows as [open, close] pairs.
     *
     * @param list<CalendarEvent> $events
     * @return list<array{\DateTimeImmutable, \DateTimeImmutable}>
     */
    private function windows(\DateTimeImmutable $day, array $events, ?int $facilityId): array
    {
        $dayKey = $day->format('Y-m-d');
        $markers = [];
        foreach ($events as $event) {
            if (!$event->kind->isMarker() || $event->start->format('Y-m-d') !== $dayKey) {
                continue;
            }
            if ($facilityId !== null && $event->facilityId !== null && $event->facilityId !== $facilityId) {
                continue;
            }
            $markers[] = $event;
        }
        $defaultClose = $day->modify('+' . $this->hours->endMinute . ' minutes');

        if ($markers === []) {
            $weekday = (int) $day->format('N');
            if (!$this->hours->assumeWhenUnmarked || !in_array($weekday, $this->hours->workingDays, true)) {
                return [];
            }
            return [[$day->modify('+' . $this->hours->startMinute . ' minutes'), $defaultClose]];
        }

        usort($markers, static fn(CalendarEvent $a, CalendarEvent $b): int => $a->start <=> $b->start);
        $windows = [];
        $openedAt = null;
        foreach ($markers as $marker) {
            if ($marker->kind === EventKind::OfficeIn && $openedAt === null) {
                $openedAt = $marker->start;
            } elseif ($marker->kind === EventKind::OfficeOut && $openedAt !== null) {
                if ($marker->start > $openedAt) {
                    $windows[] = [$openedAt, $marker->start];
                }
                $openedAt = null;
            }
        }
        if ($openedAt !== null) {
            $dayEnd = $day->modify('+1 day');
            $windows[] = [$openedAt, $defaultClose > $openedAt ? $defaultClose : $dayEnd];
        }
        return $windows;
    }

    /**
     * First step on the day's grid (multiples of the step from midnight) at or after $t.
     */
    private function alignUp(\DateTimeImmutable $day, \DateTimeImmutable $t): \DateTimeImmutable
    {
        $minutes = intdiv($t->getTimestamp() - $day->getTimestamp(), 60);
        $step = $this->hours->stepMinutes;
        $aligned = intdiv($minutes + $step - 1, $step) * $step;
        return $day->modify('+' . $aligned . ' minutes');
    }

    /**
     * @param list<array{\DateTimeImmutable, \DateTimeImmutable}> $busy
     */
    private static function overlaps(\DateTimeImmutable $start, \DateTimeImmutable $end, array $busy): bool
    {
        foreach ($busy as [$busyStart, $busyEnd]) {
            if ($start < $busyEnd && $end > $busyStart) {
                return true;
            }
        }
        return false;
    }
}
