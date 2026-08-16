<?php
/**
 * @var string              $reason
 * @var array<string,mixed> $settings
 */
?>
<section class="form-page centered">
    <h1>Voting is closed</h1>
    <p class="lede"><?= e($reason) ?></p>
    <div class="cta-row">
        <a class="btn btn-outline" href="/">Back to home</a>
        <a class="btn btn-primary" href="/results.php">Results</a>
    </div>
</section>
