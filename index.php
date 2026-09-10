<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();

$filter = ($_GET['filter'] ?? '') === 'mine' ? 'mine' : 'all';

$all       = images_all();
$mine      = array_values(array_filter($all, static function (array $image) use ($user) {
    return ($image['user_id'] ?? null) === $user['id'];
}));
$mineCount = count($mine);

$images = $filter === 'mine' ? $mine : $all;

$total = count($images);
$pages = max(1, (int) ceil($total / IMAGES_PER_PAGE));
$page  = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
$slice = array_slice($images, ($page - 1) * IMAGES_PER_PAGE, IMAGES_PER_PAGE);

$limit      = effective_upload_limit();
$usersById  = users_by_id_map();
$likeCounts = likes_count_map();
$likeNames  = likes_names_map($usersById);
$myLikes    = likes_of_user((string) $user['id']);
$returnTo   = 'index.php?filter=' . $filter . '&page=' . $page;

/*
 * Geburtstage: Der Hinweis steht auf jeder Galerieseite, der Versand der
 * Benachrichtigung hängt sich hinten an die Antwort (siehe includes/birthdays.php).
 */
$geburtstage  = birthdays_today();
$andereKinder = array_values(array_filter($geburtstage, static function (array $mitglied) use ($user) {
    return (string) $mitglied['id'] !== (string) $user['id'];
}));
$binGefeiert = count($andereKinder) < count($geburtstage);

birthday_schedule_dispatch($geburtstage);

layout_header('Galerie', $user);
?>
<?php if ($geburtstage !== []): ?>
  <div class="birthday-banner">
    <span class="birthday-cake" aria-hidden="true">&#127874;</span>
    <span class="birthday-text">
      <?php if ($binGefeiert): ?>
        <strong>Alles Gute zum Geburtstag, <?= h((string) $user['nickname']) ?>!</strong>
        <?php if ($andereKinder !== []): ?>
          <?= h(birthday_sentence($andereKinder)) ?>
        <?php endif; ?>
      <?php else: ?>
        <strong><?= h(birthday_sentence($geburtstage)) ?></strong>
      <?php endif; ?>
    </span>
    <a class="btn btn-sm" href="members.php">Mitglieder</a>
  </div>
<?php endif; ?>

<section class="toolbar">
  <form method="post" action="upload.php" enctype="multipart/form-data" id="upload-form"
        data-max-edge="<?= IMAGE_MAX_EDGE ?>"
        data-quality="<?= IMAGE_QUALITY ?>"
        data-limit="<?= (int) $limit ?>">
    <?= csrf_field() ?>

    <div class="toolbar-actions">
      <input type="file" id="files" name="files[]" class="visually-hidden"
             accept="image/jpeg,image/png,image/gif,image/webp" multiple>
      <label class="btn btn-primary" for="files">
        <span class="btn-icon" aria-hidden="true">&#8593;</span> Bilder hochladen
      </label>

      <a class="btn" href="download.php?filter=<?= h($filter) ?>">
        <span class="btn-icon" aria-hidden="true">&#8595;</span>
        <?= $filter === 'mine' ? 'Meine herunterladen' : 'Alle herunterladen' ?>
      </a>
    </div>

    <div class="upload-panel" id="upload-panel">
      <span class="upload-summary" id="upload-summary">JPG, PNG, GIF oder WEBP &middot; max. <?= h(format_bytes($limit)) ?> pro Datei</span>
      <span class="upload-note" id="upload-note" hidden></span>
      <input type="text" name="caption" maxlength="300" placeholder="Beschreibung (optional)">
      <button type="submit" class="btn btn-primary" id="upload-submit">Hochladen</button>
      <button type="button" class="btn btn-sm upload-cancel" id="upload-cancel" hidden>Abbrechen</button>
    </div>
  </form>
</section>

<div class="drop-overlay" id="drop-overlay" hidden>
  <span>Bilder hier ablegen</span>
</div>

<section class="gallery-head">
  <h1>
    <?= $filter === 'mine' ? 'Meine Bilder' : 'Alle Bilder' ?>
    <span class="count"><?= (int) $total ?></span>
  </h1>
  <div class="filters">
    <a href="index.php" class="chip<?= $filter === 'all' ? ' chip-active' : '' ?>">Alle</a>
    <a href="index.php?filter=mine" class="chip<?= $filter === 'mine' ? ' chip-active' : '' ?>">Nur meine (<?= (int) $mineCount ?>)</a>
  </div>
</section>

<?php if ($slice === []): ?>
  <div class="empty">
    <p><?= $filter === 'mine' ? 'Du hast noch keine Bilder hochgeladen.' : 'Noch keine Bilder vorhanden.' ?></p>
    <p class="muted">Lade oben das erste Bild hoch.</p>
  </div>
<?php else: ?>
  <div class="grid" id="gallery">
    <?php foreach ($slice as $index => $image): ?>
      <?php
        $imageId = (string) $image['id'];
        $caption = (string) ($image['caption'] ?? '');
        // Spitznamen live auflösen; der gespeicherte Name dient nur als
        // Rückfallebene für gelöschte Konten.
        $owner    = $usersById[(string) ($image['user_id'] ?? '')] ?? null;
        $uploader = $owner !== null
            ? (string) $owner['nickname']
            : (string) ($image['uploader_nickname'] ?? 'Unbekannt');
        $when     = format_datetime((string) ($image['uploaded_at'] ?? ''));
        $thumbUrl = 'image.php?id=' . urlencode($imageId) . (($image['thumb'] ?? '') !== '' ? '&size=thumb' : '');
        $fullUrl  = 'image.php?id=' . urlencode($imageId);

        $likeCount = (int) ($likeCounts[$imageId] ?? 0);
        $liked     = isset($myLikes[$imageId]);
        $likedBy   = $likeNames[$imageId] ?? [];
        $likeTitle = $likedBy === []
            ? 'Gefällt mir'
            : 'Gefällt: ' . implode(', ', $likedBy);
      ?>
      <figure class="tile">
        <a class="tile-link"
           href="<?= h($fullUrl) ?>"
           data-lightbox
           data-full="<?= h($fullUrl) ?>"
           data-caption="<?= h($caption) ?>"
           data-meta="<?= h($uploader . ' · ' . $when) ?>">
          <img src="<?= h($thumbUrl) ?>" alt="<?= h($caption !== '' ? $caption : (string) ($image['original_name'] ?? 'Bild')) ?>" loading="lazy">
        </a>
        <figcaption>
          <?php if ($caption !== ''): ?>
            <span class="tile-caption"><?= h($caption) ?></span>
          <?php endif; ?>
          <div class="tile-foot">
            <span class="tile-meta">
              <?= $owner !== null ? avatar_html($owner, 'avatar-xs') : '' ?>
              <span><?= h($uploader) ?> &middot; <?= h($when) ?></span>
            </span>
            <form class="like-form" method="post" action="like.php" data-like>
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= h($imageId) ?>">
              <input type="hidden" name="return" value="<?= h($returnTo) ?>">
              <button type="submit"
                      class="like-btn<?= $liked ? ' is-liked' : '' ?>"
                      aria-pressed="<?= $liked ? 'true' : 'false' ?>"
                      title="<?= h($likeTitle) ?>">
                <span class="like-heart" aria-hidden="true">&#9829;</span>
                <span class="like-count"><?= $likeCount ?></span>
                <span class="visually-hidden">Gefällt mir</span>
              </button>
            </form>
          </div>
        </figcaption>
        <?php if (image_may_delete($image, $user)): ?>
          <form class="tile-delete" method="post" action="delete.php" data-confirm="Dieses Bild wirklich löschen?">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= h($imageId) ?>">
            <input type="hidden" name="return" value="<?= h($returnTo) ?>">
            <button type="submit" title="Bild löschen" aria-label="Bild löschen">&times;</button>
          </form>
        <?php endif; ?>
      </figure>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
    <nav class="pagination">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a href="index.php?filter=<?= h($filter) ?>&page=<?= $p ?>" class="chip<?= $p === $page ? ' chip-active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<div class="lightbox" id="lightbox" hidden>
  <button class="lb-close" id="lb-close" aria-label="Schließen">&times;</button>
  <button class="lb-nav lb-prev" id="lb-prev" aria-label="Vorheriges Bild">&#8249;</button>
  <button class="lb-nav lb-next" id="lb-next" aria-label="Nächstes Bild">&#8250;</button>
  <figure class="lb-figure">
    <img id="lb-image" src="" alt="">
    <figcaption>
      <span id="lb-caption"></span>
      <button type="button" class="like-btn lb-like" id="lb-like" aria-pressed="false">
        <span class="like-heart" aria-hidden="true">&#9829;</span>
        <span class="like-count" id="lb-like-count">0</span>
        <span class="visually-hidden">Gefällt mir</span>
      </button>
    </figcaption>
  </figure>
</div>
<?php
layout_footer(true);
