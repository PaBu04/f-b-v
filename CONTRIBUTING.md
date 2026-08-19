# Mitarbeit an f-b-v

Danke fürs Interesse. Das hier ist eine kleine, absichtlich einfache Anwendung:
reines PHP, kein Framework, keine Datenbank, keine Abhängigkeiten. Bitte halte
das so – ein Composer-Paket für eine Aufgabe, die zwanzig Zeilen PHP löst,
wird nicht übernommen.

## Fehler melden oder Funktionen vorschlagen

Über **Issues**. Bei Fehlern hilfreich: was passiert ist, was erwartet wurde,
welcher Browser und ob es am Handy oder am Rechner auftrat. Bei Bildern bitte
Dateiformat und Größe angeben – die meisten Upload-Probleme hängen daran.

## Änderungen einreichen

1. Repository forken, Zweig anlegen (`git switch -c kurze-beschreibung`).
2. Änderung möglichst klein halten – ein Thema pro Pull Request.
3. Vor dem Absenden prüfen:
   ```
   php -l <geänderte Datei>      # für jede PHP-Datei
   node --check assets/app.js    # falls JavaScript berührt wurde
   ```
4. Pull Request gegen `main` öffnen und beschreiben, **warum** die Änderung
   nötig ist, nicht nur was sie tut.

## Was beim Prüfen wichtig ist

- **Keine Mitgliederdaten committen.** `data/` und `uploads/` sind in der
  `.gitignore`. Wer sie hinzufügt, veröffentlicht Passwort-Hashes, Geburtsdaten
  und private Fotos.
- **Kein Vertrauen auf JavaScript für Sicherheit.** Prüfungen gehören auf den
  Server; das Skript im Browser ist Komfort.
- **CSRF-Token** in jedem Formular, das etwas verändert (`csrf_field()` und
  `csrf_require()`).
- **Ausgaben escapen** mit `h()`.
- **Schreibzugriffe** auf die JSON-Ablage nur über `store_mutate()` – das
  sperrt die Datei, damit gleichzeitige Zugriffe sich nicht überschreiben.
- Umlaute direkt schreiben (`ä`, nicht `ae`), Dateien in UTF-8 ohne BOM.
- Kommentare und Oberfläche auf Deutsch.

## Lokal ausprobieren

PHP 7.4 oder neuer mit den Erweiterungen `gd`, `mbstring`, `exif` und
`openssl`, dann:

```
php -S 127.0.0.1:8000
```

Beim ersten Aufruf führt `setup.php` durch das Anlegen eines Administrators.
Der eingebaute Server beachtet keine `.htaccess` – lokal sind `data/` und
`uploads/` daher direkt erreichbar. Auf dem echten Server greifen die Sperren.

Weitere Einzelheiten zum Aufbau stehen in der [README](README.md).
