<?php
declare(strict_types=1);

/**
 * Configuration loader.
 *
 * Values come from an INI file that EC2 user-data writes at boot from
 * AWS Secrets Manager (default: /etc/letsvote/config.ini). Any value can be
 * overridden by an environment variable, which is handy on a laptop.
 *
 * Nothing secret is ever committed to this repository.
 */
final class Config
{
    /** @var array<string,string> flat "section.key" => value */
    private static array $values = [];
    private static bool $loaded = false;

    /** "section.key" => environment variable that overrides it */
    private const ENV_OVERRIDES = [
        'app.base_url'            => 'LETSVOTE_BASE_URL',
        'app.env'                 => 'LETSVOTE_ENV',
        'db.host'                 => 'LETSVOTE_DB_HOST',
        'db.host_read'            => 'LETSVOTE_DB_HOST_READ',
        'db.port'                 => 'LETSVOTE_DB_PORT',
        'db.name'                 => 'LETSVOTE_DB_NAME',
        'db.user'                 => 'LETSVOTE_DB_USER',
        'db.pass'                 => 'LETSVOTE_DB_PASS',
        'cognito.region'          => 'LETSVOTE_COGNITO_REGION',
        'cognito.user_pool_id'    => 'LETSVOTE_COGNITO_USER_POOL_ID',
        'cognito.client_id'       => 'LETSVOTE_COGNITO_CLIENT_ID',
        'cognito.client_secret'   => 'LETSVOTE_COGNITO_CLIENT_SECRET',
        'cognito.domain'          => 'LETSVOTE_COGNITO_DOMAIN',
    ];

    private const DEFAULTS = [
        'app.env'       => 'production',
        'app.base_url'  => 'http://localhost:8000',
        'db.port'       => '3306',
        'db.name'       => 'letsvote',
    ];

    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }

        $path ??= getenv('LETSVOTE_CONFIG') ?: '/etc/letsvote/config.ini';

        self::$values = self::DEFAULTS;

        if (is_readable($path)) {
            $ini = parse_ini_file($path, true, INI_SCANNER_TYPED);
            if ($ini === false) {
                throw new RuntimeException("Could not parse config file: {$path}");
            }
            foreach ($ini as $section => $pairs) {
                if (!is_array($pairs)) {
                    continue;
                }
                foreach ($pairs as $key => $value) {
                    self::$values["{$section}.{$key}"] = (string) $value;
                }
            }
        }

        foreach (self::ENV_OVERRIDES as $key => $envName) {
            $value = getenv($envName);
            if ($value !== false && $value !== '') {
                self::$values[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        return self::$values[$key] ?? $default;
    }

    /** Same as get(), but blows up early when a required setting is missing. */
    public static function mustGet(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new RuntimeException(
                "Missing required config value '{$key}'. "
                . 'Check /etc/letsvote/config.ini or the matching environment variable.'
            );
        }
        return $value;
    }

    public static function isProduction(): bool
    {
        return self::get('app.env') === 'production';
    }
}
