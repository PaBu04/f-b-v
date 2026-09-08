<?php
declare(strict_types=1);

/**
 * Stammtisch: wer hat wann was gekocht – und die Punktetabelle dazu.
 *
 * Ein Eintrag hält fest, was es gab, bei wem gekocht wurde, wer am Herd stand
 * und wer eingekauft hat. Dazu lässt sich ein Bild aus der Galerie verknüpfen.
 *
 * Die Punkte stehen **nirgends gespeichert**. Sie werden aus den Einträgen
 * berechnet, jedes Mal neu. Wird ein Eintrag korrigiert, stimmt die Tabelle
 * sofort wieder – und niemand kann an einem Punktestand drehen, ohne dass es
 * am Eintrag sichtbar wird.
 */

/* --------------------------------------------------------------------- */
/* Spielregeln                                                             */
/* --------------------------------------------------------------------- */

/**
 * Punkte je Eintrag. Kochen und Einkauf sind Töpfe: Stehen mehrere Leute
 * dahinter, wird der Topf geteilt. Wer allein kocht, bekommt also alles.
 */
const MEAL_POINTS_COOK     = 5.0;  // Topf für alle am Herd
const MEAL_POINTS_SHOPPING = 3.0;  // Topf für alle am Einkaufswagen
const MEAL_POINTS_HOST     = 2.0;  // für die Küche, in der gekocht wurde
const MEAL_POINTS_PHOTO    = 1.0;  // Zuschlag in den Kochtopf, wenn ein Foto hängt

/** Bonus: je drei Stammtische in Folge, an denen jemand beteiligt war. */
const MEAL_STREAK_LENGTH = 3;
const MEAL_POINTS_STREAK = 2.0;

/** Bonus: alle drei Rollen mindestens einmal in derselben Saison. */
const MEAL_POINTS_ALLROUND = 5.0;

/** Stufen: ab so vielen Saisonpunkten gilt der Titel. */
const MEAL_LEVELS = [
    [0.0,   'Küchenhilfe'],
    [10.0,  'Sous-Chef'],
    [25.0,  'Küchenleitung'],
    [50.0,  'Sterneküche'],
    [100.0, 'Legende'],
];

/** Abzeichen der Saison: Schlüssel => [Titel, Erklärung]. */
const MEAL_BADGES = [
    'koch'        => ['Küchenchef', 'die meisten Punkte am Herd'],
    'einkauf'     => ['Einkaufsheld', 'am häufigsten eingekauft'],
    'gastgeber'   => ['Gastgeber', 'am häufigsten die Küche gestellt'],
    'foodblogger' => ['Foodblogger', 'die meisten Gerichte mit Foto'],
];

/** Längster erlaubter Gerichtname. */
const MEAL_DISH_MAX_LENGTH = 120;

/* --------------------------------------------------------------------- */
/* Lesen                                                                   */
/* --------------------------------------------------------------------- */

/**
 * Alle Einträge, neueste zuerst.
 *
 * @return array<int, array<string, mixed>>
 */
function meals_all(): array
{
    $meals = store_read('meals');
    usort($meals, static function (array $a, array $b) {
        $vergleich = strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));

        return $vergleich !== 0
            ? $vergleich
            : strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return $meals;
}

function meal_by_id(string $id): ?array
{
    foreach (store_read('meals') as $meal) {
        if (($meal['id'] ?? null) === $id) {
            return $meal;
        }
    }

    return null;
}

/**
 * Alle Jahre mit Einträgen, neueste zuerst.
 *
 * @return array<int, string>
 */
function meal_seasons(): array
{
    $jahre = [];
    foreach (store_read('meals') as $meal) {
        $jahr = meal_season($meal);
        if ($jahr !== '') {
            $jahre[$jahr] = true;
        }
    }

    // array_keys liefert für „2026" die Zahl 2026 – PHP macht aus numerischen
    // Schlüsseln Ganzzahlen. Zurück auf Zeichenketten, sonst scheitert jeder
    // strenge Vergleich mit dem Jahr aus der Adresszeile.
    $jahre = array_map('strval', array_keys($jahre));
    rsort($jahre, SORT_STRING);

    return $jahre;
}

/** Saison eines Eintrags: das Jahr des Stammtischs. */
function meal_season(array $meal): string
{
    return substr((string) ($meal['date'] ?? ''), 0, 4);
}

/** Darf $user den Eintrag ändern oder löschen? */
function meal_may_edit(array $meal, array $user): bool
{
    return !empty($user['is_admin']) || ($meal['created_by'] ?? null) === ($user['id'] ?? null);
}

/**
 * Mitglieder, nach ID – innerhalb einer Anfrage zwischengespeichert, weil die
 * Liste je Eintrag mehrfach gebraucht wird.
 *
 * @return array<string, array<string, mixed>>
 */
function meal_known_users(bool $refresh = false): array
{
    static $cache = null;

    if ($cache === null || $refresh) {
        $cache = users_by_id_map();
    }

    return $cache;
}

/**
 * Die Mitglieder einer Rolle: ohne Doppelte, ohne Leerstellen und ohne
 * gelöschte Konten – Punkte bekommen nur registrierte Mitglieder.
 *
 * @return array<int, string>
 */
function meal_role_ids(array $meal, string $feld): array
{
    $roh = $meal[$feld] ?? [];
    if (is_string($roh)) {
        $roh = $roh === '' ? [] : [$roh];
    }
    if (!is_array($roh)) {
        return [];
    }

    $bekannt = meal_known_users();
    $ids     = [];
    foreach ($roh as $id) {
        if (!is_scalar($id)) {
            continue;
        }
        $id = (string) $id;
        if ($id !== '' && isset($bekannt[$id]) && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * Alle Beteiligten eines Eintrags, jede Person einmal.
 *
 * @return array<int, string>
 */
function meal_participants(array $meal): array
{
    $ids = array_merge(
        meal_role_ids($meal, 'host_id'),
        meal_role_ids($meal, 'cook_ids'),
        meal_role_ids($meal, 'shopper_ids')
    );

    return array_values(array_unique($ids));
}

/* --------------------------------------------------------------------- */
/* Schreiben                                                               */
/* --------------------------------------------------------------------- */

/**
 * Prüft die Eingaben eines Formulars.
 *
 * @param array<string, mixed> $eingabe
 * @return ?string Fehlertext oder null
 */
function meal_problem(array $eingabe): ?string
{
    $gericht = trim((string) ($eingabe['dish'] ?? ''));
    $datum   = trim((string) ($eingabe['date'] ?? ''));

    if ($gericht === '') {
        return 'Bitte eintragen, was es gab.';
    }
    if (mb_strlen($gericht) > MEAL_DISH_MAX_LENGTH) {
        return 'Der Name des Gerichts darf höchstens ' . MEAL_DISH_MAX_LENGTH . ' Zeichen lang sein.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || strtotime($datum) === false) {
        return 'Bitte ein gültiges Datum für den Stammtisch angeben.';
    }
    if (meal_role_ids($eingabe, 'cook_ids') === []) {
        return 'Bitte mindestens ein Mitglied angeben, das gekocht hat.';
    }
    if (($eingabe['image_id'] ?? '') !== '' && image_by_id((string) $eingabe['image_id']) === null) {
        return 'Das verknüpfte Bild gibt es nicht mehr.';
    }

    return null;
}

/**
 * Bringt die Formulardaten in die Form, in der sie gespeichert werden.
 * Unbekannte Mitglieder fallen dabei heraus.
 *
 * @param array<string, mixed> $eingabe
 * @return array<string, mixed>
 */
function meal_clean(array $eingabe): array
{
    $gastgeber = meal_role_ids($eingabe, 'host_id');
    $bild      = (string) ($eingabe['image_id'] ?? '');

    return [
        'date'        => trim((string) ($eingabe['date'] ?? '')),
        'dish'        => trim((string) ($eingabe['dish'] ?? '')),
        'host_id'     => $gastgeber[0] ?? '',
        'cook_ids'    => meal_role_ids($eingabe, 'cook_ids'),
        'shopper_ids' => meal_role_ids($eingabe, 'shopper_ids'),
        'image_id'    => $bild !== '' && image_by_id($bild) !== null ? $bild : '',
    ];
}

/**
 * @param array<string, mixed> $eingabe
 * @return array<string, mixed> der angelegte Eintrag
 */
function meal_create(array $eingabe, string $userId): array
{
    $meal = meal_clean($eingabe) + [
        'id'         => store_new_id(),
        'created_by' => $userId,
        'created_at' => date('c'),
        'updated_at' => null,
    ];

    store_mutate('meals', static function (array &$rows) use ($meal) {
        $rows[] = $meal;

        return true;
    });

    return $meal;
}

/**
 * @param array<string, mixed> $eingabe
 */
function meal_update(string $id, array $eingabe): void
{
    $changes = meal_clean($eingabe) + ['updated_at' => date('c')];

    store_mutate('meals', static function (array &$rows) use ($id, $changes) {
        foreach ($rows as $index => $row) {
            if (($row['id'] ?? null) === $id) {
                $rows[$index] = array_merge($row, $changes);

                return true;
            }
        }

        return false;
    });
}

/**
 * Löst die Bildverknüpfung in allen Einträgen – wird gerufen, wenn ein Bild
 * aus der Galerie verschwindet. Sonst bliebe der Foto-Zuschlag stehen,
 * obwohl am Eintrag längst kein Bild mehr hängt.
 */
function meals_unlink_image(string $imageId): void
{
    if ($imageId === '') {
        return;
    }

    store_mutate('meals', static function (array &$rows) use ($imageId) {
        foreach ($rows as $index => $row) {
            if ((string) ($row['image_id'] ?? '') === $imageId) {
                $rows[$index]['image_id'] = '';
            }
        }

        return true;
    });
}

function meal_delete(string $id): void
{
    store_mutate('meals', static function (array &$rows) use ($id) {
        $rows = array_values(array_filter($rows, static function (array $row) use ($id) {
            return ($row['id'] ?? null) !== $id;
        }));

        return true;
    });
}

/* --------------------------------------------------------------------- */
/* Punkte                                                                  */
/* --------------------------------------------------------------------- */

/**
 * Punkte, die ein einzelner Eintrag verteilt.
 *
 * @return array<string, float> Mitglieds-ID => Punkte
 */
function meal_points_of(array $meal): array
{
    $punkte = [];

    $koeche = meal_role_ids($meal, 'cook_ids');
    if ($koeche !== []) {
        $topf = MEAL_POINTS_COOK + ((string) ($meal['image_id'] ?? '') !== '' ? MEAL_POINTS_PHOTO : 0.0);
        foreach ($koeche as $id) {
            $punkte[$id] = ($punkte[$id] ?? 0.0) + $topf / count($koeche);
        }
    }

    $einkauf = meal_role_ids($meal, 'shopper_ids');
    foreach ($einkauf as $id) {
        $punkte[$id] = ($punkte[$id] ?? 0.0) + MEAL_POINTS_SHOPPING / count($einkauf);
    }

    foreach (meal_role_ids($meal, 'host_id') as $id) {
        $punkte[$id] = ($punkte[$id] ?? 0.0) + MEAL_POINTS_HOST;
    }

    return $punkte;
}

/**
 * Die Tabelle einer Saison.
 *
 * @param ?string $saison Jahr, oder null für alle Jahre zusammen
 * @return array<int, array<string, mixed>> beste zuerst
 */
function meals_scoreboard(?string $saison = null): array
{
    $meals = array_values(array_filter(meals_all(), static function (array $meal) use ($saison) {
        return $saison === null || meal_season($meal) === $saison;
    }));

    $zeilen  = [];
    $termine = [];
    $dabei   = [];

    foreach ($meals as $meal) {
        $termin = (string) ($meal['date'] ?? '');
        if ($termin !== '') {
            $termine[$termin] = true;
        }

        foreach (meal_points_of($meal) as $id => $punkte) {
            $zeilen[$id]['basis'] = ($zeilen[$id]['basis'] ?? 0.0) + $punkte;
        }
        foreach (meal_role_ids($meal, 'cook_ids') as $id) {
            $zeilen[$id]['koch'] = (int) ($zeilen[$id]['koch'] ?? 0) + 1;
            if ((string) ($meal['image_id'] ?? '') !== '') {
                $zeilen[$id]['mit_foto'] = (int) ($zeilen[$id]['mit_foto'] ?? 0) + 1;
            }
        }
        foreach (meal_role_ids($meal, 'shopper_ids') as $id) {
            $zeilen[$id]['einkauf'] = (int) ($zeilen[$id]['einkauf'] ?? 0) + 1;
        }
        foreach (meal_role_ids($meal, 'host_id') as $id) {
            $zeilen[$id]['gastgeber'] = (int) ($zeilen[$id]['gastgeber'] ?? 0) + 1;
        }
        foreach (meal_participants($meal) as $id) {
            $zeilen[$id]['gerichte'] = (int) ($zeilen[$id]['gerichte'] ?? 0) + 1;
            $dabei[$id][$termin]     = true;
        }

        // Kochpunkte getrennt mitzählen, für das Abzeichen „Küchenchef"
        $koeche = meal_role_ids($meal, 'cook_ids');
        if ($koeche !== []) {
            $topf = MEAL_POINTS_COOK + ((string) ($meal['image_id'] ?? '') !== '' ? MEAL_POINTS_PHOTO : 0.0);
            foreach ($koeche as $id) {
                $zeilen[$id]['kochpunkte'] = ($zeilen[$id]['kochpunkte'] ?? 0.0) + $topf / count($koeche);
            }
        }
    }

    if ($zeilen === []) {
        return [];
    }

    ksort($termine);
    $termine = array_keys($termine);

    $bekannt = meal_known_users();
    $fertig  = [];

    foreach ($zeilen as $id => $zeile) {
        $zeile += [
            'basis' => 0.0, 'kochpunkte' => 0.0, 'koch' => 0, 'einkauf' => 0,
            'gastgeber' => 0, 'gerichte' => 0, 'mit_foto' => 0,
        ];

        // Serie: jeder dritte Stammtisch in Folge bringt Bonus
        $lauf = 0;
        $beste = 0;
        $bonus = 0.0;
        foreach ($termine as $termin) {
            if (isset($dabei[$id][$termin])) {
                $lauf++;
                $beste = max($beste, $lauf);
                if ($lauf % MEAL_STREAK_LENGTH === 0) {
                    $bonus += MEAL_POINTS_STREAK;
                }
            } else {
                $lauf = 0;
            }
        }

        $allrounder = $zeile['koch'] > 0 && $zeile['einkauf'] > 0 && $zeile['gastgeber'] > 0;
        if ($allrounder) {
            $bonus += MEAL_POINTS_ALLROUND;
        }

        $zeile['user_id']    = (string) $id;
        $zeile['nickname']   = (string) ($bekannt[$id]['nickname'] ?? 'Unbekannt');
        $zeile['bonus']      = $bonus;
        $zeile['punkte']     = $zeile['basis'] + $bonus;
        $zeile['serie']      = $beste;
        $zeile['allrounder'] = $allrounder;
        $zeile['stufe']      = meal_level($zeile['punkte']);
        $zeile['abzeichen']  = [];

        $fertig[] = $zeile;
    }

    // Abzeichen an die jeweils Besten, bei Gleichstand an alle davon
    $spalten = [
        'koch'        => 'kochpunkte',
        'einkauf'     => 'einkauf',
        'gastgeber'   => 'gastgeber',
        'foodblogger' => 'mit_foto',
    ];
    foreach ($spalten as $abzeichen => $spalte) {
        $bestwert = 0.0;
        foreach ($fertig as $zeile) {
            $bestwert = max($bestwert, round((float) $zeile[$spalte], 3));
        }
        if ($bestwert <= 0.0) {
            continue;
        }
        foreach ($fertig as $index => $zeile) {
            // gerundet vergleichen: geteilte Töpfe ergeben sonst krumme Reste
            if (round((float) $zeile[$spalte], 3) === $bestwert) {
                $fertig[$index]['abzeichen'][] = $abzeichen;
            }
        }
    }

    usort($fertig, static function (array $a, array $b) {
        if ($a['punkte'] !== $b['punkte']) {
            return $b['punkte'] <=> $a['punkte'];
        }
        if ($a['gerichte'] !== $b['gerichte']) {
            return $b['gerichte'] <=> $a['gerichte'];
        }

        return strcasecmp($a['nickname'], $b['nickname']);
    });

    return $fertig;
}

/** Titel zur Punktzahl. */
function meal_level(float $punkte): string
{
    $titel = MEAL_LEVELS[0][1];
    foreach (MEAL_LEVELS as $stufe) {
        if ($punkte >= $stufe[0]) {
            $titel = $stufe[1];
        }
    }

    return $titel;
}

/** Punkte für die nächste Stufe, oder null auf der höchsten. */
function meal_next_level(float $punkte): ?array
{
    foreach (MEAL_LEVELS as $stufe) {
        if ($punkte < $stufe[0]) {
            return ['punkte' => $stufe[0], 'titel' => $stufe[1]];
        }
    }

    return null;
}

/* --------------------------------------------------------------------- */
/* Darstellung                                                             */
/* --------------------------------------------------------------------- */

/** 2.5 => „2,5", 5.0 => „5" */
function meal_format_points(float $punkte): string
{
    return rtrim(rtrim(number_format(round($punkte, 1), 1, ',', '.'), '0'), ',');
}

/** „KW 37 · 2026" */
function meal_week_label(string $datum): string
{
    $ts = strtotime($datum);
    if ($ts === false) {
        return 'Ohne Datum';
    }

    return 'KW ' . date('W', $ts) . ' · ' . date('o', $ts);
}

/** Schlüssel zum Gruppieren nach Woche, absteigend sortierbar. */
function meal_week_key(string $datum): string
{
    $ts = strtotime($datum);

    return $ts === false ? '0000-00' : date('o-W', $ts);
}
