<?php

/**
 * PatientDirectory over patient_data (read only).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Directory;

use OpenEMR\Modules\AiAssistant\Database\Database;
use OpenEMR\Modules\AiAssistant\Database\Row;

final readonly class SqlPatientDirectory implements PatientDirectory
{
    private const SELECT = 'SELECT p.`pid`, p.`fname`, p.`lname`, p.`DOB`, p.`sex`,'
        . ' (SELECT MAX(fe.`date`) FROM `form_encounter` fe WHERE fe.`pid` = p.`pid`) AS `last_visit`'
        . ' FROM `patient_data` p';

    public function __construct(private Database $db)
    {
    }

    public function search(string $lastName, ?string $firstName, ?\DateTimeImmutable $dob, int $limit): array
    {
        $where = ['p.`lname` LIKE ?'];
        $binds = [self::prefix($lastName)];
        if ($firstName !== null) {
            $where[] = 'p.`fname` LIKE ?';
            $binds[] = self::prefix($firstName);
        }
        if ($dob !== null) {
            $where[] = 'p.`DOB` = ?';
            $binds[] = $dob->format('Y-m-d');
        }
        $rows = $this->db->fetchAll(
            self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY p.`lname`, p.`fname`, p.`DOB` LIMIT ' . max(1, min(50, $limit)),
            $binds
        );
        return array_map(self::patient(...), $rows);
    }

    public function find(int $pid): ?Patient
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE p.`pid` = ?', [$pid]);
        return $row === null ? null : self::patient($row);
    }

    private static function prefix(string $text): string
    {
        return addcslashes($text, '%_\\') . '%';
    }

    /**
     * @param array<string, scalar|null> $row
     */
    private static function patient(array $row): Patient
    {
        return new Patient(
            pid: Row::int($row, 'pid'),
            firstName: Row::str($row, 'fname'),
            lastName: Row::str($row, 'lname'),
            dob: Row::date($row, 'DOB'),
            sex: Row::str($row, 'sex'),
            lastVisit: Row::date($row, 'last_visit'),
        );
    }
}
