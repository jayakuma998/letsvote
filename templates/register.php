<?php
/**
 * @var array<string,string> $values
 * @var array<string,string> $errors
 * @var array<int,string>    $regions
 * @var string               $email
 * @var bool                 $alreadyRegistered
 */
?>
<section class="form-page">
    <h1>Voter registration</h1>
    <p class="lede">
        Signed in as <strong><?= e($email) ?></strong>.
        <?= $alreadyRegistered
            ? 'Your details are below — you can still correct them until you vote.'
            : 'These details are needed before a ballot can be issued to you.' ?>
    </p>

    <?php if ($errors !== []): ?>
        <div class="flash flash-error" role="alert">
            Please correct the highlighted fields.
        </div>
    <?php endif; ?>

    <form method="post" action="/register.php" class="stack" novalidate>
        <?= Csrf::field() ?>

        <div class="field <?= isset($errors['full_name']) ? 'has-error' : '' ?>">
            <label for="full_name">Full name (as on your ID)</label>
            <input type="text" id="full_name" name="full_name" maxlength="150" required
                   value="<?= e($values['full_name']) ?>">
            <?php if (isset($errors['full_name'])): ?>
                <p class="error-text"><?= e($errors['full_name']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field <?= isset($errors['national_id']) ? 'has-error' : '' ?>">
            <label for="national_id">National ID number</label>
            <input type="text" id="national_id" name="national_id" maxlength="40" required
                   autocomplete="off" value="<?= e($values['national_id']) ?>">
            <p class="hint">Letters, digits, hyphens and slashes. Each ID can register only once.</p>
            <?php if (isset($errors['national_id'])): ?>
                <p class="error-text"><?= e($errors['national_id']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field <?= isset($errors['date_of_birth']) ? 'has-error' : '' ?>">
            <label for="date_of_birth">Date of birth</label>
            <input type="date" id="date_of_birth" name="date_of_birth" required
                   value="<?= e($values['date_of_birth']) ?>">
            <p class="hint">You must be at least 20 years old.</p>
            <?php if (isset($errors['date_of_birth'])): ?>
                <p class="error-text"><?= e($errors['date_of_birth']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field <?= isset($errors['region']) ? 'has-error' : '' ?>">
            <label for="region">Region</label>
            <select id="region" name="region" required>
                <option value="">Choose a region…</option>
                <?php foreach ($regions as $region): ?>
                    <option value="<?= e($region) ?>" <?= $values['region'] === $region ? 'selected' : '' ?>>
                        <?= e($region) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['region'])): ?>
                <p class="error-text"><?= e($errors['region']) ?></p>
            <?php endif; ?>
        </div>

        <div class="field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
            <label for="phone">Phone number <span class="optional">(optional)</span></label>
            <input type="tel" id="phone" name="phone" maxlength="20"
                   value="<?= e($values['phone']) ?>">
            <?php if (isset($errors['phone'])): ?>
                <p class="error-text"><?= e($errors['phone']) ?></p>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary btn-lg">
            <?= $alreadyRegistered ? 'Save changes' : 'Complete registration' ?>
        </button>
    </form>
</section>
