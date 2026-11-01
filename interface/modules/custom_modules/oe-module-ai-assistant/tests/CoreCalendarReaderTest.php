<?php

/**
 * Mapping of core fetchEvents() rows (column names as selected in
 * library/appointments.inc.php) into calendar events.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tests;

use OpenEMR\Modules\AiAssistant\Calendar\CoreCalendarReader;
use OpenEMR\Modules\AiAssistant\Calendar\EventKind;
use OpenEMR\Modules\AiAssistant\Tests\Support\FakeDatabase;
use PHPUnit\Framework\TestCase;

final class CoreCalendarReaderTest extends TestCase
{
    public function testRowsBecomeSortedTypedEvents(): void
    {
        $db = (new FakeDatabase())
            ->on('openemr_postcalendar_categories', [
                ['pc_catid' => 5, 'pc_constant_id' => 'office_visit', 'pc_cattype' => 0],
                ['pc_catid' => 8, 'pc_constant_id' => 'lunch', 'pc_cattype' => 1],
                ['pc_catid' => 2, 'pc_constant_id' => 'in_office', 'pc_cattype' => 1],
            ])
            ->on("'apptstat'", [['option_id' => 'x', 'title' => 'x Canceled'], ['option_id' => '@', 'title' => '@ Arrived']]);
        $calls = [];
        $rows = [
            self::row(['pc_eid' => '41', 'pc_catid' => '5', 'pc_startTime' => '10:00:00', 'pc_duration' => '1800', 'pc_apptstatus' => 'x', 'pid' => '101', 'fname' => 'Patricia', 'lname' => 'Test', 'DOB' => '1968-03-14', 'pc_catname' => 'Office Visit']),
            self::row(['pc_eid' => '40', 'pc_catid' => '8', 'pc_startTime' => '12:00', 'pc_duration' => '3600', 'pid' => null, 'pc_catname' => 'Lunch']),
            self::row(['pc_eid' => '39', 'pc_catid' => '2', 'pc_startTime' => '08:00:00', 'pc_duration' => '0', 'pid' => '0', 'pc_catname' => 'In Office']),
        ];
        $reader = new CoreCalendarReader(
            $db,
            static function (string $from, string $to, string $where, array $binds) use (&$calls, $rows): array {
                $calls[] = [$from, $to, $where, $binds];
                return $rows;
            },
            new \DateTimeZone('America/New_York'),
        );
        $zone = new \DateTimeZone('America/New_York');
        $events = $reader->forProvider(7, new \DateTimeImmutable('2026-11-04', $zone), new \DateTimeImmutable('2026-11-04', $zone));

        self::assertSame([['2026-11-04', '2026-11-04', ' AND (e.pc_aid = ? OR c.pc_cattype = 2)', [7]]], $calls);
        self::assertSame([39, 41, 40], array_map(static fn($e): int => $e->id, $events));
        [$in, $visit, $lunch] = $events;
        self::assertSame(EventKind::OfficeIn, $in->kind);
        self::assertNull($in->patient);
        self::assertSame(EventKind::ProviderBlock, $lunch->kind);
        self::assertSame('2026-11-04 12:00', $lunch->start->format('Y-m-d H:i'));
        self::assertSame(60, $lunch->durationMinutes);
        self::assertSame(EventKind::Appointment, $visit->kind);
        self::assertSame('Canceled', $visit->statusTitle);
        self::assertTrue($visit->isCancelled());
        self::assertSame(101, $visit->patient?->pid);
        self::assertSame(7, $visit->providerId);
        self::assertSame('Alvin Antony', $visit->providerName);
        self::assertSame('Raleigh', $visit->facilityName);
    }

    /**
     * @param array<string, string|null> $overrides
     * @return array<string, string|null>
     */
    private static function row(array $overrides): array
    {
        return $overrides + [
            'pc_eventDate' => '2026-11-04', 'pc_apptstatus' => '-', 'fname' => null, 'lname' => null, 'DOB' => null,
            'ufname' => 'Alvin', 'ulname' => 'Antony', 'uprovider_id' => '7', 'name' => 'Raleigh', 'pc_facility' => '3',
        ];
    }
}
