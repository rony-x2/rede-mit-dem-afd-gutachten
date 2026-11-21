#!/usr/bin/env python3
"""anreichern.py – Suchhilfen je Textabschnitt des GFF-Gutachtens (Hauptteil, mit --bereich anhang die Belege im Anhang).

Ein lokales Sprachmodell (hier Gemma 4 26B-A4B hinter llama-server) schreibt zu jedem Abschnitt
Fragen in Alltagssprache, die der Abschnitt beantwortet, und Stichwörter (Synonyme,
Abkürzungen, Alltagsbegriffe). Die Zusätze dienen nur der Suche; Antworten kommen weiter
aus dem Originaltext.

  python anreichern.py [--grenze 40] [--backend URL,MODELL,PARALLEL ...]
  Mehrere --backend verteilen die Arbeit aus einer gemeinsamen Warteschlange
  (z. B. zwei Grafikkarten auf verschiedenen Rechnern, eine davon über einen SSH-Tunnel).
Ausgabe: anreicherung.jsonl (wiederaufnehmbar), danach
         anreicherung_einbettung.jsonl und kombi_einbettung.jsonl für einbetten.py.
"""
import argparse, json, os, queue, sys, threading, time
from pathlib import Path
import requests

HIER = Path.cwd()   # Arbeitsverzeichnis mit den Zwischendateien (siehe docs/aufbereitung.md)
def daten(n): return HIER / n if (HIER / n).exists() else HIER / 'out' / n
HAUPT = ('Zusammenfassung', 'Einführung', 'Teil 1', 'Teil 2', 'Teil 3', 'Schluss')
ap = argparse.ArgumentParser()
ap.add_argument('--backend', action='append', default=[],
                help='URL,Modell,Parallelität – mehrfach angebbar; Vorgabe: llama-server auf 127.0.0.1:1235')
ap.add_argument('--grenze', type=int, default=0, help='nur die ersten n offenen Abschnitte (Probelauf)')
ap.add_argument('--bereich', choices=['haupt', 'anhang'], default='haupt',
                help='haupt = Zusammenfassung bis Schluss; anhang = Anhang A–E (ohne Literaturverzeichnis)')
ap.add_argument('--nur_dateien', action='store_true', help='nur die Einbettungsdateien aus anreicherung.jsonl schreiben')
a = ap.parse_args()

SYSTEM = (
    'Du hilfst, einen Suchindex für ein deutsches Rechtsgutachten zu verbessern. Das Gutachten der '
    'Gesellschaft für Freiheitsrechte prüft, ob die Partei AfD verfassungswidrig ist. Du bekommst einen '
    'Textabschnitt mit seiner Überschrift. Schreibe:\n'
    '1. "fragen": 3 bis 5 kurze Fragen, wie interessierte Laien sie in einem Chat stellen würden und die genau '
    'dieser Abschnitt beantwortet. Alltagssprache statt Fachsprache, neutral formuliert, ohne eigene Wertung. '
    'Nur Fragen, auf die der Abschnitt wirklich eine Antwort gibt.\n'
    '2. "stichwoerter": 8 bis 15 Suchbegriffe, die im Text nicht unbedingt vorkommen: Synonyme, '
    'alltagssprachliche Entsprechungen von Fachbegriffen, Abkürzungen und ihre ausgeschriebene Form '
    '(z. B. KI und künstliche Intelligenz), Oberbegriffe sowie die im Abschnitt genannten Personen, '
    'Organisationen, Orte und Gesetze.\n'
    'Ist der Abschnitt ein Belegzitat aus dem Anhang (dokumentierte Äußerung, Antrag oder Programmstelle), dann frage '
    'danach, wer sich wann wozu geäußert hat und was gefordert wird (z. B. „Was hat … über … gesagt?“), und nimm '
    'Person, Partei-Gliederung, Ort und Thema in die Suchbegriffe auf. Keine Wertung der Äußerung.\n'
    'Erfinde keine Inhalte, die nicht im Abschnitt stehen. Antworte nur mit JSON.')
SCHEMA = {'type': 'object', 'properties': {
    'fragen': {'type': 'array', 'items': {'type': 'string'}, 'minItems': 2, 'maxItems': 6},
    'stichwoerter': {'type': 'array', 'items': {'type': 'string'}, 'minItems': 4, 'maxItems': 20}},
    'required': ['fragen', 'stichwoerter']}

abschnitte = {s['id']: s for s in json.load(open(daten('abschnitte.json'), encoding='utf-8'))}
absaetze = json.load(open(daten('absaetze.json'), encoding='utf-8'))
teile = []
for z in open(HIER / 'abschnitte_einbettung.jsonl', encoding='utf-8'):
    x = json.loads(z)
    oben = abschnitte[absaetze[x['absatz_id'] - 1]['abschnitt']]['pfad'][0]
    if (a.bereich == 'haupt' and oben.startswith(HAUPT)) or (a.bereich == 'anhang' and oben.startswith('Anhang')):
        teile.append(x)

AUS = HIER / 'anreicherung.jsonl'
fertig = {}
if AUS.exists():
    for z in open(AUS, encoding='utf-8'):
        d = json.loads(z)
        fertig[(d['absatz_id'], d['teil'])] = d


def schreibe_einbettungsdateien():
    n = 0
    with open(HIER / 'anreicherung_einbettung.jsonl', 'w', encoding='utf-8') as fa, \
         open(HIER / 'kombi_einbettung.jsonl', 'w', encoding='utf-8') as fk:
        for t in teile:
            d = fertig.get((t['absatz_id'], t['teil']))
            if not d:
                continue
            kopf = t['text'].split('\n', 1)[0]
            zusatz = 'Fragen: ' + ' '.join(d['fragen']) + '\nStichwörter: ' + ', '.join(d['stichwoerter'])
            basis = {'absatz_id': t['absatz_id'], 'teil': t['teil'], 'start': t['start'], 'laenge': t['laenge']}
            fa.write(json.dumps({**basis, 'text': kopf + '\n' + zusatz}, ensure_ascii=False) + '\n')
            fk.write(json.dumps({**basis, 'text': t['text'] + '\n' + zusatz}, ensure_ascii=False) + '\n')
            n += 1
    print(f'Einbettungsdateien: {n} Abschnitte', flush=True)


if a.nur_dateien:
    schreibe_einbettungsdateien(); sys.exit()

offen = [t for t in teile if (t['absatz_id'], t['teil']) not in fertig]
if a.grenze:
    offen = offen[:a.grenze]
print(f'{len(teile)} Abschnitte im Bereich {a.bereich}, {sum(1 for t in teile if (t["absatz_id"], t["teil"]) in fertig)} fertig, '
      f'{len(offen)} in diesem Lauf', flush=True)

sperre = threading.Lock()
stat = {'n': 0, 'fehler': 0, 'tok_ein': 0, 'tok_aus': 0}
t0 = time.time()


def eins(t, url, modell, name):
    body = {'model': modell, 'temperature': 0.3, 'max_tokens': 600,
            'messages': [{'role': 'system', 'content': SYSTEM}, {'role': 'user', 'content': t['text'][:6000]}],
            'response_format': {'type': 'json_schema', 'json_schema': {'name': 'suchhilfen', 'schema': SCHEMA}},
            'chat_template_kwargs': {'enable_thinking': False}}
    for versuch in range(3):
        try:
            if versuch == 2:
                # Ausweg: manche Antworten scheitern an der JSON-Vorgabe des Servers („empty grammar stack“) –
                # dann ohne Vorgabe fragen und das JSON selbst aus der Antwort lesen
                body.pop('response_format', None)
            r = requests.post(url + '/v1/chat/completions', json=body, timeout=600)
            r.raise_for_status()
            j = r.json()
            inhalt = j['choices'][0]['message']['content']
            if versuch == 2:
                inhalt = inhalt[inhalt.find('{'):inhalt.rfind('}') + 1]
            d = json.loads(inhalt)
            if not isinstance(d.get('fragen'), list) or not isinstance(d.get('stichwoerter'), list):
                raise ValueError('JSON ohne fragen/stichwoerter')
            erg = {'absatz_id': t['absatz_id'], 'teil': t['teil'],
                   'fragen': [s.strip() for s in d['fragen'] if s.strip()][:6],
                   'stichwoerter': [s.strip() for s in d['stichwoerter'] if s.strip()][:20]}
            with sperre:
                with open(AUS, 'a', encoding='utf-8') as f:
                    f.write(json.dumps(erg, ensure_ascii=False) + '\n')
                fertig[(t['absatz_id'], t['teil'])] = erg
                stat['n'] += 1
                stat[name] = stat.get(name, 0) + 1
                u = j.get('usage', {})
                stat['tok_ein'] += u.get('prompt_tokens', 0); stat['tok_aus'] += u.get('completion_tokens', 0)
                if stat['n'] % 50 == 0 or stat['n'] == len(offen):
                    dt = time.time() - t0
                    print(f"{stat['n']}/{len(offen)} · {dt/60:.1f} min · {stat['n']/dt*60:.0f}/min · "
                          f"aus {stat['tok_aus']/dt:.0f} tok/s · Fehler {stat['fehler']} · "
                          + ' '.join(f"{b[3]} {stat.get(b[3], 0)}" for b in backends), flush=True)
            return
        except Exception as e:
            time.sleep(3)
            err = e
    with sperre:
        stat['fehler'] += 1
        print('Fehler', t['absatz_id'], t['teil'], err, flush=True)


backends = []
for i, b in enumerate(a.backend or ['http://127.0.0.1:1235,google/gemma-4-26b-a4b,4']):
    url, modell, par = b.split(',')
    backends.append((url, modell, int(par), f'B{i + 1}'))
warteschlange = queue.Queue()
for t in offen:
    warteschlange.put(t)


def arbeiter(url, modell, name):
    while True:
        try:
            t = warteschlange.get_nowait()
        except queue.Empty:
            return
        eins(t, url, modell, name)


faeden = [threading.Thread(target=arbeiter, args=(u, m, n)) for u, m, p, n in backends for _ in range(p)]
for f in faeden:
    f.start()
for f in faeden:
    f.join()
dt = time.time() - t0
print(f"fertig: {stat['n']} in {dt/60:.1f} min, Fehler {stat['fehler']}", flush=True)
schreibe_einbettungsdateien()
