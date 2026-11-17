<?php

/**
 * AWS credentials: environment variables only (docker compose env_file); never read from or stored in the database. Never logged or returned to the browser.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe\Aws;

use OpenEMR\Modules\ElegantAiScribe\Settings;

final class Credentials
{
    private function __construct(
        public readonly string $accessKeyId,
        public readonly string $secretAccessKey,
        public readonly ?string $sessionToken,
        public readonly string $source
    ) {
    }

    public static function resolve(Settings $settings): ?self
    {
        $key = self::env('AWS_ACCESS_KEY_ID');
        $secret = self::env('AWS_SECRET_ACCESS_KEY');
        if ($key !== '' && $secret !== '') {
            $token = self::env('AWS_SESSION_TOKEN');
            return new self($key, $secret, $token !== '' ? $token : null, 'environment');
        }
        return null;
    }

    /** Where credentials would come from, for the settings page (no values). */
    public static function describe(Settings $settings): string
    {
        $c = self::resolve($settings);
        return $c === null ? 'none found' : $c->source . ' (key ending ' . substr($c->accessKeyId, -4) . ')';
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? '';
        }
        return is_string($value) ? trim($value) : '';
    }
}
