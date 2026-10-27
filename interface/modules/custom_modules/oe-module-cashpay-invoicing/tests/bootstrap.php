<?php

/**
 * Bootstrap for the module's isolated tests (no database, no OpenEMR boot).
 * Run with: vendor/bin/phpunit -c interface/modules/custom_modules/oe-module-cashpay-invoicing/phpunit.xml
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'OpenEMR\\Modules\\CashpayInvoicing\\Tests\\' => __DIR__ . '/',
        'OpenEMR\\Modules\\CashpayInvoicing\\' => __DIR__ . '/../src/',
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

// Translation helpers used by a few classes; identity functions are enough here.
if (!function_exists('xlt')) {
    function xlt(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}
