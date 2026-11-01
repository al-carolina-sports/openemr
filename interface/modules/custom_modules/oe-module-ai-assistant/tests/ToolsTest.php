<?php

/**
 * The five read tools through the registry, as the model calls them.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tests;

use OpenEMR\Modules\AiAssistant\Bedrock\ToolUse;
use OpenEMR\Modules\AiAssistant\Calendar\CalendarEvent;
use OpenEMR\Modules\AiAssistant\Calendar\EventKind;
use OpenEMR\Modules\AiAssistant\Calendar\OfficeHours;
use OpenEMR\Modules\AiAssistant\Calendar\OpenSlotFinder;
use OpenEMR\Modules\AiAssistant\Directory\Patient;
use OpenEMR\Modules\AiAssistant\Tests\Support\Fakes;
use OpenEMR\Modules\AiAssistant\Tests\Support\FixedClock;
use OpenEMR\Modules\AiAssistant\Tests\Support\RecordingCalendar;
use OpenEMR\Modules\AiAssistant\Tools\FindOpenSlotsTool;
use OpenEMR\Modules\AiAssistant\Tools\FindPatientTool;
use OpenEMR\Modules\AiAssistant\Tools\GetPatientAppointmentsTool;
use OpenEMR\Modules\AiAssistant\Tools\GetProviderScheduleTool;
use OpenEMR\Modules\AiAssistant\Tools\ListProvidersTool;
use OpenEMR\Modules\AiAssistant\Tools\PermissionSnapshot;
use OpenEMR\Modules\AiAssistant\Tools\ToolRegistry;
use OpenEMR\Modules\AiAssistant\Tools\ToolResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ToolsTest extends TestCase
{
    private const TZ = 'America/New_York';

    private Patient $patricia;
    private Patient $patrick;
    private RecordingCalendar $calendar;

    protected function setUp(): void
    {
        $this->patricia = Fakes::patient(101, 'Patricia', 'Test', '1968-03-14');
        $this->patrick = Fakes::patient(102, 'Patrick', 'Test', '1975-07-02');
        $zone = new \DateTimeZone(self::TZ);
        $this->calendar = Fakes::calendar(
            new CalendarEvent(501, new \DateTimeImmutable('2026-10-20 09:00', $zone), 30, EventKind::Appointment, 'Follow-Up', '>', 'Checked out', $this->patricia, 7, 'Alvin Antony', 3, 'Raleigh'),
            new CalendarEvent(502, new \DateTimeImmutable('2026-11-04 10:00', $zone), 30, EventKind::Appointment, 'Follow-Up', '-', '', $this->patricia, 7, 'Alvin Antony', 3, 'Raleigh'),
            new CalendarEvent(503, new \DateTimeImmutable('2026-11-04 12:00', $zone), 60, EventKind::ProviderBlock, 'Lunch', '-', '', null, 7, 'Alvin Antony', 3, 'Raleigh'),
        );
    }

    public function testFindPatientAsksWhichOneWhenSeveralMatch(): void
    {
        $result = $this->registry()->run(new ToolUse('t1', 'find_patient', ['last_name' => 'Test']));
        self::assertTrue($result->ok);
        self::assertCount(2, self::list($result, 'matches'));
        self::assertStringContainsString('Ask the user which one', self::str($result, 'note'));
        self::assertSame([101, 102], $result->patientIds);
    }

    public function testFindPatientNarrowsByFirstNameAndDob(): void
    {
        $result = $this->registry()->run(new ToolUse('t1', 'find_patient', ['last_name' => 'test', 'first_name' => 'Pat', 'dob' => '1968-03-14']));
        $matches = self::list($result, 'matches');
        self::assertCount(1, $matches);
        self::assertSame(['pid' => 101, 'name' => 'Patricia Test', 'dob' => '1968-03-14', 'sex' => 'Female', 'last_visit' => null], $matches[0]);
    }

    public function testNoMatchSaysNotToGuess(): void
    {
        $result = $this->registry()->run(new ToolUse('t1', 'find_patient', ['last_name' => 'Nobody']));
        self::assertSame([], self::list($result, 'matches'));
        self::assertStringContainsString('Do not guess', self::str($result, 'note'));
    }

    public function testBadInputGoesBackToTheModelAsAnError(): void
    {
        $registry = $this->registry();
        self::assertFalse($registry->run(new ToolUse('t1', 'find_patient', [])) ->ok);
        self::assertFalse($registry->run(new ToolUse('t2', 'find_patient', ['last_name' => 'Test', 'dob' => '03/14/1968']))->ok);
        $unknown = $registry->run(new ToolUse('t3', 'create_appointment', ['pid' => 101]));
        self::assertFalse($unknown->ok);
        self::assertStringContainsString('Unknown tool', $unknown->toText());
    }

    public function testToolsRefuseWithoutTheUsersRights(): void
    {
        $registry = $this->registry(new PermissionSnapshot(false, false));
        foreach (['find_patient' => ['last_name' => 'Test'], 'list_providers' => [], 'get_patient_appointments' => ['pid' => 101]] as $name => $args) {
            $result = $registry->run(new ToolUse('t', $name, $args));
            self::assertFalse($result->ok, $name);
            self::assertStringContainsString('Not permitted', $result->toText(), $name);
        }
        self::assertSame([], $this->calendar->calls);
    }

    public function testListProvidersIgnoresTheDoctorTitle(): void
    {
        $result = $this->registry()->run(new ToolUse('t1', 'list_providers', ['name_contains' => 'Dr. Antony']));
        $providers = self::list($result, 'providers');
        self::assertSame(7, $providers[0]['provider_id'] ?? null);
        self::assertSame('Dr. Alvin Antony', $providers[0]['name'] ?? null);
    }

    public function testScheduleShowsPatientsAndBlocksAndLimitsTheRange(): void
    {
        $registry = $this->registry();
        $result = $registry->run(new ToolUse('t1', 'get_provider_schedule', ['provider_id' => '7', 'date_from' => '2026-11-04', 'date_to' => '2026-11-04']));
        self::assertTrue($result->ok);
        $events = self::list($result, 'events');
        self::assertSame(['appointment', 'appointment', 'provider block'], array_column($events, 'type'));
        self::assertSame('Wed Nov 4, 2026', $events[1]['when'] ?? null);
        self::assertSame('10:00 AM', $events[1]['start'] ?? null);
        self::assertSame(['pid' => 101, 'name' => 'Patricia Test', 'dob' => '1968-03-14'], $events[1]['patient'] ?? null);

        $tooLong = $registry->run(new ToolUse('t2', 'get_provider_schedule', ['provider_id' => 7, 'date_from' => '2026-11-01', 'date_to' => '2026-11-30']));
        self::assertFalse($tooLong->ok);
        self::assertStringContainsString('14 days', $tooLong->toText());
        $unknown = $registry->run(new ToolUse('t3', 'get_provider_schedule', ['provider_id' => 99, 'date_from' => '2026-11-04', 'date_to' => '2026-11-04']));
        self::assertStringContainsString('No active provider', $unknown->toText());
    }

    public function testPatientAppointmentsSplitUpcomingFromPast(): void
    {
        $result = $this->registry()->run(new ToolUse('t1', 'get_patient_appointments', ['pid' => 101, 'include_past' => true]));
        $upcoming = self::list($result, 'upcoming');
        $past = self::list($result, 'past_most_recent_first');
        self::assertSame([502], array_column($upcoming, 'event_id'));
        self::assertSame([501], array_column($past, 'event_id'));
        self::assertSame(['id' => 7, 'name' => 'Alvin Antony'], $upcoming[0]['provider'] ?? null);
        self::assertSame([101], $result->patientIds);
        self::assertSame(['patient', 101, '2025-11-01', '2027-11-01'], $this->calendar->calls[0]);
    }

    public function testOpenSlotsSkipBookedTimeAndReadADayEarlier(): void
    {
        $result = $this->registry()->run(new ToolUse('t1', 'find_open_slots', [
            'provider_id' => 7, 'date_from' => '2026-11-04', 'date_to' => '2026-11-04', 'duration_min' => 60,
        ]));
        self::assertTrue($result->ok);
        $slots = self::list($result, 'slots');
        // Calendar 9-14, hourly: 10:00 is half taken, 12:00 is lunch.
        self::assertSame(['2026-11-04T09:00', '2026-11-04T11:00', '2026-11-04T13:00'], array_column($slots, 'start'));
        self::assertSame('Wed Nov 4, 2026, 9:00 AM', $slots[0]['label'] ?? null);
        self::assertSame(['provider', 7, '2026-11-03', '2026-11-04'], $this->calendar->calls[0]);
    }

    public function testOpenSlotsRejectPastDates(): void
    {
        $result = $this->registry()->run(new ToolUse('t1', 'find_open_slots', [
            'provider_id' => 7, 'date_from' => '2026-10-01', 'date_to' => '2026-10-02', 'duration_min' => 30,
        ]));
        self::assertFalse($result->ok);
        self::assertStringContainsString('in the past', $result->toText());
    }

    private function registry(?PermissionSnapshot $permissions = null): ToolRegistry
    {
        $permissions ??= new PermissionSnapshot(true, true);
        $zone = new \DateTimeZone(self::TZ);
        $clock = FixedClock::at('2026-11-01 12:00', self::TZ);
        $patients = Fakes::patients($this->patricia, $this->patrick);
        $providers = Fakes::providers(Fakes::provider());
        return new ToolRegistry([
            new FindPatientTool($patients, $permissions),
            new ListProvidersTool($providers, $permissions),
            new GetProviderScheduleTool($this->calendar, $providers, $permissions),
            new FindOpenSlotsTool($this->calendar, $providers, new OpenSlotFinder(new OfficeHours(9 * 60, 14 * 60, 60, true, [1, 2, 3, 4, 5])), $permissions, $clock, $zone),
            new GetPatientAppointmentsTool($this->calendar, $patients, $permissions, $clock, $zone),
        ], new NullLogger());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(ToolResult $result, string $key): array
    {
        $value = $result->data[$key] ?? null;
        self::assertIsArray($value);
        $out = [];
        foreach ($value as $row) {
            self::assertIsArray($row);
            $clean = [];
            foreach ($row as $k => $v) {
                self::assertIsString($k);
                $clean[$k] = $v;
            }
            $out[] = $clean;
        }
        return $out;
    }

    private static function str(ToolResult $result, string $key): string
    {
        $value = $result->data[$key] ?? null;
        self::assertIsString($value);
        return $value;
    }
}
