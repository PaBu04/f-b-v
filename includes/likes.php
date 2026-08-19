<?php
declare(strict_types=1);

/**
 * Likes: je Mitglied und Bild höchstens einer.
 * Ablage in data/likes.json als flache Liste.
 */

/**
 * Alle Likes. Das Ergebnis wird je Aufruf zwischengespeichert, damit eine
 * Galerieseite die Datei nicht mehrfach liest.
 *
 * @return array<int, array<string, mixed>>
 */
function likes_all(bool $refresh = false): array
{
    static $cache = null;

    if ($cache === null || $refresh) {
        $cache = store_read('likes');
    }

    return $cache;
}

/**
 * Anzahl der Likes je Bild – eine Abfrage für die ganze Galerie.
 *
 * @return array<string, int>
 */
function likes_count_map(): array
{
    $counts = [];
    foreach (likes_all() as $like) {
        $imageId = (string) ($like['image_id'] ?? '');
        if ($imageId !== '') {
            $counts[$imageId] = ($counts[$imageId] ?? 0) + 1;
        }
    }

    return $counts;
}

/**
 * Bilder, die ein bestimmtes Mitglied mag.
 *
 * @return array<string, true>
 */
function likes_of_user(string $userId): array
{
    $mine = [];
    foreach (likes_all() as $like) {
        if ((string) ($like['user_id'] ?? '') === $userId) {
            $mine[(string) ($like['image_id'] ?? '')] = true;
        }
    }

    return $mine;
}

/**
 * Spitznamen je Bild, in einem Durchlauf für die ganze Galerie.
 *
 * @param array<string, array<string, mixed>> $usersById
 * @return array<string, array<int, string>> Bild-ID => Spitznamen
 */
function likes_names_map(array $usersById): array
{
    $names = [];

    foreach (likes_all() as $like) {
        $imageId = (string) ($like['image_id'] ?? '');
        $user    = $usersById[(string) ($like['user_id'] ?? '')] ?? null;
        if ($imageId === '' || $user === null) {
            continue;
        }
        $names[$imageId][] = (string) $user['nickname'];
    }

    foreach ($names as $imageId => $list) {
        sort($list);
        $names[$imageId] = $list;
    }

    return $names;
}

/**
 * Setzt oder entfernt den Like eines Mitglieds – atomar, damit gleichzeitige
 * Klicks sich nicht gegenseitig überschreiben.
 *
 * @return array{liked: bool, count: int}
 */
function like_toggle(string $imageId, string $userId): array
{
    $result = store_mutate('likes', static function (array &$rows) use ($imageId, $userId) {
        $existed = false;
        $kept    = [];

        foreach ($rows as $row) {
            if ((string) ($row['image_id'] ?? '') === $imageId
                && (string) ($row['user_id'] ?? '') === $userId) {
                $existed = true;
                continue;
            }
            $kept[] = $row;
        }

        $rows = $kept;

        if (!$existed) {
            $rows[] = [
                'image_id'   => $imageId,
                'user_id'    => $userId,
                'created_at' => date('c'),
            ];
        }

        $count = 0;
        foreach ($rows as $row) {
            if ((string) ($row['image_id'] ?? '') === $imageId) {
                $count++;
            }
        }

        return ['liked' => !$existed, 'count' => $count];
    });

    likes_all(true);

    return $result;
}

/** Räumt die Likes eines gelöschten Bildes ab. */
function likes_remove_for_image(string $imageId): void
{
    store_mutate('likes', static function (array &$rows) use ($imageId) {
        $rows = array_values(array_filter($rows, static function (array $row) use ($imageId) {
            return (string) ($row['image_id'] ?? '') !== $imageId;
        }));

        return true;
    });

    likes_all(true);
}

/** Räumt die Likes eines gelöschten Mitglieds ab. */
function likes_remove_for_user(string $userId): void
{
    store_mutate('likes', static function (array &$rows) use ($userId) {
        $rows = array_values(array_filter($rows, static function (array $row) use ($userId) {
            return (string) ($row['user_id'] ?? '') !== $userId;
        }));

        return true;
    });

    likes_all(true);
}
