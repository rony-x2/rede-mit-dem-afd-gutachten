#!/usr/bin/env python3
"""
teilen.py – Absätze des Gutachtens in Textteile für die Einbettung zerlegen.

Liest abschnitte.json und absaetze.json (aus parse.py) und schreibt abschnitte_einbettung.jsonl,
die Eingabe für einbetten.py. Regeln:
  - Absätze mit weniger als 6 Wörtern bleiben außen vor (Tabellenzellen, Silbenreste, Zwischentitel);
    sie sind weiter über die Volltextsuche auffindbar.
  - Lange Absätze werden in Teile zu 350 Wörtern zerlegt (Grenze an Leerzeichen); ein Rest unter
    15 Wörtern am Ende entfällt (das Modell sieht ohnehin nur den Anfang langer Texte scharf).
  - Jeder Teil beginnt mit „<Gliederungsnummer> – <Überschrift>“, damit der Vektor den Zusammenhang kennt;
    Zitate erhalten zusätzlich „Belegzitat: “. Fußnotenverweise [^n] werden entfernt.
  - start/laenge geben die Lage des Teils im Absatztext an (Zeichen), absatz_id ist die laufende
    Nummer des Absatzes ab 1.

  python3 teilen.py [--daten ../daten] [--aus abschnitte_einbettung.jsonl]
"""
import argparse, json, re
from pathlib import Path

WOERTER_MIN = 6
WOERTER_TEIL = 350
REST_MIN = 15
RE_FN = re.compile(r'\[\^\d+\]')

ap = argparse.ArgumentParser()
ap.add_argument('--daten', default='out')
ap.add_argument('--aus', default='abschnitte_einbettung.jsonl')
a = ap.parse_args()

d = Path(a.daten)
abschnitte = {s['id']: s for s in json.load(open(d / 'abschnitte.json', encoding='utf-8'))}
absaetze = json.load(open(d / 'absaetze.json', encoding='utf-8'))


def teile(text):
    """Liefert (start, laenge) je Teil; Wortgrenzen am Leerzeichen."""
    woerter = [m.span() for m in re.finditer(r'\S+', text)]
    if len(woerter) <= WOERTER_TEIL:
        return [(0, len(text))]
    erg = []
    for i in range(0, len(woerter), WOERTER_TEIL):
        if i and len(woerter) - i < REST_MIN:
            break
        st, en = woerter[i][0], woerter[min(i + WOERTER_TEIL, len(woerter)) - 1][1]
        erg.append((st, en - st))
    return erg


n = 0
with open(a.aus, 'w', encoding='utf-8') as f:
    for aid, ab in enumerate(absaetze, 1):
        text = ab['text']
        if len(text.split()) < WOERTER_MIN:
            continue
        s = abschnitte[ab['abschnitt']]
        kopf = f"{s['nummer']} – {s['titel']}\n" + ('Belegzitat: ' if ab['typ'] == 'zitat' else '')
        for teil, (st, ln) in enumerate(teile(text)):
            f.write(json.dumps({'absatz_id': aid, 'teil': teil, 'start': st, 'laenge': ln,
                                'text': kopf + RE_FN.sub('', text[st:st + ln])}, ensure_ascii=False) + '\n')
            n += 1
print('Teile:', n)
