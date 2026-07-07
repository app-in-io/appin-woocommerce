<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

spl_autoload_register(function (string $class): void {
    $prefix = 'AppIn\\WooCommerce\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, \strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
