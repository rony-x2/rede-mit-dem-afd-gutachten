#!/usr/bin/env python3
"""pruefe_frage.py "Frage" – zeigt die besten Fragen-Treffer und Originaltext-Treffer mit Abschnitt (Fehlersuche)."""
import json, sys, base64
import numpy as np, requests
from pathlib import Path
H = Path.cwd()   # Arbeitsverzeichnis mit den Zwischendateien (siehe docs/aufbereitung.md)
def daten(n): return H / n if (H / n).exists() else H / 'out' / n
ANW = ('Instruct: Given a question about a German legal opinion on whether the AfD party is unconstitutional, '
       'retrieve passages of the opinion that address it\nQuery: ')
ab = {s['id']: s for s in json.load(open(daten('abschnitte.json'), encoding='utf-8'))}
aa = json.load(open(daten('absaetze.json'), encoding='utf-8'))
nr = lambda aid: ab[aa[aid - 1]['abschnitt']]['nummer']
frage = sys.argv[1]
v = np.array(requests.post('http://127.0.0.1:1240/v1/embeddings', json={'input': [ANW + frage], 'model': 'e'}).json()['data'][0]['embedding'][:384])
v /= np.linalg.norm(v)
fr = {}
for z in open(H / 'fragen_einbettung.jsonl', encoding='utf-8'):
    d = json.loads(z); fr[(d['absatz_id'], d['teil'])] = d['text'].split('Query: ', 1)[1]
for datei, titel in (('fragen_vektoren.jsonl', 'Fragen'), ('vektoren.jsonl', 'Originaltext')):
    ids, M = [], []
    for z in open(H / datei, encoding='utf-8'):
        d = json.loads(z); ids.append((d['absatz_id'], d['teil'])); M.append(np.frombuffer(base64.b64decode(d['v']), dtype='<f4'))
    s = np.vstack(M) @ v
    print(f'== {titel}')
    for i in np.argsort(-s)[:8]:
        k = ids[i]
        print(f'{s[i]:.3f}  {nr(k[0]):32s}  {fr.get(k, "")[:80]}')
# Wie gut treffen die Fragen zu einem bestimmten Abschnitt?
if len(sys.argv) > 2:
    ziel = sys.argv[2]
    best = []
    for (aid, t), q in fr.items():
        if nr(aid).startswith(ziel):
            best.append(q)
    print(f'== {len(best)} Fragen in {ziel}, z. B.:', best[:6])
