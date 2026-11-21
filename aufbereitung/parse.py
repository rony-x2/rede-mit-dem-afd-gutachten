#!/usr/bin/env python3
"""
Parser für das GFF-Gutachten „Ist die AfD verfassungswidrig?“ (PDF, 3.062 Seiten).

Liest die PDF über PyMuPDF und nutzt die Satzmerkmale des Layouts:
  - Seitenkopf „–401 –“ (y < 60)          -> gedruckte Seitenzahl
  - Trennstrich ab x≈113, unterhalb nur 10,1 pt -> Beginn des Fußnotenbereichs
  - hochgestellte Ziffern im Text            -> Fußnotenverweis [^n]
  - Fußnotenbeginn: Ziffer ≤ 6,5 pt am Zeilenanfang
  - Überschriften: Dosis 43,5/48 (Teil), Dosis 27,8 (A.), fett 13,9/11,5 mit Nummer bei x≈71
  - Zitate: eingerückt (x ≥ 125) -> Absatz als Zitat

Ausgabe (out/): abschnitte.json, absaetze.json, fussnoten.json, seiten.json, bericht.txt
"""
import json, re, sys, collections, pickle

OUT = 'out'
ROH = pickle.load(open(f'{OUT}/seiten_roh.pkl', 'rb'))   # aus extract.py

RE_SEITE = re.compile(r'^\s*[–-]\s*(\d+)\s*[–-]\s*$')
LABEL_RANG = [
    (re.compile(r'^[IVXL]+\.$'), 2),
    (re.compile(r'^\d+\.$'), 3),
    (re.compile(r'^[a-z]\)$'), 4),
    (re.compile(r'^([a-z])\1\)$'), 5),
    (re.compile(r'^\(\d+\)$'), 6),
    (re.compile(r'^\([a-z]\)$'), 7),
    (re.compile(r'^\(([a-z])\1\)$'), 8),
]
SATZENDE = tuple('.:!?“”"‘’)»«;')
KEEP_DASH_NEXT = {'und', 'oder', 'bzw.', 'sowie', 'als', 'bis', 'noch', 'wie'}


def is_bold(s):
    return 'Bold' in s['font'] or (s['flags'] & 16)


def is_super(s):
    return bool(s['flags'] & 1)


def label_rang(lbl):
    for rx, r in LABEL_RANG:
        if rx.match(lbl):
            return r
    return None


def dominant_size(spans):
    c = collections.Counter()
    for s in spans:
        c[round(s['size'], 1)] += len(s['text'].strip())
    return c.most_common(1)[0][0] if c else 0


def is_ref(s):
    """Fußnotenverweis im Fließtext: kleine Ziffern, hochgestellt oder ≤ 7,6 pt."""
    st = s['text'].strip()
    return st.isdigit() and s['size'] < 9 and (is_super(s) or s['size'] <= 7.6)


def rows_of(lines, tol=2.5):
    """Zeilen mit gleicher Grundlinie zu einer Reihe zusammenfassen (Nummer bei x=71 + Titel bei x=113)."""
    rows = []
    for ln in lines:
        if rows and abs(rows[-1]['y'] - ln['y']) <= tol:
            rows[-1]['parts'].append(ln)
            rows[-1]['y1'] = max(rows[-1]['y1'], ln['y1'])
        else:
            rows.append({'y': ln['y'], 'y1': ln['y1'], 'parts': [ln]})
    for r in rows:
        r['parts'].sort(key=lambda p: p['x'])
        r['x'] = r['parts'][0]['x']
        r['spans'] = [s for p in r['parts'] for s in p['spans']]
    return rows


def find_separator(striche, lines):
    """y des Fußnoten-Trennstrichs oder None.
    Kandidat: kurzer Strich ab x≈113; gültig, wenn darunter nur Fußnotenzeilen stehen
    (bündig bei x≈113, Schrift < 11,3 pt – Fußnoten sind teils in Aptos 11 pt gesetzt)."""
    cands = []
    for (x0, y0, x1, y1) in striche:
        if not (108 <= x0 <= 118 and 90 <= (x1 - x0) <= 200 and y0 > 120):
            continue
        # Tabellenrahmen ausschließen: dort setzt ein weiterer Strich auf gleicher Höhe rechts an
        if any(abs(b - y0) < 1.2 and a >= x1 - 3 for (a, b, c, d) in striche):
            continue
        cands.append(y0)
    for y in sorted(set(round(c, 1) for c in cands)):
        below = [l for l in lines if l['y'] > y]
        if not below:
            continue
        # Fußnotenzeilen sind ≤ 11,3 pt; vereinzelt steht doppelt gesetzter Text in 12 pt darin (PDF 1317).
        # Fließtext (11,5 pt) darf höchstens einen kleinen Anteil haben, und die erste Zeile muss bündig stehen.
        groesse = [dominant_size(l['spans']) for l in below]
        gross = sum(1 for g in groesse if g > 11.3)
        erste = [s for s in below[0]['spans'] if s['text'].strip()]
        beginnt_fn = bool(erste) and erste[0]['text'].strip().isdigit() and erste[0]['size'] <= 7.3
        ok = 108 <= below[0]['x'] <= 125 and max(groesse) <= 12.1 \
            and (beginnt_fn or (gross <= len(below) // 3 and dominant_size(below[0]['spans']) <= 11.3))
        if ok:
            return y
    return None


def row_text(spans, refs_out=None, in_footnote=False):
    """Text einer Reihe; hochgestellte Ziffern werden zu [^n]."""
    out = []
    for s in spans:
        t = s['text']
        st = t.strip()
        if is_ref(s):
            n = int(st)
            if refs_out is not None:
                refs_out.append(n)
            out.append(f'[^{n}]' + (' ' if t.endswith(' ') else ''))
        else:
            out.append(t)
    return ''.join(out)


def join_lines(lines_txt):
    """Zeilen eines Absatzes zusammenfügen: Silbentrennung und URLs beachten."""
    acc = ''
    for t in lines_txt:
        t = t.strip()
        if not t:
            continue
        if not acc:
            acc = t
            continue
        last_tok = acc.split(' ')[-1]
        if re.search(r'(https?://|www\.)\S*$', acc) and not re.match(r'^(\[zur|\(abgerufen|abgerufen)', t):
            # URL läuft über den Zeilenumbruch: ohne Leerzeichen anhängen, Bindestrich behalten
            acc += t
        elif acc.endswith('–') and not acc.endswith(' –') and t[:1].isdigit():
            acc += t                                # „2017–“ + „2022“
        elif acc.endswith('-') and not acc.endswith(' -'):
            nxt = t.split(' ')[0]
            if nxt in KEEP_DASH_NEXT:
                acc += ' ' + t                      # „Landes- und Bundes…“
            elif nxt[:1].islower():
                acc = acc[:-1] + t                  # Silbentrennung
            else:
                acc += t                            # „AfD-“ + „Fraktion“
        else:
            acc += ' ' + t
    acc = re.sub(r'[  ]{2,}', ' ', acc)
    acc = acc.replace('ﬁ', 'fi').replace('ﬂ', 'fl')
    return acc.strip()


# ---------------------------------------------------------------- Durchlauf
abschnitte = []          # {id, rang, label, titel, nummer, pfad, seite_von, pdf_von, absaetze:[]}
fussnoten = {}           # nr -> {nr, seite, pdf, zeilen:[], urls:set, datenbank}
seiten = []              # {pdf, seite, text, fussnoten:[nr]}
absaetze = []            # {abschnitt, seite, pdf, typ, text}
warn = []

stack = []               # aktueller Pfad (Abschnitts-Dicts)
cur_par = None           # {'lines': [...], 'typ', 'seite', 'pdf', 'x', 'y_last', 'size'}
last_fn = None
teil_kurz = ''


def flush_par():
    global cur_par
    if cur_par and cur_par['lines']:
        txt = join_lines(cur_par['lines'])
        if txt:
            absaetze.append({
                'abschnitt': stack[-1]['id'] if stack else None,
                'seite': cur_par['seite'], 'pdf': cur_par['pdf'],
                'seite_bis': cur_par.get('seite_last', cur_par['seite']),
                'pdf_bis': cur_par.get('pdf_last', cur_par['pdf']),
                'typ': cur_par['typ'], 'text': txt})
    cur_par = None


def open_section(rang, label, titel, seite, pdf):
    global teil_kurz
    flush_par()
    while stack and stack[-1]['rang'] >= rang:
        stack.pop()
    titel = re.sub(r'\s+', ' ', titel).strip()
    if rang == 0:
        m = re.match(r'^(Teil \d+):?\s*(.*)$', titel)
        teil_kurz = m.group(1) if m else titel
        nummer = teil_kurz
        label = teil_kurz
        if m:
            titel = m.group(2)
    else:
        sub = ''.join(s['label'] for s in stack if s['rang'] > 0) + label
        nummer = (teil_kurz + ' ' + sub).strip().rstrip('.')
    def zeile(n, t):
        return t if n == t else f'{n} {t}'
    sec = {
        'id': len(abschnitte) + 1, 'rang': rang, 'label': label, 'titel': titel,
        'nummer': nummer, 'eltern': stack[-1]['id'] if stack else None,
        'pfad': [zeile(s['nummer'], s['titel']) for s in stack] + [zeile(nummer, titel)],
        'seite_von': seite, 'pdf_von': pdf, 'seite_bis': seite, 'pdf_bis': pdf,
    }
    abschnitte.append(sec)
    stack.append(sec)
    return sec


in_toc = False
for roh in ROH:
    pdf = roh['pdf']
    lines = roh['lines']
    # Seitenkopf
    seite = None
    body_lines = []
    for ln in lines:
        txt = ''.join(s['text'] for s in ln['spans'])
        if ln['y'] < 60 and RE_SEITE.match(txt):
            seite = int(RE_SEITE.match(txt).group(1))
        else:
            body_lines.append(ln)
    sep = find_separator(roh['striche'], body_lines)
    if sep is None:
        # Seite ohne Trennstrich: Fußnoten nur, wenn ganz unten eine Zeile mit Fußnotenziffer beginnt
        fn_lines = []
        main_lines = body_lines
    else:
        main_lines = [l for l in body_lines if l['y'] < sep]
        fn_lines = [l for l in body_lines if l['y'] > sep]

    # ---- Fußnoten
    page_fn = []
    links = roh['links']
    fn_rows = []          # (y, fussnote) je Reihe, für die Link-Zuordnung
    for r in rows_of(fn_lines, tol=2.0):
        # Streuzeichen links vom Fußnotenblock (z. B. ein verirrtes „I.“ bei x=71) verwerfen
        while len(r['parts']) > 1 and r['parts'][0]['x'] < 100 and \
                not ''.join(s['text'] for s in r['parts'][0]['spans']).strip().isdigit():
            r['parts'].pop(0)
        r['x'] = r['parts'][0]['x']
        r['spans'] = [s for p in r['parts'] for s in p['spans']]
        sp = [s for s in r['spans'] if s['text'].strip()] or r['spans']
        first = sp[0]
        st = first['text'].strip()
        rowtxt = ''.join(s['text'] for s in sp)
        m_plain = re.match(r'^\s*(\d{1,4})\s+\S', rowtxt)
        nr = None
        rest = None
        if st.isdigit() and first['size'] <= 7.3 and r['x'] < 125:
            nr = int(st)
            rest = ''.join(s['text'] for s in sp[1:])
        elif m_plain and last_fn is not None and int(m_plain.group(1)) == last_fn['nr'] + 1 and r['x'] < 125 \
                and last_fn['zeilen'] and last_fn['zeilen'][-1].rstrip().endswith(('.', ')', '“')):
            # Fußnotennummer in normaler Schriftgröße – nur akzeptieren, wenn sie die Folge fortsetzt
            nr = int(m_plain.group(1))
            rest = rowtxt[m_plain.end(1):]
        if nr is not None:
            fn = fussnoten.setdefault(nr, {'nr': nr, 'seite': seite, 'pdf': pdf, 'zeilen': [], 'urls': [], 'datenbank': None})
            if fn['zeilen']:
                warn.append(f'Fußnote {nr} doppelt (PDF {pdf})')
            fn['zeilen'].append(rest)
            last_fn = fn
            page_fn.append(nr)
        elif last_fn is not None:
            last_fn['zeilen'].append(''.join(s['text'] for s in sp))
            if not page_fn:
                last_fn.setdefault('weiter_pdf', []).append(pdf)
        else:
            warn.append(f'Fußnotenzeile ohne Nummer (PDF {pdf}): {"".join(s["text"] for s in sp)[:60]}')
        if last_fn is not None:
            fn_rows.append((r['y'], last_fn))
    # Links im Fußnotenbereich: der Reihe zuordnen, in der der Link beginnt
    if sep is not None and fn_rows:
        for lk in links:
            lx0, ly0, lx1, ly1 = lk['rect']
            if ly0 < sep:
                continue
            ziel = None
            for y, fn in fn_rows:
                if y <= ly0 + 3:
                    ziel = fn
            if ziel is None:
                continue
            uri, lab = lk['uri'], lk['label']
            if 'fragdenstaat.de/afd-datenbank' in uri:
                if not ziel['datenbank']:
                    ziel['datenbank'] = uri
            elif uri not in ziel['urls']:
                ziel['urls'].append(uri)

    # ---- Haupttext
    page_txt_parts = []
    rows = rows_of(main_lines)
    i = 0
    while i < len(rows):
        r = rows[i]
        sp = [s for s in r['spans'] if s['text'].strip()]
        txt = ''.join(s['text'] for s in r['spans']).strip()
        sizes = {round(s['size'], 1) for s in sp}
        page_txt_parts.append(txt)

        # Inhaltsverzeichnis (PDF 3–13) überspringen, aber als Seite behalten
        if pdf <= 2 or 3 <= pdf <= 13:
            i += 1
            continue

        # Überschrift Dosis (Teil / A.)
        if sp and all('Dosis' in s['font'] for s in sp) and max(sizes) > 20:
            big = max(sizes) > 35
            parts = [txt]
            j = i + 1
            while j < len(rows):
                sp2 = [s for s in rows[j]['spans'] if s['text'].strip()]
                if sp2 and all('Dosis' in s['font'] for s in sp2) and (max(round(s['size'], 1) for s in sp2) > 35) == big and rows[j]['y'] - rows[j - 1]['y'] < 70:
                    parts.append(''.join(s['text'] for s in rows[j]['spans']).strip())
                    j += 1
                else:
                    break
            full = ' '.join(parts)
            if big:
                open_section(0, '', full, seite, pdf)
            else:
                m = re.match(r'^([A-Z])\.\s*(.*)$', full)
                if m:
                    open_section(1, m.group(1) + '.', m.group(2), seite, pdf)
                else:
                    warn.append(f'Dosis-Überschrift ohne Buchstabe (PDF {pdf}): {full}')
            for k in range(i + 1, j):
                page_txt_parts.append(''.join(s['text'] for s in rows[k]['spans']).strip())
            i = j
            continue

        # Überschrift fett mit Nummer bei x≈71
        if sp and r['x'] < 90 and all(is_bold(s) or is_ref(s) for s in sp) and max(sizes) >= 11.4:
            lbl = r['parts'][0]['spans'][0]['text'].strip() if r['parts'] else ''
            lbl = ''.join(s['text'] for s in r['parts'][0]['spans']).strip()
            rang = label_rang(lbl)
            if rang is not None:
                title_parts = [row_text([s for p in r['parts'][1:] for s in p['spans']])]
                j = i + 1
                while j < len(rows):
                    r2 = rows[j]
                    sp2 = [s for s in r2['spans'] if s['text'].strip()]
                    if sp2 and r2['x'] >= 100 and all(is_bold(s) or is_ref(s) for s in sp2) and \
                            abs(max(round(s['size'], 1) for s in sp2) - max(sizes)) < 0.5 and r2['y'] - rows[j - 1]['y'] < 30:
                        title_parts.append(row_text(r2['spans']))
                        page_txt_parts.append(''.join(s['text'] for s in r2['spans']).strip())
                        j += 1
                    else:
                        break
                titel = join_lines(title_parts)
                t_fn = [int(n) for n in re.findall(r'\[\^(\d+)\]', titel)]
                titel = re.sub(r'\[\^\d+\]', '', titel).strip()
                sec = open_section(rang, lbl, titel, seite, pdf)
                sec['titel_fn'] = t_fn
                i = j
                continue

        # ---- normaler Text
        refs = []
        t = row_text(r['spans'], refs)
        x = r['x']
        size = dominant_size([s for s in sp if not is_ref(s)] or sp)
        typ ='zitat' if x >= 125 else ('liste' if txt.startswith('•') else 'text')
        if txt == '•':
            # Aufzählungszeichen steht allein, Text folgt in eigener Zeile
            flush_par()
            cur_par = {'lines': [], 'typ': 'liste', 'seite': seite, 'pdf': pdf, 'x': x, 'y_last': r['y'], 'size': size, 'bullet': True}
            i += 1
            continue
        new_par = True
        if cur_par is not None:
            gap = r['y'] - cur_par['y_last']
            same_page = cur_par['pdf_last'] == pdf if 'pdf_last' in cur_par else cur_par['pdf'] == pdf
            if cur_par.get('bullet') and not cur_par['lines']:
                new_par = False
            elif same_page:
                lim = 24.5 if cur_par['size'] >= 11 else 21.0
                same_kind = (typ == 'zitat') == (cur_par['typ'] == 'zitat')
                new_par = not (gap <= lim and same_kind and gap > 0)
            else:
                # Seitenwechsel: fortsetzen, wenn der Absatz nicht abgeschlossen war
                prev = cur_par['lines'][-1].rstrip() if cur_par['lines'] else ''
                same_kind = (typ == 'zitat') == (cur_par['typ'] == 'zitat')
                new_par = not (same_kind and prev and not prev.endswith(SATZENDE))
        if new_par:
            flush_par()
            cur_par = {'lines': [], 'typ': typ, 'seite': seite, 'pdf': pdf, 'x': x, 'y_last': r['y'], 'size': size}
        if cur_par.get('bullet') and not cur_par['lines']:
            t = '• ' + t.strip()
        cur_par['lines'].append(t)
        cur_par['y_last'] = r['y']
        cur_par['pdf_last'] = pdf
        cur_par['seite_last'] = seite
        i += 1

    if stack:
        for s in stack:
            if seite is not None:
                s['seite_bis'] = seite
            s['pdf_bis'] = pdf
    seiten.append({'pdf': pdf, 'seite': seite, 'text': '\n'.join(page_txt_parts), 'fussnoten': page_fn})

flush_par()

# ---------------------------------------------------------------- Nacharbeit
fn_list = []
for nr in sorted(fussnoten):
    f = fussnoten[nr]
    text = join_lines(f['zeilen'])
    text = re.sub(r'\s*\[zur Datenbank\]\s*', ' ', text).strip()
    fn_list.append({'nr': nr, 'seite': f['seite'], 'pdf': f['pdf'], 'text': text,
                    'urls': f['urls'], 'datenbank': f['datenbank']})

# Verweise ohne Hochstellung nachtragen: Fußnoten ohne Verweis zwischen Vorgänger- und Nachfolgerverweis suchen
def _refs(a):
    return [int(n) for n in re.findall(r'\[\^(\d+)\]', a['text'])]
gesehen = set()
for a in absaetze:
    gesehen.update(_refs(a))
for s in abschnitte:
    gesehen.update(s.get('titel_fn', []))
nachgetragen = []
for nr in sorted(set(fussnoten) - gesehen):
    vor = max((i for i, a in enumerate(absaetze) if any(n < nr for n in _refs(a))), default=0)
    nach = min((i for i, a in enumerate(absaetze) if any(n > nr for n in _refs(a))), default=len(absaetze) - 1)
    for i in range(vor, nach + 1):
        t = absaetze[i]['text']
        m = None
        for m in re.finditer(rf'(?<=[^\d\s]){nr}(?=[\s.,;:)“”]|$)', t):
            pass
        if m:
            absaetze[i]['text'] = t[:m.start()] + f'[^{nr}]' + t[m.end():]
            nachgetragen.append(nr)
            break

# Absätze nummerieren, Abschnittstext zählen
zaehler = collections.Counter()
for a in absaetze:
    zaehler[a['abschnitt']] += 1
    a['nr'] = zaehler[a['abschnitt']]

# Prüfungen
ref_all = []
for a in absaetze:
    ref_all += [int(n) for n in re.findall(r'\[\^(\d+)\]', a['text'])]
for s in abschnitte:
    ref_all += s.get('titel_fn', [])
ref_set = collections.Counter(ref_all)
fn_nums = set(fussnoten)
max_fn = max(fn_nums) if fn_nums else 0
fehlend_fn = sorted(set(range(1, max_fn + 1)) - fn_nums)
ohne_ref = sorted(fn_nums - set(ref_set))
ref_ohne_fn = sorted(set(ref_set) - fn_nums)
doppelt_ref = sorted(n for n, c in ref_set.items() if c > 1)

json.dump(abschnitte, open(f'{OUT}/abschnitte.json', 'w'), ensure_ascii=False, indent=1)
json.dump(absaetze, open(f'{OUT}/absaetze.json', 'w'), ensure_ascii=False)
json.dump(fn_list, open(f'{OUT}/fussnoten.json', 'w'), ensure_ascii=False)
json.dump(seiten, open(f'{OUT}/seiten.json', 'w'), ensure_ascii=False)

with open(f'{OUT}/bericht.txt', 'w') as fh:
    def p(*a):
        print(*a)
        print(*a, file=fh)
    p(f'PDF-Seiten: {len(ROH)},mit Seitenzahl: {sum(1 for s in seiten if s["seite"] is not None)}')
    p(f'Abschnitte: {len(abschnitte)}  je Rang: {dict(sorted(collections.Counter(s["rang"] for s in abschnitte).items()))}')
    p(f'Absätze: {len(absaetze)}  je Typ: {dict(collections.Counter(a["typ"] for a in absaetze))}')
    p(f'Fußnoten: {len(fn_list)} (höchste {max_fn}), mit Datenbank-Link: {sum(1 for f in fn_list if f["datenbank"])}, mit URL: {sum(1 for f in fn_list if f["urls"])}')
    p(f'Verweise im Text: {len(ref_all)}')
    p(f'Fehlende Fußnotennummern: {len(fehlend_fn)} {fehlend_fn[:30]}')
    p(f'Fußnoten ohne Verweis: {len(ohne_ref)} {ohne_ref[:30]}')
    p(f'Verweise ohne Fußnote: {len(ref_ohne_fn)} {ref_ohne_fn[:30]}')
    p(f'Mehrfach verwiesen: {len(doppelt_ref)} {doppelt_ref[:30]}')
    p(f'Nachgetragene Verweise (ohne Hochstellung): {nachgetragen}')
    p(f'Warnungen: {len(warn)}')
    for w in warn[:40]:
        p('  ', w)
