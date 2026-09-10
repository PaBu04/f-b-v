<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$admin = require_admin();

/** Erzeugt ein gut lesbares Zufallspasswort. */
function generate_password(int $length = 12): string
{
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }

    return $out;
}

$error  = null;
$values = ['username' => '', 'nickname' => '', 'birthdate' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = (string) ($_POST['action'] ?? '');
    $userId = (string) ($_POST['user_id'] ?? '');
    $target = $userId !== '' ? user_by_id($userId) : null;

    if ($action === 'create') {
        $values['username']  = trim((string) ($_POST['username'] ?? ''));
        $values['nickname']  = trim((string) ($_POST['nickname'] ?? ''));
        $values['birthdate'] = trim((string) ($_POST['birthdate'] ?? ''));
        $password  = (string) ($_POST['password'] ?? '');
        $generated = false;

        if ($password === '') {
            $password  = generate_password();
            $generated = true;
        }

        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $values['username'])) {
            $error = 'Benutzername: 3–32 Zeichen, erlaubt sind Buchstaben, Ziffern, Punkt, Bindestrich und Unterstrich.';
        } elseif ($values['nickname'] === '' || mb_strlen($values['nickname']) > NICKNAME_MAX_LENGTH) {
            $error = 'Bitte einen Spitznamen mit höchstens ' . NICKNAME_MAX_LENGTH . ' Zeichen angeben.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['birthdate'])) {
            $error = 'Bitte ein gültiges Geburtsdatum angeben.';
        } elseif (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
            $error = 'Das Passwort muss mindestens ' . PASSWORD_MIN_LENGTH . ' Zeichen lang sein.';
        }

        if ($error === null) {
            try {
                user_create(
                    $values['username'],
                    $values['nickname'],
                    $values['birthdate'],
                    $password,
                    !empty($_POST['is_admin']),
                    true
                );
                flash('success', 'Zugang für „' . $values['nickname'] . '“ angelegt. Startpasswort: ' . $password
                    . ($generated ? ' (automatisch erzeugt)' : '') . ' – bitte persönlich weitergeben.');
                redirect('admin.php');
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'reset_password' && $target !== null) {
        $password = trim((string) ($_POST['password'] ?? ''));
        if ($password === '') {
            $password = generate_password();
        }
        if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
            flash('error', 'Das Passwort muss mindestens ' . PASSWORD_MIN_LENGTH . ' Zeichen lang sein.');
        } else {
            user_update((string) $target['id'], [
                'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
                'must_change_password' => true,
            ]);
            flash('success', 'Neues Passwort für „' . $target['nickname'] . '“: ' . $password);
        }
        redirect('admin.php');
    } elseif ($action === 'update_profile' && $target !== null) {
        $nickname  = trim((string) ($_POST['nickname'] ?? ''));
        $birthdate = trim((string) ($_POST['birthdate'] ?? ''));
        if ($nickname === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
            flash('error', 'Spitzname und Geburtsdatum sind erforderlich.');
        } elseif (mb_strlen($nickname) > NICKNAME_MAX_LENGTH) {
            flash('error', 'Der Spitzname darf höchstens ' . NICKNAME_MAX_LENGTH . ' Zeichen lang sein.');
        } else {
            user_update((string) $target['id'], ['nickname' => $nickname, 'birthdate' => $birthdate]);
            flash('success', 'Profil aktualisiert.');
        }
        redirect('admin.php');
    } elseif ($action === 'toggle_admin' && $target !== null) {
        $makeAdmin = empty($target['is_admin']);
        if (!$makeAdmin && admin_count() <= 1) {
            flash('error', 'Es muss mindestens ein Administrator bleiben.');
        } else {
            user_update((string) $target['id'], ['is_admin' => $makeAdmin]);
            flash('success', $makeAdmin
                ? '„' . $target['nickname'] . '“ ist jetzt Administrator.'
                : 'Administratorrechte für „' . $target['nickname'] . '“ entzogen.');
        }
        redirect('admin.php');
    } elseif ($action === 'delete_user' && $target !== null) {
        if ((string) $target['id'] === (string) $admin['id']) {
            flash('error', 'Du kannst dein eigenes Konto nicht löschen.');
        } elseif (!empty($target['is_admin']) && admin_count() <= 1) {
            flash('error', 'Es muss mindestens ein Administrator bleiben.');
        } else {
            if (!empty($_POST['delete_images'])) {
                foreach (images_all() as $image) {
                    if (($image['user_id'] ?? null) === $target['id']) {
                        image_delete((string) $image['id']);
                    }
                }
            }
            avatar_delete($target);
            likes_remove_for_user((string) $target['id']);
            push_unsubscribe_user((string) $target['id']);
            user_delete((string) $target['id']);
            flash('success', 'Der Zugang wurde gelöscht.');
        }
        redirect('admin.php');
    }
}

$users = users_all();

$imageCount    = [];
$imageOwner    = [];
$likesReceived = [];

foreach (images_all() as $image) {
    $uid = (string) ($image['user_id'] ?? '');
    $imageCount[$uid] = ($imageCount[$uid] ?? 0) + 1;
    $imageOwner[(string) $image['id']] = $uid;
}

// Likes, die die Bilder eines Mitglieds bekommen haben
foreach (likes_all() as $like) {
    $owner = $imageOwner[(string) ($like['image_id'] ?? '')] ?? null;
    if ($owner !== null) {
        $likesReceived[$owner] = ($likesReceived[$owner] ?? 0) + 1;
    }
}

layout_header('Mitgliederverwaltung', $admin);
?>
<h1 class="page-title">Mitgliederverwaltung</h1>

<section class="card">
  <h2>Neuen Zugang anlegen</h2>

  <?php if ($error !== null): ?>
    <div class="flash flash-error"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">

    <div>
      <label for="username">Benutzername</label>
      <input type="text" id="username" name="username" value="<?= h($values['username']) ?>" required autocapitalize="none">
    </div>
    <div>
      <label for="nickname">Spitzname</label>
      <input type="text" id="nickname" name="nickname" value="<?= h($values['nickname']) ?>" required>
    </div>
    <div>
      <label for="birthdate">Geburtsdatum</label>
      <input type="date" id="birthdate" name="birthdate" value="<?= h($values['birthdate']) ?>" required>
    </div>
    <div>
      <label for="password">Startpasswort</label>
      <input type="text" id="password" name="password" placeholder="leer lassen = automatisch">
    </div>
    <div class="form-wide">
      <label class="checkbox"><input type="checkbox" name="is_admin" value="1"> Administrator (darf Mitglieder verwalten und alle Bilder löschen)</label>
      <button type="submit" class="btn btn-primary">Zugang anlegen</button>
    </div>
  </form>
  <p class="muted">Das Mitglied muss das Startpasswort bei der ersten Anmeldung ändern.</p>
</section>

<section class="card">
  <h2>Mitglieder (<?= count($users) ?>)</h2>
  <div class="table-scroll">
    <table class="table">
      <thead>
        <tr>
          <th>Spitzname</th>
          <th>Benutzername</th>
          <th>Geburtstag</th>
          <th>Bilder</th>
          <th>Likes</th>
          <th>Letzte Anmeldung</th>
          <th>Aktionen</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td data-label="">
            <span class="user-cell">
              <?= avatar_html($u, 'avatar-sm') ?>
              <span>
                <strong><?= h((string) $u['nickname']) ?></strong>
                <?php if (!empty($u['is_admin'])): ?><span class="badge">Admin</span><?php endif; ?>
                <?php if (!empty($u['must_change_password'])): ?><span class="badge badge-warn">Startpasswort</span><?php endif; ?>
              </span>
            </span>
          </td>
          <td data-label="Benutzername"><?= h((string) $u['username']) ?></td>
          <td data-label="Geburtstag"><?= h(format_date((string) $u['birthdate'])) ?></td>
          <td data-label="Bilder"><?= (int) ($imageCount[(string) $u['id']] ?? 0) ?></td>
          <td data-label="Likes erhalten"><?= (int) ($likesReceived[(string) $u['id']] ?? 0) ?></td>
          <td data-label="Letzte Anmeldung"><?= h(format_datetime($u['last_login_at'] ?? null)) ?></td>
          <td class="actions">
            <details>
              <summary>Bearbeiten</summary>
              <div class="action-panel">
                <form method="post" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_profile">
                  <input type="hidden" name="user_id" value="<?= h((string) $u['id']) ?>">
                  <input type="text" name="nickname" value="<?= h((string) $u['nickname']) ?>" required aria-label="Spitzname">
                  <input type="date" name="birthdate" value="<?= h((string) $u['birthdate']) ?>" required aria-label="Geburtsdatum">
                  <button type="submit" class="btn btn-sm">Speichern</button>
                </form>

                <form method="post" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reset_password">
                  <input type="hidden" name="user_id" value="<?= h((string) $u['id']) ?>">
                  <input type="text" name="password" placeholder="neues Passwort (leer = automatisch)" aria-label="Neues Passwort">
                  <button type="submit" class="btn btn-sm">Passwort zurücksetzen</button>
                </form>

                <form method="post" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_admin">
                  <input type="hidden" name="user_id" value="<?= h((string) $u['id']) ?>">
                  <button type="submit" class="btn btn-sm"><?= !empty($u['is_admin']) ? 'Adminrechte entziehen' : 'Zum Admin machen' ?></button>
                </form>

                <?php if ((string) $u['id'] !== (string) $admin['id']): ?>
                <form method="post" class="inline-form" data-confirm="Zugang „<?= h((string) $u['nickname']) ?>“ wirklich löschen?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_user">
                  <input type="hidden" name="user_id" value="<?= h((string) $u['id']) ?>">
                  <label class="checkbox"><input type="checkbox" name="delete_images" value="1"> Bilder mitlöschen</label>
                  <button type="submit" class="btn btn-sm btn-danger">Zugang löschen</button>
                </form>
                <?php endif; ?>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php
/*
 * Serverumgebung: zeigt die tatsächlich wirksamen PHP-Werte. Gedacht für die
 * Frage „warum greift mein Upload-Limit nicht?" – die Antwort hängt davon ab,
 * wie PHP betrieben wird.
 */
$sapi        = php_sapi_name();
$istModul    = $sapi === 'apache2handler';
$istFastCgi  = in_array($sapi, ['fpm-fcgi', 'cgi-fcgi', 'litespeed'], true);
$userIniPfad = BASE_DIR . '/.user.ini';
$userIniDa   = is_readable($userIniPfad);
$uploadLimit = ini_bytes((string) ini_get('upload_max_filesize'));

// Wird .user.ini überhaupt eingelesen? max_input_vars = 1234 steht dort als
// Sonde; der Wert wird von Hostern praktisch nie gesperrt.
$sondeName   = trim((string) ini_get('user_ini.filename'));
$sondeGreift = (int) ini_get('max_input_vars') === 1234;
$zuKlein     = $uploadLimit > 0 && $uploadLimit < 10 * 1024 * 1024;
?>
<section class="card">
  <h2>Serverumgebung</h2>
  <p class="muted">Diese Werte stammen aus der laufenden PHP-Konfiguration – sie zeigen,
    was auf diesem Server wirklich gilt, nicht was in einer Datei steht.</p>

  <?php if ($zuKlein): ?>
    <div class="flash flash-error">
      <strong>Das Upload-Limit liegt bei <?= h(format_bytes($uploadLimit)) ?> – für Handyfotos zu wenig.</strong><br>
      <?php if (!$userIniDa): ?>
        Die Datei <code>.user.ini</code> fehlt im Stammverzeichnis – sie muss neben
        <code>index.php</code> liegen.
      <?php elseif ($sondeName === ''): ?>
        Dieser Server liest <code>.user.ini</code> grundsätzlich nicht
        (<code>user_ini.filename</code> ist leer). Das Limit kann nur der Hoster ändern.
      <?php elseif ($istModul): ?>
        PHP läuft als Apache-Modul, dort wirkt <code>.user.ini</code> nie – hier zählt nur der
        <code>php_value</code>-Block in <code>.htaccess</code>.
      <?php elseif ($sondeGreift): ?>
        Die <code>.user.ini</code> <strong>wird gelesen</strong> (die Sonde greift), das
        Upload-Limit ist davon aber ausgenommen. Der Hoster hat es fest verdrahtet
        (<code>php_admin_value</code> in der FPM-Konfiguration) – dagegen kommt keine Datei an.
        <strong>Bitte den Hoster, für diese Domain <code>upload_max_filesize&nbsp;=&nbsp;25M</code>,
        <code>post_max_size&nbsp;=&nbsp;150M</code> und <code>memory_limit&nbsp;=&nbsp;256M</code> zu
        setzen</strong> – oder in Plesk die Berechtigung zum Verwalten der PHP-Einstellungen
        freizugeben.
      <?php else: ?>
        Die <code>.user.ini</code> liegt zwar da, wird aber nicht eingelesen (die Sonde greift
        nicht). Mögliche Gründe: falsches Verzeichnis, fehlende Leserechte für den Webserver,
        oder der Zwischenspeicher ist noch nicht abgelaufen (bis zu
        <?= h((string) (ini_get('user_ini.cache_ttl') ?: '300')) ?> Sekunden).
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <dl class="datalist">
    <dt>PHP-Version</dt><dd><?= h(PHP_VERSION) ?></dd>
    <dt>Betriebsart</dt>
    <dd>
      <?= h($sapi) ?>
      <?php if ($istFastCgi): ?>– <code>.user.ini</code> möglich
      <?php elseif ($istModul): ?>– nur <code>.htaccess</code> wirkt
      <?php endif; ?>
    </dd>
    <dt>Geladene php.ini</dt><dd><?= h(php_ini_loaded_file() ?: 'keine') ?></dd>
    <dt>.user.ini im Stammverzeichnis</dt>
    <dd><?= $userIniDa ? 'vorhanden' : 'fehlt – wurde sie hochgeladen?' ?></dd>
    <dt>Name der Nutzer-ini</dt>
    <dd><?= $sondeName !== '' ? h($sondeName) : 'abgeschaltet – .user.ini wird ignoriert' ?></dd>
    <dt>Sonde aus .user.ini</dt>
    <dd>
      <?= $sondeGreift
          ? 'greift (max_input_vars = 1234) – die Datei wird gelesen'
          : 'greift nicht (max_input_vars = ' . h((string) ini_get('max_input_vars')) . ') – die Datei wird nicht gelesen' ?>
    </dd>
    <dt>Upload je Datei</dt><dd><?= h((string) ini_get('upload_max_filesize')) ?></dd>
    <dt>Datenmenge je Formular</dt><dd><?= h((string) ini_get('post_max_size')) ?></dd>
    <dt>Dateien je Upload</dt><dd><?= h((string) ini_get('max_file_uploads')) ?></dd>
    <dt>Arbeitsspeicher</dt><dd><?= h((string) ini_get('memory_limit')) ?></dd>
    <dt>Laufzeit je Aufruf</dt><dd><?= h((string) ini_get('max_execution_time')) ?> s</dd>
    <dt>Bildverarbeitung (GD)</dt>
    <dd><?= gd_available() ? 'verfügbar' : 'fehlt – keine Vorschau- und Profilbilder' ?></dd>
    <?php $pushFehler = null; $pushKeys = push_keys($pushFehler); ?>
    <dt>Geburtstagsgrüße</dt>
    <dd>
      <?php if (!BIRTHDAY_NOTIFY): ?>
        abgeschaltet (BIRTHDAY_NOTIFY in config.php)
      <?php else: ?>
        <?php $letzterGruss = birthday_last_notice(); ?>
        täglich ab <?= (int) BIRTHDAY_NOTIFY_HOUR ?> Uhr, beim ersten Aufruf der Galerie<br>
        <?= $letzterGruss !== null
            ? 'zuletzt am ' . h(format_date((string) $letzterGruss['date']))
            : 'bisher keiner verschickt' ?>
      <?php endif; ?>
    </dd>
    <dt>Benachrichtigungen</dt>
    <dd>
      <?php if ($pushKeys !== null): ?>
        einsatzbereit, <?= count(push_subscriptions()) ?> Gerät(e) angemeldet
      <?php else: ?>
        nicht möglich – <?= h((string) $pushFehler) ?>
      <?php endif; ?>
    </dd>
    <dt>Versand per</dt>
    <dd><?= function_exists('curl_init') ? 'cURL' : (ini_get('allow_url_fopen') ? 'Streams (cURL fehlt)' : 'nicht möglich – cURL fehlt und allow_url_fopen ist aus') ?></dd>
    <dt>Wirksames Limit in der App</dt><dd><?= h(format_bytes(effective_upload_limit())) ?></dd>
  </dl>
</section>
<?php
layout_footer();
