<?php

/**
 * CalendarReader over a fixed list of events that records each call.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tests\Support;

use OpenEMR\Modules\AiAssistant\Calendar\CalendarEvent;
use OpenEMR\Modules\AiAssistant\Calendar\CalendarReader;

final class RecordingCalendar implements CalendarReader
{
    /** @var list<array{string, int, string, string}> */
    public array $calls = [];

    /**
     * @param list<CalendarEvent> $events
     */
    public function __construct(private readonly array $events)
    {
    }

    public function forProvider(int $providerId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $this->calls[] = ['provider', $providerId, $from->format('Y-m-d'), $to->format('Y-m-d')];
        return array_values(array_filter($this->events, static fn(CalendarEvent $e): bool => $e->providerId === $providerId || $e->providerId === null));
    }

    public function forPatient(int $pid, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $this->calls[] = ['patient', $pid, $from->format('Y-m-d'), $to->format('Y-m-d')];
        return array_values(array_filter($this->events, static fn(CalendarEvent $e): bool => $e->patient?->pid === $pid));
    }
}
