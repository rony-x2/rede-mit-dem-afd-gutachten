# Projektseite

Die Seite <https://poc.deutschkaemer.info/afd-gutachten/> ist statisches HTML. `seite/bauen.py` erzeugt sie aus `seite/vorlage.html`:

```bash
cd seite && python3 bauen.py          # schreibt seite/index.html
```

`seite/index.html` ist die gebaute Seite, wie sie live läuft, und liegt mit im Repository. Hochgeladen werden `index.html`, `seite.css` und `seite.js` nach `/afd-gutachten/`, zusammen mit dem Inhalt von `server/web/`. `seite/style.css` ist das gemeinsame Stylesheet aller PoC-Seiten und gehört in den Webroot (`/style.css`). Es legt Farben, Schrift, Kopf und Fuß fest, einschließlich der Akzentfarbe für Schrift (`--akzent-text`), die im hellen Bild mindestens 4,5:1 Kontrast erreicht.

Zum Ansehen ohne Server-Einrichtung genügt ein lokaler Webserver mit derselben Ordnerstruktur:

```bash
mkdir -p /tmp/poc/afd-gutachten
cp seite/style.css /tmp/poc/
cp seite/index.html seite/seite.css seite/seite.js server/web/*.svg server/web/*.png /tmp/poc/afd-gutachten/
cd /tmp/poc && python3 -m http.server 8000   # http://localhost:8000/afd-gutachten/
```

## Platzhalter in der Vorlage

| Platzhalter | wird ersetzt durch |
|---|---|
| `{{ADRESSE}}` | Connector-Adresse (`ADRESSE` in `bauen.py`) |
| `{{logo:datei.svg:klasse}}` | SVG aus `seite/logos/`, eingebettet; IDs, die per `url(#…)` verwiesen werden, bleiben erhalten |
| `{{ikon:name:klasse}}` | Strichpiktogramm aus `IKONEN` in `bauen.py` (24 × 24, `currentColor`) |
| `{{GESPRAECHE}}`, `{{PUNKTE}}` | animierte Beispielunterhaltungen aus `GESPRAECHE` und ihre Umschaltpunkte |
| `{{BEISPIELE}}` | Liste „Fragen zum Ausprobieren“ |
| `{{DIAGRAMM}}` | Ablaufdiagramm aus `seite/diagramm.py`, quer und hochkant (Smartphone), Farben aus den CSS-Variablen |

`bauen.py` bricht ab, wenn ein Platzhalter übrig bleibt oder ein Geviertstrich im Text steht.

Die Beispielunterhaltungen in `GESPRAECHE` sind echte Antworten der Schnittstelle, für die Seite gekürzt. Jede nennt das Werkzeug, das der Assistent aufgerufen hat, und verlinkt die Fundstelle in der PDF.

## Gestaltung

- Volle Breite bis 2.200 px, Raster mit drei oder vier Spalten, auf dem Smartphone einspaltig (Umbrüche bei 1.100, 960 und 640 px).
- Hell und dunkel nach Systemeinstellung. Auf Bildschirmen mit großem Farbraum gelten kräftigere Farben in Display P3 (`@media (color-gamut: p3)`, `oklch()`).
- `<meta name="darkreader-lock">`, damit die Erweiterung Dark Reader die Logos nicht unsichtbar umfärbt.
- Animationen sind dezent und entfallen bei `prefers-reduced-motion`: Tippen der Beispielunterhaltung, wandernde Punkte in „Wie das funktioniert“.
- Auf dem Smartphone (unter 640 px) klappt ein Knopf „Menü“ die Navigation auf (`aria-expanded`, Escape schließt). Ohne JavaScript bleiben alle Links sichtbar.
- Kontrast nach WCAG 2.2 AA in hell und dunkel; geprüft in den Breiten 320 bis 1920 px ohne seitliches Scrollen.

## Zeichen

Das Projektzeichen ist eine Sprechblase mit Waage. Es gehört zu einer Familie für die Proofs of Concept auf poc.deutschkaemer.info: Die Sprechblase steht für „Rede mit …“, das Zeichen innen für das Projekt, und PoCs ohne Chat bekommen ein abgerundetes Quadrat. `seite/zeichen/familie.py` erzeugt die Varianten als Linien- und Kachelfassung. Favicon und App-Zeichen liegen in `server/web/`. Die Projektfarbe ist ein Rostrot (`--projekt`, #b5523b hell bzw. #da6e55 dunkel). Sie hebt sich bewusst von den übrigen Akzentfarben ab.

## Diagramme

`seite/diagramm.py` zeichnet das Ablaufdiagramm aus einer Quelle für beide Orte: `bauen.py` bettet es inline in die Seite ein, dort richten sich die Farben nach den CSS-Variablen (hell, dunkel, Display P3). Auf dem Smartphone erscheint eine hochkant angeordnete Fassung mit etwas größerer Schrift. Für GitHub schreibt

```bash
cd seite && python3 diagramm.py
```

feste SVG-Dateien in hell und dunkel nach `docs/bilder/`. Dazu gehört auch das Diagramm zur Suche in `docs/suche.md`.
