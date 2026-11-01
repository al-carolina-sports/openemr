<?php

/**
 * One occurrence of a calendar event (recurring events are expanded).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Calendar;

use OpenEMR\Modules\AiAssistant\Directory\Patient;

final readonly class CalendarEvent
{
    /** Appointment statuses that free the time (x Canceled, % Canceled < 24h). */
    public const CANCELLED_STATUSES = ['x', '%'];

    public function __construct(
        public int $id,
        public \DateTimeImmutable $start,
        public int $durationMinutes,
        public EventKind $kind,
        public string $categoryName,
        public string $status,
        public string $statusTitle,
        public ?Patient $patient,
        public ?int $providerId,
        public string $providerName,
        public ?int $facilityId,
        public string $facilityName,
    ) {
    }

    public function end(): \DateTimeImmutable
    {
        return $this->start->modify('+' . $this->durationMinutes . ' minutes');
    }

    public function isCancelled(): bool
    {
        return in_array($this->status, self::CANCELLED_STATUSES, true);
    }

    /**
     * True when the event takes the provider's time (markers and cancelled
     * appointments do not).
     */
    public function blocksTime(): bool
    {
        return !$this->kind->isMarker() && !$this->isCancelled() && $this->durationMinutes > 0;
    }
}
