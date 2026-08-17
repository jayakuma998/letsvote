<?php
/**
 * @var array<string,mixed>            $settings
 * @var array<int,array<string,mixed>> $candidates
 * @var bool                           $isOpen
 * @var string                         $closedReason
 * @var bool                           $hasVoted
 * @var bool                           $profileComplete
 * @var bool                           $resultsPublic
 * @var array<string,mixed>|null       $user
 */
?>
<section class="hero">
    <p class="eyebrow"><?= $isOpen ? 'Polls are open' : 'Polls are closed' ?></p>
    <h1><?= e($settings['title']) ?></h1>
    <p class="lede">
        Create an account, register as a voter, and cast one secret ballot.
        Results are published by the election administrator.
    </p>

    <div class="cta-row">
        <?php if ($user === null): ?>
            <a class="btn btn-primary btn-lg" href="/login.php?new=1">Create an account</a>
            <a class="btn btn-outline btn-lg" href="/login.php">I already have an account</a>
        <?php elseif ($hasVoted): ?>
            <a class="btn btn-outline btn-lg" href="/vote.php">View your receipt</a>
            <?php if ($resultsPublic): ?>
                <a class="btn btn-primary btn-lg" href="/results.php">See the results</a>
            <?php endif; ?>
        <?php elseif (!$profileComplete): ?>
            <a class="btn btn-primary btn-lg" href="/register.php">Complete voter registration</a>
        <?php else: ?>
            <a class="btn btn-primary btn-lg" href="/vote.php">Cast your ballot</a>
        <?php endif; ?>
    </div>

    <?php if (!$isOpen): ?>
        <p class="notice"><?= e($closedReason) ?></p>
    <?php endif; ?>
</section>

<section class="steps">
    <h2>How it works</h2>
    <ol class="step-list">
        <li>
            <span class="step-num">1</span>
            <h3>Sign up</h3>
            <p>Your email and password are handled by Amazon Cognito. This site never sees your password.</p>
        </li>
        <li>
            <span class="step-num">2</span>
            <h3>Register</h3>
            <p>Give your name, ID number, date of birth and region. One ID number, one voter.</p>
        </li>
        <li>
            <span class="step-num">3</span>
            <h3>Vote once</h3>
            <p>Pick a candidate and confirm. You get a receipt code; your choice stays secret.</p>
        </li>
    </ol>
</section>

<section class="candidates">
    <h2>On the ballot</h2>
    <?php if ($candidates === []): ?>
        <p class="notice">No candidates have been added yet.</p>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($candidates as $candidate): ?>
                <?php $party = candidate_party_class($candidate['party_abbr'] ?? ''); ?>
                <article class="card candidate-card <?= e($party) ?>">
                    <div class="candidate-head">
                        <?php if (($candidate['photo'] ?? '') !== ''): ?>
                            <img class="avatar" src="<?= e($candidate['photo']) ?>" width="72" height="72"
                                 alt="Illustrated portrait of <?= e($candidate['full_name']) ?>">
                        <?php else: ?>
                            <span class="avatar avatar-fallback" aria-hidden="true"><?= e(candidate_initials($candidate['full_name'])) ?></span>
                        <?php endif; ?>
                        <div class="candidate-id">
                            <h3><?= e($candidate['full_name']) ?></h3>
                            <p class="party">
                                <?php if (($candidate['party_abbr'] ?? '') !== ''): ?>
                                    <span class="party-tag"><?= e($candidate['party_abbr']) ?></span>
                                <?php endif; ?>
                                <span class="party-name"><?= e($candidate['party']) ?></span>
                            </p>
                        </div>
                    </div>
                    <?php if ($candidate['slogan'] !== ''): ?>
                        <p class="slogan">“<?= e($candidate['slogan']) ?>”</p>
                    <?php endif; ?>
                    <p class="bio"><?= e($candidate['bio']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="hint">
            Portraits are illustrated placeholders, not photographs. Biographies
            are limited to offices held.
        </p>
    <?php endif; ?>
</section>
