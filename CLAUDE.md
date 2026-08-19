# Hinweise für Claude Code

Interner Bilderbereich eines Vereins: Mitglieder melden sich an, laden Fotos
hoch, sehen die Bilder der anderen und können sie liken. Erreichbar unter
f-b-v.de, betrieben auf einem Plesk-Webspace.

## Die wichtigste Regel

**Keine Abhängigkeiten.** Kein Composer, kein Framework, kein npm, keine
Datenbank, keine CDN-Einbindung. Das ist eine bewusste Entscheidung: Die
Anwendung soll auf einem einfachen Webspace ohne Kommandozeilenzugriff laufen
und sich per FTP kopieren lassen. Ein Paket für eine Aufgabe, die zwanzig
Zeilen PHP lösen, wird nicht übernommen.

Alles ist reines PHP 7.4+ mit den Erweiterungen `gd`, `mbstring`, `exif` und
`openssl`. JavaScript ist handgeschrieben, ohne Framework.

## Aufbau

```
includes/        Programmcode, nicht direkt aufrufbar (.htaccess sperrt)
  bootstrap.php  lädt alles Weitere, setzt Sicherheitskopfzeilen, startet die Sitzung
  config.php     sämtliche Stellschrauben als const
  store.php      JSON-Ablage mit Dateisperre
  auth.php       Konten, Anmeldung, Sperre nach Fehlversuchen
  images.php     Upload, Vorschaubilder, Profilbilder
  likes.php      Likes mit Zwischenspeicher
  push.php       Web Push nach RFC 8291/8292, komplett selbst gebaut
  zip.php        ZIP-Ausgabe ohne Zwischendatei
  helpers.php    h(), CSRF, Meldungen, Formatierung
  layout.php     Kopf- und Fußbereich jeder Seite
*.php            eine Datei je Seite bzw. Endpunkt, kein Router
assets/          app.js und style.css
data/            JSON-Dateien zur Laufzeit  — NIE committen
uploads/         Bilder, Vorschauen, Profilbilder — NIE committen
```

Es gibt keinen Router und keine Vorlagen-Sprache. Jede Seite ist eine
PHP-Datei, die `includes/bootstrap.php` einbindet und HTML ausgibt.

## Ablage

Sechs JSON-Sammlungen in `data/`: `users`, `images`, `likes`,
`push_subscriptions`, `push_keys`, `throttle`.

**Lesen** mit `store_read('users')`. **Schreiben ausschließlich** mit
`store_mutate('users', function (array &$daten) { ... })` — das sperrt die
Datei mit `flock(LOCK_EX)`. Ohne die Sperre überschreiben sich gleichzeitige
Zugriffe gegenseitig; bei einem Verein mit gemeinsamem Upload-Abend passiert
das wirklich. Neue Kennungen kommen von `store_new_id()`.

## Beim Ändern beachten

- **CSRF:** jedes verändernde Formular bekommt `csrf_field()`, jede
  verarbeitende Datei ruft `csrf_require()` vor dem Schreiben auf.
- **Ausgabe escapen** mit `h()`. Ausnahmslos.
- **Rückmeldungen** über `flash('success', '…')`, `flash('error', '…')` oder
  `flash('info', '…')`, Abholen mit `flash_take()`. Andere Typen kennt das
  Stylesheet nicht.
- **Zugriffsschutz** am Anfang jeder geschützten Seite: `require_login()`
  oder `require_admin()`.
- **Umlaute direkt schreiben** — `ä`, nicht `ae`. Dateien in UTF-8 ohne BOM.
- **Deutsch** für Oberfläche, Kommentare und Commit-Nachrichten.
- `declare(strict_types=1);` steht in jeder PHP-Datei.
- Sicherheit gehört auf den Server. JavaScript ist Komfort, keine Prüfung.

## Zwei Eigenheiten, die man kennen muss

**Das Upload-Limit liegt bei 2 MB und lässt sich nicht ändern.** Der Hoster
hat `upload_max_filesize` per `php_admin_value` in der FPM-Konfiguration
festgenagelt; `.user.ini` und Plesk-Oberfläche bleiben wirkungslos. Nachgewiesen
über eine Sonde in der `.user.ini` (`max_input_vars = 1234`), die in der Karte
„Serverumgebung" unter *Verwaltung* sichtbar ist.

Deshalb rechnet **der Browser** die Bilder vor dem Hochladen herunter, siehe
`scaleFile()` in `assets/app.js`. `IMAGE_MAX_EDGE` und `IMAGE_QUALITY` in
`config.php` steuern das Gerät, nicht den Server. Serverseitiges Verkleinern
gibt es bewusst nicht mehr. Wer es wieder einbaut, bekommt Handyfotos gar
nicht erst durch.

**Web Push ist von Hand implementiert.** `includes/push.php` erzeugt
VAPID-Signaturen und verschlüsselt die Nutzlast nach RFC 8291 mit
`aes128gcm`. Der Code ist gegen den offiziellen Testvektor aus RFC 8291 §5
geprüft und liefert byteweise dasselbe Ergebnis. Wer daran etwas ändert,
prüft erneut gegen den Testvektor.

`data/push_keys.json` enthält den privaten VAPID-Schlüssel. Wird die Datei
gelöscht, sind **alle** Anmeldungen der Mitglieder ungültig und jedes Gerät
muss sich neu registrieren.

## Prüfen

```
php -l <geänderte Datei>      # jede PHP-Datei
node --check assets/app.js    # wenn JavaScript berührt wurde
php -S 127.0.0.1:8000         # lokal ausprobieren
```

Der eingebaute Server beachtet keine `.htaccess`; lokal sind `data/` und
`uploads/` deshalb direkt erreichbar, auf dem echten Server nicht.

Ohne Konten führt der erste Aufruf über `setup.php` zum Anlegen eines
Administrators.

Es gibt keine automatischen Tests. Änderungen an Upload, Anmeldung oder Push
also von Hand durchspielen.

## Bereitstellung

Per FTP nach Plesk. Das Repository ist die Quelle des Codes, **nicht** der
Betriebszustand: `data/` und `uploads/` leben nur auf dem Server und werden
beim Kopieren ausgelassen. Vor größeren Änderungen beide Ordner sichern.

## Niemals

- `data/` oder `uploads/` einchecken — darin stehen Passwort-Hashes,
  Geburtsdaten, IP-Adressen, der private Push-Schlüssel und private Fotos.
- Echte Mitgliederdaten in Beispiele, Fehlerberichte oder Commits schreiben.
- `data/push_keys.json` löschen oder neu erzeugen.
