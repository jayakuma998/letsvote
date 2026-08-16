<?php
declare(strict_types=1);

final class Session
{
    private const LIFETIME = 7200; // 2 hours

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_save_handler(new DbSessionHandler(self::LIFETIME), true);

        session_name('LETSVOTE_SESSION');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            // Our EC2 instances speak plain HTTP to the ALB, so PHP cannot detect
            // TLS by itself. The browser leg is HTTPS (CloudFront -> ALB), which is
            // what the cookie flag is actually about.
            'secure'   => Http::isHttps(),
            'httponly' => true,
            // "Lax" (not "Strict") so the cookie still rides along on the top-level
            // GET redirect coming back from the Cognito Hosted UI.
            'samesite' => 'Lax',
        ]);

        // Reject any session id we did not issue (see DbSessionHandler::validateId).
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) self::LIFETIME);
        // Note: session.sid_length and session.sid_bits_per_character are NOT set
        // here. They are deprecated as of PHP 8.4 and PHP's own defaults already
        // produce a session id with plenty of entropy.

        session_start();
    }

    /** Call right after a successful login to prevent session fixation. */
    public static function regenerate(): void
    {
        // Guard: session_regenerate_id() warns and does nothing if the session
        // was already closed (which is the case in CLI scripts and cron jobs).
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::start();
        }

        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        session_destroy();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);

        return $value;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function takeFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $flashes;
    }
}
