<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tests;

use OpenEMR\Modules\AiAssistant\Calendar\CalendarEvent;
use OpenEMR\Modules\AiAssistant\Calendar\EventKind;
use OpenEMR\Modules\AiAssistant\Calendar\OfficeHours;
use OpenEMR\Modules\AiAssistant\Calendar\OpenSlotFinder;
use OpenEMR\Modules\AiAssistant\Calendar\SlotSearch;
use PHPUnit\Framework\TestCase;

final class OpenSlotFinderTest extends TestCase
{
    private const TZ = 'America/New_York';

    public function testDefaultHoursOnAWorkingDayWithNoMarkers(): void
    {
        // Tue Nov 3, 2026, 8:00-12:00 calendar, 30-minute steps
        $found = $this->find([], '2026-11-03', '2026-11-03', 30, hours: new OfficeHours(8 * 60, 12 * 60, 30, true, [1, 2, 3, 4, 5]));
        self::assertSame(['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30'], self::times($found));
    }

    public function testBookedAndBlockedTimeIsSkippedButCancelledIsFree(): void
    {
        $events = [
            self::event(1, '2026-11-03 09:00', 30, EventKind::Appointment),
            self::event(2, '2026-11-03 10:00', 60, EventKind::ProviderBlock),
            self::event(3, '2026-11-03 11:00', 30, EventKind::Appointment, status: 'x'),
        ];
        $found = $this->find($events, '2026-11-03', '2026-11-03', 30, hours: new OfficeHours(9 * 60, 12 * 60, 30, true, [2]));
        self::assertSame(['09:30', '11:00', '11:30'], self::times($found));
    }

    public function testLongerVisitNeedsTheWholeGap(): void
    {
        $events = [self::event(1, '2026-11-03 10:00', 30, EventKind::Appointment)];
        $found = $this->find($events, '2026-11-03', '2026-11-03', 60, hours: new OfficeHours(9 * 60, 12 * 60, 15, true, [2]));
        self::assertSame(['09:00', '10:30', '10:45', '11:00'], self::times($found));
    }

    public function testInAndOutOfficeMarkersDefineTheDay(): void
    {
        $events = [
            self::event(10, '2026-11-03 13:00', 0, EventKind::OfficeIn),
            self::event(11, '2026-11-03 15:00', 0, EventKind::OfficeOut),
        ];
        $found = $this->find($events, '2026-11-03', '2026-11-03', 60, hours: new OfficeHours(8 * 60, 17 * 60, 60, true, [2]));
        self::assertSame(['13:00', '14:00'], self::times($found));
    }

    public function testMarkersAtAnotherFacilityAreIgnoredWhenAFacilityIsAsked(): void
    {
        $events = [
            self::event(10, '2026-11-03 13:00', 0, EventKind::OfficeIn, facility: 2),
            self::event(11, '2026-11-03 15:00', 0, EventKind::OfficeOut, facility: 2),
        ];
        $found = $this->find($events, '2026-11-03', '2026-11-03', 60, facility: 1, hours: new OfficeHours(8 * 60, 10 * 60, 60, true, [2]));
        self::assertSame(['08:00', '09:00'], self::times($found));
    }

    public function testWeekendAndClosedDaysHaveNoTimes(): void
    {
        $events = [self::event(20, '2026-11-11 00:00', 1440, EventKind::ClinicClosure)];
        $found = $this->find($events, '2026-11-07', '2026-11-11', 60, hours: new OfficeHours(9 * 60, 10 * 60, 60, true, [1, 2, 3, 4, 5]));
        // Sat 7 and Sun 8 are not working days; Wed 11 is closed all day.
        self::assertSame(['2026-11-09 09:00', '2026-11-10 09:00'], self::stamps($found));
        self::assertSame(['2026-11-07', '2026-11-08'], array_map(static fn(\DateTimeImmutable $d): string => $d->format('Y-m-d'), $found->closedDays));
    }

    public function testNoAssumedHoursWhenTurnedOff(): void
    {
        $found = $this->find([], '2026-11-03', '2026-11-03', 30, hours: new OfficeHours(8 * 60, 17 * 60, 30, false, [2]));
        self::assertSame([], $found->slots);
    }

    public function testPastTimesAreNotOffered(): void
    {
        $found = $this->find([], '2026-11-02', '2026-11-02', 60, now: '2026-11-02 10:15', hours: new OfficeHours(8 * 60, 13 * 60, 60, true, [1]));
        self::assertSame(['11:00', '12:00'], self::times($found));
    }

    public function testResultsAreLimited(): void
    {
        $found = $this->find([], '2026-11-03', '2026-11-05', 15, limit: 5, hours: new OfficeHours(8 * 60, 17 * 60, 15, true, [2, 3, 4]));
        self::assertCount(5, $found->slots);
        self::assertTrue($found->truncated);
    }

    public function testStartsAlignToTheCalendarGrid(): void
    {
        $events = [
            self::event(10, '2026-11-03 08:10', 0, EventKind::OfficeIn),
            self::event(11, '2026-11-03 09:00', 0, EventKind::OfficeOut),
        ];
        $found = $this->find($events, '2026-11-03', '2026-11-03', 15, hours: new OfficeHours(8 * 60, 17 * 60, 15, true, [2]));
        self::assertSame(['08:15', '08:30', '08:45'], self::times($found));
    }

    /**
     * @param list<CalendarEvent> $events
     */
    private function find(
        array $events,
        string $from,
        string $to,
        int $duration,
        OfficeHours $hours,
        ?int $facility = null,
        string $now = '2026-11-01 12:00',
        int $limit = 50,
    ): SlotSearch {
        $zone = new \DateTimeZone(self::TZ);
        return (new OpenSlotFinder($hours))->find(
            $events,
            new \DateTimeImmutable($from, $zone),
            new \DateTimeImmutable($to, $zone),
            $duration,
            $facility,
            new \DateTimeImmutable($now, $zone),
            $limit,
        );
    }

    private static function event(int $id, string $start, int $minutes, EventKind $kind, string $status = '-', ?int $facility = 1): CalendarEvent
    {
        return new CalendarEvent($id, new \DateTimeImmutable($start, new \DateTimeZone(self::TZ)), $minutes, $kind, 'Category', $status, '', null, 7, 'Alvin Antony', $facility, 'Raleigh');
    }

    /**
     * @return list<string>
     */
    private static function times(SlotSearch $found): array
    {
        return array_map(static fn(\DateTimeImmutable $t): string => $t->format('H:i'), $found->slots);
    }

    /**
     * @return list<string>
     */
    private static function stamps(SlotSearch $found): array
    {
        return array_map(static fn(\DateTimeImmutable $t): string => $t->format('Y-m-d H:i'), $found->slots);
    }
}
