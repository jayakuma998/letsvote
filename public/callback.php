<?php
declare(strict_types=1);

/**
 * Step 2 of the login flow: Cognito sends the browser back here with a code.
 *
 * This exact URL must be listed as an "Allowed callback URL" on the Cognito
 * app client, e.g. https://letsvote.example/callback.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

app_start_session();

// Cognito reports failures in the query string rather than as an HTTP error.
$error = Http::query('error');
if ($error !== '') {
    error_log('letsvote: cognito returned error=' . $error . ' description=' . Http::query('error_description'));
    Session::flash('error', 'Sign-in was cancelled or refused. Please try again.');
    Http::redirect('/');
}

$code  = Http::query('code');
$state = Http::query('state');

$expectedState = Session::pull('oauth_state');
$expectedNonce = Session::pull('oauth_nonce');
$codeVerifier  = Session::pull('oauth_code_verifier');

if ($code === '' || !is_string($expectedState) || !is_string($expectedNonce) || !is_string($codeVerifier)) {
    Session::flash('error', 'Your sign-in link expired. Please start again.');
    Http::redirect('/login.php');
}

// The state check proves this redirect answers a login *we* started.
if (!hash_equals($expectedState, $state)) {
    error_log('letsvote: oauth state mismatch from ip=' . Http::clientIp());
    Session::flash('error', 'Sign-in could not be verified. Please try again.');
    Http::redirect('/login.php');
}

try {
    $tokens = Cognito::exchangeCode($code, $codeVerifier);
    $claims = Cognito::verifyIdToken($tokens['id_token'], $expectedNonce);
} catch (Throwable $e) {
    error_log('letsvote: token exchange/verification failed: ' . $e->getMessage());
    Session::flash('error', 'We could not complete your sign-in. Please try again.');
    Http::redirect('/');
}

Auth::loginFromClaims($claims);

// New users still have to register as voters before they can be issued a ballot.
if (!Auth::profileComplete()) {
    Session::flash('info', 'Welcome! One more step: complete your voter registration.');
    Http::redirect('/register.php');
}

$intended = Session::pull('intended_url');
Session::flash('success', 'You are signed in.');

Http::redirect(is_string($intended) && str_starts_with($intended, '/') ? $intended : '/');
