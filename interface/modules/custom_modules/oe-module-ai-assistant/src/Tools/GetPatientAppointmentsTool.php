<?php

/**
 * get_patient_appointments: a patient's upcoming (and optionally past year's) appointments.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

use OpenEMR\Modules\AiAssistant\Bedrock\ToolSpec;
use OpenEMR\Modules\AiAssistant\Calendar\CalendarEvent;
use OpenEMR\Modules\AiAssistant\Calendar\CalendarReader;
use OpenEMR\Modules\AiAssistant\Directory\PatientDirectory;
use Psr\Clock\ClockInterface;

final readonly class GetPatientAppointmentsTool implements Tool
{
    public const NAME = 'get_patient_appointments';
    private const MAX = 50;

    public function __construct(
        private CalendarReader $calendar,
        private PatientDirectory $patients,
        private Permissions $permissions,
        private ClockInterface $clock,
        private \DateTimeZone $timezone,
    ) {
    }

    public static function spec(): ToolSpec
    {
        return new ToolSpec(
            self::NAME,
            'A patient\'s appointments for the next 12 months, soonest first, with provider, visit type, status and facility. '
            . 'Set include_past to also get the last 12 months. Get pid from find_patient first.',
            [
                'type' => 'object',
                'properties' => [
                    'pid' => ['type' => 'integer', 'description' => 'Patient id from find_patient'],
                    'include_past' => ['type' => 'boolean', 'description' => 'Also return the past 12 months (default false)'],
                ],
                'required' => ['pid'],
            ],
        );
    }

    public function run(ToolInput $input): ToolResult
    {
        if (!$this->permissions->canReadCalendar() || !$this->permissions->canReadPatients()) {
            return ToolResult::error('Not permitted: this user cannot view patients\' appointments.');
        }
        $patient = $this->patients->find($input->int('pid', 1, PHP_INT_MAX));
        if ($patient === null) {
            return ToolResult::error('No patient has that pid. Use find_patient.');
        }
        $includePast = $input->bool('include_past', false);
        $now = $this->clock->now()->setTimezone($this->timezone);
        $today = $now->setTime(0, 0);

        $events = $this->calendar->forPatient(
            $patient->pid,
            $includePast ? $today->modify('-12 months') : $today,
            $today->modify('+12 months'),
        );
        $upcoming = [];
        $past = [];
        foreach ($events as $event) {
            if ($event->end() > $now) {
                $upcoming[] = $event;
            } else {
                $past[] = $event;
            }
        }
        $past = array_reverse($past);

        $row = static fn(CalendarEvent $e): array => Format::event($e, false, true);
        $data = [
            'patient' => $patient->brief(),
            'today' => Format::dayLabel($now),
            'upcoming' => array_map($row, array_slice($upcoming, 0, self::MAX)),
        ];
        if ($includePast) {
            $data['past_most_recent_first'] = array_map($row, array_slice($past, 0, self::MAX));
        }
        if ($upcoming === []) {
            $data['note'] = 'No upcoming appointments in the next 12 months.';
        }
        return ToolResult::ok($data, count($upcoming) . ' upcoming' . ($includePast ? ', ' . count($past) . ' past' : ''), [$patient->pid]);
    }
}
