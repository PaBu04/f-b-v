<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user          = require_login();
$mustChange    = !empty($user['must_change_password']);
$profileError  = null;
$passwordError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bei Überschreitung von post_max_size ist $_POST komplett leer
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'Die Daten waren zu groß (Serverlimit: ' . format_bytes(ini_bytes((string) ini_get('post_max_size'))) . ').');
        redirect('account.php');
    }

    csrf_require();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'profile') {
        $nickname = trim((string) ($_POST['nickname'] ?? ''));
        $changes  = [];

        if ($nickname === '') {
            $profileError = 'Bitte einen Spitznamen angeben.';
        } elseif (mb_strlen($nickname) > NICKNAME_MAX_LENGTH) {
            $profileError = 'Der Spitzname darf höchstens ' . NICKNAME_MAX_LENGTH . ' Zeichen lang sein.';
        } elseif ($nickname !== (string) $user['nickname']) {
            $changes['nickname'] = $nickname;
        }

        $avatarUploaded = isset($_FILES['avatar']) && (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($profileError === null && $avatarUploaded) {
            list($filename, $avatarError) = avatar_store($_FILES['avatar'], $user);
            if ($filename === null) {
                $profileError = $avatarError;
            } else {
                $changes['avatar'] = $filename;
            }
        }

        if ($profileError === null) {
            if ($changes !== []) {
                user_update((string) $user['id'], $changes);
                flash('success', 'Dein Profil wurde gespeichert.');
            }
            redirect('account.php');
        }
    } elseif ($action === 'avatar_remove') {
        avatar_delete($user);
        user_update((string) $user['id'], ['avatar' => '']);
        flash('success', 'Dein Profilbild wurde entfernt.');
        redirect('account.php');
    } elseif ($action === 'notify') {
        user_update((string) $user['id'], [
            'notify' => [
                'uploads'   => !empty($_POST['notify_uploads']),
                'likes'     => !empty($_POST['notify_likes']),
                'birthdays' => !empty($_POST['notify_birthdays']),
            ],
        ]);
        flash('success', 'Deine Benachrichtigungen wurden gespeichert.');
        redirect('account.php');
    } elseif ($action === 'notify_off') {
        push_unsubscribe_user((string) $user['id']);
        flash('success', 'Alle Geräte wurden abgemeldet.');
        redirect('account.php');
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $repeat  = (string) ($_POST['new_password_repeat'] ?? '');

        if (!password_verify($current, (string) $user['password_hash'])) {
            $passwordError = 'Das aktuelle Passwort ist nicht korrekt.';
        } else {
            $passwordError = password_problem($new, $repeat);
            if ($passwordError === null && $new === $current) {
                $passwordError = 'Bitte wähle ein anderes als das bisherige Passwort.';
            }
        }

        if ($passwordError === null) {
            user_update((string) $user['id'], [
                'password_hash'        => password_hash($new, PASSWORD_DEFAULT),
                'must_change_password' => false,
            ]);
            session_regenerate_id(true);
            flash('success', 'Dein Passwort wurde geändert.');
            redirect('account.php');
        }
    }
}

$myImages     = 0;
$myImageIds   = [];
foreach (images_all() as $image) {
    if (($image['user_id'] ?? null) === $user['id']) {
        $myImages++;
        $myImageIds[(string) $image['id']] = true;
    }
}

$likesReceived = 0;
$likesGiven    = 0;
foreach (likes_all() as $like) {
    if (isset($myImageIds[(string) ($like['image_id'] ?? '')])) {
        $likesReceived++;
    }
    if ((string) ($like['user_id'] ?? '') === (string) $user['id']) {
        $likesGiven++;
    }
}

$avatarLimit = min(MAX_AVATAR_BYTES, effective_upload_limit());

$einstellungen = is_array($user['notify'] ?? null) ? $user['notify'] : [];
$notifyUploads   = !array_key_exists('uploads', $einstellungen) || !empty($einstellungen['uploads']);
$notifyLikes     = !array_key_exists('likes', $einstellungen) || !empty($einstellungen['likes']);
$notifyBirthdays = !array_key_exists('birthdays', $einstellungen) || !empty($einstellungen['birthdays']);
$pushFehler    = null;
$pushKeys      = push_keys($pushFehler);
$geraete       = count(push_subscriptions((string) $user['id']));

layout_header('Mein Konto', $user, true);
?>
<h1 class="page-title">Mein Konto</h1>

<?php if ($mustChange): ?>
  <div class="flash flash-info">Dein Zugang wurde mit einem vorläufigen Passwort angelegt. Bitte vergib jetzt ein eigenes.</div>
<?php endif; ?>

<section class="card">
  <h2>Profil</h2>

  <?php if ($profileError !== null): ?>
    <div class="flash flash-error"><?= h($profileError) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="profile">

    <div class="profile-edit">
      <div class="profile-avatar">
        <?= avatar_html($user, 'avatar-xl') ?>
        <label class="btn btn-sm" for="avatar">Bild wählen</label>
        <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" class="visually-hidden" data-avatar-input>
        <span class="muted" id="avatar-name">JPG, PNG, GIF oder WEBP<br>max. <?= h(format_bytes($avatarLimit)) ?></span>
      </div>

      <div class="profile-fields">
        <label for="nickname">Spitzname</label>
        <input type="text" id="nickname" name="nickname" value="<?= h((string) $user['nickname']) ?>" maxlength="<?= NICKNAME_MAX_LENGTH ?>" required>
        <p class="muted">Unter diesem Namen erscheinen deine Bilder in der Galerie.</p>

        <button type="submit" class="btn btn-primary">Profil speichern</button>
      </div>
    </div>
  </form>

  <?php if (user_has_avatar($user)): ?>
    <form method="post" class="mt">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="avatar_remove">
      <button type="submit" class="btn btn-sm btn-danger">Profilbild entfernen</button>
    </form>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Meine Daten</h2>
  <dl class="datalist">
    <dt>Benutzername</dt><dd><?= h((string) $user['username']) ?></dd>
    <dt>Geburtsdatum</dt><dd><?= h(format_date((string) $user['birthdate'])) ?></dd>
    <dt>Rolle</dt><dd><?= !empty($user['is_admin']) ? 'Administrator' : 'Mitglied' ?></dd>
    <dt>Meine Bilder</dt><dd><?= (int) $myImages ?></dd>
    <dt>Likes erhalten</dt><dd><?= (int) $likesReceived ?></dd>
    <dt>Likes vergeben</dt><dd><?= (int) $likesGiven ?></dd>
    <dt>Dabei seit</dt><dd><?= h(format_date((string) $user['created_at'])) ?></dd>
  </dl>
  <p class="muted">Benutzername und Geburtsdatum ändert ein Administrator.</p>
</section>

<section class="card"
         id="push-card"
         data-vapid="<?= h($pushKeys['public'] ?? '') ?>"
         data-csrf="<?= h(csrf_token()) ?>"
         data-devices="<?= (int) $geraete ?>">
  <h2>Benachrichtigungen</h2>

  <?php if ($pushKeys === null): ?>
    <div class="flash flash-error">
      Benachrichtigungen sind auf diesem Server nicht möglich.
      <?= $pushFehler !== null ? h($pushFehler) : '' ?>
    </div>
  <?php else: ?>
    <p class="muted">Benachrichtigungen kommen auf jedem Gerät einzeln an – schalte sie dort ein,
      wo du sie haben möchtest. Aktuell angemeldet: <strong id="push-devices"><?= (int) $geraete ?></strong>
      <?= $geraete === 1 ? 'Gerät' : 'Geräte' ?>.</p>

    <div class="push-status" id="push-status" hidden></div>

    <p>
      <button type="button" class="btn btn-primary" id="push-toggle" hidden>Auf diesem Gerät einschalten</button>
    </p>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="notify">
      <label class="checkbox">
        <input type="checkbox" name="notify_uploads" value="1" <?= $notifyUploads ? 'checked' : '' ?>>
        Wenn jemand neue Bilder hochlädt
      </label>
      <label class="checkbox">
        <input type="checkbox" name="notify_likes" value="1" <?= $notifyLikes ? 'checked' : '' ?>>
        Wenn jemandem eines meiner Bilder gefällt
      </label>
      <label class="checkbox">
        <input type="checkbox" name="notify_birthdays" value="1" <?= $notifyBirthdays ? 'checked' : '' ?>>
        Wenn jemand Geburtstag hat
      </label>
      <button type="submit" class="btn">Auswahl speichern</button>
    </form>

    <?php if ($geraete > 0): ?>
      <form method="post" class="mt" onsubmit="return confirm('Alle Geräte abmelden?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="notify_off">
        <button type="submit" class="btn btn-sm btn-danger">Alle Geräte abmelden</button>
      </form>
    <?php endif; ?>

    <p class="muted mt">
      <strong>Auf dem iPhone</strong> funktionieren Benachrichtigungen nur, wenn die Seite über
      „Teilen → Zum Home-Bildschirm" abgelegt und von dort geöffnet wird (ab iOS 16.4).
      Auf Android genügt der Browser.
    </p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Passwort ändern</h2>

  <?php if ($passwordError !== null): ?>
    <div class="flash flash-error"><?= h($passwordError) ?></div>
  <?php endif; ?>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password">

    <label for="current_password">Aktuelles Passwort</label>
    <input type="password" id="current_password" name="current_password" required autocomplete="current-password" <?= $mustChange ? 'autofocus' : '' ?>>

    <label for="new_password">Neues Passwort</label>
    <input type="password" id="new_password" name="new_password" required autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>">

    <label for="new_password_repeat">Neues Passwort wiederholen</label>
    <input type="password" id="new_password_repeat" name="new_password_repeat" required autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>">

    <p class="muted">Mindestens <?= PASSWORD_MIN_LENGTH ?> Zeichen.</p>
    <button type="submit" class="btn btn-primary">Passwort speichern</button>
  </form>
</section>
<?php
layout_footer(true);
