# Server einrichten

Die Schnittstelle läuft auf gewöhnlichem Webhosting mit PHP und MariaDB. Sie braucht keine Hintergrunddienste, nur den Einbettungsdienst für die Suche nach Bedeutung. Ohne ihn fällt `bedeutung_suchen` auf den Volltext zurück.

## Voraussetzungen

- **PHP 8.1** oder neuer mit PDO MySQL. APCu ist optional, ohne APCu entfallen die Anfragegrenzen.
- **MariaDB 11.7** oder neuer, wegen des Datentyps `VECTOR` und `VEC_DISTANCE_COSINE`. Die Sortierung `utf8mb4_uca1400_ai_ci` macht die Volltextsuche unabhängig von Groß- und Kleinschreibung sowie von Akzenten.
- **Apache** mit `mod_rewrite`, damit `/mcp` ohne Dateiendung und ohne Weiterleitung erreichbar ist. MCP-Clients folgen bei POST keiner Umleitung.
- **Einbettungsdienst:** ein llama-server mit Qwen3-Embedding-0.6B, siehe [aufbereitung.md](aufbereitung.md#4-einbetten--einbettenpy). Eine CPU mit vier Kernen genügt, eine Anfrage dauert dort etwa 0,15 Sekunden.

## Verzeichnisse

Programmcode und Zugangsdaten liegen außerhalb des Webroots:

```
~/poc/config.php                  Zugangsdaten (Vorlage: server/config.beispiel.php)
~/poc/afd/                        Inhalt von server/lib/
~/poc/afd/daten/                  Daten für die Importskripte (abschnitte.json … fragen_vektoren.jsonl)
~/poc/afd/logs/                   Monatsprotokolle, legt der Server selbst an
~/public_html/poc/afd-gutachten/  Inhalt von server/web/, dazu index.html, seite.css, seite.js aus seite/
```

`server/web/mcp.php` bindet `~/poc/afd/mcp_server.php` ein. Bei anderen Pfaden ist nur diese Zeile anzupassen. Die Adressen in `mcp_server.php` (`AFD_ICONS`, `websiteUrl`, Infoseite) und in `seite/bauen.py` (`ADRESSE`) zeigen auf poc.deutschkaemer.info und sind für einen eigenen Betrieb zu ändern.

## Datenbank

Zwei Datenbankkonten:

| Rolle | Rechte | genutzt von |
|---|---|---|
| `lesen` | nur `SELECT` | MCP-Schnittstelle (mit `max_statement_time = 15`) |
| `admin` | Tabellen anlegen, schreiben | Importskripte auf der Kommandozeile |

Beide stehen mit Host und Datenbankname in `~/poc/config.php`. Die Datei gehört nicht ins Repository.

```php
return [
    'host'  => 'localhost',
    'name'  => 'poc_db',
    'lesen' => ['user' => 'poc_lesen', 'pass' => '…'],
    'admin' => ['user' => 'poc_admin', 'pass' => '…'],
    'einbettung_url' => 'http://127.0.0.1:1240/v1',   // optional
];
```

## Daten laden

Alle Importskripte laufen nur auf der Kommandozeile und legen ihre Tabellen neu an:

```bash
cd ~/poc/afd
php import.php daten                                   # afd_abschnitt, afd_absatz, afd_fussnote, afd_verweis, afd_meta
php import_vektoren.php daten/vektoren.jsonl           # afd_vektor (mit Vektorindex)
php import_fragen.php daten/fragen_vektoren.jsonl --neu  # afd_frage_vektor, afd_frage_kurz
php import_normen.php normen.json daten/norm_vektoren.jsonl
php import_herausgeberin.php herausgeberin.json daten/herausgeberin_vektoren.jsonl
```

`import_fragen.php` ohne `--neu` ergänzt nur fehlende Zeilen. So kam der Anhang nachträglich hinzu.

| Tabelle | Inhalt | Zeilen |
|---|---|---:|
| `afd_abschnitt` | Gliederung mit Seiten, Pfad, Kennzahlen | 2.455 |
| `afd_absatz` | Absätze mit Fußnotenverweisen `[^n]`, Volltextindex | 15.179 |
| `afd_fussnote` | Fußnoten, Links, Beleg bei FragDenStaat | 7.467 |
| `afd_verweis` | welche Fußnote in welchem Absatz steht | rund 7.500 |
| `afd_vektor` | Vektoren der Textteile, 384 Dim., Vektorindex | 13.967 |
| `afd_frage_vektor` | Vektoren der erzeugten Fragen, 384 Dim. | 45.775 |
| `afd_frage_kurz` | dieselben, auf 64 Dim. gekürzt, für die Vorauswahl | 45.775 |
| `afd_norm`, `afd_norm_vektor` | Art. 21 GG | 5 |
| `afd_herausgeberin`, `afd_herausgeberin_vektor` | Angaben zur GFF, mit Fragen | 6 / 35 |
| `afd_meta` | Stand, Quelle, Lizenz | |

## Einbettungsdienst anbinden

`afd_anfrage_vektor()` holt den Vektor einer Suchanfrage auf zwei möglichen Wegen:

1. **`einbettung_url` in `config.php`:** Der Server ruft `POST {einbettung_url}/embeddings` auf, eine Schnittstelle nach Art von OpenAI, und kürzt das Ergebnis auf 384 Dimensionen. Der Dienst muss vom Webserver aus erreichbar sein, etwa über einen SSH-Tunnel oder ein VPN zu einem Rechner im eigenen Netz.
2. **Ohne Eintrag:** Der Server nutzt den Einbettungsweg von [plenarprotokolle.digital](https://plenarprotokolle.digital/) auf demselben Hosting-Konto (`~/public_html/bundestag/lib/vektor_lib.php`). So läuft die Live-Fassung.

Liefert keiner der Wege innerhalb von 8 Sekunden einen Vektor, antwortet `bedeutung_suchen` mit einer Volltextsuche und einem Hinweis darauf.

## Betrieb

- **Anfragegrenzen** (mit APCu): 240 Anfragen je Minute insgesamt. Je Netzbereich (/24 bzw. /48) gelten 120 je Minute und 1.500 je Stunde.
- **Herkunft:** Browser-Anfragen werden nur von den in `AFD_ORIGINS` genannten Seiten angenommen (claude.ai, chatgpt.com, die Projektseite). Clients ohne `Origin`-Kopf, also Desktop-Programme und Server, sind nicht betroffen.
- **Protokoll:** Je Aufruf werden Zeit, Werkzeug, die ersten 300 Zeichen der Argumente, Dauer und gegebenenfalls ein Fehler geschrieben, ohne IP-Adresse. Monatsdateien älter als drei Monate löscht der Server selbst.
- **Infoseite:** Ein Aufruf von `/mcp` im Browser (GET) zeigt eine kurze Seite mit der Connector-Adresse.

## Prüfen

```bash
curl -s -X POST https://poc.deutschkaemer.info/afd-gutachten/mcp \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"bedeutung_suchen","arguments":{"frage":"Wurde bei dem Gutachten KI benutzt?","anzahl":3}}}'
```

[`messung/gff_test.sh`](../messung/gff_test.sh) prüft auf dieselbe Weise, ob Fragen zur Herausgeberin die Angaben zur GFF auslösen und inhaltliche Fragen nicht.
