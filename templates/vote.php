<?php
/**
 * @var array<int,array<string,mixed>> $candidates
 * @var string|null                    $error
 * @var array<string,mixed>            $settings
 * @var string                         $region
 */
?>
<section class="form-page">
    <h1>Cast your ballot</h1>
    <p class="lede">
        <?= e($settings['title']) ?> — voting as a registered voter in
        <strong><?= e($region) ?></strong>.
    </p>

    <div class="notice notice-strong">
        You may vote <strong>once</strong>. Your choice cannot be changed afterwards,
        and it is stored separately from your identity, so nobody can see how you voted.
    </div>

    <?php if ($error !== null): ?>
        <div class="flash flash-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/vote.php" class="stack">
        <?= Csrf::field() ?>

        <fieldset class="ballot">
            <legend>Choose one candidate</legend>

            <?php foreach ($candidates as $candidate): ?>
                <label class="ballot-option">
                    <input type="radio" name="candidate_id" value="<?= (int) $candidate['id'] ?>" required>
                    <span class="ballot-body">
                        <span class="ballot-name"><?= e($candidate['full_name']) ?></span>
                        <span class="ballot-party"><?= e($candidate['party']) ?></span>
                        <?php if ($candidate['slogan'] !== ''): ?>
                            <span class="ballot-slogan">“<?= e($candidate['slogan']) ?>”</span>
                        <?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <label class="confirm">
            <input type="checkbox" name="confirm" value="yes" required>
            I understand this is final and I can only vote once.
        </label>

        <button type="submit" class="btn btn-primary btn-lg">Submit my vote</button>
    </form>
</section>
