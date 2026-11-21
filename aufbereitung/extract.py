#!/usr/bin/env python3
"""Liest die PDF einmal aus (Zeilen, Spans, Trennlinien, Links) und speichert alles in out/seiten_roh.pkl."""
import pickle, sys
import pymupdf
from multiprocessing import Pool

PDF = 'src/gutachten.pdf'


def lese(rng):
    doc = pymupdf.open(PDF)
    res = []
    for pno in range(*rng):
        page = doc[pno]
        lines = []
        for b in page.get_text('dict')['blocks']:
            if b['type'] != 0:
                continue
            for l in b['lines']:
                sp = [{'text': s['text'], 'size': s['size'], 'font': s['font'], 'flags': s['flags']}
                      for s in l['spans'] if s['text'] != '']
                if not ''.join(s['text'] for s in sp).strip():
                    continue
                lines.append({'x': l['bbox'][0], 'y': l['bbox'][1], 'y1': l['bbox'][3], 'spans': sp})
        lines.sort(key=lambda r: (round(r['y']), r['x']))
        striche = []
        for d in page.get_drawings():
            r = d['rect']
            if r.height < 2.5 and r.width > 40:
                striche.append((r.x0, r.y0, r.x1, r.y1))
        links = []
        for lk in page.get_links():
            if lk.get('uri'):
                rc = lk['from']
                links.append({'uri': lk['uri'], 'rect': (rc.x0, rc.y0, rc.x1, rc.y1),
                              'label': page.get_textbox(rc).strip()})
        res.append({'pdf': pno + 1, 'lines': lines, 'striche': striche, 'links': links})
    return res


if __name__ == '__main__':
    n = pymupdf.open(PDF).page_count
    step = 100
    chunks = [(a, min(a + step, n)) for a in range(0, n, step)]
    with Pool(2) as p:
        parts = p.map(lese, chunks)
    seiten = [s for part in parts for s in part]
    pickle.dump(seiten, open('out/seiten_roh.pkl', 'wb'))
    print('Seiten:', len(seiten))
