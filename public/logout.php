<?php
declare(strict_types=1);

/**
 * Logging out has two halves:
 *   1. destroy OUR session row in MySQL, and
 *   2. send the browser to Cognito's /logout so its own cookie is cleared too.
 *
 * Skipping step 2 is a classic bug: the local session disappears, the user
 * clicks "Sign in", and Cognito silently logs them straight back in.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

app_start_session();

if (Http::isPost()) {
    Csrf::verify();
}

Auth::logout();

Http::redirect(Cognito::logoutUrl());
