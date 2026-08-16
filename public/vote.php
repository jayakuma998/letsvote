<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

app_start_session();
Auth::requireVoter();

$user   = Auth::user();
$userId = (int) $user['id'];

// Show the receipt instead of the ballot if this account has already voted.
if (Election::hasVoted($userId)) {
    View::render('voted', [
        'receipt'       => Election::receiptFor($userId),
        'resultsPublic' => Election::resultsArePublic(),
    ], 'You have voted — LetsVote');
    exit;
}

if (!Election::isOpen()) {
    View::render('closed', [
        'reason'   => Election::closedReason(),
        'settings' => Election::settings(),
    ], 'Voting is closed — LetsVote');
    exit;
}

$candidates = Election::candidates();
$error      = null;

if (Http::isPost()) {
    Csrf::verify();

    $candidateId = filter_input(INPUT_POST, 'candidate_id', FILTER_VALIDATE_INT);
    $confirmed   = Http::post('confirm') === 'yes';

    if ($candidateId === false || $candidateId === null) {
        $error = 'Please select a candidate.';
    } elseif (!$confirmed) {
        $error = 'Please tick the confirmation box — a vote cannot be changed once cast.';
    } else {
        try {
            $receipt = Election::castBallot($userId, $candidateId, (string) $user['region']);

            Session::flash('success', 'Your vote has been recorded. Receipt: ' . $receipt);
            Http::redirect('/vote.php');
        } catch (AlreadyVotedException $e) {
            // Two tabs, two clicks: the database refused the second one.
            Session::flash('error', 'This account has already voted.');
            Http::redirect('/vote.php');
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}

View::render('vote', [
    'candidates' => $candidates,
    'error'      => $error,
    'settings'   => Election::settings(),
    'region'     => (string) $user['region'],
], 'Cast your vote — LetsVote');
