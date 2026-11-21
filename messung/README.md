# Messungen

Skripte, mit denen das Suchverfahren entwickelt und überprüft wurde. Ergebnisse und Begründungen stehen in [docs/suche.md](../docs/suche.md).

Die Python-Skripte laufen im Arbeitsverzeichnis der Aufbereitung (siehe [docs/aufbereitung.md](../docs/aufbereitung.md)). Die PHP-Skripte laufen auf dem Server neben `db.php` in `~/poc/afd/`.

| Datei | Zweck |
|---|---|
| `testsatz.json` | 46 Fragen in Alltagssprache mit den Abschnitten, in denen die Antwort steht (`z` Hauptteil, `zf` Zusammenfassung) |
| `messen.py` | Treffer@1/@5/@10 und MRR@10 je Variante: `live` (Schnittstelle), `vek`, `anr`, `kombi`, `max`, `fq`; Fusion mit `\|`, Reranker mit `+rr` |
| `unterschiede.py` | zwei Varianten Frage für Frage vergleichen |
| `pruefe_frage.py` | für eine Frage die nächsten erzeugten Fragen und Textteile zeigen (Fehlersuche) |
| `gff_test.sh` | prüft, wann die Angaben zur Herausgeberin beigefügt werden |
| `index_test.php` | Vektorindex gegen exakte Suche für einen Vektor |
| `index_m.php` | Trefferquote des Vektorindex bei anderem `M` |
| `kurz_test.php` | exakte Vorauswahl mit gekürzten Vektoren: Zeit und Trefferquote je Dimension |
| `exakt_zeit.php` | Laufzeit der exakten Suche über alle Fragen |
| `rr_test.sh` | Reranker auf CPU und Grafikkarte messen |
| `gewichte.sh` | Gewichte der Fusion in `mcp_server.php` setzen (nur zum Abstimmen) |
| `nachlauf.sh`, `nachlauf_anhang.sh` | Beispielabläufe nach einer Anreicherung: einbetten, messen, Modell entladen |

Die Ablaufskripte enthalten Pfade und Ports der eigenen Umgebung (llama-server-Router auf Port 1235, Einbettung auf 1240/1242) und sind als Vorlage gedacht.
