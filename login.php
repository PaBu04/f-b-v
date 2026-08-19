<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (current_user() !== null) {
    redirect('index.php');
}

if (!users_exist()) {
    redirect('setup.php');
}

$error    = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Bitte Benutzername und Passwort eingeben.';
    } else {
        list($user, $error) = auth_attempt($username, $password);
        if ($user !== null) {
            $target = (string) ($_SESSION['redirect_after_login'] ?? 'index.php');
            unset($_SESSION['redirect_after_login']);
            auth_login($user);
            if (!preg_match('/^[a-z0-9_.-]+\.php(\?[^\r\n]*)?$/i', $target)) {
                $target = 'index.php';
            }
            redirect($target);
        }
    }
}

layout_header('Anmelden', null, true);
?>
<div class="auth-card">
  <div class="auth-head">
    <h1><?= h(APP_NAME) ?></h1>
    <p><?= h(app_tagline()) ?></p>
  </div>

  <?php if ($error !== null): ?>
    <div class="flash flash-error"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <label for="username">Benutzername</label>
    <input type="text" id="username" name="username" value="<?= h($username) ?>" required autofocus autocapitalize="none" autocomplete="username">

    <label for="password">Passwort</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">

    <button type="submit" class="btn btn-primary btn-block">Anmelden</button>
  </form>

  <p class="auth-hint">Zugänge werden vom Administrator vergeben.</p>
</div>
<?php
layout_footer();
