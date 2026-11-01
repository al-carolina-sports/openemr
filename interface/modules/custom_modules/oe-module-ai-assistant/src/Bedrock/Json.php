<?php

/**
 * JSON helpers for the Converse API. PHP encodes an empty array as [], but
 * Converse needs {} for schema "properties" and tool "input"; this fixes
 * those spots before encoding.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Bedrock;

final class Json
{
    private const OBJECT_KEYS = ['properties', 'input', 'json'];

    /**
     * Returns $value with every empty array found under an object-only key
     * replaced by an empty object.
     */
    public static function objects(mixed $value, ?string $key = null): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === []) {
            return $key !== null && in_array($key, self::OBJECT_KEYS, true) ? new \stdClass() : [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::objects($v, is_string($k) ? $k : null);
        }
        return $out;
    }

    public static function encode(mixed $value): string
    {
        return json_encode(self::objects($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new \UnexpectedValueException('Expected a JSON object');
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }
}
