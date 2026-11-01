<?php

/**
 * In-memory directories, calendar and permissions for tool tests.
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
use OpenEMR\Modules\AiAssistant\Directory\Patient;
use OpenEMR\Modules\AiAssistant\Directory\PatientDirectory;
use OpenEMR\Modules\AiAssistant\Directory\Provider;
use OpenEMR\Modules\AiAssistant\Directory\ProviderDirectory;

final class Fakes
{
    public static function patients(Patient ...$patients): PatientDirectory
    {
        return new class (array_values($patients)) implements PatientDirectory {
            /**
             * @param list<Patient> $patients
             */
            public function __construct(private readonly array $patients)
            {
            }

            public function search(string $lastName, ?string $firstName, ?\DateTimeImmutable $dob, int $limit): array
            {
                $out = [];
                foreach ($this->patients as $p) {
                    if (stripos($p->lastName, $lastName) !== 0) {
                        continue;
                    }
                    if ($firstName !== null && stripos($p->firstName, $firstName) !== 0) {
                        continue;
                    }
                    if ($dob !== null && $p->dob?->format('Y-m-d') !== $dob->format('Y-m-d')) {
                        continue;
                    }
                    $out[] = $p;
                }
                return array_slice($out, 0, $limit);
            }

            public function find(int $pid): ?Patient
            {
                foreach ($this->patients as $p) {
                    if ($p->pid === $pid) {
                        return $p;
                    }
                }
                return null;
            }
        };
    }

    public static function providers(Provider ...$providers): ProviderDirectory
    {
        return new class (array_values($providers)) implements ProviderDirectory {
            /**
             * @param list<Provider> $providers
             */
            public function __construct(private readonly array $providers)
            {
            }

            public function active(?string $nameContains): array
            {
                return array_values(array_filter(
                    $this->providers,
                    static fn(Provider $p): bool => $nameContains === null || stripos($p->firstName . ' ' . $p->lastName, $nameContains) !== false
                ));
            }

            public function find(int $id): ?Provider
            {
                foreach ($this->providers as $p) {
                    if ($p->id === $id) {
                        return $p;
                    }
                }
                return null;
            }
        };
    }

    public static function calendar(CalendarEvent ...$events): RecordingCalendar
    {
        return new RecordingCalendar(array_values($events));
    }

    public static function patient(int $pid, string $first, string $last, string $dob): Patient
    {
        return new Patient($pid, $first, $last, new \DateTimeImmutable($dob), 'Female', null);
    }

    public static function provider(int $id = 7): Provider
    {
        return new Provider($id, 'Alvin', 'Antony', 'Dr.', 'PM&R', 3, 'Raleigh');
    }
}
