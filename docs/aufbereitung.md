# Aufbereitung: von der PDF zu den Tabellen

Die Skripte in [`aufbereitung/`](../aufbereitung) arbeiten in einem gemeinsamen **Arbeitsverzeichnis** (im Folgenden `arbeit/`) und werden von dort aus aufgerufen. Zwischendateien entstehen dort bzw. im Unterordner `out/`. Keine dieser Dateien gehört ins Repository (siehe `.gitignore`).

```
arbeit/
├── src/gutachten.pdf                PDF der GFF
├── out/                             extract.py, parse.py
│   ├── seiten_roh.pkl
│   ├── abschnitte.json  absaetze.json  fussnoten.json  seiten.json  bericht.txt
├── abschnitte_einbettung.jsonl      teilen.py
├── vektoren.jsonl                   einbetten.py
├── anreicherung.jsonl               anreichern.py
├── fragen_einbettung.jsonl          fragen_datei.py
└── fragen_vektoren.jsonl            einbetten.py
```

Voraussetzungen: Python 3.11 oder neuer mit `pymupdf`, `requests` und `numpy` (nur für die Messungen), außerdem ein [llama.cpp](https://github.com/ggml-org/llama.cpp)-Server für Einbettung und Anreicherung.

```bash
python3 -m venv venv && ./venv/bin/pip install pymupdf requests numpy
```

## 1. PDF auslesen – `extract.py`

```bash
python3 ../aufbereitung/extract.py
```

Liest alle 3.062 Seiten mit PyMuPDF und speichert je Seite die Zeilen samt Schriftart, Schriftgröße und Satzmerkmalen sowie waagrechte Linien und Links in `out/seiten_roh.pkl`. Gelesen wird mit zwei Prozessen parallel in Blöcken zu je 100 Seiten. Das dauert einige Minuten und muss nur einmal laufen.

## 2. Gliedern – `parse.py`

```bash
python3 ../aufbereitung/parse.py
```

Der Parser verwendet keinen Fließtext-Export, sondern die Satzmerkmale des Layouts:

| Merkmal im Layout | Bedeutung |
|---|---|
| Seitenkopf „– 401 –“ (y < 60) | gedruckte Seitenzahl |
| Trennstrich ab x ≈ 113, darunter nur 10,1 pt | Beginn des Fußnotenbereichs |
| hochgestellte Ziffern im Text | Fußnotenverweis, im Text als `[^n]` |
| Ziffer ≤ 6,5 pt am Zeilenanfang | Beginn einer Fußnote |
| Dosis 43,5/48 pt bzw. 27,8 pt, fett 13,9/11,5 pt mit Nummer bei x ≈ 71 | Überschriften der Ebenen Teil, A., I., 1., a) … |
| eingerückt ab x ≥ 125 | Zitat (im Anhang: Belegzitat) |

Aus der Gliederung entstehen Gliederungsnummern wie im Gutachten, etwa `Teil 3 A.III.2.c)cc)(1)`. Silbentrennungen am Zeilenende werden aufgelöst, Gedankenstriche vor „und“, „oder“ usw. bleiben stehen. Fußnoten, die über eine Seite hinauslaufen, werden zusammengesetzt. Links in Fußnoten werden erkannt, Links auf die AfD-Datenbank von FragDenStaat gesondert gespeichert.

`out/bericht.txt` fasst das Ergebnis zusammen und zeigt Unstimmigkeiten. Für die veröffentlichte Fassung ergab sich:

```
PDF-Seiten: 3062, mit Seitenzahl: 3060
Abschnitte: 2455  je Rang: {0: 10, 1: 32, 2: 98, 3: 181, 4: 244, 5: 309, 6: 674, 7: 796, 8: 111}
Absätze: 15179  je Typ: {'text': 8106, 'liste': 80, 'zitat': 6993}
Fußnoten: 7467 (höchste 7468), mit Datenbank-Link: 3533, mit URL: 2840
Fehlende Fußnotennummern: 1 [5456]
Fußnoten ohne Verweis: 1 [7016]
```

Die Fußnote 5456 fehlt schon in der PDF, die Fußnote 7016 hat dort keinen Verweis im Text.

## 3. In Teile zerlegen – `teilen.py`

```bash
python3 ../aufbereitung/teilen.py --daten out
```

Bereitet die Absätze für die Einbettung vor und schreibt `abschnitte_einbettung.jsonl` (13.967 Teile).

- Absätze mit weniger als 6 Wörtern bleiben außen vor: Tabellenzellen, Silbenreste, Zwischentitel. Über die Volltextsuche sind sie weiter auffindbar.
- Absätze über 350 Wörter werden in Teile zu 350 Wörtern zerlegt. Ein Rest unter 15 Wörtern entfällt.
- Jeder Teil beginnt mit `<Gliederungsnummer> – <Überschrift>`, Belegzitate zusätzlich mit `Belegzitat: `. So kennt der Vektor den Zusammenhang, auch wenn der Absatz selbst ihn nicht nennt.
- Fußnotenverweise `[^n]` werden für die Einbettung entfernt.
- `start` und `laenge` geben die Lage des Teils im Absatztext an. Die Suche zeigt damit den passenden Ausschnitt.

## 4. Einbetten – `einbetten.py`

Ein llama-server mit [Qwen3-Embedding-0.6B](https://huggingface.co/Qwen/Qwen3-Embedding-0.6B) (GGUF, Q8_0) genügt, auch auf der CPU:

```bash
llama-server -m Qwen3-Embedding-0.6B-Q8_0.gguf --embedding --pooling last \
  -t 4 -np 2 -c 4096 -ub 2048 -b 2048 --host 127.0.0.1 --port 1240

python3 ../aufbereitung/einbetten.py                      # abschnitte_einbettung.jsonl -> vektoren.jsonl
```

Die Vektoren werden auf 384 Dimensionen gekürzt. Das Modell ist darauf trainiert (Matryoshka-Darstellung), sodass die Kürzung kaum Genauigkeit kostet. Danach werden die Vektoren normiert und als float32 in Base64 gespeichert. Ein abgebrochener Lauf setzt beim nächsten Aufruf fort. Auf einer Grafikkarte dauert das ganze Gutachten wenige Minuten, auf der CPU deutlich länger.

Texte werden **ohne** Anweisung eingebettet, Suchanfragen **mit** der Anweisung, die auch in `mcp_server.php` steht (`AFD_VEK_ANWEISUNG`). Qwen3-Embedding ist so für die Suche nach Dokumenten zu einer Frage gedacht.

Für die Zusatzdaten (Art. 21 GG, Angaben zur GFF) schreibt `zusatz_einbettung.py` die Eingabedateien:

```bash
python3 ../aufbereitung/zusatz_einbettung.py
python3 ../aufbereitung/einbetten.py --ein norm_einbettung.jsonl --aus norm_vektoren.jsonl
python3 ../aufbereitung/einbetten.py --ein herausgeberin_einbettung.jsonl --aus herausgeberin_vektoren.jsonl
```

## 5. Anreicherung mit Fragen

Die Frage eines Menschen und der Absatz, der sie beantwortet, sind sich sprachlich oft fern. „Wurde bei dem Gutachten KI benutzt?“ steht so nirgends im Text, die Antwort aber schon. Deshalb schreibt ein lokales Sprachmodell zu jedem Teil drei bis fünf Fragen in Alltagssprache, die dieser Teil beantwortet, dazu Suchbegriffe. Die Fragen dienen nur der Suche. Antworten kommen immer aus dem Originaltext.

```bash
# Sprachmodell, hier Gemma 4 26B-A4B in Q4_K_M (Mixture of Experts, 17 GB, passt auf eine 24-GB-Karte)
llama-server -m gemma-4-26B-A4B-it-Q4_K_M.gguf -ngl 99 -np 4 -c 32768 --reasoning-budget 0 --port 1235

python3 ../aufbereitung/anreichern.py --grenze 40            # Probelauf mit 40 Teilen
python3 ../aufbereitung/anreichern.py                        # Hauptteil
python3 ../aufbereitung/anreichern.py --bereich anhang       # Belege im Anhang
```

- Mehrere `--backend URL,Modell,Parallelität` verteilen die Arbeit aus einer gemeinsamen Warteschlange, etwa auf zwei Grafikkarten.
- Die Antwort wird per JSON-Schema erzwungen. Scheitert das (bei Gemma gelegentlich, „Unexpected empty grammar stack“), folgt ein dritter Versuch ohne Schema, aus dem das JSON herausgeschnitten wird.
- Für Belegzitate im Anhang fragt der Prompt gezielt danach, wer sich wann wozu geäußert hat.
- `anreicherung.jsonl` wird fortlaufend geschrieben. Ein neuer Aufruf setzt dort fort.

Für die veröffentlichte Fassung wurden alle Teile des Hauptteils und 8.140 von 8.166 Teilen des Anhangs angereichert. 26 Belege scheiterten dauerhaft.

Danach wird jede Frage einzeln eingebettet, und zwar **mit derselben Anweisung wie eine Suchanfrage**. Verglichen wird dann Frage gegen Frage, und gleichbedeutende Formulierungen liegen fast deckungsgleich. Ohne Anweisung erreichte ein wörtlich identischer Text nur eine Nähe von 0,49.

```bash
python3 ../aufbereitung/fragen_datei.py                      # -> fragen_einbettung.jsonl
python3 ../aufbereitung/einbetten.py --ein fragen_einbettung.jsonl --aus fragen_vektoren.jsonl
```

Kennung jeder Frage: `absatz_id` und `teil = Absatzteil × 100 + Nummer der Frage`.

## 6. Laden auf dem Server

Siehe [server.md](server.md#daten-laden). Die Reihenfolge lautet `import.php` (Text), `import_vektoren.php`, `import_fragen.php`, `import_normen.php`, `import_herausgeberin.php`.
