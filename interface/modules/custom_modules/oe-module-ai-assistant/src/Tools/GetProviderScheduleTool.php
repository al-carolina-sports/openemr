<?php

/**
 * get_provider_schedule: a provider's appointments, blocks and office hours.
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
use OpenEMR\Modules\AiAssistant\Directory\ProviderDirectory;

final readonly class GetProviderScheduleTool implements Tool
{
    public const NAME = 'get_provider_schedule';
    public const MAX_DAYS = 14;
    private const MAX_EVENTS = 200;

    public function __construct(
        private CalendarReader $calendar,
        private ProviderDirectory $providers,
        private Permissions $permissions,
    ) {
    }

    public static function spec(): ToolSpec
    {
        return new ToolSpec(
            self::NAME,
            'A provider\'s calendar between two dates (at most 14 days): patient appointments with patient name and DOB, '
            . 'provider blocks (lunch, vacation, out of office), clinic closures, and office hours markers. '
            . 'Cancelled appointments are flagged. Get provider_id from list_providers first.',
            [
                'type' => 'object',
                'properties' => [
                    'provider_id' => ['type' => 'integer', 'description' => 'From list_providers'],
                    'date_from' => ['type' => 'string', 'description' => 'First date, YYYY-MM-DD'],
                    'date_to' => ['type' => 'string', 'description' => 'Last date, YYYY-MM-DD (same as date_from for one day)'],
                ],
                'required' => ['provider_id', 'date_from', 'date_to'],
            ],
        );
    }

    public function run(ToolInput $input): ToolResult
    {
        if (!$this->permissions->canReadCalendar()) {
            return ToolResult::error('Not permitted: this user cannot view the appointment calendar.');
        }
        $provider = $this->providers->find($input->int('provider_id', 1, PHP_INT_MAX));
        if ($provider === null) {
            return ToolResult::error('No active provider has that provider_id. Use list_providers.');
        }
        [$from, $to] = DateRange::parse($input, self::MAX_DAYS);

        $events = $this->calendar->forProvider($provider->id, $from, $to);
        $more = count($events) > self::MAX_EVENTS;
        $events = array_slice($events, 0, self::MAX_EVENTS);
        $patientIds = [];
        foreach ($events as $event) {
            if ($event->patient !== null) {
                $patientIds[$event->patient->pid] = $event->patient->pid;
            }
        }

        $data = [
            'provider' => $provider->brief(),
            'from' => Format::dayLabel($from),
            'to' => Format::dayLabel($to),
            'events' => array_map(static fn(CalendarEvent $e): array => Format::event($e, true, false), $events),
        ];
        if ($events === []) {
            $data['note'] = 'Nothing is on the calendar for these dates.';
        } elseif ($more) {
            $data['note'] = 'Only the first 200 events are shown; ask for fewer days.';
        }
        return ToolResult::ok($data, count($events) . ' event(s)', array_values($patientIds));
    }
}
