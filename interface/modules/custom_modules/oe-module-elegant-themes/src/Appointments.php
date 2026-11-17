<?php

/**
 * Footer quick-booking context (+ APPT / + OFFICE): who is the default provider
 * and what is the next free slot today. Read-only; the native dialog saves.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantThemes;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\OEGlobalsBag;

final class Appointments
{
    public const CAT_RESERVED = 11;
    public const CAT_IN_OFFICE = 2;

    private \DateTimeImmutable $now;

    private function isProvider(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $r = QueryUtils::querySingleRow(
            "SELECT id FROM users WHERE id = ? AND active = 1 AND authorized = 1 AND username <> ''",
            [$id]
        );
        return !empty($r);
    }

    private function idForUsername(string $u): int
    {
        if ($u === '' || $u === '__PC_ALL__') {
            return 0;
        }
        $r = QueryUtils::querySingleRow('SELECT id FROM users WHERE username = ? AND active = 1', [$u]);
        return (int)($r['id'] ?? 0);
    }

    private function catExists(int $id): bool
    {
        return !empty(QueryUtils::querySingleRow('SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_catid = ?', [$id]));
    }

    /**
     * @param string[] $calUsers usernames currently selected in the calendar filter
     * @param string $clientNow browser-local time as YmdHi (the user's wall clock); server time if invalid
     * @return array<string,mixed>
     */
    public function context(int $userId, array $calUsers, string $clientNow = ''): array
    {
        $dt = preg_match('/^\\d{12}$/', $clientNow) ? \DateTimeImmutable::createFromFormat('!YmdHi', $clientNow) : false;
        $this->now = ($dt && $dt->format('YmdHi') === $clientNow) ? $dt : new \DateTimeImmutable();
        $calUsers = array_values(array_filter($calUsers, static fn($u) => $u !== '' && $u !== '__PC_ALL__'));
        $calProv = count($calUsers) === 1 ? $this->idForUsername((string)$calUsers[0]) : 0;
        $meProv = $this->isProvider($userId);
        $apptProv = $meProv ? $userId : $calProv;
        $officeProv = $calProv ?: $userId;
        $g = OEGlobalsBag::getInstance();
        $interval = max(5, (int)($g->get('calendar_interval') ?: 15));
        $officeCat = $this->catExists(self::CAT_RESERVED) ? self::CAT_RESERVED : self::CAT_IN_OFFICE;
        return [
            'date' => $this->now->format('Ymd'),
            'interval' => $interval,
            'appt' => ['userid' => $apptProv, 'slot' => $this->slot($apptProv, $interval)],
            'office' => ['userid' => $officeProv, 'catid' => $officeCat, 'slot' => $this->rounded($interval)],
        ];
    }

    /** @return array{h:int,m:int} */
    private function rounded(int $interval): array
    {
        $now = (int)$this->now->format('G') * 60 + (int)$this->now->format('i');
        $t = (int)(ceil($now / $interval) * $interval);
        $t = min($t, 23 * 60 + 60 - $interval);
        return ['h' => intdiv($t, 60), 'm' => $t % 60];
    }

    /** @return array{h:int,m:int,free:bool} */
    private function slot(int $prov, int $interval): array
    {
        $r = $this->rounded($interval);
        $fallback = ['h' => $r['h'], 'm' => $r['m'], 'free' => false];
        if ($prov <= 0) {
            return $fallback;
        }
        $g = OEGlobalsBag::getInstance();
        $endMin = (int)($g->get('schedule_end') ?: 17) * 60;
        $startMin = (int)($g->get('schedule_start') ?: 8) * 60;
        $busy = [];
        $rows = QueryUtils::fetchRecords(
            "SELECT pc_startTime, pc_endTime, pc_duration FROM openemr_postcalendar_events
              WHERE pc_aid = ? AND pc_eventDate = ? AND pc_recurrtype = 0 AND pc_catid NOT IN (2, 3)",
            [(string)$prov, $this->now->format('Y-m-d')]
        );
        foreach ($rows as $e) {
            [$h, $m] = array_map('intval', explode(':', (string)$e['pc_startTime']) + [0, 0]);
            $s = $h * 60 + $m;
            $d = (int)ceil(((int)$e['pc_duration']) / 60);
            if ($d <= 0 && !empty($e['pc_endTime'])) {
                [$eh, $em] = array_map('intval', explode(':', (string)$e['pc_endTime']) + [0, 0]);
                $d = $eh * 60 + $em - $s;
            }
            $busy[] = [$s, $s + max(1, $d)];
        }
        for ($t = max($r['h'] * 60 + $r['m'], $startMin); $t + $interval <= $endMin; $t += $interval) {
            $clash = false;
            foreach ($busy as [$bs, $be]) {
                if ($t < $be && $t + $interval > $bs) {
                    $clash = true;
                    break;
                }
            }
            if (!$clash) {
                return ['h' => intdiv($t, 60), 'm' => $t % 60, 'free' => true];
            }
        }
        return $fallback;
    }
}
