<?php

/**
 * The fixed list of fields the hover card can show.
 *
 * "sensitive" fields (DOB and everything that identifies how to reach the
 * patient) are only returned to roles allowed to see DOB and contact details.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ApptHoverCard;

final class FieldCatalog
{
    public const BLOCK_APPOINTMENT = 'appointment';
    public const BLOCK_PATIENT = 'patient';
    public const BLOCK_CONTACT = 'contact';

    /**
     * @return array<string, array{block: string, label: string, show: bool, sensitive: bool, custom: bool}>
     */
    public static function all(): array
    {
        return [
            'time_range' => ['block' => self::BLOCK_APPOINTMENT, 'label' => 'Time', 'show' => true, 'sensitive' => false, 'custom' => false],
            'provider' => ['block' => self::BLOCK_APPOINTMENT, 'label' => 'Provider', 'show' => true, 'sensitive' => false, 'custom' => false],
            'facility' => ['block' => self::BLOCK_APPOINTMENT, 'label' => 'Facility', 'show' => true, 'sensitive' => false, 'custom' => false],
            'reason' => ['block' => self::BLOCK_APPOINTMENT, 'label' => 'Reason', 'show' => true, 'sensitive' => false, 'custom' => false],
            'comments' => ['block' => self::BLOCK_APPOINTMENT, 'label' => 'Comments', 'show' => true, 'sensitive' => false, 'custom' => false],
            'status' => ['block' => self::BLOCK_APPOINTMENT, 'label' => 'Status', 'show' => false, 'sensitive' => false, 'custom' => false],
            'name' => ['block' => self::BLOCK_PATIENT, 'label' => 'Patient', 'show' => true, 'sensitive' => false, 'custom' => false],
            'dob_age' => ['block' => self::BLOCK_PATIENT, 'label' => 'DOB', 'show' => true, 'sensitive' => true, 'custom' => false],
            'insurance' => ['block' => self::BLOCK_PATIENT, 'label' => 'Primary insurance', 'show' => false, 'sensitive' => true, 'custom' => false],
            'balance' => ['block' => self::BLOCK_PATIENT, 'label' => 'Balance due', 'show' => false, 'sensitive' => true, 'custom' => false],
            'last_visit' => ['block' => self::BLOCK_PATIENT, 'label' => 'Last visit', 'show' => false, 'sensitive' => true, 'custom' => false],
            'email' => ['block' => self::BLOCK_CONTACT, 'label' => 'Email', 'show' => true, 'sensitive' => true, 'custom' => false],
            'cell' => ['block' => self::BLOCK_CONTACT, 'label' => 'Cell', 'show' => true, 'sensitive' => true, 'custom' => false],
            'other_phones' => ['block' => self::BLOCK_CONTACT, 'label' => 'Home / work', 'show' => false, 'sensitive' => true, 'custom' => false],
            'preferred_contact' => ['block' => self::BLOCK_CONTACT, 'label' => 'Preferred contact', 'show' => true, 'sensitive' => true, 'custom' => true],
            'best_time' => ['block' => self::BLOCK_CONTACT, 'label' => 'Best time to call', 'show' => true, 'sensitive' => true, 'custom' => true],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
