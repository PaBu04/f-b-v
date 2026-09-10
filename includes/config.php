<?php
declare(strict_types=1);

/**
 * Zentrale Konfiguration.
 * Pfade sind relativ zu diesem Verzeichnis (includes/), damit die App in
 * jedem Unterordner und unter jeder Domain läuft.
 */

const APP_NAME     = 'f-b-v';
const APP_TAGLINE  = 'Interner Bilderbereich';
const APP_TIMEZONE = 'Europe/Berlin';

/**
 * Wofür f-b-v angeblich steht. Beim Aufruf wird zufällig eine Variante
 * gewählt und als Untertitel angezeigt.
 */
const APP_TAGLINES = [
    'Fotos Bleiben Vertraulich',
    'Festplatte Bereits Voll',
    'Falsch Belichtet, Verkantet',
    'Fokus Bleibt Verhandelbar',
    'Freunde Bunter Verschwörungen',
    'Feierabend, Bilder, Verpflegung',
    'Frisch Belichtet, Voll Verpixelt',
    'Fast Blitzfrei Verwackelt',
    'Für Bildschirmschoner Verschwendet',
    'Freitags Bier, Vormittags Reue',
];

/**
 * true  = bei jedem Seitenaufruf neu würfeln,
 * false = eine Variante je Besuch (bleibt beim Klicken stabil).
 */
const TAGLINE_PER_REQUEST = true;

const BASE_DIR   = __DIR__ . '/..';
const DATA_DIR   = BASE_DIR . '/data';
const UPLOAD_DIR = BASE_DIR . '/uploads';
const THUMB_DIR  = BASE_DIR . '/uploads/thumbs';
const AVATAR_DIR = BASE_DIR . '/uploads/avatars';
const RECIPE_DIR = BASE_DIR . '/uploads/recipes';

/** Maximale Größe je Bild (zusätzlich zu den php.ini-Limits, siehe .user.ini). */
const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

/** Maximale Größe einer hochgeladenen Profilbild-Datei. */
const MAX_AVATAR_BYTES = 10 * 1024 * 1024;

/**
 * Maximale Größe eines Rezepts (Bild oder PDF). Fotografierte Rezepte rechnet
 * der Browser vorher herunter, PDFs gehen unverändert durch – für die zählt
 * am Ende ohnehin das Serverlimit aus der php.ini.
 */
const MAX_RECIPE_BYTES = 10 * 1024 * 1024;

/**
 * Zielgröße beim Verkleinern **im Browser**, bevor ein Bild hochgeladen wird.
 *
 * Der Hoster hat upload_max_filesize fest auf 2 MB gesetzt; ein 8-MB-Handyfoto
 * käme also gar nicht erst an. Deshalb rechnet das Gerät es vorher herunter
 * (siehe assets/app.js). 2560 px reichen für jeden Monitor und für einen
 * ordentlichen Ausdruck. 0 schaltet das Verkleinern ab.
 */
const IMAGE_MAX_EDGE = 2560;

/** JPEG-/WEBP-Qualität beim Verkleinern im Browser (0–100). */
const IMAGE_QUALITY = 85;

/** Längste Kante der Vorschaubilder in Pixeln. */
const THUMB_MAX_EDGE = 700;

/** Kantenlänge der quadratischen Profilbilder in Pixeln. */
const AVATAR_SIZE = 320;

/** Maximale Länge eines Spitznamens. */
const NICKNAME_MAX_LENGTH = 40;

/** Bilder pro Galerie-Seite. */
const IMAGES_PER_PAGE = 48;

/** Erlaubte Bildtypen: IMAGETYPE_* => Dateiendung. */
const ALLOWED_IMAGE_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
];

/** Mindestlänge für Passwörter. */
const PASSWORD_MIN_LENGTH = 8;

/**
 * Brute-Force-Schutz: Fehlversuche je Herkunft bzw. je Konto, danach Sperre
 * in Sekunden. Die Kontoschwelle liegt höher, weil sonst jeder ein fremdes
 * Mitglied mit ein paar falschen Passwörtern aussperren könnte.
 */
const LOGIN_MAX_ATTEMPTS         = 10;
const LOGIN_MAX_ATTEMPTS_ACCOUNT = 20;
const LOGIN_WINDOW               = 900;
const LOGIN_LOCK_SECONDS         = 900;

/** Session-Einstellungen. */
const SESSION_NAME         = 'fbv_sid';
const SESSION_IDLE_TIMEOUT = 60 * 60 * 12; // 12 Stunden ohne Aktivität

/**
 * Geburtstage: einmal am Tag geht ein Gruß an alle Mitglieder, die
 * Benachrichtigungen dafür eingeschaltet haben.
 *
 * Auf dem Webspace läuft kein Cron-Dienst; den Versand stößt deshalb der erste
 * Galerie-Aufruf des Tages ab BIRTHDAY_NOTIFY_HOUR an (siehe
 * includes/birthdays.php). Schaut vor Mitternacht niemand vorbei, kommt der
 * Gruß an diesem Tag nicht mehr – der Hinweis auf der Galerieseite bleibt
 * davon unberührt.
 */
const BIRTHDAY_NOTIFY      = true;
const BIRTHDAY_NOTIFY_HOUR = 8;
