<?php

/**
 * Bootstrap for the module's isolated tests (no database, no OpenEMR boot,
 * no network).
 * Run from the OpenEMR folder with:
 *   vendor/bin/phpunit -c interface/modules/custom_modules/oe-module-ai-assistant/phpunit.xml
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// AIA_TEST_AUTOLOAD lets the tests run against another vendor folder.
$autoload = getenv('AIA_TEST_AUTOLOAD');
require_once is_string($autoload) && $autoload !== '' ? $autoload : __DIR__ . '/../../../../../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'OpenEMR\\Modules\\AiAssistant\\Tests\\' => __DIR__ . '/',
        'OpenEMR\\Modules\\AiAssistant\\' => __DIR__ . '/../src/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }
});
