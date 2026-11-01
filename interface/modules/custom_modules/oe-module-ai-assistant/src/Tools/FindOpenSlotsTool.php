<?php

/**
 * find_open_slots: free start times for a provider.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

use OpenEMR\Modules\AiAssistant\Bedrock\ToolSpec;
use OpenEMR\Modules\AiAssistant\Calendar\CalendarReader;
use OpenEMR\Modules\AiAssistant\Calendar\OpenSlotFinder;
use OpenEMR\Modules\AiAssistant\Directory\ProviderDirectory;
use Psr\Clock\ClockInterface;

final readonly class FindOpenSlotsTool implements Tool
{
    public const NAME = 'find_open_slots';
    private const MAX_SLOTS = 30;

    public function __construct(
        private CalendarReader $calendar,
        private ProviderDirectory $providers,
        private OpenSlotFinder $finder,
        private Permissions $permissions,
        private ClockInterface $clock,
        private \DateTimeZone $timezone,
    ) {
    }

    public static function spec(): ToolSpec
    {
        return new ToolSpec(
            self::NAME,
            'Free appointment start times for a provider between two dates (at most 14 days), inside the provider\'s office hours, '
            . 'skipping booked appointments, blocks, clinic closures and times already past. Returns up to 30 times, earliest first. '
            . 'Only offer times this tool returned.',
            [
                'type' => 'object',
                'properties' => [
                    'provider_id' => ['type' => 'integer', 'description' => 'From list_providers'],
                    'date_from' => ['type' => 'string', 'description' => 'First date, YYYY-MM-DD'],
                    'date_to' => ['type' => 'string', 'description' => 'Last date, YYYY-MM-DD'],
                    'duration_min' => ['type' => 'integer', 'description' => 'Length of the visit in minutes, for example 15, 30 or 60'],
                    'facility_id' => ['type' => 'integer', 'description' => 'Only count office hours at this facility (optional)'],
                ],
                'required' => ['provider_id', 'date_from', 'date_to', 'duration_min'],
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
        [$from, $to] = DateRange::parse($input, GetProviderScheduleTool::MAX_DAYS, $this->timezone);
        $duration = $input->int('duration_min', 5, 480);
        $facilityId = $input->optionalInt('facility_id', 1, PHP_INT_MAX);
        $now = $this->clock->now()->setTimezone($this->timezone);
        if ($to < $now->setTime(0, 0)) {
            return ToolResult::error('Those dates are in the past. Today is ' . Format::dayLabel($now) . '.');
        }

        // A day earlier so an event that runs past midnight still blocks.
        $events = $this->calendar->forProvider($provider->id, $from->modify('-1 day'), $to);
        $result = $this->finder->find($events, $from, $to, $duration, $facilityId, $now, self::MAX_SLOTS);

        $slots = array_map(static fn(\DateTimeImmutable $t): array => [
            'start' => $t->format('Y-m-d\TH:i'),
            'label' => Format::when($t),
        ], $result->slots);
        $data = [
            'provider' => $provider->brief(),
            'duration_min' => $duration,
            'slots' => $slots,
        ];
        if ($result->truncated) {
            $data['note'] = 'More times are free; only the first 30 are listed.';
        } elseif ($slots === []) {
            $data['note'] = 'No free time of that length in these dates. Offer to search the following days.';
        }
        if ($result->closedDays !== []) {
            $data['days_without_office_hours'] = array_map(Format::dayLabel(...), $result->closedDays);
        }
        return ToolResult::ok($data, count($slots) . ' free time(s)');
    }
}
