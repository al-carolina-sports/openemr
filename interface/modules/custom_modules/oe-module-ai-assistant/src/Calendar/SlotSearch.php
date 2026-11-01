<?php

/**
 * Result of an open-slot search.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Calendar;

final readonly class SlotSearch
{
    /**
     * @param list<\DateTimeImmutable> $slots free start times, earliest first
     * @param bool $truncated more free times exist than were returned
     * @param list<\DateTimeImmutable> $closedDays days with no working hours
     */
    public function __construct(
        public array $slots,
        public bool $truncated,
        public array $closedDays,
    ) {
    }
}
