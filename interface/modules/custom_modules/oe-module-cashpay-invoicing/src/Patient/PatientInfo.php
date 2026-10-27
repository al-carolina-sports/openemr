<?php

/**
 * The patient and practice details printed on quotes and receipts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Patient;

use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;

final readonly class PatientInfo
{
    /**
     * @param list<string> $address
     */
    public function __construct(
        public int $pid,
        public string $name,
        public string $pubpid,
        public string $dob,
        public string $email,
        public string $phone,
        public array $address,
    ) {
    }

    public static function load(Database $db, int $pid): ?self
    {
        $row = $db->fetchOne(
            'SELECT `fname`, `lname`, `mname`, `pubpid`, `DOB`, `email`, `phone_cell`, `phone_home`, `street`, `city`, `state`, `postal_code`'
            . ' FROM `patient_data` WHERE `pid` = ?',
            [$pid]
        );
        if ($row === null) {
            return null;
        }
        $cityLine = trim(Row::str($row, 'city') . (Row::str($row, 'state') !== '' ? ', ' . Row::str($row, 'state') : '') . ' ' . Row::str($row, 'postal_code'));
        return new self(
            pid: $pid,
            name: trim(Row::str($row, 'fname') . ' ' . Row::str($row, 'mname') . ' ' . Row::str($row, 'lname')),
            pubpid: Row::str($row, 'pubpid'),
            dob: Row::str($row, 'DOB'),
            email: trim(Row::str($row, 'email')),
            phone: Row::str($row, 'phone_cell') !== '' ? Row::str($row, 'phone_cell') : Row::str($row, 'phone_home'),
            address: array_values(array_filter([trim(Row::str($row, 'street')), trim($cityLine, ', ')], static fn (string $l): bool => $l !== '')),
        );
    }

    /**
     * Facility name, address and phone for the letterhead.
     *
     * @return array{name: string, lines: list<string>, phone: string}
     */
    public static function facility(Database $db, ?int $facilityId): array
    {
        $row = $facilityId !== null
            ? $db->fetchOne('SELECT * FROM `facility` WHERE `id` = ?', [$facilityId])
            : $db->fetchOne('SELECT * FROM `facility` WHERE `primary_business_entity` = 1 ORDER BY `id` LIMIT 1');
        $row ??= $db->fetchOne('SELECT * FROM `facility` ORDER BY `id` LIMIT 1');
        if ($row === null) {
            return ['name' => '', 'lines' => [], 'phone' => ''];
        }
        $cityLine = trim(Row::str($row, 'city') . ', ' . Row::str($row, 'state') . ' ' . Row::str($row, 'postal_code'), ', ');
        return [
            'name' => Row::str($row, 'name'),
            'lines' => array_values(array_filter([Row::str($row, 'street'), $cityLine], static fn (string $l): bool => trim($l) !== '')),
            'phone' => Row::str($row, 'phone'),
        ];
    }
}
