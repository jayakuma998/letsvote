<?php
declare(strict_types=1);

/**
 * Two lazy PDO connections:
 *
 *   Db::write()  -> the RDS primary       (registrations, ballots, sessions)
 *   Db::read()   -> the RDS read replica  (results, tallies, dashboards)
 *
 * If no replica endpoint is configured, read() simply falls back to the
 * primary, so the app still works on a laptop or a single-instance setup.
 */
final class Db
{
    private static ?PDO $write = null;
    private static ?PDO $read = null;

    public static function write(): PDO
    {
        return self::$write ??= self::connect(Config::mustGet('db.host'));
    }

    public static function read(): PDO
    {
        if (self::$read !== null) {
            return self::$read;
        }

        $replica = Config::get('db.host_read');
        if ($replica === null || $replica === '') {
            return self::$read = self::write();
        }

        try {
            return self::$read = self::connect($replica);
        } catch (PDOException $e) {
            // A replica hiccup must never take the site down: degrade to the primary.
            error_log('letsvote: read replica unavailable, falling back to primary: ' . $e->getMessage());
            return self::$read = self::write();
        }
    }

    private static function connect(string $host): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            Config::get('db.port', '3306'),
            Config::mustGet('db.name')
        );

        return new PDO($dsn, Config::mustGet('db.user'), Config::mustGet('db.pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    }

    /** Cheap liveness probe used by the ALB health check. */
    public static function ping(): bool
    {
        try {
            return self::write()->query('SELECT 1')->fetchColumn() == 1;
        } catch (Throwable $e) {
            error_log('letsvote: db ping failed: ' . $e->getMessage());
            return false;
        }
    }
}
