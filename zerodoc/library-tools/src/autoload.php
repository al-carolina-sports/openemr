<?php
declare(strict_types=1);

/**
 * ZeroDoc Clinical Encounter Workflow - library tooling (WP-06).
 *
 * Standalone PSR-4 autoloader. No composer step, so the content track can run
 * the validators on a bare PHP 8 install:
 *
 *     php bin/validate-library.php my-templates.yaml
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'ZeroDoc\\LibraryTools\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "These tools need PHP 8.1 or newer; this is " . PHP_VERSION . ".\n");
    exit(1);
}
