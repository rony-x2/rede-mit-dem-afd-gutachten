#!/usr/bin/env python3
"""Vergleich der Bedeutungssuche im AfD-Gutachten (Hauptteil ohne Anhang/Literatur).

Varianten (soweit Daten vorhanden):
  live      – Live-Werkzeug bedeutung_suchen (Hybrid Vektor+Volltext, Bereich gutachten)
  vek       – nur Vektoren, Originalabsätze (0.6B, 384 Dim.)
  anr       – nur Vektoren der Anreicherung (Fragen/Stichwörter)
  max       – je Absatzteil das Maximum aus Original und Anreicherung
  kombi     – Original und Anreicherung als ein Text eingebettet
  … jeweils mit Suffix +rr: Top-40 mit Qwen3-Reranker nachsortiert

Kennzahlen: Treffer@1/@5/@10 und MRR@10, „streng“ (nur Fundstellen im Hauptteil)
und „mild“ (zusätzlich die passende Nummer der Zusammenfassung).
"""
import argparse, base64, json, struct, sys, time
from pathlib import Path
import numpy as np, requests

HIER = Path.cwd()   # Arbeitsverzeichnis mit den Zwischendateien (siehe docs/aufbereitung.md)
def daten(n): return HIER / n if (HIER / n).exists() else HIER / 'out' / n
ANW = ('Instruct: Given a question about a German legal opinion on whether the AfD party is unconstitutional, '
       'retrieve passages of the opinion that address it\nQuery: ')
HAUPT = ('Zusammenfassung', 'Einführung', 'Teil 1', 'Teil 2', 'Teil 3', 'Schluss')

ap = argparse.ArgumentParser()
ap.add_argument('--varianten', default='live,vek')
ap.add_argument('--rerank', default='', help='URL des Reranker-Servers, z. B. http://127.0.0.1:1241')
ap.add_argument('--k', type=int, default=10)
ap.add_argument('--rr_n', type=int, default=20)
ap.add_argument('--rr_zeichen', type=int, default=1000)
ap.add_argument('--aus', default='ergebnis.json')
a = ap.parse_args()

abschnitte = {s['id']: s for s in json.load(open(daten('abschnitte.json'), encoding='utf-8'))}
absaetze = json.load(open(daten('absaetze.json'), encoding='utf-8'))
abs_nummer = {i + 1: abschnitte[x['abschnitt']]['nummer'] for i, x in enumerate(absaetze)}
abs_haupt = {i + 1: abschnitte[x['abschnitt']]['pfad'][0].startswith(HAUPT) for i, x in enumerate(absaetze)}
tests = json.load(open(Path(__file__).parent / 'testsatz.json', encoding='utf-8'))


def passt(nummer, ziele):
    for p in ziele:
        if nummer == p or nummer.startswith(p + '.') or (p.endswith(')') and nummer.startswith(p)):
            return True
    return False


def lade_vektoren(datei):
    ids, teile, vs = [], [], []
    for z in open(datei, encoding='utf-8'):
        x = json.loads(z)
        if not abs_haupt.get(x['absatz_id']):
            continue
        b = base64.b64decode(x['v'])
        ids.append(x['absatz_id']); teile.append(x.get('teil', 0))
        vs.append(np.frombuffer(b, dtype='<f4'))
    return np.array(ids), np.array(teile), np.vstack(vs)


def texte_laden(datei):
    t = {}
    for z in open(datei, encoding='utf-8'):
        x = json.loads(z)
        t[(x['absatz_id'], x.get('teil', 0))] = x['text']
    return t


def frage_vektor(f, cache={}):
    if f not in cache:
        r = requests.post('http://127.0.0.1:1240/v1/embeddings', json={'input': [ANW + f], 'model': 'e'}, timeout=60)
        v = np.array(r.json()['data'][0]['embedding'][:384], dtype=np.float32)
        cache[f] = v / np.linalg.norm(v)
    return cache[f]


def rerank(frage, kandidaten, texte):
    n = a.rr_n
    """kandidaten: Liste (absatz_id, teil); Ergebnis: neu sortiert."""
    kand = kandidaten[:n]
    docs = [texte.get(k, '')[:a.rr_zeichen] for k in kand]
    r = requests.post(a.rerank + '/v1/rerank', json={'model': 'r', 'query': frage, 'documents': docs, 'top_n': len(docs)}, timeout=120)
    order = [x['index'] for x in sorted(r.json()['results'], key=lambda x: -x['relevance_score'])]
    return [kand[i] for i in order] + kandidaten[n:]


def live(frage):
    body = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call',
            'params': {'name': 'bedeutung_suchen', 'arguments': {'frage': frage, 'anzahl': a.k, 'bereich': 'gutachten'}}}
    r = requests.post('https://poc.deutschkaemer.info/afd-gutachten/mcp', json=body, timeout=60,
                      headers={'Accept': 'application/json, text/event-stream'})
    j = json.loads(r.json()['result']['content'][0]['text'])
    return [t['abschnitt'] for t in j['treffer']]


def bewerte(liste_nummern, t):
    def rang(ziele):
        for i, n in enumerate(liste_nummern[:a.k]):
            if passt(n, ziele):
                return i + 1
        return None
    return rang(t['z']), rang(t['z'] + t.get('zf', []))


def eindeutig(nummern_folge):
    """Gleiche Abschnitte zusammenfassen wie im Werkzeug (je Absatz nur der beste Teil)."""
    return nummern_folge


varianten = [v.strip() for v in a.varianten.split(',') if v.strip()]
daten = {}
if any('vek' in v or 'max' in v for v in varianten):
    daten['vek'] = lade_vektoren(HIER / 'vektoren.jsonl')
if any('anr' in v or 'max' in v for v in varianten):
    daten['anr'] = lade_vektoren(HIER / 'anreicherung_vektoren.jsonl')
if any('kombi' in v for v in varianten):
    daten['kombi'] = lade_vektoren(HIER / 'kombi_vektoren.jsonl')
if any('fq' in v for v in varianten):
    daten['fq'] = lade_vektoren(HIER / 'fragen_vektoren.jsonl')   # teil = absatzteil*100 + Fragennummer
texte = texte_laden(HIER / 'abschnitte_einbettung.jsonl') if a.rerank else {}


def suche(variante, frage):
    basis = variante.replace('+rr', '')
    if basis == 'live':
        return live(frage), None
    q = frage_vektor(frage)
    if basis in ('fq', 'vek|fq', 'vek|anr', 'vek|fq|anr'):
        listen = []
        for teil in basis.split('|'):
            ids, teile, m = daten[teil]; s = m @ q
            idx = np.argsort(-s)[:400]
            best, folge = set(), []
            for i in idx:
                k = (int(ids[i]), int(teile[i]) // 100 if teil == 'fq' else int(teile[i]))
                if k not in best:
                    best.add(k); folge.append(k)
            listen.append(folge[:200])
        rrf = {}
        for folge in listen:
            for r, k in enumerate(folge):
                rrf[k] = rrf.get(k, 0) + 1 / (60 + r + 1)
        kand = sorted(rrf, key=lambda k: -rrf[k])[:200]
    elif basis == 'max':
        ids, teile, m = daten['vek']; s = m @ q
        best = {}
        for i, sc in zip(zip(ids.tolist(), teile.tolist()), s.tolist()):
            best[i] = max(best.get(i, -9), sc)
        ids2, teile2, m2 = daten['anr']; s2 = m2 @ q
        for i, sc in zip(zip(ids2.tolist(), teile2.tolist()), s2.tolist()):
            best[i] = max(best.get(i, -9), sc)
        kand = sorted(best, key=lambda k: -best[k])[:200]
    else:
        ids, teile, m = daten[basis]; s = m @ q
        idx = np.argsort(-s)[:200]
        kand = [(int(ids[i]), int(teile[i])) for i in idx]
    t0 = time.time()
    if variante.endswith('+rr'):
        kand = rerank(frage, kand, texte)
    dt = time.time() - t0
    # wie das Werkzeug: je Absatz nur einmal
    gesehen, nummern = set(), []
    for aid, _ in kand:
        if aid in gesehen:
            continue
        gesehen.add(aid); nummern.append(abs_nummer[aid])
    return nummern, dt


ergebnis = {}
for v in varianten:
    zeilen = []
    for t in tests:
        nummern, dt = suche(v, t['f'])
        r_s, r_m = bewerte(nummern, t)
        zeilen.append({'f': t['f'], 'streng': r_s, 'mild': r_m, 'top3': nummern[:3], 'rr_s': dt})
    def kz(schl):
        rr = [z[schl] for z in zeilen]
        return {'@1': sum(1 for r in rr if r == 1), '@5': sum(1 for r in rr if r and r <= 5),
                '@10': sum(1 for r in rr if r and r <= 10), 'mrr': round(sum(1 / r for r in rr if r) / len(rr), 3)}
    zeit = [z['rr_s'] for z in zeilen if z['rr_s'] is not None]
    ergebnis[v] = {'streng': kz('streng'), 'mild': kz('mild'), 'zeilen': zeilen,
                   'rerank_s': round(sum(zeit) / len(zeit), 2) if zeit and v.endswith('+rr') else None}
    print(f"{v:12s} streng {ergebnis[v]['streng']}  mild {ergebnis[v]['mild']}"
          + (f"  Rerank Ø {ergebnis[v]['rerank_s']} s" if ergebnis[v]['rerank_s'] else ''), flush=True)

json.dump(ergebnis, open(HIER / a.aus, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print('Fragen:', len(tests))
