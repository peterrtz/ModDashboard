# Eigene Icons

SVG-Dateien in diesem Ordner ergänzen die mitgelieferten Phosphor-Icons oder ersetzen sie,
wenn der Dateiname gleich ist (z. B. `sun.svg`, `clock-countdown.svg`).

- Dateiname = Icon-Name: Kleinbuchstaben, Ziffern und Bindestrich, z. B. `fa-star.svg`
- höchstens 32 KB je Datei; eine Datei = ein Icon
- Das Plugin liefert die Icons inline aus dem eigenen Server aus. Es entsteht keine
  Verbindung zu Phosphor, Font Awesome oder einem anderen Dienst.
- Beim Ausliefern werden die SVGs bereinigt: nur Formelemente (`path`, `circle`, `rect` …)
  und Darstellungsattribute bleiben, Skripte, Event-Handler, Links und Kommentare entfallen.
  Ohne `fill`/`stroke` übernimmt das Icon die Textfarbe.

**Lizenz beachten:** Legen Sie die Lizenzdatei der verwendeten Icon-Bibliothek mit in diesen
Ordner (z. B. `LICENSE-fontawesome.txt`). Font Awesome Free (Icons) steht etwa unter
CC BY 4.0 und verlangt eine Namensnennung; Kommentare in den SVG-Dateien werden beim
Ausliefern entfernt, die Lizenzdatei hier bleibt maßgeblich.
