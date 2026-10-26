# Changelog

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
