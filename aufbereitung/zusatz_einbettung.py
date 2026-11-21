#!/usr/bin/env python3
"""
zusatz_einbettung.py – Eingaben für einbetten.py zu den Zusatzdaten außerhalb des Gutachtentextes.

  python3 zusatz_einbettung.py [--server ../server/lib]

Schreibt
  norm_einbettung.jsonl           Wortlaut von Art. 21 GG (normen.json), je Absatz ein Teil
  herausgeberin_einbettung.jsonl  Angaben zur GFF (herausgeberin.json): teil 0 = Text,
                                  ab teil 1 je eine typische Frage, mit derselben Anweisung wie
                                  Suchanfragen (Vergleich Frage gegen Frage)
Das Feld absatz_id ist hier die id des Eintrags.
"""
import argparse, json
from pathlib import Path

ANW = ('Instruct: Given a question about a German legal opinion on whether the AfD party is unconstitutional, '
       'retrieve passages of the opinion that address it\nQuery: ')

ap = argparse.ArgumentParser()
ap.add_argument('--server', default=str(Path(__file__).resolve().parent.parent / 'server' / 'lib'))
a = ap.parse_args()
lib = Path(a.server)


def zeile(f, i, teil, laenge, text):
    f.write(json.dumps({'absatz_id': i, 'teil': teil, 'start': 0, 'laenge': laenge, 'text': text},
                       ensure_ascii=False) + '\n')


with open('norm_einbettung.jsonl', 'w', encoding='utf-8') as f:
    for n in json.load(open(lib / 'normen.json', encoding='utf-8')):
        zeile(f, n['id'], 0, len(n['text']), f"Rechtsgrundlage: {n['bezeichnung']} ({n['gesetz']})\n{n['text']}")

with open('herausgeberin_einbettung.jsonl', 'w', encoding='utf-8') as f:
    for h in json.load(open(lib / 'herausgeberin.json', encoding='utf-8')):
        zeile(f, h['id'], 0, len(h['text']), f"Über die Herausgeberin – {h['titel']}\n{h['text']}")
        for k, q in enumerate(h.get('fragen', []), 1):
            zeile(f, h['id'], k, 0, ANW + q)
print('fertig')
