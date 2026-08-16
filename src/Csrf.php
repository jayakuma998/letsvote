<?php
declare(strict_types=1);

/**
 * Per-session CSRF token. Every state-changing POST must carry it, otherwise
 * another site could make a logged-in student's browser cast a ballot.
 */
final class Csrf
{
    private const FIELD = '_csrf';

    public static function token(): string
    {
        $token = Session::get('csrf_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set('csrf_token', $token);
        }

        return $token;
    }

    /** Drop this straight into every <form>. */
    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
        );
    }

    public static function isValid(): bool
    {
        $submitted = $_POST[self::FIELD] ?? '';

        return is_string($submitted)
            && $submitted !== ''
            && hash_equals(self::token(), $submitted);
    }

    /** Stops the request dead if the token is wrong. */
    public static function verify(): void
    {
        if (!self::isValid()) {
            http_response_code(419);
            exit('419 — your session expired or the request was not genuine. Please go back and try again.');
        }
    }
}
