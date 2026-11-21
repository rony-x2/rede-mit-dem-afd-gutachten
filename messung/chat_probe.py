#!/usr/bin/env python3
"""
chat_probe.py – Probelauf für einen Chat mit eigenem Modell (Ausblick, Fall 2).

Ein lokales Modell hinter llama-server (OpenAI-artige Schnittstelle mit Werkzeugaufrufen) beantwortet
Testfragen und benutzt dafür die Werkzeuge der MCP-Schnittstelle. Das Skript ist der „Vermittler“:
Es reicht die Werkzeugaufrufe des Modells per MCP weiter und spielt die Ergebnisse zurück.

  python3 chat_probe.py --url http://127.0.0.1:1243 --name gemma --fragen 0,1,5 [--denken]

Je Frage: Gesamtzeit, Zeit bis zum Beginn der Antwort, Schritte, Werkzeuge, Token (Eingabe/Ausgabe) und
die zitierten Seiten. „Fundstelle“ prüft, ob eine zitierte Seite in einem der erwarteten Abschnitte
des Testsatzes liegt (streng: Hauptteil; mild: zusätzlich Zusammenfassung).
Ausgabe: probe_<name>.jsonl
"""
import argparse, json, re, sys, time
from pathlib import Path
import requests

HIER = Path(__file__).parent
MCP = 'https://poc.deutschkaemer.info/afd-gutachten/mcp'

ap = argparse.ArgumentParser()
ap.add_argument('--url', default='http://127.0.0.1:1243')
ap.add_argument('--name', required=True)
ap.add_argument('--fragen', default='0,1,5,11,16,20,26,31,34,40')
ap.add_argument('--denken', action='store_true', help='Denkmodus des Modells eingeschaltet lassen')
ap.add_argument('--schritte', type=int, default=8)
ap.add_argument('--abschnitte', default='abschnitte.json')
a = ap.parse_args()


def mcp(methode, params):
    r = requests.post(MCP, json={'jsonrpc': '2.0', 'id': 1, 'method': methode, 'params': params},
                      headers={'Accept': 'application/json, text/event-stream'}, timeout=90)
    r.raise_for_status()
    j = r.json()
    if 'error' in j:
        raise RuntimeError(j['error'])
    return j['result']


init = mcp('initialize', {'protocolVersion': '2025-06-18', 'capabilities': {},
                          'clientInfo': {'name': 'chat_probe', 'version': '1'}})
werkzeuge = [t for t in mcp('tools/list', {})['tools'] if t['name'] not in ('search', 'fetch')]
TOOLS = [{'type': 'function', 'function': {'name': t['name'], 'description': t['description'],
                                           'parameters': t['inputSchema']}} for t in werkzeuge]
SYSTEM = ('Du bist der Chat auf der Projektseite „Rede mit dem AfD-Gutachten“. Beantworte Fragen ausschließlich '
          'mit den Werkzeugen zum Gutachten, nicht aus eigenem Wissen. Suche zuerst, lies bei Bedarf nach und '
          'antworte dann auf Deutsch in höchstens 200 Wörtern, mit Seitenangaben (S. …) und einem Link auf die '
          'passende Seite der PDF.\n\n' + init.get('instructions', ''))

tests = json.load(open(HIER / 'testsatz.json', encoding='utf-8'))
abschnitte = json.load(open(a.abschnitte, encoding='utf-8'))


def bereiche(nummern):
    erg = []
    for z in nummern:
        for s in abschnitte:
            if (s['nummer'] == z or s['nummer'].startswith(z + '.') or s['nummer'].startswith(z + ' ')
                    or (s['nummer'].startswith(z) and s['nummer'][len(z):len(z) + 1] in '.( ')) and s.get('seite_von'):
                erg.append((s['seite_von'], s['seite_bis'] or s['seite_von']))
    return erg


def seiten(text):
    s = {int(x) for x in re.findall(r'S\.\s?(\d{1,4})', text)}
    s |= {int(x) - 2 for x in re.findall(r'#page=(\d{1,4})', text)}
    for von, bis in re.findall(r'S\.\s?(\d{1,4})\s?[–-]\s?(\d{1,4})', text):
        s |= set(range(int(von), int(bis) + 1))
    return s


def trifft(seitenmenge, bereich):
    return any(v <= p <= b for p in seitenmenge for v, b in bereich)


aus = open(HIER / f'probe_{a.name}.jsonl', 'w', encoding='utf-8')
for i in [int(x) for x in a.fragen.split(',')]:
    t = tests[i]
    msgs = [{'role': 'system', 'content': SYSTEM}, {'role': 'user', 'content': t['f']}]
    t0 = time.time()
    schritte, aufrufe, tok_ein, tok_aus, neu_ein = [], [], 0, 0, 0
    antwort, fehler, beginn = '', None, None
    try:
        for n in range(a.schritte):
            body = {'model': a.name, 'messages': msgs, 'tools': TOOLS, 'temperature': 0.3, 'max_tokens': 2500}
            if not a.denken:
                body['chat_template_kwargs'] = {'enable_thinking': False}
            ts = time.time()
            r = requests.post(a.url + '/v1/chat/completions', json=body, timeout=1200)
            if r.status_code != 200:
                raise RuntimeError(f'HTTP {r.status_code}: {r.text[:300]}')
            j = r.json()
            tm = j.get('timings', {})
            u = j.get('usage', {})
            tok_ein = u.get('prompt_tokens', tok_ein)
            tok_aus += u.get('completion_tokens', 0)
            neu_ein += tm.get('prompt_n', 0)
            schritte.append({'s': round(time.time() - ts, 1), 'prompt_n': tm.get('prompt_n'),
                             'prompt_ms': tm.get('prompt_ms'), 'predicted_n': tm.get('predicted_n'),
                             'predicted_ms': tm.get('predicted_ms')})
            m = j['choices'][0]['message']
            calls = m.get('tool_calls') or []
            if not calls:
                antwort = m.get('content') or ''
                # Beginn der Antwort ≈ Ende des letzten Schritts minus Schreibzeit der Antwort
                beginn = round(time.time() - t0 - (tm.get('predicted_ms') or 0) / 1000, 1)
                break
            msgs.append({'role': 'assistant', 'content': m.get('content') or '', 'tool_calls': calls})
            for c in calls:
                name = c['function']['name']
                try:
                    args = json.loads(c['function'].get('arguments') or '{}')
                    res = mcp('tools/call', {'name': name, 'arguments': args})
                    inhalt = res['content'][0]['text']
                except Exception as e:  # Fehler an das Modell zurückgeben, wie ein Client es täte
                    args, inhalt = {}, f'Fehler: {e}'
                aufrufe.append({'werkzeug': name, 'args': args, 'zeichen': len(inhalt)})
                msgs.append({'role': 'tool', 'tool_call_id': c.get('id', ''), 'content': inhalt})
        else:
            fehler = 'keine Antwort nach %d Schritten' % a.schritte
    except Exception as e:
        fehler = str(e)[:400]
    ges = round(time.time() - t0, 1)
    s = seiten(antwort)
    erg = {'i': i, 'frage': t['f'], 'sek': ges, 'beginn': beginn, 'schritte': len(schritte), 'werkzeuge': aufrufe,
           'tok_ein_letzt': tok_ein, 'tok_ein_neu': neu_ein, 'tok_aus': tok_aus, 'zeiten': schritte,
           'seiten': sorted(s), 'link': '#page=' in antwort,
           'streng': trifft(s, bereiche(t['z'])), 'mild': trifft(s, bereiche(t['z'] + t.get('zf', []))),
           'woerter': len(antwort.split()), 'fehler': fehler, 'antwort': antwort}
    aus.write(json.dumps(erg, ensure_ascii=False) + '\n')
    aus.flush()
    print(f"{i:>2} {ges:>6}s Schritte {len(schritte)} Werkz. {len(aufrufe)} streng {erg['streng']!s:5} "
          f"mild {erg['mild']!s:5} {'FEHLER ' + fehler if fehler else ''}", flush=True)
