<?php

/**
 * Patient lookups for the assistant.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Directory;

interface PatientDirectory
{
    /**
     * Patients whose last name starts with $lastName (and first name with
     * $firstName, and DOB equals $dob, when given), at most $limit.
     *
     * @return list<Patient>
     */
    public function search(string $lastName, ?string $firstName, ?\DateTimeImmutable $dob, int $limit): array;

    public function find(int $pid): ?Patient;
}
