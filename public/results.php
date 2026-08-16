<?php
declare(strict_types=1);

/**
 * Every query on this page is served by the RDS READ REPLICA (Db::read()).
 *
 * That is the whole point of the replica in the architecture diagram: on
 * election night the results page gets far more traffic than the ballot box,
 * and none of that load should touch the primary that is still accepting votes.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

app_start_session();

$isAdmin = Auth::isAdmin();

if (!Election::resultsArePublic() && !$isAdmin) {
    View::render('results_hidden', [
        'settings' => Election::settings(),
    ], 'Results — LetsVote');
    exit;
}

View::render('results', [
    'settings'    => Election::settings(),
    'tally'       => Election::tally(),
    'byRegion'    => Election::tallyByRegion(),
    'turnout'     => Election::turnout(),
    'isOpen'      => Election::isOpen(),
    'previewOnly' => !Election::resultsArePublic(),
], 'Results — LetsVote');
