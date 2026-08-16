<?php
declare(strict_types=1);

/**
 * Loaded first by every page in public/.
 *
 * There is no Composer and no autoloader on purpose: with fewer than a dozen
 * classes, plain require statements are something the whole class can follow,
 * and there is nothing extra to install on the EC2 instances.
 */

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/src/Config.php';
require APP_ROOT . '/src/Http.php';
require APP_ROOT . '/src/Db.php';
require APP_ROOT . '/src/DbSessionHandler.php';
require APP_ROOT . '/src/Session.php';
require APP_ROOT . '/src/Jwt.php';
require APP_ROOT . '/src/Cognito.php';
require APP_ROOT . '/src/Auth.php';
require APP_ROOT . '/src/Csrf.php';
require APP_ROOT . '/src/Election.php';
require APP_ROOT . '/src/View.php';

Config::load();

mb_internal_encoding('UTF-8');
date_default_timezone_set('Africa/Douala');

if (Config::isProduction()) {
    // Never leak a database hostname or a stack trace to a voter.
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// Anything uncaught becomes a generic 500 page; the detail goes to the Apache
// error log (and from there to CloudWatch Logs via the agent).
set_exception_handler(static function (Throwable $e): void {
    error_log('letsvote: uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    if (Config::isProduction()) {
        echo '<h1>Something went wrong</h1><p>Please try again in a moment.</p>';
    } else {
        echo '<h1>Unhandled exception</h1><pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    exit;
});

Http::securityHeaders();

/** Pages that need a logged-in user or CSRF protection call this first. */
function app_start_session(): void
{
    Session::start();
}
