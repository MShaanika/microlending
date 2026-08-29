<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('VIEW_PATH', APP_PATH . '/Views');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('PUBLIC_PATH', ROOT_PATH . '/public');

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
});

if (is_file(ROOT_PATH . '/vendor/autoload.php')) {
    require ROOT_PATH . '/vendor/autoload.php';
}

// Takes over error/exception/fatal display entirely (Part 4-6) -- what's
// shown is decided by system_settings.error_display_mode, not php.ini.
// Registered as early as possible so it can catch config/bootstrap failures too.
\App\Core\ErrorHandler::register();

use App\Core\Auth;
use App\Core\Session;

$config = require ROOT_PATH . '/config/app.php';
date_default_timezone_set($config['timezone'] ?? 'Africa/Windhoek');

$security = require ROOT_PATH . '/config/security.php';
Session::start($security['session_name'] ?? 'MLS_SESSION');

require APP_PATH . '/Helpers/functions.php';

\App\Core\EventListeners::register();

// Remember-me auto-login is disabled while investigating a login issue --
// see Auth::attemptRememberLogin() for the (currently unused) implementation.

return $config;
