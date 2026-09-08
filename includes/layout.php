<?php
declare(strict_types=1);

/**
 * Gemeinsames Seitengerüst.
 *
 * @param array<string, mixed>|null $user
 */
function layout_header(string $title, ?array $user = null, bool $narrow = false): void
{
    header('Content-Type: text/html; charset=utf-8');
    $script = current_script();
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#14171c" media="(prefers-color-scheme: dark)">
<title><?= h($title) ?> &middot; <?= h(APP_NAME) ?></title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="alternate icon" href="favicon.ico" sizes="16x16 32x32 48x48">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<link rel="manifest" href="manifest.webmanifest">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h(APP_NAME) ?>">
<link rel="stylesheet" href="assets/style.css?v=6">
</head>
<body<?= $narrow ? ' class="narrow"' : '' ?>>
<?php if ($user !== null): ?>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="index.php">
      <span class="brand-mark"><?= h(APP_NAME) ?></span>
      <span class="brand-sub"><?= h(app_tagline()) ?></span>
    </a>
    <nav class="nav">
      <a href="index.php"<?= $script === 'index.php' ? ' class="active"' : '' ?>>Galerie</a>
      <a href="members.php"<?= $script === 'members.php' ? ' class="active"' : '' ?>>Mitglieder</a>
      <a href="stammtisch.php"<?= $script === 'stammtisch.php' ? ' class="active"' : '' ?>>Stammtisch</a>
      <a href="account.php"<?= $script === 'account.php' ? ' class="active"' : '' ?>>Mein Konto</a>
      <?php if (!empty($user['is_admin'])): ?>
        <a href="admin.php"<?= $script === 'admin.php' ? ' class="active"' : '' ?>>Verwaltung</a>
      <?php endif; ?>
    </nav>
    <div class="topbar-user">
      <a class="who" href="account.php" title="Mein Konto">
        <?= avatar_html($user, 'avatar-sm') ?>
        <span><?= h($user['nickname']) ?></span>
      </a>
      <a class="btn btn-ghost btn-sm" href="logout.php">Abmelden</a>
    </div>
  </div>
</header>
<?php endif; ?>
<main class="page">
<?php
    foreach (flash_take() as $message) {
        echo '<div class="flash flash-' . h($message['type']) . '">' . h($message['message']) . '</div>';
    }
}

function layout_footer(bool $withScript = false): void
{
    ?>
</main>
<footer class="site-footer">
  <span><?= h(APP_NAME) ?> &middot; interner Bereich &middot; <?= date('Y') ?></span>
</footer>
<?php if ($withScript): ?>
<script src="assets/app.js?v=5"></script>
<?php endif; ?>
</body>
</html>
<?php
}
