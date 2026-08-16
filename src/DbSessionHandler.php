<?php
declare(strict_types=1);

/**
 * Stores PHP sessions in MySQL instead of on the local disk.
 *
 * WHY THIS MATTERS FOR OUR ARCHITECTURE:
 * the Auto Scaling group runs one webapp instance in AZ1 and one in AZ2.
 * The ALB may send request #1 to AZ1 and request #2 to AZ2. With the default
 * file-based sessions, the AZ2 instance would not find the session written in
 * AZ1 and the user would appear logged out at random. Putting sessions in the
 * shared RDS database makes any instance able to serve any request, which is
 * what lets us scale out (and lets an instance die) without logging anyone out.
 *
 * The alternative would be ALB sticky sessions, but that pins users to a single
 * instance and undoes some of the benefit of the load balancer.
 */
final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(private int $lifetimeSeconds = 7200)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = Db::write()->prepare(
            'SELECT payload FROM sessions WHERE id = :id AND last_activity > :cutoff'
        );
        $stmt->execute([':id' => $id, ':cutoff' => time() - $this->lifetimeSeconds]);
        $payload = $stmt->fetchColumn();

        return $payload === false ? '' : (string) $payload;
    }

    public function write(string $id, string $data): bool
    {
        $stmt = Db::write()->prepare(
            'INSERT INTO sessions (id, user_id, payload, last_activity)
                  VALUES (:id, :user_id, :payload, :now)
             ON DUPLICATE KEY UPDATE
                  user_id       = VALUES(user_id),
                  payload       = VALUES(payload),
                  last_activity = VALUES(last_activity)'
        );

        return $stmt->execute([
            ':id'      => $id,
            // Denormalised so an admin can see who is currently signed in.
            ':user_id' => $_SESSION['user_id'] ?? null,
            ':payload' => $data,
            ':now'     => time(),
        ]);
    }

    public function destroy(string $id): bool
    {
        $stmt = Db::write()->prepare('DELETE FROM sessions WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = Db::write()->prepare('DELETE FROM sessions WHERE last_activity < :cutoff');
        $stmt->execute([':cutoff' => time() - max($max_lifetime, $this->lifetimeSeconds)]);

        return $stmt->rowCount();
    }

    /** Rejects session IDs we never issued, so nobody can invent one. */
    public function validateId(string $id): bool
    {
        $stmt = Db::write()->prepare('SELECT 1 FROM sessions WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    /** Called when the session is unchanged; just bump the timestamp. */
    public function updateTimestamp(string $id, string $data): bool
    {
        $stmt = Db::write()->prepare(
            'UPDATE sessions SET last_activity = :now WHERE id = :id'
        );

        return $stmt->execute([':now' => time(), ':id' => $id]);
    }
}
