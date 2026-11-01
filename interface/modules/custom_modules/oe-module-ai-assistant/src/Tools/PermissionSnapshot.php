<?php

/**
 * The user's rights, read once while the session is open, so tools can
 * check them after the session lock has been released.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

final readonly class PermissionSnapshot implements Permissions
{
    public function __construct(private bool $patients, private bool $calendar)
    {
    }

    public static function of(Permissions $live): self
    {
        return new self($live->canReadPatients(), $live->canReadCalendar());
    }

    public function canReadPatients(): bool
    {
        return $this->patients;
    }

    public function canReadCalendar(): bool
    {
        return $this->calendar;
    }
}
