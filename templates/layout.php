<?php
/**
 * @var string                                        $content
 * @var string                                        $title
 * @var array<int,array{type:string,message:string}>  $flashes
 * @var array<string,mixed>|null                      $user
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="icon" href="data:,">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<!--
    This banner is not decoration. The site carries the names of real public
    figures on a public domain with open sign-up, so every page has to say
    plainly what it is. Do not remove it.
-->
<div class="demo-banner" role="note">
    <div class="container demo-banner-inner">
        <span class="demo-badge">Demo</span>
        <p>
            <strong>This is a classroom exercise, not a real election.</strong>
            Votes cast here count for nothing, the result is not a poll or a
            prediction, and this site is not affiliated with INEC, any political
            party, or any candidate named on it.
        </p>
    </div>
</div>

<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="/">
            <span class="brand-mark" aria-hidden="true"></span>
            <span class="brand-text">Lets<strong>Vote</strong></span>
        </a>

        <nav aria-label="Main">
            <a href="/">Home</a>
            <?php if ($user !== null): ?>
                <a href="/vote.php">Vote</a>
            <?php endif; ?>
            <a href="/results.php">Results</a>
            <?php if ($user !== null && (int) $user['is_admin'] === 1): ?>
                <a href="/admin.php">Admin</a>
            <?php endif; ?>
        </nav>

        <div class="session-box">
            <?php if ($user !== null): ?>
                <span class="who" title="<?= e($user['email']) ?>"><?= e($user['full_name'] ?: $user['email']) ?></span>
                <form method="post" action="/logout.php" class="inline">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-ghost">Sign out</button>
                </form>
            <?php else: ?>
                <a class="btn btn-ghost" href="/login.php">Sign in</a>
                <a class="btn btn-primary" href="/login.php?new=1">Sign up</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main id="main" class="container">
    <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="container">
        <p>
            LetsVote — a classroom project demonstrating a highly available web
            application on AWS. Not affiliated with any electoral authority, and
            not a real election.
        </p>
        <p>
            Candidates are real public figures, included so the exercise
            resembles something recognisable. Portraits are illustrated
            initials, not photographs, and the biographies are limited to
            offices held. Nothing here should be read as a statement by or
            about any candidate.
        </p>
        <p class="served-by">
            Served by <code><?= e(gethostname()) ?></code>
            — refresh the page a few times to watch the load balancer alternate
            between the instance in AZ1 and the one in AZ2.
        </p>
    </div>
</footer>
</body>
</html>
