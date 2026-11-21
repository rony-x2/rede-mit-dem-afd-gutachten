#!/usr/bin/env python3
"""fragen_datei.py – jede erzeugte Frage einzeln, mit derselben Anweisung wie Suchanfragen.

Frage–Frage-Vergleich: eine Nutzerfrage trifft so gleichbedeutende Fragen fast deckungsgleich.
Kennung: teil = absatzteil * 100 + Nummer der Frage. Ausgabe: fragen_einbettung.jsonl
"""
import json
from pathlib import Path

HIER = Path.cwd()   # Arbeitsverzeichnis mit den Zwischendateien (siehe docs/aufbereitung.md)
def daten(n): return HIER / n if (HIER / n).exists() else HIER / 'out' / n
ANW = ('Instruct: Given a question about a German legal opinion on whether the AfD party is unconstitutional, '
       'retrieve passages of the opinion that address it\nQuery: ')
n = 0
with open(HIER / 'fragen_einbettung.jsonl', 'w', encoding='utf-8') as f:
    for z in open(HIER / 'anreicherung.jsonl', encoding='utf-8'):
        d = json.loads(z)
        for k, q in enumerate(d['fragen'][:6], 1):
            f.write(json.dumps({'absatz_id': d['absatz_id'], 'teil': d['teil'] * 100 + k, 'start': 0, 'laenge': 0,
                                'text': ANW + q}, ensure_ascii=False) + '\n')
            n += 1
print('Fragen:', n)
