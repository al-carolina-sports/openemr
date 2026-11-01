<?php

/**
 * The logged-in staff member the assistant acts for.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Agent;

final readonly class StaffUser
{
    public function __construct(
        public int $id,
        public string $username,
        public string $displayName,
        public string $group,
        public bool $isProvider,
    ) {
        if ($id <= 0) {
            throw new \DomainException('Staff user id must be positive');
        }
    }
}
