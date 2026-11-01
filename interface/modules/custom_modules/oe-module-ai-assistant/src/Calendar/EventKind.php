<?php

/**
 * What a calendar event means for a provider's availability.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Calendar;

enum EventKind
{
    /** A patient appointment. */
    case Appointment;
    /** "In Office": the provider is available from this time. */
    case OfficeIn;
    /** "Out Of Office": the provider is unavailable from this time. */
    case OfficeOut;
    /** Provider time with no patient (lunch, vacation, reserved, a block). */
    case ProviderBlock;
    /** Clinic-wide (holiday, closed). */
    case ClinicClosure;
    /** A group therapy session. */
    case Group;

    /**
     * @param int $catType openemr_postcalendar_categories.pc_cattype (0 patient, 1 provider, 2 clinic, 3 group)
     */
    public static function classify(string $constantId, int $catType, int $pid): self
    {
        return match (true) {
            $constantId === 'in_office' => self::OfficeIn,
            $constantId === 'out_of_office' => self::OfficeOut,
            $catType === 2 => self::ClinicClosure,
            $catType === 3 => self::Group,
            $catType === 1 => self::ProviderBlock,
            $pid > 0 => self::Appointment,
            default => self::ProviderBlock,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Appointment => 'appointment',
            self::OfficeIn => 'office hours start',
            self::OfficeOut => 'office hours end',
            self::ProviderBlock => 'provider block',
            self::ClinicClosure => 'clinic closure',
            self::Group => 'group session',
        };
    }

    public function isMarker(): bool
    {
        return $this === self::OfficeIn || $this === self::OfficeOut;
    }
}
