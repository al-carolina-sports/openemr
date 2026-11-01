<?php

/**
 * Typed-safe nested lookups into decoded JSON / Converse messages.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tests\Support;

final class Dig
{
    /**
     * The value at $path inside $value, or null when any step is missing.
     */
    public static function at(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }
}
