<?php
declare(strict_types=1);

/**
 * Voter registration.
 *
 * Cognito already proved the person owns their email address. This page
 * collects the election-specific details we need before issuing a ballot.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

app_start_session();
Auth::requireLogin();

$user   = Auth::user();
$errors = [];

$values = [
    'full_name'     => (string) ($user['full_name'] ?? ''),
    'national_id'   => (string) ($user['national_id'] ?? ''),
    'date_of_birth' => (string) ($user['date_of_birth'] ?? ''),
    'region'        => (string) ($user['region'] ?? ''),
    'phone'         => (string) ($user['phone'] ?? ''),
];

if (Http::isPost()) {
    Csrf::verify();

    foreach (array_keys($values) as $field) {
        $values[$field] = Http::post($field);
    }

    if (mb_strlen($values['full_name']) < 3 || mb_strlen($values['full_name']) > 150) {
        $errors['full_name'] = 'Enter your full name as it appears on your ID (3–150 characters).';
    }

    if (!preg_match('/^[A-Za-z0-9\-\/]{5,40}$/', $values['national_id'])) {
        $errors['national_id'] = 'Enter a valid ID number: 5–40 letters, digits, hyphens or slashes.';
    }

    $dob = DateTimeImmutable::createFromFormat('Y-m-d', $values['date_of_birth']);
    if ($dob === false || $dob->format('Y-m-d') !== $values['date_of_birth']) {
        $errors['date_of_birth'] = 'Enter your date of birth as YYYY-MM-DD.';
    } else {
        $age = $dob->diff(new DateTimeImmutable('today'))->y;
        // The voting age in Cameroon is 20.
        if ($age < 20) {
            $errors['date_of_birth'] = 'You must be at least 20 years old to register to vote.';
        } elseif ($age > 120) {
            $errors['date_of_birth'] = 'Please check the date of birth you entered.';
        }
    }

    if (!in_array($values['region'], Election::REGIONS, true)) {
        $errors['region'] = 'Choose the region where you are registering.';
    }

    if ($values['phone'] !== '' && !preg_match('/^\+?[0-9 ]{6,20}$/', $values['phone'])) {
        $errors['phone'] = 'Enter a valid phone number, or leave the field empty.';
    }

    if ($errors === []) {
        try {
            $stmt = Db::write()->prepare(
                'UPDATE users
                    SET full_name = :full_name,
                        national_id = :national_id,
                        date_of_birth = :date_of_birth,
                        region = :region,
                        phone = :phone
                  WHERE id = :id'
            );
            $stmt->execute([
                ':full_name'     => $values['full_name'],
                ':national_id'   => $values['national_id'],
                ':date_of_birth' => $values['date_of_birth'],
                ':region'        => $values['region'],
                ':phone'         => $values['phone'] === '' ? null : $values['phone'],
                ':id'            => (int) $user['id'],
            ]);

            Session::flash('success', 'Voter registration complete. You may now cast your ballot.');
            Http::redirect('/vote.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                // The UNIQUE index on national_id caught a duplicate.
                $errors['national_id'] = 'That ID number is already registered to another account.';
            } else {
                throw $e;
            }
        }
    }
}

View::render('register', [
    'values'    => $values,
    'errors'    => $errors,
    'regions'   => Election::REGIONS,
    'email'     => (string) $user['email'],
    'alreadyRegistered' => Auth::profileComplete(),
], 'Voter registration — LetsVote');
