<?php
/**
 * @var array<string,mixed>                        $settings
 * @var array<int,array<string,mixed>>             $tally
 * @var array<int,array<string,mixed>>             $byRegion
 * @var array{registered:int,voted:int,turnout:float} $turnout
 * @var bool                                       $isOpen
 * @var bool                                       $previewOnly
 *
 * The bars are <progress> elements rather than divs with inline widths,
 * because our Content-Security-Policy forbids inline styles.
 */

$regions = [];
foreach ($byRegion as $row) {
    $regions[(string) $row['region']][] = $row;
}
$leader = $tally[0] ?? null;
?>
<section class="results">
    <?php if ($previewOnly): ?>
        <div class="flash flash-info" role="status">
            <strong>Administrator preview.</strong> These results are not public yet.
        </div>
    <?php endif; ?>

    <h1>Results</h1>
    <p class="lede">
        <?= e($settings['title']) ?> —
        <?= $isOpen ? 'voting is still open, so these numbers will change.' : 'voting has closed.' ?>
    </p>

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
    </div>

    <?php if ($leader !== null && $leader['votes'] > 0 && !$isOpen): ?>
        <p class="notice notice-strong">
            Leading candidate: <strong><?= e($leader['full_name']) ?></strong>
            with <?= e((string) $leader['percent']) ?>% of votes cast.
        </p>
    <?php endif; ?>

    <h2>National tally</h2>
    <ol class="tally">
        <?php foreach ($tally as $row): ?>
            <li class="tally-row">
                <div class="tally-head">
                    <span class="tally-name"><?= e($row['full_name']) ?></span>
                    <span class="tally-party"><?= e($row['party']) ?></span>
                    <span class="tally-figure">
                        <strong><?= e((string) $row['percent']) ?>%</strong>
                        <span class="tally-count"><?= number_format($row['votes']) ?> votes</span>
                    </span>
                </div>
                <progress class="bar" max="100" value="<?= e((string) $row['percent']) ?>">
                    <?= e((string) $row['percent']) ?>%
                </progress>
            </li>
        <?php endforeach; ?>
    </ol>

    <h2>By region</h2>
    <?php if ($regions === []): ?>
        <p class="notice">No votes have been cast yet.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <caption class="sr-only">Votes per candidate in each region</caption>
                <thead>
                    <tr>
                        <th scope="col">Region</th>
                        <th scope="col">Candidate</th>
                        <th scope="col" class="num">Votes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($regions as $regionName => $rows): ?>
                        <?php foreach ($rows as $index => $row): ?>
                            <tr>
                                <?php if ($index === 0): ?>
                                    <th scope="rowgroup" rowspan="<?= count($rows) ?>"><?= e($regionName) ?></th>
                                <?php endif; ?>
                                <td><?= e($row['full_name']) ?></td>
                                <td class="num"><?= number_format((int) $row['votes']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p class="hint">
        Every figure on this page is read from the RDS read replica, not the primary
        database that is accepting votes.
    </p>
</section>
