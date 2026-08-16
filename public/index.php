<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

app_start_session();

$user       = Auth::check() ? Auth::user() : null;
$settings   = Election::settings();
$candidates = Election::candidates();

$hasVoted = $user !== null && Election::hasVoted((int) $user['id']);

View::render('home', [
    'settings'        => $settings,
    'candidates'      => $candidates,
    'isOpen'          => Election::isOpen(),
    'closedReason'    => Election::closedReason(),
    'hasVoted'        => $hasVoted,
    'profileComplete' => Auth::profileComplete(),
    'resultsPublic'   => Election::resultsArePublic(),
], 'LetsVote — ' . $settings['title']);
