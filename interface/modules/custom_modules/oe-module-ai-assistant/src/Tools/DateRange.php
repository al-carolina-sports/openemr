<?php

/**
 * Parses and checks a date_from / date_to pair.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

final class DateRange
{
    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    public static function parse(ToolInput $input, int $maxDays, ?\DateTimeZone $zone = null): array
    {
        $from = $input->date('date_from');
        $to = $input->date('date_to');
        if ($zone !== null) {
            $from = new \DateTimeImmutable($from->format('Y-m-d'), $zone);
            $to = new \DateTimeImmutable($to->format('Y-m-d'), $zone);
        }
        if ($to < $from) {
            throw new InvalidToolInput('date_to is before date_from');
        }
        $days = $from->diff($to)->days;
        if ($days + 1 > $maxDays) {
            throw new InvalidToolInput('The date range is limited to ' . $maxDays . ' days; split it into smaller ranges');
        }
        return [$from, $to];
    }
}
