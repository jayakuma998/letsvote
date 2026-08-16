<?php
declare(strict_types=1);

/**
 * Small helpers for living behind CloudFront + an Application Load Balancer.
 *
 * The webapp instances sit in a private subnet and only ever receive plain
 * HTTP on port 80 from the ALB, so $_SERVER['HTTPS'] is never set. The real
 * client protocol and IP arrive in X-Forwarded-* headers instead.
 *
 * SECURITY NOTE: these headers are only trustworthy because the webapp
 * security group (webapp-sg) accepts port 80 exclusively from the ALB
 * security group (webapp-lb-sg). Nobody on the internet can reach the
 * instances directly to forge them.
 */
final class Http
{
    public static function isHttps(): bool
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($forwarded !== '') {
            // The header can be a comma-separated list (CloudFront, then ALB).
            return strtolower(trim(explode(',', $forwarded)[0])) === 'https';
        }

        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    }

    /** The real visitor IP, not the ALB's private IP. */
    public static function clientIp(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /** Absolute URL for a path, e.g. url('/vote.php'). */
    public static function url(string $path = '/'): string
    {
        return rtrim(Config::mustGet('app.base_url'), '/') . '/' . ltrim($path, '/');
    }

    public static function redirect(string $pathOrUrl): never
    {
        $target = str_starts_with($pathOrUrl, 'http') ? $pathOrUrl : self::url($pathOrUrl);
        header('Location: ' . $target, true, 302);
        exit;
    }

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Content-Security-Policy: default-src \'self\'; img-src \'self\' data:; style-src \'self\'; script-src \'self\'; form-action \'self\' https://*.amazoncognito.com; frame-ancestors \'none\'');

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    public static function post(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }

    public static function query(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }
}
