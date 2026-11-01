<?php

/**
 * CalendarReader over OpenEMR's own fetchEvents() (library/appointments.inc.php),
 * the same function the calendar and reports use, so recurring events,
 * their exceptions and module appointment filters are honoured.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Calendar;

use OpenEMR\Modules\AiAssistant\Database\Database;
use OpenEMR\Modules\AiAssistant\Database\Row;
use OpenEMR\Modules\AiAssistant\Directory\Patient;

final class CoreCalendarReader implements CalendarReader
{
    /** @var array<int, array{constant: string, type: int}>|null */
    private ?array $categories = null;
    /** @var array<string, string>|null */
    private ?array $statuses = null;

    /**
     * @param \Closure(string, string, string, list<int>): list<array<array-key, mixed>> $fetchEvents
     *        (from Y-m-d, to Y-m-d, extra WHERE starting with " AND", binds) => event rows
     */
    public function __construct(
        private readonly Database $db,
        private readonly \Closure $fetchEvents,
        private readonly \DateTimeZone $timezone,
    ) {
    }

    /**
     * Wires the reader to the legacy fetchEvents() function.
     */
    public static function create(Database $db, string $projectDir, \DateTimeZone $timezone): self
    {
        require_once $projectDir . '/library/appointments.inc.php';
        return new self(
            $db,
            static function (string $from, string $to, string $where, array $binds): array {
                $rows = fetchEvents($from, $to, $where, null, false, 0, $binds);
                return array_values(array_filter($rows, is_array(...)));
            },
            $timezone,
        );
    }

    public function forProvider(int $providerId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->read($from, $to, ' AND (e.pc_aid = ? OR c.pc_cattype = 2)', [$providerId]);
    }

    public function forPatient(int $pid, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->read($from, $to, ' AND e.pc_pid = ?', [$pid]);
    }

    /**
     * @param list<int> $binds
     * @return list<CalendarEvent>
     */
    private function read(\DateTimeImmutable $from, \DateTimeImmutable $to, string $where, array $binds): array
    {
        $rows = ($this->fetchEvents)($from->format('Y-m-d'), $to->format('Y-m-d'), $where, $binds);
        $events = [];
        foreach ($rows as $row) {
            $event = $this->map($row);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        usort($events, static fn(CalendarEvent $a, CalendarEvent $b): int => [$a->start, $a->id] <=> [$b->start, $b->id]);
        return $events;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public function map(array $row): ?CalendarEvent
    {
        $date = Row::str($row, 'pc_eventDate');
        $time = Row::str($row, 'pc_startTime');
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . (strlen($time) === 5 ? $time . ':00' : $time), $this->timezone);
        if ($start === false) {
            return null;
        }
        $categoryId = Row::intOrNull($row, 'pc_catid') ?? 0;
        $category = $this->categories()[$categoryId] ?? ['constant' => '', 'type' => 0];
        $pid = Row::intOrNull($row, 'pid') ?? 0;
        $durationSeconds = Row::intOrNull($row, 'pc_duration') ?? 0;
        $status = Row::str($row, 'pc_apptstatus');
        $providerId = Row::intOrNull($row, 'uprovider_id');
        $facilityId = Row::intOrNull($row, 'pc_facility');

        return new CalendarEvent(
            id: Row::int($row, 'pc_eid'),
            start: $start,
            durationMinutes: intdiv(max(0, $durationSeconds), 60),
            kind: EventKind::classify($category['constant'], $category['type'], $pid),
            categoryName: Row::str($row, 'pc_catname'),
            status: $status,
            statusTitle: $this->statuses()[$status] ?? $status,
            patient: $pid > 0
                ? new Patient($pid, Row::str($row, 'fname'), Row::str($row, 'lname'), Row::date($row, 'DOB'), '', null)
                : null,
            providerId: $providerId !== null && $providerId > 0 ? $providerId : null,
            providerName: trim(Row::str($row, 'ufname') . ' ' . Row::str($row, 'ulname')),
            facilityId: $facilityId !== null && $facilityId > 0 ? $facilityId : null,
            facilityName: Row::str($row, 'name'),
        );
    }

    /**
     * @return array<int, array{constant: string, type: int}>
     */
    private function categories(): array
    {
        if ($this->categories === null) {
            $this->categories = [];
            foreach ($this->db->fetchAll('SELECT `pc_catid`, `pc_constant_id`, `pc_cattype` FROM `openemr_postcalendar_categories`') as $row) {
                $this->categories[Row::int($row, 'pc_catid')] = [
                    'constant' => Row::str($row, 'pc_constant_id'),
                    'type' => Row::intOrNull($row, 'pc_cattype') ?? 0,
                ];
            }
        }
        return $this->categories;
    }

    /**
     * @return array<string, string> status code => title, e.g. "x" => "Canceled"
     */
    private function statuses(): array
    {
        if ($this->statuses === null) {
            $this->statuses = [];
            foreach ($this->db->fetchAll("SELECT `option_id`, `title` FROM `list_options` WHERE `list_id` = 'apptstat'") as $row) {
                $code = Row::str($row, 'option_id');
                $title = trim(Row::str($row, 'title'));
                // Titles repeat the code ("x Canceled"); keep the words.
                $this->statuses[$code] = str_starts_with($title, $code . ' ') ? substr($title, strlen($code) + 1) : $title;
            }
        }
        return $this->statuses;
    }
}
