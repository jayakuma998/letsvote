<?php
declare(strict_types=1);

/**
 * All the election rules live here.
 *
 * BALLOT SECRECY — the important design decision in this app:
 * we never store "user X voted for candidate Y". Instead a vote writes two
 * rows inside one transaction:
 *
 *   voter_receipts : who has voted (user_id)         — no candidate
 *   ballots        : the choice (candidate_id, region) — no user
 *
 * That still guarantees one vote per person (the primary key on
 * voter_receipts.user_id does it), but nobody with database access — including
 * us — can tell how any individual voted.
 */
final class Election
{
    /** The ten regions of Cameroon. */
    public const REGIONS = [
        'Adamawa',
        'Centre',
        'East',
        'Far North',
        'Littoral',
        'North',
        'North-West',
        'South',
        'South-West',
        'West',
    ];

    /** @var array<string,mixed>|null */
    private static ?array $settings = null;

    /**
     * Read from the primary: an admin who just opened the polls must not be
     * confused by replica lag.
     *
     * @return array<string,mixed>
     */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $row = Db::write()->query('SELECT * FROM election_settings WHERE id = 1')->fetch();
        if ($row === false) {
            throw new RuntimeException('election_settings row is missing — did you run sql/schema.sql?');
        }

        return self::$settings = $row;
    }

    public static function isOpen(): bool
    {
        $settings = self::settings();
        $now      = time();

        if ((int) $settings['is_open'] !== 1) {
            return false;
        }
        if (!empty($settings['opens_at']) && strtotime((string) $settings['opens_at']) > $now) {
            return false;
        }
        if (!empty($settings['closes_at']) && strtotime((string) $settings['closes_at']) < $now) {
            return false;
        }

        return true;
    }

    /** Why the polls are shut, in words a voter can understand. */
    public static function closedReason(): string
    {
        $settings = self::settings();
        $now      = time();

        if ((int) $settings['is_open'] !== 1) {
            return 'Voting has not been opened by the election administrator yet.';
        }
        if (!empty($settings['opens_at']) && strtotime((string) $settings['opens_at']) > $now) {
            return 'Voting opens on ' . date('j F Y, H:i', strtotime((string) $settings['opens_at'])) . '.';
        }
        if (!empty($settings['closes_at']) && strtotime((string) $settings['closes_at']) < $now) {
            return 'Voting closed on ' . date('j F Y, H:i', strtotime((string) $settings['closes_at'])) . '.';
        }

        return 'Voting is currently closed.';
    }

    public static function resultsArePublic(): bool
    {
        return (int) self::settings()['results_public'] === 1;
    }

    /** @return array<int,array<string,mixed>> */
    public static function candidates(): array
    {
        return Db::read()->query(
            'SELECT id, full_name, party, slogan, bio
               FROM candidates
              WHERE is_active = 1
           ORDER BY sort_order, full_name'
        )->fetchAll();
    }

    /** Authoritative check, so it goes to the primary. */
    public static function hasVoted(int $userId): bool
    {
        $stmt = Db::write()->prepare('SELECT 1 FROM voter_receipts WHERE user_id = :id');
        $stmt->execute([':id' => $userId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Casts one ballot atomically.
     *
     * @return string a receipt code the voter can keep (proves they voted,
     *                reveals nothing about the choice)
     * @throws AlreadyVotedException
     */
    public static function castBallot(int $userId, int $candidateId, string $region): string
    {
        $pdo = Db::write();

        $check = $pdo->prepare('SELECT 1 FROM candidates WHERE id = :id AND is_active = 1');
        $check->execute([':id' => $candidateId]);
        if (!$check->fetchColumn()) {
            throw new InvalidArgumentException('That candidate is not on the ballot.');
        }

        $receipt = strtoupper(bin2hex(random_bytes(6))); // e.g. 9F2C41A0B7D3

        $pdo->beginTransaction();
        try {
            // The PRIMARY KEY on user_id is the real one-person-one-vote guard.
            // Two simultaneous requests cannot both get past this INSERT.
            $receiptStmt = $pdo->prepare(
                'INSERT INTO voter_receipts (user_id, receipt_code, cast_at)
                      VALUES (:user_id, :receipt, NOW())'
            );
            $receiptStmt->execute([':user_id' => $userId, ':receipt' => $receipt]);

            // Separate, unlinked row: the anonymous ballot itself.
            //
            // cast_at is deliberately rounded down to the hour. If both tables
            // stored the exact second, anyone could re-link a receipt to a
            // ballot just by matching timestamps and the secrecy above would be
            // worth nothing. (The `region` column is a smaller version of the
            // same risk: in a tiny classroom dataset, one voter from one region
            // is still identifiable. Fine for a demo, worth saying out loud.)
            $ballotStmt = $pdo->prepare(
                "INSERT INTO ballots (candidate_id, region, cast_at)
                      VALUES (:candidate_id, :region, DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'))"
            );
            $ballotStmt->execute([':candidate_id' => $candidateId, ':region' => $region]);

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();

            if ($e->getCode() === '23000') { // integrity constraint violation
                throw new AlreadyVotedException('This account has already cast a ballot.', 0, $e);
            }
            throw $e;
        }

        return $receipt;
    }

    /** @return array<string,mixed>|null */
    public static function receiptFor(int $userId): ?array
    {
        $stmt = Db::write()->prepare(
            'SELECT receipt_code, cast_at FROM voter_receipts WHERE user_id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * National tally — served by the read replica, which is what keeps a
     * results-page traffic spike off the primary that is still taking votes.
     *
     * @return array<int,array{id:int,full_name:string,party:string,votes:int,percent:float}>
     */
    public static function tally(): array
    {
        $rows = Db::read()->query(
            'SELECT c.id, c.full_name, c.party, COUNT(b.id) AS votes
               FROM candidates c
          LEFT JOIN ballots b ON b.candidate_id = c.id
              WHERE c.is_active = 1
           GROUP BY c.id, c.full_name, c.party
           ORDER BY votes DESC, c.full_name'
        )->fetchAll();

        $total = array_sum(array_map(static fn(array $r): int => (int) $r['votes'], $rows));

        return array_map(static function (array $r) use ($total): array {
            $votes = (int) $r['votes'];

            return [
                'id'        => (int) $r['id'],
                'full_name' => (string) $r['full_name'],
                'party'     => (string) $r['party'],
                'votes'     => $votes,
                'percent'   => $total > 0 ? round($votes * 100 / $total, 2) : 0.0,
            ];
        }, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    public static function tallyByRegion(): array
    {
        return Db::read()->query(
            'SELECT b.region, c.full_name, COUNT(*) AS votes
               FROM ballots b
               JOIN candidates c ON c.id = b.candidate_id
           GROUP BY b.region, c.full_name
           ORDER BY b.region, votes DESC'
        )->fetchAll();
    }

    /** @return array{registered:int,voted:int,turnout:float} */
    public static function turnout(): array
    {
        $pdo = Db::read();

        $registered = (int) $pdo->query(
            "SELECT COUNT(*) FROM users WHERE national_id IS NOT NULL AND national_id <> ''"
        )->fetchColumn();

        $voted = (int) $pdo->query('SELECT COUNT(*) FROM ballots')->fetchColumn();

        // Normally voted <= registered. It can drift the other way if a user row
        // is deleted: the ON DELETE CASCADE removes their receipt, but the
        // ballot survives on purpose — a secret ballot cannot be traced back and
        // withdrawn once it is in the box. Cap the percentage rather than
        // printing a nonsense "200% turnout".
        return [
            'registered' => $registered,
            'voted'      => $voted,
            'turnout'    => $registered > 0 ? min(100.0, round($voted * 100 / $registered, 1)) : 0.0,
        ];
    }
}

final class AlreadyVotedException extends RuntimeException
{
}
