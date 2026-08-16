<?php
/**
 * @var array<string,mixed>|null $receipt
 * @var bool                     $resultsPublic
 */
?>
<section class="form-page centered">
    <div class="tick" aria-hidden="true">✓</div>
    <h1>Your vote has been recorded</h1>

    <?php if ($receipt !== null): ?>
        <p class="lede">Keep this receipt code. It proves you voted; it does not reveal your choice.</p>
        <p class="receipt"><?= e($receipt['receipt_code']) ?></p>
        <p class="hint">Recorded on <?= e(date('j F Y \a\t H:i', strtotime((string) $receipt['cast_at']))) ?></p>
    <?php else: ?>
        <p class="lede">This account has already cast a ballot.</p>
    <?php endif; ?>

    <div class="cta-row">
        <?php if ($resultsPublic): ?>
            <a class="btn btn-primary" href="/results.php">See the results</a>
        <?php else: ?>
            <p class="notice">Results will appear here once the administrator publishes them.</p>
        <?php endif; ?>
        <a class="btn btn-outline" href="/">Back to home</a>
    </div>
</section>
