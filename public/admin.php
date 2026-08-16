<?php
declare(strict_types=1);

/**
 * Election control panel. Only users with users.is_admin = 1 can reach it.
 *
 * Promote yourself after your first Cognito sign-in:
 *   UPDATE users SET is_admin = 1 WHERE email = 'you@example.com';
 */

require dirname(__DIR__) . '/src/bootstrap.php';

app_start_session();
Auth::requireAdmin();

$pdo = Db::write();

if (Http::isPost()) {
    Csrf::verify();

    $action = Http::post('action');

    $newSettings = match ($action) {
        'open_voting'   => ['is_open' => 1],
        'close_voting'  => ['is_open' => 0],
        'publish'       => ['results_public' => 1],
        'unpublish'     => ['results_public' => 0],
        default         => null,
    };

    if ($newSettings === null) {
        Session::flash('error', 'Unknown action.');
    } else {
        $column = array_key_first($newSettings);
        // $column comes from the match above, never from user input.
        $stmt = $pdo->prepare("UPDATE election_settings SET {$column} = :value WHERE id = 1");
        $stmt->execute([':value' => $newSettings[$column]]);

        $log = $pdo->prepare(
            'INSERT INTO audit_log (user_id, action, detail, ip_address)
                  VALUES (:user_id, :action, :detail, :ip)'
        );
        $log->execute([
            ':user_id' => Auth::id(),
            ':action'  => $action,
            ':detail'  => "{$column} = {$newSettings[$column]}",
            ':ip'      => Http::clientIp(),
        ]);

        Session::flash('success', 'Setting updated.');
        Http::redirect('/admin.php');
    }
}

View::render('admin', [
    'settings'   => Election::settings(),
    'isOpen'     => Election::isOpen(),
    'tally'      => Election::tally(),
    'turnout'    => Election::turnout(),
    'candidates' => Election::candidates(),
    'auditLog'   => $pdo->query(
        'SELECT a.created_at, a.action, a.detail, a.ip_address, u.email
           FROM audit_log a
      LEFT JOIN users u ON u.id = a.user_id
       ORDER BY a.id DESC
          LIMIT 20'
    )->fetchAll(),
], 'Administration — LetsVote');
