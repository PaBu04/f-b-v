<?php
declare(strict_types=1);

/**
 * Einmalige Ersteinrichtung: legt den ersten Administrator an.
 * Sobald ein Mitglied existiert, ist diese Seite gesperrt.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (users_exist()) {
    layout_header('Einrichtung', null, true);
    echo '<div class="auth-card"><div class="auth-head"><h1>Bereits eingerichtet</h1>'
        . '<p>Es existieren bereits Mitgliedskonten.</p></div>'
        . '<p class="auth-hint">Neue Zugänge legt ein Administrator unter &bdquo;Mitglieder&ldquo; an. '
        . 'Diese Datei kann gelöscht werden.</p>'
        . '<a class="btn btn-primary btn-block" href="login.php">Zur Anmeldung</a></div>';
    layout_footer();
    exit;
}

$error  = null;
$values = ['username' => '', 'nickname' => '', 'birthdate' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $values['username']  = trim((string) ($_POST['username'] ?? ''));
    $values['nickname']  = trim((string) ($_POST['nickname'] ?? ''));
    $values['birthdate'] = trim((string) ($_POST['birthdate'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $repeat   = (string) ($_POST['password_repeat'] ?? '');

    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $values['username'])) {
        $error = 'Benutzername: 3–32 Zeichen, erlaubt sind Buchstaben, Ziffern, Punkt, Bindestrich und Unterstrich.';
    } elseif ($values['nickname'] === '' || mb_strlen($values['nickname']) > NICKNAME_MAX_LENGTH) {
        $error = 'Bitte einen Spitznamen mit höchstens ' . NICKNAME_MAX_LENGTH . ' Zeichen angeben.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['birthdate'])) {
        $error = 'Bitte ein gültiges Geburtsdatum angeben.';
    } else {
        $error = password_problem($password, $repeat);
    }

    if ($error === null) {
        try {
            $user = user_create(
                $values['username'],
                $values['nickname'],
                $values['birthdate'],
                $password,
                true,
                false
            );
            auth_login($user);
            flash('success', 'Willkommen! Der Administrator-Zugang wurde angelegt.');
            redirect('index.php');
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

layout_header('Einrichtung', null, true);
?>
<div class="auth-card">
  <div class="auth-head">
    <h1>Ersteinrichtung</h1>
    <p>Lege den ersten Administrator an.</p>
  </div>

  <?php if ($error !== null): ?>
    <div class="flash flash-error"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <?= csrf_field() ?>
    <label for="username">Benutzername</label>
    <input type="text" id="username" name="username" value="<?= h($values['username']) ?>" required autofocus autocapitalize="none">

    <label for="nickname">Spitzname</label>
    <input type="text" id="nickname" name="nickname" value="<?= h($values['nickname']) ?>" maxlength="<?= NICKNAME_MAX_LENGTH ?>" required>

    <label for="birthdate">Geburtsdatum</label>
    <input type="date" id="birthdate" name="birthdate" value="<?= h($values['birthdate']) ?>" required>

    <label for="password">Passwort</label>
    <input type="password" id="password" name="password" required autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>">

    <label for="password_repeat">Passwort wiederholen</label>
    <input type="password" id="password_repeat" name="password_repeat" required autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>">

    <button type="submit" class="btn btn-primary btn-block">Administrator anlegen</button>
  </form>

  <p class="auth-hint">Nach der Einrichtung sollte diese Datei vom Server gelöscht werden.</p>
</div>
<?php
layout_footer();
