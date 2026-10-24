<?php

/**
 * Visual themes offered by the Compact Theme module. Backed because the value is stored in globals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CompactTheme;

enum Theme: string
{
    case Slate = 'slate';
    case Paper = 'paper';
    case Rail = 'rail';
    case Off = 'off';
}
