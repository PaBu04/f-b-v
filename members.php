<?php
declare(strict_types=1);

/**
 * Mitgliederübersicht für alle angemeldeten Mitglieder.
 *
 * Bewusst nur Profilbild, Spitzname, Geburtstag und Anzahl der Bilder.
 * Benutzername, Rolle, Likes, letzte Anmeldung und alle Aktionen bleiben
 * der Verwaltung in admin.php vorbehalten.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user    = require_login();
$members = users_all();

$imageCount = [];
foreach (images_all() as $image) {
    $uid = (string) ($image['user_id'] ?? '');
    $imageCount[$uid] = ($imageCount[$uid] ?? 0) + 1;
}

layout_header('Mitglieder', $user);
?>
<section class="gallery-head">
  <h1>Mitglieder <span class="count"><?= count($members) ?></span></h1>
</section>

<div class="member-grid">
  <?php foreach ($members as $member): ?>
    <?php $bilder = (int) ($imageCount[(string) $member['id']] ?? 0); ?>
    <article class="member-card">
      <?= avatar_html($member, 'avatar-lg') ?>
      <div class="member-body">
        <span class="member-name">
          <?= h((string) $member['nickname']) ?>
          <?php if ((string) $member['id'] === (string) $user['id']): ?>
            <span class="badge">Du</span>
          <?php endif; ?>
        </span>
        <span class="member-meta">
          <span title="Geburtstag">&#127874; <?= h(format_date((string) $member['birthdate'])) ?></span>
          <span title="Hochgeladene Bilder">&#128247; <?= $bilder ?> <?= $bilder === 1 ? 'Bild' : 'Bilder' ?></span>
        </span>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php
layout_footer();
