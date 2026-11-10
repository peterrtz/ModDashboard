# Changelog

## 1.3.0-alpha.1 (ILIAS 12) – 2026-11-10

- **Weitermachen** nur noch für gewählte Objekttypen (Standard: Lernmodul, Lernsequenz, Übung, Test,
  SCORM) und nur mit aktivem Lernfortschritt. Dateien, Weblinks, Kurse oder Gruppen rücken nicht
  mehr als Ersatz nach; sie bleiben unter „Zuletzt angesehen“.
- Gibt es nichts zum Weitermachen: kurzer Hinweis (Standard) oder Kasten ausblenden – einstellbar.
- **Persönliche Leiste** (optional, in der Konfiguration freizugeben): Favoriten (alle) und bis zu vier
  eigene Links; jede Person richtet sie unter „Anpassen“ selbst ein oder schaltet sie ab.
  Gespeichert in den Nutzereinstellungen, keine eigenen Tabellen; Leserecht wird bei jeder Anzeige geprüft.
- **Icons:** zehn weitere in der Standardauswahl (u. a. `chalkboard-teacher`, `exam`, `video-camera`);
  vollständige Phosphor-Bibliothek (1.512 Icons, regular, MIT) im Plugin, per Konfiguration freizugeben.
- Handy-Ansicht: Kasten nur mit „Zuletzt angesehen“ entfällt; Leisten-Chips wischbar statt untereinander.
- Update: neue GUI-Klasse – `composer dump-autoload` und `php cli/setup.php build` erforderlich.

## 1.2.0-alpha.1 (ILIAS 12) – 2026-10-27

- Erste Vorabversion für ILIAS 12 (Branch `release_12`), Funktionsumfang wie 1.2.0 für ILIAS 11.
- Getestet auf ILIAS `trunk` („12.0_alpha“) mit PHP 8.4. Nicht für den Betrieb.

## 1.2.0 – 2026-10-27

- Eigene Branches je ILIAS-Version: `release_11`, `release_10`, Entwicklung in `trunk`.
- Keine funktionalen Änderungen gegenüber 1.1.0 für ILIAS 11.

## 1.1.0 – 2026-10-26

- Farben in der Konfiguration: „Aus dem Design übernehmen“ (Standard) oder eigene Farben per
  Farbwähler für Kopfbereich, Akzent, Frist-Markierung und Hintergrund „Weitermachen“.
  Dunklere Töne werden abgeleitet; zu geringer Kontrast (unter 4,5:1) wird beim Speichern abgelehnt.
  Ausgabe nur als geprüfte `#RRGGBB`-Werte in CSS-Variablen am Willkommensbereich.

## 1.0.0 – 2026-10-26

Erste öffentliche Version.

- Willkommensbereich mit Begrüßung nach Tageszeit, Datum und Einleitung (Du- oder Sie-Form)
- Nächste Frist aus den abgeleiteten Aufgaben, hervorgehoben unter 48 Stunden
- „Weitermachen“ nach Lernfortschritt (angefangen vor nicht begonnen), mit Fortschrittsbalken
- „Zuletzt angesehen“ (bis zu drei Einträge) und Wochenbilanz abgeschlossener Lerneinheiten
- Schnellzugriffe und bis zu vier eigene Links mit Icon
- Einschränkung auf mehrere globale und lokale Rollen; lokale Rollen per Suche mit Autovervollständigung
- Option „Seitentitel ausblenden“ (Standard an) für Skins mit eigenem Kopfbereich
- Folgt den Schaltern der Dashboard-Administration (Aufgaben, Lernfortschritt für Lernende,
  Meine Kurse, Kalender)
- Gestaltung über Custom Properties `--mdash-*`, neutrale Standardwerte
- Lizenz: GPL-3.0-only, wie ILIAS
- Eigene Icon-Bibliothek über `icons-custom/` (lokal, bereinigtes Inline-SVG)
