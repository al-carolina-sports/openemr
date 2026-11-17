<?php

/**
 * Annie's Compact Themes constants.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CompactTheme;

final class Config
{
    /**
     * Cache-busting version appended to the module's stylesheet and script URLs.
     * Bump it with version.php whenever a CSS or JS file changes.
     */
    public const VERSION = '1.2.0';
}
