#!/usr/bin/env python3
"""
einbetten.py – Vektoren für das GFF-Gutachten (PoC poc.deutschkaemer.info/afd-gutachten).

Rechnet die Textabschnitte aus abschnitte_einbettung.jsonl mit einem Einbettungsmodell hinter einer
OpenAI-artigen Schnittstelle (llama-server --embedding) und schreibt vektoren.jsonl.
Dieselben Regeln wie KI/vektor_arbeiter.py (plenarprotokolle.digital): Qwen3-Embedding-0.6B,
gekürzt auf 384 Dimensionen (Matryoshka), danach normiert; Ausgabe float32 little endian als Base64.

  python3 einbetten.py [--url http://127.0.0.1:1240/v1] [--stapel 64]

Wiederaufnahme: bereits in vektoren.jsonl stehende Abschnitte werden übersprungen.
"""
import argparse, base64, json, math, os, struct, sys, time, urllib.request

ap = argparse.ArgumentParser()
ap.add_argument('--url', default='http://127.0.0.1:1240/v1')
ap.add_argument('--modell', default='qwen3-embedding-0.6b')
ap.add_argument('--dim', type=int, default=384)
ap.add_argument('--stapel', type=int, default=64)
ap.add_argument('--ein', default='abschnitte_einbettung.jsonl')
ap.add_argument('--aus', default='vektoren.jsonl')
a = ap.parse_args()

teile = [json.loads(z) for z in open(a.ein, encoding='utf-8')]
fertig = set()
if os.path.exists(a.aus):
    for z in open(a.aus, encoding='utf-8'):
        d = json.loads(z)
        fertig.add((d['absatz_id'], d['teil']))
offen = [t for t in teile if (t['absatz_id'], t['teil']) not in fertig]
print(f'{len(teile)} Abschnitte, {len(fertig)} schon fertig, {len(offen)} offen', flush=True)


def einbetten(texte):
    anf = urllib.request.Request(a.url + '/embeddings', method='POST', headers={'Content-Type': 'application/json'},
                                 data=json.dumps({'model': a.modell, 'input': texte}).encode('utf-8'))
    with urllib.request.urlopen(anf, timeout=300) as r:
        daten = json.loads(r.read())['data']
    daten.sort(key=lambda x: x['index'])
    aus = []
    for x in daten:
        v = x['embedding'][: a.dim]
        if len(v) < a.dim:
            raise ValueError(f'Modell liefert {len(v)} Dimensionen, gebraucht werden {a.dim}')
        n = math.sqrt(sum(y * y for y in v)) or 1.0
        aus.append(base64.b64encode(struct.pack(f'<{a.dim}f', *[y / n for y in v])).decode())
    return aus


t0 = time.time()
zeichen = 0
with open(a.aus, 'a', encoding='utf-8') as f:
    for i in range(0, len(offen), a.stapel):
        st = offen[i:i + a.stapel]
        for versuch in range(3):
            try:
                vs = einbetten([t['text'] for t in st])
                break
            except Exception as e:
                print('Fehler, neuer Versuch:', e, flush=True)
                time.sleep(5)
        else:
            sys.exit('Abbruch nach drei Fehlversuchen')
        for t, v in zip(st, vs):
            f.write(json.dumps({k: t[k] for k in ('absatz_id', 'teil', 'start', 'laenge')} | {'v': v}) + '\n')
        zeichen += sum(len(t['text']) for t in st)
        if (i // a.stapel) % 20 == 0:
            dt = time.time() - t0
            print(f'{i + len(st)}/{len(offen)}  {zeichen / max(dt, 0.01):,.0f} Zeichen/s', flush=True)
print(f'Fertig in {time.time() - t0:.0f} s', flush=True)
