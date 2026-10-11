<?php

/**
 * Annie's Elegant Calendar Style constants.
 *
 * The appointment font size itself lives at the top of
 * public/assets/elegant-calendar.css as --aecs-event-font-size; change it
 * there and bump VERSION here so browsers fetch the new file.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantCalendarStyle;

final class Config
{
    /** Cache-busting version appended to the stylesheet URL. */
    public const VERSION = '1.0.0';
}
