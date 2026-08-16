<?php
/**
 * @var array<string,mixed>            $settings
 * @var bool                           $isOpen
 * @var array<int,array<string,mixed>> $tally
 * @var array{registered:int,voted:int,turnout:float} $turnout
 * @var array<int,array<string,mixed>> $candidates
 * @var array<int,array<string,mixed>> $auditLog
 */
?>
<section class="admin">
    <h1>Election administration</h1>
    <p class="lede"><?= e($settings['title']) ?></p>

    <div class="panel-grid">
        <div class="panel">
            <h2>Voting</h2>
            <p class="state <?= $isOpen ? 'state-on' : 'state-off' ?>">
                <?= $isOpen ? 'Polls are OPEN' : 'Polls are CLOSED' ?>
            </p>
            <form method="post" action="/admin.php" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="<?= $isOpen ? 'close_voting' : 'open_voting' ?>">
                <button type="submit" class="btn <?= $isOpen ? 'btn-danger' : 'btn-primary' ?>">
                    <?= $isOpen ? 'Close voting' : 'Open voting' ?>
                </button>
            </form>
            <p class="hint">
                Scheduled window:
                <?= $settings['opens_at'] ? e((string) $settings['opens_at']) : 'no start time' ?> →
                <?= $settings['closes_at'] ? e((string) $settings['closes_at']) : 'no end time' ?>
                (set these directly in the <code>election_settings</code> table).
            </p>
        </div>

        <div class="panel">
            <h2>Results</h2>
            <p class="state <?= (int) $settings['results_public'] === 1 ? 'state-on' : 'state-off' ?>">
                <?= (int) $settings['results_public'] === 1 ? 'PUBLISHED to everyone' : 'HIDDEN from the public' ?>
            </p>
            <form method="post" action="/admin.php" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action"
                       value="<?= (int) $settings['results_public'] === 1 ? 'unpublish' : 'publish' ?>">
                <button type="submit" class="btn <?= (int) $settings['results_public'] === 1 ? 'btn-danger' : 'btn-primary' ?>">
                    <?= (int) $settings['results_public'] === 1 ? 'Hide results' : 'Publish results' ?>
                </button>
            </form>
            <p class="hint">You can always see the live tally below, even while it is hidden.</p>
        </div>
    </div>

    <h2>Live tally</h2>
    <div class="stat-row">
        <div class="stat">
            <span class="stat-value"><?= number_format($turnout['voted']) ?></span>
            <span class="stat-label">Votes cast</span>
        </div>
        <div class="stat">
            <span class="stat-value"><?= number_format($turnout['registered']) ?></span>
            <span class="stat-label">Registered voters</span>
        </div>
        <div class="stat">
            <span class="stat-value"><?= e((string) $turnout['turnout']) ?>%</span>
            <span class="stat-label">Turnout</span>
        </div>
        <div class="stat">
            <span class="stat-value"><?= count($candidates) ?></span>
            <span class="stat-label">Candidates</span>
        </div>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Candidate</th>
                    <th scope="col">Party</th>
                    <th scope="col" class="num">Votes</th>
                    <th scope="col" class="num">Share</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tally as $row): ?>
                    <tr>
                        <td><?= e($row['full_name']) ?></td>
                        <td><?= e($row['party']) ?></td>
                        <td class="num"><?= number_format($row['votes']) ?></td>
                        <td class="num"><?= e((string) $row['percent']) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2>Recent administrative actions</h2>
    <?php if ($auditLog === []): ?>
        <p class="notice">Nothing logged yet.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Who</th>
                        <th scope="col">Action</th>
                        <th scope="col">Detail</th>
                        <th scope="col">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($auditLog as $row): ?>
                        <tr>
                            <td><?= e((string) $row['created_at']) ?></td>
                            <td><?= e((string) ($row['email'] ?? 'unknown')) ?></td>
                            <td><code><?= e((string) $row['action']) ?></code></td>
                            <td><?= e((string) $row['detail']) ?></td>
                            <td><code><?= e((string) $row['ip_address']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
