# f-b-v – interner Bilderbereich

Kleine, eigenständige PHP-Anwendung: angemeldete Mitglieder laden Bilder hoch,
alle angemeldeten Mitglieder sehen die Galerie. Keine Datenbank, kein Framework,
keine externen Abhängigkeiten.

## Inbetriebnahme

1. Ordner `f-b-v.de/` per FTP auf den Webspace hochladen (VS Code SFTP-Sync
   übernimmt das beim Speichern).
2. In Plesk eine Domain oder Subdomain anlegen und als Dokumentenstamm
   `/f-b-v.de` eintragen – zunächst die Testdomain, später `f-b-v.de`.
   Die Anwendung verwendet ausschließlich relative Pfade und läuft daher
   unter jeder Domain und auch in einem Unterordner.
3. Schreibrechte für `data/` und `uploads/` sicherstellen (die Ordner werden
   beim ersten Aufruf automatisch angelegt, falls das Elternverzeichnis
   beschreibbar ist).
4. `https://<domain>/setup.php` aufrufen und den ersten Administrator anlegen.
5. `setup.php` danach vom Server löschen (die Seite sperrt sich zwar selbst,
   sobald ein Mitglied existiert, aber sicher ist sicher).
6. Sobald ein SSL-Zertifikat eingerichtet ist, den HTTPS-Block in `.htaccess`
   einkommentieren.

## Mitglieder anlegen

Konten werden ausschließlich manuell durch einen Administrator angelegt
(Menüpunkt **Mitglieder**). Je Konto: Benutzername, Spitzname, Geburtsdatum und
ein Startpasswort (leer lassen erzeugt automatisch eines). Das Startpasswort
wird einmalig angezeigt und muss vom Mitglied bei der ersten Anmeldung geändert
werden.

Unter **Mein Konto** pflegt jedes Mitglied selbst:

- **Spitzname** – er wird überall live aufgelöst, ältere Bilder zeigen
  nach einer Änderung sofort den neuen Namen.
- **Profilbild** – wird serverseitig mittig quadratisch zugeschnitten und auf
  320 px als JPEG gespeichert. Ohne Profilbild zeigt die Seite einen farbigen
  Kreis mit der Initiale des Spitznamens.
- **Passwort**.

Benutzername und Geburtsdatum bleiben dem Administrator vorbehalten.

## Zwei Ansichten auf die Mitglieder

`members.php` steht **jedem angemeldeten Mitglied** offen und zeigt bewusst
nur vier Angaben je Person: Profilbild, Spitzname, Geburtstag und Anzahl der
hochgeladenen Bilder.

`admin.php` bleibt Administratoren vorbehalten (sonst HTTP 403) und zeigt
zusätzlich Benutzername, Rolle, erhaltene Likes, letzte Anmeldung und alle
Verwaltungsaktionen. Beide Seiten sind getrennte Dateien – so kann eine
Erweiterung der Verwaltung nicht versehentlich Daten in die offene Übersicht
schwappen lassen.

In der Navigation heißt die offene Liste **Mitglieder**, die geschützte Seite
**Verwaltung**; letztere erscheint nur bei Administratoren.

## Hochladen und Herunterladen

Über der Galerie stehen zwei Schaltflächen. **Bilder hochladen** ist ein
`<label>` für ein verstecktes Dateifeld – der Dialog öffnet sich dadurch auch
ohne JavaScript. Erst nach der Auswahl klappt eine Zeile mit Anzahl, Größe,
optionaler Beschreibung und dem eigentlichen Absenden auf. Am Rechner lassen
sich Dateien zusätzlich irgendwo auf der Seite fallen lassen.

Zwei Entscheidungen im Ablauf sind wichtig:

**Aufbereitet wird sofort nach der Auswahl, nicht erst beim Absenden.** Ein
`<input type="file">` hält nur einen Verweis auf die Datei; beim Absenden liest
der Browser sie erneut von der Platte und bricht mit „Your file couldn't be
accessed" (`ERR_UPLOAD_FILE_CHANGED`) ab, wenn sie inzwischen verschoben oder
verändert wurde. Auf Handys passiert das regelmäßig, weil Verweise aus der
Fotogalerie nur kurz gelten. Nach dem Verkleinern liegen die Bilder im
Arbeitsspeicher – auch die unveränderten werden dafür einmal eingelesen.

**Gesendet wird per `XMLHttpRequest`, nicht als Formular.** Bricht etwas ab,
bleibt die Seite stehen und zeigt eine verständliche Meldung, statt dass der
Browser auf eine eigene Fehlerseite wechselt. `upload.php` antwortet auf
`Accept: application/json` mit JSON; die Rückmeldungen bleiben dabei als
Flash-Nachrichten in der Session und erscheinen beim anschließenden
Seitenwechsel wie gewohnt. Der Fortschritt steht währenddessen auf der
Schaltfläche.

**Alle herunterladen** liefert die Bilder der aktuellen Ansicht (alle oder nur
die eigenen) als ZIP. Das Archiv entsteht in [includes/zip.php](includes/zip.php)
und wird direkt in die Ausgabe geschrieben: keine temporäre Datei auf dem
Webspace, konstanter Speicherbedarf. Die Bilder werden unkomprimiert abgelegt
(„stored"), weil Fotos ohnehin komprimiert sind – das spart Rechenzeit und
erlaubt ein exaktes `Content-Length`, sodass der Browser den Fortschritt zeigt.
Die Dateien heißen im Archiv `Datum_Spitzname_Originalname.jpg`.

Grenze: Das klassische ZIP-Format endet bei 4 GB pro Archiv (kein ZIP64).
Darüber bricht `download.php` mit einer Meldung ab, statt eine kaputte Datei
zu liefern.

## Bildgrößen und Serverlimits

PHP erlaubt von Haus aus nur **2 MB** je Upload – Handyfotos liegen heute bei
3–8 MB. Die Limits stehen deshalb in [.user.ini](.user.ini) (gilt bei
FastCGI/PHP-FPM, dem Normalfall unter Plesk) und zusätzlich in einem
`mod_php`-Block in [.htaccess](.htaccess) für den Fall, dass PHP als
Apache-Modul läuft:

| Einstellung | Wert | warum |
|---|---|---|
| `upload_max_filesize` | 25M | lässt auch Rohaufnahmen durch |
| `post_max_size` | 150M | mehrere Bilder in einem Rutsch |
| `memory_limit` | 256M | GD braucht ~4 Byte je Bildpunkt |
| `max_execution_time` | 300 | langsame Mobilfunk-Uploads |

Greifen die Werte nicht, setzt man sie in Plesk unter **Websites & Domains →
PHP-Einstellungen**. Die Anzeige im Upload-Feld nennt immer das tatsächlich
geltende Limit, sie liest es aus der laufenden PHP-Konfiguration.

**Der Hoster lässt diese Werte nicht ändern.** Auf cweb02 sind sie in der
PHP-FPM-Konfiguration mit `php_admin_value` festgezurrt; dagegen kommt weder
`.user.ini` noch `.htaccess` an. Nachgewiesen mit der Sonde `max_input_vars`
in der `.user.ini`: Sie greift, das Upload-Limit bleibt trotzdem bei 2 MB.
Die Karte „Serverumgebung" in der Verwaltung zeigt diesen Zustand im Klartext.

**Deshalb verkleinert der Browser, nicht der Server.** Vor dem Absenden rechnet
das Gerät jedes Bild auf `IMAGE_MAX_EDGE` (2560 px) herunter und kodiert es mit
`IMAGE_QUALITY` neu – ein 8,5-MB-Handyfoto geht dadurch als rund 1,2 MB auf die
Reise und passt unter das 2-MB-Limit. Nebeneffekt: Im Mobilfunk sind die
Uploads deutlich schneller. Die Werte reicht `index.php` als `data-`Attribute
an [assets/app.js](assets/app.js) durch, `data-limit` kommt dabei aus der
laufenden PHP-Konfiguration – hebt der Hoster das Limit irgendwann an, passt
sich alles von selbst an.

Regeln beim Verkleinern:

- **GIFs bleiben unangetastet**, sonst ginge eine Animation verloren.
- JPEG bleibt JPEG, PNG bleibt PNG, WEBP bleibt WEBP. **Alles andere wird zu
  JPEG** – vor allem HEIC von iPhones, das der Server sonst ablehnen würde.
  Die Dateiendung wird passend mitgeändert.
- **Mehrere Stufen:** Reicht die erste Verkleinerung nicht unter das
  Serverlimit, wird mit geringerer Güte und kleinerer Kante nachgelegt
  (bis 1536 px / Güte 0,7). Abgebrochen wird, sobald es passt.
- Wird das Ergebnis größer als das Original, wird das Original gesendet.
- Die EXIF-Ausrichtung wird beachtet, das Ergebnis ist fertig gedreht.
- **Ein Fehlschlag wird einmal wiederholt.** Auf Handys liefert der erste
  Zugriff auf ein Bild aus der Galerie gelegentlich nichts – etwa weil es noch
  aus der Cloud geladen wird oder der Speicher knapp ist.
- Fehlt eine der benötigten Browser-Funktionen, wird unverändert gesendet.
  Dann meldet der Server ein zu großes Bild – unschön, aber nichts geht kaputt.

Der Server nimmt Bilder so entgegen, wie sie ankommen; er skaliert nicht mehr.
Reicht der Speicher für die Vorschau eines sehr großen Bildes nicht, wird sie
übersprungen und die Galerie zeigt das Original. Das ist langsamer, aber es
bricht nichts ab.

## Likes

Jedes Mitglied kann jedes Bild einmal mit einem Herz markieren; ein zweiter
Klick nimmt den Like zurück. Die Anzahl steht an jeder Kachel und in der
Großansicht, der Tooltip nennt die Mitglieder, denen das Bild gefällt.

Der Klick läuft per `fetch` ohne Seitenwechsel; ohne JavaScript sendet
derselbe Button ein normales Formular an `like.php` und die Seite lädt neu.
Das Umschalten passiert innerhalb einer Dateisperre, gleichzeitige Klicks
mehrerer Mitglieder gehen also nicht verloren.

Beim Löschen eines Bildes oder eines Mitglieds werden die zugehörigen Likes
mit entfernt. Im Adminbereich zeigt die Spalte „Likes" je Mitglied, wie viele
Likes dessen Bilder insgesamt bekommen haben.

## Datenablage

| Ort               | Inhalt                                             |
|-------------------|----------------------------------------------------|
| `data/users.json` | Mitgliedskonten (Passwörter als bcrypt-Hash)          |
| `data/images.json`| Metadaten der Bilder                                |
| `data/likes.json` | Likes (je Mitglied und Bild höchstens einer)        |
| `data/throttle.json` | Fehlversuche beim Login je IP                    |
| `uploads/`        | Originalbilder                                      |
| `uploads/thumbs/` | Vorschaubilder (JPEG, längste Kante 700 px)         |
| `uploads/avatars/`| Profilbilder (JPEG, 320 × 320 px)                   |

Schreibzugriffe laufen über exklusive Dateisperren (`flock`), gleichzeitige
Uploads mehrerer Mitglieder sind damit unproblematisch.

Sicherung: es genügt, `data/` und `uploads/` zu kopieren.

## Benachrichtigungen

Mitglieder können sich per Web Push benachrichtigen lassen, wenn jemand neue
Bilder hochlädt oder wenn einem ihrer Bilder ein Like gegeben wird. Ein- und
ausgeschaltet wird das unter **Mein Konto → Benachrichtigungen**, getrennt nach
Anlass; angemeldet wird **je Gerät** einzeln.

Umgesetzt ist das ohne Fremdbibliothek in [includes/push.php](includes/push.php):

- **VAPID** (RFC 8292) weist den Server gegenüber den Push-Diensten aus. Das
  Schlüsselpaar wird beim ersten Aufruf erzeugt und liegt in
  `data/push_keys.json`. **Es darf nicht gelöscht werden** – sonst werden alle
  bestehenden Abos ungültig und müssen neu erteilt werden.
- **Verschlüsselung** nach RFC 8291 / RFC 8188 (`aes128gcm`) mit ECDH auf
  P-256, HKDF und AES-128-GCM. Die Umsetzung liefert für den Testvektor aus
  RFC 8291 Abschnitt 5 ein byte-identisches Ergebnis.
- Gebraucht werden nur OpenSSL mit ECDH und `hash_hkdf` – beides gehört seit
  PHP 7.3 zum Standard. Verschickt wird über cURL, ersatzweise über Streams.
- Verschickt wird erst **nach** dem Abschluss der Antwort
  (`fastcgi_finish_request`), damit niemand auf fremde Push-Dienste wartet.
- Meldet ein Dienst 404 oder 410, ist das Abo erloschen und wird entfernt.

Der Service Worker [sw.js](sw.js) nimmt die Nachrichten entgegen und zeigt sie
an; ein Klick öffnet die Galerie. Zwischengespeichert wird bewusst nichts.

**Auf dem iPhone** funktioniert das nur, wenn die Seite über „Teilen → Zum
Home-Bildschirm" abgelegt und von dort geöffnet wird (ab iOS 16.4). Dafür gibt
es [manifest.webmanifest](manifest.webmanifest). Auf Android genügt der Browser.

Der Zustand steht in der Verwaltung unter „Serverumgebung": ob Push
einsatzbereit ist, wie viele Geräte angemeldet sind und worüber verschickt wird.

## Erscheinungsbild

Das Favicon ist eine Kamerablende mit sechs Lamellen: `favicon.svg` für alles
Moderne, `favicon.ico` (16, 32 und 48 px) als Rückfallebene und
`apple-touch-icon.png` (180 px) für den Startbildschirm auf iOS. Die Geometrie
steht im SVG; Loch und Außenradius sind bewusst großzügig gewählt, damit die
Öffnung auch bei 16 px noch als solche zu erkennen ist.

## Sicherheit

- Passwörter als `password_hash()`-Bcrypt-Hash, nie im Klartext gespeichert.
- Session-Cookie `HttpOnly`, `SameSite=Lax`, `Secure` bei HTTPS;
  Session-ID wird bei Anmeldung und Passwortwechsel erneuert.
- CSRF-Token in allen Formularen.
- Brute-Force-Schutz: nach 10 Fehlversuchen je IP 15 Minuten Sperre.
- Uploads werden per `getimagesize()` geprüft; nur JPG, PNG, GIF und WEBP
  werden akzeptiert und unter einem neu erzeugten Dateinamen abgelegt.
- `uploads/`, `data/` und `includes/` sind per `.htaccess` direkt gesperrt;
  Bilder liefert `image.php`, Profilbilder `avatar.php` – beides nur an
  angemeldete Mitglieder.
- Profilbilder werden mit GD neu gerendert; dabei fallen EXIF-Daten
  (u. a. GPS-Koordinaten) und eingebettete Fremdinhalte weg.
- `robots.txt` und `X-Robots-Tag` halten Suchmaschinen fern.

## Handy-Ansicht

Das Layout ist für kleine Displays ausgelegt: Die Kopfzeile bricht in Marke,
Konto und eine seitlich scrollbare Navigationsleiste um, die Galerie zeigt
zwei Spalten, Eingabefelder verwenden 16 px (sonst zoomt iOS beim Antippen
hinein), Schaltflächen sind mindestens 44 px hoch. Die Mitgliedertabelle wird
unter 720 px Breite zu einer Kartenliste. In der Großansicht liegen die
Blätterknöpfe unten in Daumennähe, zusätzlich funktioniert Wischen nach links
und rechts.

Der zufällige Untertitel steht in einer Spalte mit fester Breite – seine Länge
verschiebt die Navigation daher nicht mehr.

## Konfiguration

Alle Stellschrauben stehen in `includes/config.php`: maximale Dateigröße,
Größe der Vorschaubilder, Bilder pro Seite, Mindestlänge der Passwörter,
Login-Sperre und Session-Timeout.

## Anforderungen

PHP 7.1 oder neuer. GD wird für Vorschaubilder genutzt; fehlt die Erweiterung,
zeigt die Galerie stattdessen die Originalbilder an (funktioniert, kostet aber
mehr Bandbreite).
