<?php
declare(strict_types=1);

/**
 * Stammtisch: Liste der wöchentlichen Essen und die Punktetabelle.
 *
 * Anlegen darf jedes Mitglied, ändern und löschen nur, wer den Eintrag
 * verfasst hat – oder die Verwaltung.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user   = require_login();
$fehler = null;

/** Leeres Formular: heutiges Datum, sonst nichts. */
$formular = [
    'id'          => '',
    'date'        => date('Y-m-d'),
    'dish'        => '',
    'host_id'     => '',
    'cook_ids'    => [],
    'shopper_ids' => [],
    'washer_ids'  => [],
    'image_id'    => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $action = (string) ($_POST['action'] ?? '');
    $id     = (string) ($_POST['id'] ?? '');
    $meal   = $id !== '' ? meal_by_id($id) : null;

    $eingabe = [
        'date'        => (string) ($_POST['date'] ?? ''),
        'dish'        => (string) ($_POST['dish'] ?? ''),
        'host_id'     => (string) ($_POST['host_id'] ?? ''),
        'cook_ids'    => stammtisch_post_ids('cook_ids'),
        'shopper_ids' => stammtisch_post_ids('shopper_ids'),
        'washer_ids'  => stammtisch_post_ids('washer_ids'),
        'image_id'    => (string) ($_POST['image_id'] ?? ''),
    ];

    if ($action === 'create') {
        $fehler = meal_problem($eingabe);
        if ($fehler === null) {
            meal_create($eingabe, (string) $user['id']);
            flash('success', 'Der Eintrag wurde gespeichert.');
            redirect('stammtisch.php');
        }
        $formular = $eingabe + ['id' => ''];
    } elseif ($action === 'update' && $meal !== null) {
        if (!meal_may_edit($meal, $user)) {
            flash('error', 'Diesen Eintrag darf nur ändern, wer ihn angelegt hat.');
            redirect('stammtisch.php');
        }
        $fehler = meal_problem($eingabe);
        if ($fehler === null) {
            meal_update($id, $eingabe);
            flash('success', 'Der Eintrag wurde geändert.');
            redirect('stammtisch.php');
        }
        $formular = $eingabe + ['id' => $id];
    } elseif ($action === 'delete' && $meal !== null) {
        if (!meal_may_edit($meal, $user)) {
            flash('error', 'Diesen Eintrag darf nur löschen, wer ihn angelegt hat.');
        } else {
            meal_delete($id);
            flash('success', 'Der Eintrag wurde gelöscht.');
        }
        redirect('stammtisch.php');
    } else {
        redirect('stammtisch.php');
    }
}

// Bearbeiten: Formular mit den Werten des Eintrags füllen
$bearbeiten = (string) ($_GET['bearbeiten'] ?? '');
if ($bearbeiten !== '' && $fehler === null) {
    $meal = meal_by_id($bearbeiten);
    if ($meal === null) {
        flash('error', 'Den Eintrag gibt es nicht mehr.');
        redirect('stammtisch.php');
    }
    if (!meal_may_edit($meal, $user)) {
        flash('error', 'Diesen Eintrag darf nur ändern, wer ihn angelegt hat.');
        redirect('stammtisch.php');
    }
    $formular = [
        'id'          => (string) $meal['id'],
        'date'        => (string) ($meal['date'] ?? ''),
        'dish'        => (string) ($meal['dish'] ?? ''),
        'host_id'     => (string) ($meal['host_id'] ?? ''),
        'cook_ids'    => meal_role_ids($meal, 'cook_ids'),
        'shopper_ids' => meal_role_ids($meal, 'shopper_ids'),
        'washer_ids'  => meal_role_ids($meal, 'washer_ids'),
        'image_id'    => (string) ($meal['image_id'] ?? ''),
    ];
}
$istBearbeitung = (string) $formular['id'] !== '';

$mitglieder = users_all();
$usersById  = users_by_id_map();
$meals      = meals_all();
$bilder     = images_all();

// Saison: Jahr aus der Adresszeile, sonst das jüngste Jahr mit Einträgen
$saisons  = meal_seasons();
$gewaehlt = (string) ($_GET['saison'] ?? '');
if ($gewaehlt === 'alle') {
    $saison = null;
} elseif (in_array($gewaehlt, $saisons, true)) {
    $saison = $gewaehlt;
} else {
    $saison = $saisons[0] ?? date('Y');
}

$tabelle = meals_scoreboard($saison);
$podium  = array_slice($tabelle, 0, 3);

// Eigene Zeile für den Fortschrittshinweis
$eigene = null;
foreach ($tabelle as $zeile) {
    if ($zeile['user_id'] === (string) $user['id']) {
        $eigene = $zeile;
        break;
    }
}

/**
 * Angekreuzte Mitglieder eines Formularfeldes, nur als Zeichenketten.
 *
 * @return array<int, string>
 */
function stammtisch_post_ids(string $feld): array
{
    $roh = $_POST[$feld] ?? null;
    if (!is_array($roh)) {
        return [];
    }

    $ids = [];
    foreach ($roh as $id) {
        if (is_scalar($id)) {
            $ids[] = (string) $id;
        }
    }

    return $ids;
}

/** Namen einer Rolle als HTML, mit Profilbild. */
function stammtisch_rolle(array $ids, array $usersById): string
{
    if ($ids === []) {
        return '<span class="muted">–</span>';
    }

    $teile = [];
    foreach ($ids as $id) {
        $mitglied = $usersById[$id] ?? null;
        if ($mitglied === null) {
            continue;
        }
        $teile[] = '<span class="meal-person">' . avatar_html($mitglied, 'avatar-xs')
            . h((string) $mitglied['nickname']) . '</span>';
    }

    return $teile === [] ? '<span class="muted">–</span>' : implode(' ', $teile);
}

layout_header('Stammtisch', $user);
?>
<h1 class="page-title">Stammtisch</h1>

<section class="card">
  <div class="board-head">
    <h2>Rangliste</h2>
    <div class="filters">
      <?php foreach ($saisons as $jahr): ?>
        <a href="stammtisch.php?saison=<?= h($jahr) ?>" class="chip<?= $saison === $jahr ? ' chip-active' : '' ?>"><?= h($jahr) ?></a>
      <?php endforeach; ?>
      <a href="stammtisch.php?saison=alle" class="chip<?= $saison === null ? ' chip-active' : '' ?>">Ewige Tabelle</a>
    </div>
  </div>

  <?php if ($tabelle === []): ?>
    <div class="empty">
      <p>Noch keine Punkte vergeben.</p>
      <p class="muted">Trag unten das erste Essen ein.</p>
    </div>
  <?php else: ?>
    <ol class="podium">
      <?php foreach ($podium as $rang => $zeile): ?>
        <?php $mitglied = $usersById[$zeile['user_id']] ?? null; ?>
        <li class="podium-platz podium-<?= $rang + 1 ?>">
          <span class="podium-rang" aria-hidden="true"><?= ['&#129351;', '&#129352;', '&#129353;'][$rang] ?></span>
          <?= $mitglied !== null ? avatar_html($mitglied, 'avatar-lg') : '' ?>
          <span class="podium-name"><?= h($zeile['nickname']) ?></span>
          <span class="podium-punkte"><?= h(meal_format_points((float) $zeile['punkte'])) ?> P</span>
          <span class="podium-stufe"><?= h($zeile['stufe']) ?></span>
        </li>
      <?php endforeach; ?>
    </ol>

    <div class="table-scroll">
      <table class="table">
        <thead>
          <tr>
            <th>#</th>
            <th>Mitglied</th>
            <th>Stufe</th>
            <th title="Am Herd">Kochen</th>
            <th title="Zutaten gekauft">Einkauf</th>
            <th title="Nach dem Essen abgespült">Spülen</th>
            <th title="Küche gestellt">Gastgeber</th>
            <th title="Längste Serie in Folge">Serie</th>
            <th title="Serien- und Allrounder-Bonus">Bonus</th>
            <th>Punkte</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tabelle as $rang => $zeile): ?>
            <?php $mitglied = $usersById[$zeile['user_id']] ?? null; ?>
            <tr<?= $zeile['user_id'] === (string) $user['id'] ? ' class="is-me"' : '' ?>>
              <td data-label="Platz"><?= $rang + 1 ?></td>
              <td data-label="">
                <span class="user-cell">
                  <?= $mitglied !== null ? avatar_html($mitglied, 'avatar-sm') : '' ?>
                  <span>
                    <?= h($zeile['nickname']) ?>
                    <?php foreach ($zeile['abzeichen'] as $schluessel): ?>
                      <span class="badge badge-warn" title="<?= h(MEAL_BADGES[$schluessel][1]) ?>"><?= h(MEAL_BADGES[$schluessel][0]) ?></span>
                    <?php endforeach; ?>
                    <?php if ($zeile['allrounder']): ?>
                      <span class="badge" title="alle vier Rollen in dieser Saison">Allrounder</span>
                    <?php endif; ?>
                  </span>
                </span>
              </td>
              <td data-label="Stufe"><?= h($zeile['stufe']) ?></td>
              <td data-label="Kochen"><?= (int) $zeile['koch'] ?></td>
              <td data-label="Einkauf"><?= (int) $zeile['einkauf'] ?></td>
              <td data-label="Spülen"><?= (int) $zeile['spuelen'] ?></td>
              <td data-label="Gastgeber"><?= (int) $zeile['gastgeber'] ?></td>
              <td data-label="Längste Serie"><?= (int) $zeile['serie'] ?></td>
              <td data-label="Bonus"><?= $zeile['bonus'] > 0 ? '+' . h(meal_format_points((float) $zeile['bonus'])) : '–' ?></td>
              <td data-label="Punkte"><strong><?= h(meal_format_points((float) $zeile['punkte'])) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($eigene !== null): ?>
      <?php $naechste = meal_next_level((float) $eigene['punkte']); ?>
      <p class="muted mt">
        Du stehst bei <strong><?= h(meal_format_points((float) $eigene['punkte'])) ?> Punkten</strong>
        als <strong><?= h($eigene['stufe']) ?></strong><?php if ($naechste !== null): ?>,
        noch <?= h(meal_format_points($naechste['punkte'] - (float) $eigene['punkte'])) ?> bis
        <strong><?= h($naechste['titel']) ?></strong><?php endif; ?>.
      </p>
    <?php endif; ?>
  <?php endif; ?>

  <details class="regeln">
    <summary>Wie die Punkte zustande kommen</summary>
    <ul>
      <li><strong><?= h(meal_format_points(MEAL_POINTS_COOK)) ?> Punkte</strong> fürs Kochen –
        geteilt, wenn mehrere am Herd standen. Hängt ein Foto am Eintrag, sind es
        <?= h(meal_format_points(MEAL_POINTS_COOK + MEAL_POINTS_PHOTO)) ?>.</li>
      <li><strong><?= h(meal_format_points(MEAL_POINTS_SHOPPING)) ?> Punkte</strong> für den Einkauf –
        ebenfalls geteilt.</li>
      <li><strong><?= h(meal_format_points(MEAL_POINTS_WASHING)) ?> Punkte</strong> fürs Abspülen –
        auch die werden unter allen am Spülbecken geteilt.</li>
      <li><strong><?= h(meal_format_points(MEAL_POINTS_HOST)) ?> Punkte</strong> für die Küche, in der gekocht wurde.</li>
      <li><strong>+<?= h(meal_format_points(MEAL_POINTS_STREAK)) ?></strong> für je
        <?= MEAL_STREAK_LENGTH ?> Stammtische in Folge, an denen du beteiligt warst.</li>
      <li><strong>+<?= h(meal_format_points(MEAL_POINTS_ALLROUND)) ?></strong> als Allrounder:
        in einer Saison einmal gekocht, einmal eingekauft, einmal abgespült und
        einmal die Küche gestellt.</li>
      <li>Stufen:
        <?php $stufen = []; foreach (MEAL_LEVELS as $stufe) { $stufen[] = h($stufe[1]) . ' ab ' . h(meal_format_points($stufe[0])); } ?>
        <?= implode(' · ', $stufen) ?>.</li>
      <li>Abzeichen je Saison:
        <?php $namen = []; foreach (MEAL_BADGES as $abzeichen) { $namen[] = h($abzeichen[0]) . ' (' . h($abzeichen[1]) . ')'; } ?>
        <?= implode(' · ', $namen) ?>.</li>
    </ul>
  </details>
</section>

<section class="card" id="formular">
  <h2><?= $istBearbeitung ? 'Eintrag bearbeiten' : 'Essen eintragen' ?></h2>

  <?php if ($fehler !== null): ?>
    <div class="flash flash-error"><?= h($fehler) ?></div>
  <?php endif; ?>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $istBearbeitung ? 'update' : 'create' ?>">
    <input type="hidden" name="id" value="<?= h((string) $formular['id']) ?>">

    <div>
      <label for="dish">Was gab es?</label>
      <input type="text" id="dish" name="dish" maxlength="<?= MEAL_DISH_MAX_LENGTH ?>"
             value="<?= h((string) $formular['dish']) ?>" required placeholder="z. B. Chili sin Carne">
    </div>
    <div>
      <label for="date">Wann war der Stammtisch?</label>
      <input type="date" id="date" name="date" value="<?= h((string) $formular['date']) ?>" required>
    </div>

    <div>
      <label for="host_id">Bei wem wurde gekocht?</label>
      <select id="host_id" name="host_id">
        <option value="">– niemand angegeben –</option>
        <?php foreach ($mitglieder as $mitglied): ?>
          <option value="<?= h((string) $mitglied['id']) ?>"<?= (string) $formular['host_id'] === (string) $mitglied['id'] ? ' selected' : '' ?>>
            <?= h((string) $mitglied['nickname']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="image_id">Bild aus der Galerie</label>
      <select id="image_id" name="image_id" data-image-picker>
        <option value="">– kein Bild –</option>
        <?php foreach ($bilder as $bild): ?>
          <?php
            $bildId   = (string) $bild['id'];
            $wer      = $usersById[(string) ($bild['user_id'] ?? '')]['nickname']
                ?? (string) ($bild['uploader_nickname'] ?? 'Unbekannt');
            $text     = trim((string) ($bild['caption'] ?? ''));
            if ($text === '') {
                $text = (string) ($bild['original_name'] ?? 'Bild');
            }
            $vorschau = 'image.php?id=' . urlencode($bildId) . (($bild['thumb'] ?? '') !== '' ? '&size=thumb' : '');
          ?>
          <option value="<?= h($bildId) ?>" data-thumb="<?= h($vorschau) ?>"<?= (string) $formular['image_id'] === $bildId ? ' selected' : '' ?>>
            <?= h(mb_substr($text, 0, 60)) ?> · <?= h((string) $wer) ?> · <?= h(format_date((string) ($bild['uploaded_at'] ?? ''))) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="meal-preview" id="meal-preview" hidden><img src="" alt="Vorschau des verknüpften Bildes"></p>
    </div>

    <fieldset class="form-wide">
      <legend>Wer hat gekocht? <span class="muted">(Verantwortung am Herd, Punkte werden geteilt)</span></legend>
      <div class="person-picker">
        <?php foreach ($mitglieder as $mitglied): ?>
          <label class="checkbox">
            <input type="checkbox" name="cook_ids[]" value="<?= h((string) $mitglied['id']) ?>"
                   <?= in_array((string) $mitglied['id'], $formular['cook_ids'], true) ? 'checked' : '' ?>>
            <?= h((string) $mitglied['nickname']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <fieldset class="form-wide">
      <legend>Wer hat eingekauft? <span class="muted">(Punkte werden geteilt)</span></legend>
      <div class="person-picker">
        <?php foreach ($mitglieder as $mitglied): ?>
          <label class="checkbox">
            <input type="checkbox" name="shopper_ids[]" value="<?= h((string) $mitglied['id']) ?>"
                   <?= in_array((string) $mitglied['id'], $formular['shopper_ids'], true) ? 'checked' : '' ?>>
            <?= h((string) $mitglied['nickname']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <fieldset class="form-wide">
      <legend>Wer hat abgespült? <span class="muted">(Punkte werden geteilt)</span></legend>
      <div class="person-picker">
        <?php foreach ($mitglieder as $mitglied): ?>
          <label class="checkbox">
            <input type="checkbox" name="washer_ids[]" value="<?= h((string) $mitglied['id']) ?>"
                   <?= in_array((string) $mitglied['id'], $formular['washer_ids'], true) ? 'checked' : '' ?>>
            <?= h((string) $mitglied['nickname']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="form-wide">
      <button type="submit" class="btn btn-primary"><?= $istBearbeitung ? 'Änderung speichern' : 'Eintrag speichern' ?></button>
      <?php if ($istBearbeitung): ?>
        <a class="btn" href="stammtisch.php">Abbrechen</a>
      <?php endif; ?>
    </div>
  </form>
  <p class="muted">Auswählbar sind nur registrierte Mitglieder. Gericht, Datum und mindestens
    eine Person am Herd sind Pflicht.</p>
</section>

<section class="gallery-head">
  <h1>Gekocht <span class="count"><?= count($meals) ?></span></h1>
</section>

<?php if ($meals === []): ?>
  <div class="empty">
    <p>Noch nichts eingetragen.</p>
    <p class="muted">Der erste Eintrag entsteht oben im Formular.</p>
  </div>
<?php else: ?>
  <?php $woche = null; ?>
  <?php foreach ($meals as $meal): ?>
    <?php $schluessel = meal_week_key((string) ($meal['date'] ?? '')); ?>
    <?php if ($schluessel !== $woche): ?>
      <?php $woche = $schluessel; ?>
      <h2 class="week-head"><?= h(meal_week_label((string) ($meal['date'] ?? ''))) ?></h2>
    <?php endif; ?>
    <?php
      $punkte = meal_points_of($meal);
      $bild   = (string) ($meal['image_id'] ?? '') !== '' ? image_by_id((string) $meal['image_id']) : null;
      $autor  = $usersById[(string) ($meal['created_by'] ?? '')]['nickname'] ?? 'Unbekannt';
    ?>
    <article class="meal-card<?= (string) $formular['id'] === (string) $meal['id'] ? ' is-editing' : '' ?>">
      <?php if ($bild !== null): ?>
        <a class="meal-image" href="image.php?id=<?= h((string) $bild['id']) ?>">
          <img src="image.php?id=<?= h((string) $bild['id']) ?><?= ($bild['thumb'] ?? '') !== '' ? '&amp;size=thumb' : '' ?>"
               alt="<?= h((string) ($bild['caption'] ?? 'Bild zum Essen')) ?>" loading="lazy">
        </a>
      <?php endif; ?>

      <div class="meal-body">
        <div class="meal-head">
          <h3><?= h((string) ($meal['dish'] ?? '')) ?></h3>
          <span class="muted"><?= h(format_date((string) ($meal['date'] ?? ''))) ?></span>
        </div>

        <dl class="meal-roles">
          <dt>Gekocht</dt><dd><?= stammtisch_rolle(meal_role_ids($meal, 'cook_ids'), $usersById) ?></dd>
          <dt>Eingekauft</dt><dd><?= stammtisch_rolle(meal_role_ids($meal, 'shopper_ids'), $usersById) ?></dd>
          <dt>Abgespült</dt><dd><?= stammtisch_rolle(meal_role_ids($meal, 'washer_ids'), $usersById) ?></dd>
          <dt>Küche</dt><dd><?= stammtisch_rolle(meal_role_ids($meal, 'host_id'), $usersById) ?></dd>
        </dl>

        <?php if ($punkte !== []): ?>
          <p class="meal-points">
            <?php $teile = []; ?>
            <?php foreach ($punkte as $id => $wert): ?>
              <?php $teile[] = h((string) ($usersById[$id]['nickname'] ?? 'Unbekannt')) . ' +' . h(meal_format_points($wert)); ?>
            <?php endforeach; ?>
            <?= implode(' · ', $teile) ?>
          </p>
        <?php endif; ?>

        <div class="meal-foot">
          <span class="muted">eingetragen von <?= h((string) $autor) ?></span>
          <?php if (meal_may_edit($meal, $user)): ?>
            <span class="meal-actions">
              <a class="btn btn-sm" href="stammtisch.php?bearbeiten=<?= h((string) $meal['id']) ?>#formular">Bearbeiten</a>
              <form method="post" onsubmit="return confirm('Diesen Eintrag wirklich löschen?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= h((string) $meal['id']) ?>">
                <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
              </form>
            </span>
          <?php endif; ?>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
<?php endif; ?>
<?php
layout_footer(true);
