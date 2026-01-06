<?php

require_once __DIR__ . '/Core/Constants.php'; // Load constants first

use Src\Core\Constants;

spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'Src\\';

    // Base directory for the namespace prefix
    $base_dir = __DIR__ . '/';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // no, move to the next registered autoloader
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators in the relative class name, append
    // with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

// Define global constants if not already defined (backward compatibility or standalone usage)
// This ensures code using global constants still works if they use Src\Core\Constants values.
// We map the class constants to global constants if they are not defined.
if (!defined('STATE_ADMIN_ADDING_PROD_NAME')) define('STATE_ADMIN_ADDING_PROD_NAME', Constants::STATE_ADMIN_ADDING_PROD_NAME);
if (!defined('CALLBACK_ADMIN_PANEL')) define('CALLBACK_ADMIN_PANEL', Constants::CALLBACK_ADMIN_PANEL);
// ... Define crucial ones used in routing/controllers that rely on global namespace if strict OOP isn't followed 100%
// But since we updated Controllers to use global constants (which PHP resolves to global namespace),
// we must ensure they ARE defined globally.
// The Controllers used e.g. `CALLBACK_ADMIN_PANEL`. Without `use Src\Core\Constants;` and using `Constants::...`,
// PHP looks for global `CALLBACK_ADMIN_PANEL`.

// Helper to define all if not defined
foreach ((new ReflectionClass('Src\Core\Constants'))->getConstants() as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
