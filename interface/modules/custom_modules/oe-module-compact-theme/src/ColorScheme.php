<?php

/**
 * Which OpenEMR General Theme a theme pack is designed to sit on. Backed because it is read from theme.json.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CompactTheme;

enum ColorScheme: string
{
    case Light = 'light';
    case Dark = 'dark';
}
