# Werkzeuge und Einbindung

## Protokoll

- MCP über **Streamable HTTP** an `https://poc.deutschkaemer.info/afd-gutachten/mcp`. Unterstützt werden die Protokollversionen 2025-11-25, 2025-06-18 und 2025-03-26.
- Nur `POST`. Ein `GET` im Browser zeigt eine Infoseite, ein `GET` mit `Accept: text/event-stream` liefert 405, weil der Server keinen Rückkanal anbietet.
- Keine Anmeldung und keine Sitzung, jeder Aufruf steht für sich.
- Alle Werkzeuge sind als `readOnlyHint` gekennzeichnet. Clients müssen einzelne Aufrufe deshalb nicht freigeben lassen.
- `serverInfo` enthält Titel, Webseite und Zeichen (`icons`, ab Protokoll 2025-11-25). Claude zeigt das Zeichen beim Connector an.

## Werkzeuge

| Werkzeug | Parameter | Ergebnis |
|---|---|---|
| `ueberblick` | – | Herausgeberin, Fragestellung, Umfang, oberste Gliederung mit Seiten, Ergebnis-Abschnitte, Lizenz |
| `inhaltsverzeichnis` | `nummer`, `tiefe` (1–4) | Gliederung ab einem Abschnitt, mit Seiten und Zahl der Unterabschnitte |
| `suchen` | `anfrage`, `bereich` (alles · gutachten · anhang · fussnoten), `innerhalb`, `anzahl` | Volltextfundstellen mit Seite, Gliederungsnummer, Ausschnitt; `Wort*` für den Wortanfang, „feste Wendung“ in Anführungszeichen |
| `bedeutung_suchen` | `frage`, `bereich`, `innerhalb`, `anzahl` | Fundstellen zur Frage in eigenen Worten, siehe [suche.md](suche.md); bei Fragen zur Herausgeberin zusätzlich deren Angaben |
| `abschnitt_lesen` | `nummer`, `ab_absatz`, `fussnoten` | Text eines Abschnitts mit Pfad, Seiten, Unterabschnitten und Fußnoten; lange Abschnitte in Teilen (jede Antwort höchstens 60.000 Zeichen) |
| `seite_lesen` | `seite` (1–3060) | eine gedruckte Seite: Überschriften, Absätze, Fußnoten |
| `fussnote` | `nr` (Zahl oder Liste bis 30) | Fußnotentext, Links, Beleg in der AfD-Datenbank von FragDenStaat, Fundort |
| `rechtsgrundlage` | `absatz` (1–5) | Wortlaut von Art. 21 GG, als Gesetzestext gekennzeichnet |
| `herausgeberin` | – | Impressum, Initiative, Finanzierung, Selbstdarstellung der GFF, Wikipedia-Zusammenfassung, jeweils mit Art und Quelle |
| `search` | `query` | vereinfachte Suche für ChatGPT ohne Entwicklermodus: Abschnitte mit `id`, Titel, URL |
| `fetch` | `id` | Volltext eines Abschnitts aus `search` |

`nummer` ist eine Gliederungsnummer wie im Gutachten, etwa `Teil 3 A.III.2.c)` oder `Zusammenfassung 1`. Alternativ geht die Kennung aus einer früheren Antwort. Jedes Ergebnis enthält Links auf die passende Seite der PDF (`…GFF_AfD-Gutachten.pdf#page=…`).

## Anweisungen an das Modell

Beim Verbindungsaufbau gibt der Server dem Modell `instructions` mit. Sie legen fest, wie es mit den Daten antworten soll:

- Das Gutachten ist die Bewertung einer zivilgesellschaftlichen Organisation und keine gerichtliche Entscheidung. Aussagen werden als Feststellung des Gutachtens kenntlich gemacht („laut Gutachten“, „die GFF kommt zu dem Ergebnis …“).
- Keine eigene Wertung der Partei, von Personen oder der GFF.
- Bei Fragen zur Partei selbst kommen zuerst die dokumentierten Äußerungen mit Urheber, Datum und Beleg, danach getrennt davon die rechtliche Einordnung durch das Gutachten.
- Jede Antwort zitiert mit Seitenzahl und, wo sinnvoll, mit Gliederungsnummer und verlinkt die Seite in der PDF.
- Art. 21 GG wird als Gesetzestext zitiert, nie mit einer Seitenzahl des Gutachtens.
- Die Quellenangabe nennt die GFF als Urheberin und die Lizenz CC BY 4.0.

Den genauen Wortlaut enthält `afd_anleitung()` in [`server/lib/mcp_server.php`](../server/lib/mcp_server.php).

## Einbinden

Die Projektseite <https://poc.deutschkaemer.info/afd-gutachten/#einrichten> beschreibt die Einrichtung Schritt für Schritt. Sie deckt Claude, LM Studio, ChatGPT, Grok, Claude Code, Codex, Cursor, VS Code sowie die Schnittstellen von Anthropic und OpenAI ab. Für die Kommandozeile reicht:

```bash
claude mcp add --transport http afd-gutachten https://poc.deutschkaemer.info/afd-gutachten/mcp
codex mcp add afd-gutachten --url https://poc.deutschkaemer.info/afd-gutachten/mcp
```

Für Programme mit einer `mcp.json` (LM Studio, Cursor):

```json
{
  "mcpServers": {
    "afd-gutachten": { "url": "https://poc.deutschkaemer.info/afd-gutachten/mcp" }
  }
}
```
