<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tests\Support;

use Psr\Clock\ClockInterface;

final class FixedClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public static function at(string $time, string $zone = 'America/New_York'): self
    {
        return new self(new \DateTimeImmutable($time, new \DateTimeZone($zone)));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
