<?php
declare(strict_types=1);

/**
 * Bridges a verified Cognito identity to a row in our own `users` table.
 *
 * Cognito owns credentials (password, email verification, MFA, reset flows).
 * We own everything election-specific: the national ID, the region, whether
 * the person is an admin, and whether they have already voted.
 */
final class Auth
{
    /** @var array<string,mixed>|null|false false = not looked up yet */
    private static array|null|false $cached = false;

    /**
     * Called once, right after the ID token is verified.
     *
     * @param array<string,mixed> $claims
     */
    public static function loginFromClaims(array $claims): void
    {
        $sub   = (string) $claims['sub'];
        $email = (string) ($claims['email'] ?? '');
        $name  = trim((string) ($claims['name'] ?? ''));

        if ($name === '') {
            $name = trim(((string) ($claims['given_name'] ?? '')) . ' ' . ((string) ($claims['family_name'] ?? '')));
        }
        if ($name === '') {
            $name = $email !== '' ? explode('@', $email)[0] : 'Voter';
        }

        $pdo = Db::write();

        // First login creates the row; later logins just refresh what Cognito owns.
        $stmt = $pdo->prepare(
            'INSERT INTO users (cognito_sub, email, full_name, email_verified, created_at, last_login_at)
                  VALUES (:sub, :email, :name, :verified, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                  email          = VALUES(email),
                  email_verified = VALUES(email_verified),
                  last_login_at  = NOW()'
        );
        $stmt->execute([
            ':sub'      => $sub,
            ':email'    => $email,
            ':name'     => $name,
            ':verified' => !empty($claims['email_verified']) ? 1 : 0,
        ]);

        // lastInsertId() is unreliable after ON DUPLICATE KEY UPDATE, so look it up.
        $lookup = $pdo->prepare('SELECT id FROM users WHERE cognito_sub = :sub');
        $lookup->execute([':sub' => $sub]);
        $userId = (int) $lookup->fetchColumn();

        Session::regenerate();
        Session::set('user_id', $userId);
        Session::set('logged_in_at', time());
        self::$cached = false;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$cached !== false) {
            return self::$cached;
        }

        $userId = Session::get('user_id');
        if (!is_int($userId)) {
            return self::$cached = null;
        }

        $stmt = Db::write()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        return self::$cached = ($row === false ? null : $row);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && (int) $user['is_admin'] === 1;
    }

    /** A profile is complete once we have the details needed to issue a ballot. */
    public static function profileComplete(): bool
    {
        $user = self::user();

        return $user !== null
            && !empty($user['national_id'])
            && !empty($user['region'])
            && !empty($user['date_of_birth']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            Session::set('intended_url', $_SERVER['REQUEST_URI'] ?? '/');
            Http::redirect('/login.php');
        }
    }

    /** Logged in AND registered as a voter. */
    public static function requireVoter(): void
    {
        self::requireLogin();

        if (!self::profileComplete()) {
            Session::flash('info', 'Please finish your voter registration before you can vote.');
            Http::redirect('/register.php');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();

        if (!self::isAdmin()) {
            http_response_code(403);
            exit('403 Forbidden — administrators only.');
        }
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$cached = false;
    }
}
