<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for Aem\Renderer. No framework, no Composer
 * requirement. A host that already has Composer can map the namespace to
 * src/ instead and delete this file.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Aem\\Renderer\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
