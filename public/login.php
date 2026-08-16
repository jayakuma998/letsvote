<?php
declare(strict_types=1);

/**
 * Step 1 of the login flow: bounce the browser to the Cognito Hosted UI.
 *
 * We deliberately host no password field ourselves. Passwords, email
 * verification, password reset and (optionally) MFA are all Cognito's job.
 *
 *   /login.php          -> sign-in page
 *   /login.php?new=1    -> sign-up page
 */

require dirname(__DIR__) . '/src/bootstrap.php';

app_start_session();

if (Auth::check()) {
    Http::redirect('/');
}

$wantsSignUp = Http::query('new') === '1';

Http::redirect(Cognito::authorizeUrl($wantsSignUp));
