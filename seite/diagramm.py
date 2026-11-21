#!/usr/bin/env python3
"""
diagramm.py – Ablaufdiagramm „Wie es gemacht ist“ als SVG, aus einer Quelle für Projektseite und GitHub.

  python3 diagramm.py            schreibt docs/bilder/ablauf-*.svg (README) und suche-*.svg (docs/suche.md),
                                 je in einer hellen und einer dunklen Fassung

bauen.py bettet über diagramm_seite() beide Fassungen (quer, hoch) inline in die Projektseite ein; dort kommen
die Farben aus den CSS-Variablen der Seite (hell/dunkel, Display P3), im README aus festen Farben.
"""
from html import escape
from pathlib import Path

# ------------------------------------------------------------------ Inhalt
KASTEN = {
    'pdf':     ('PDF der GFF', '3.062 Seiten', None),
    'parser':  ('Parser', 'Gliederung, Seiten, Fußnoten', 'extract.py · parse.py'),
    'teilen':  ('Textteile', '13.967 Abschnitte', 'teilen.py'),
    'fragen':  ('Typische Fragen', '45.775 Fragen von Gemma 4', 'anreichern.py'),
    'vektor':  ('Vektoren', 'Qwen3-Embedding-0.6B', 'einbetten.py'),
    'assist':  ('KI-Assistent', 'Claude, ChatGPT, Grok, LM Studio', None),
    'server':  ('MCP-Schnittstelle', '11 Werkzeuge, Suche', 'mcp_server.php'),
    'einbett': ('Einbettung der Frage', 'Qwen3 auf der CPU, 0,15 s', None),
}
DB = ('MariaDB', 'Text · Fußnoten', 'Volltext · Vektoren')
BAHN_A = 'Einmalig: Aufbereitung'
BAHN_B = 'Bei jeder Frage'
TITEL = 'Aufbau von „Rede mit dem AfD-Gutachten“'
BESCHREIBUNG = ('Einmalig wird die PDF der GFF vom Parser gegliedert und in Textteile zerlegt; ein lokales Sprachmodell '
                'schreibt typische Fragen dazu. Beides wird zu Vektoren und landet mit Text, Fußnoten und Volltextindex '
                'in MariaDB. Bei jeder Frage ruft ein KI-Assistent die MCP-Schnittstelle auf; sie bettet die Frage ein, '
                'sucht in der Datenbank und liefert Fundstellen mit Seite und Link zurück.')

# ------------------------------------------------------------------ Farben
STIL_KLASSEN = '''
.d-bahn{fill:%(bahn)s;stroke:%(linie)s;stroke-dasharray:4 4}
.d-bahntitel{fill:%(leise)s;font-size:11.5px;font-weight:600;letter-spacing:.09em;text-transform:uppercase}
.d-kasten{fill:%(flaeche)s;stroke:%(linie)s;stroke-width:1.2}
.d-t{fill:%(text)s;font-size:15px;font-weight:600}
.d-d{fill:%(leise)s;font-size:12.5px}
.d-f{fill:%(projekt)s;font-size:11.5px;font-family:%(mono)s}
.d-p{fill:none;stroke:%(leise)s;stroke-width:1.4;stroke-linejoin:round}
.d-p.b{stroke:%(akzent)s;stroke-width:1.6}
.d-s{fill:%(leise)s}.d-s.b{fill:%(akzent)s}
.d-l{fill:%(leise)s;font-size:11.5px}.d-l.b{fill:%(akzent)s}
'''
SCHRIFT = '-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans",Helvetica,Arial,sans-serif'
MONO = 'ui-monospace,SFMono-Regular,"Cascadia Mono",Consolas,monospace'
THEMEN = {
    'seite': dict(bahn='color-mix(in oklab,var(--flaeche) 40%,transparent)', linie='var(--linie)', leise='var(--text-2)',
                  flaeche='var(--flaeche)', text='var(--text)', projekt='var(--projekt)', akzent='var(--akzent)',
                  mono='var(--mono)'),
    'hell':  dict(bahn='#f6f8fa', linie='#d1d9e0', leise='#59636e', flaeche='#ffffff', text='#1f2328',
                  projekt='#b5523b', akzent='#2f8a7b', mono=MONO),
    'dunkel': dict(bahn='#151b23', linie='#3d444d', leise='#9198a1', flaeche='#0d1117', text='#e6edf3',
                   projekt='#da6e55', akzent='#5eb8a8', mono=MONO),
}

H = 70  # Höhe eines Kastens


class Zeichnung:
    def __init__(self, praefix):
        self.teile = []
        self.p = praefix

    def bahn(self, x, y, w, h, titel):
        self.teile.append(f'<rect class="d-bahn" x="{x}" y="{y}" width="{w}" height="{h}" rx="14"/>'
                          f'<text class="d-bahntitel" x="{x + 16}" y="{y + 22}">{escape(titel)}</text>')

    def kasten(self, schluessel, x, y, w):
        t, d, f = KASTEN[schluessel] if isinstance(schluessel, str) else schluessel
        breite = max(len(t) * 15 * .6, len(d) * 12.5 * .53, len(f or '') * 11.5 * .62)
        assert breite <= w - 16, f'{schluessel}: Text zu breit ({breite:.0f} > {w - 16})'
        cx = x + w / 2
        zeilen = [(t, 'd-t'), (d, 'd-d')] + ([(f, 'd-f')] if f else [])
        y0 = y + (24 if f else 31)
        s = [f'<rect class="d-kasten" x="{x}" y="{y}" width="{w}" height="{H}" rx="10"/>']
        for i, (txt, k) in enumerate(zeilen):
            s.append(f'<text class="{k}" x="{cx:g}" y="{y0 + i * 18:g}" text-anchor="middle">{escape(txt)}</text>')
        self.teile.append(''.join(s))

    def zylinder(self, x, y, w, h):
        ry = 13
        cx = x + w / 2
        s = [f'<path class="d-kasten" d="M{x},{y + ry} v{h - 2 * ry} a{w / 2:g},{ry} 0 0 0 {w},0 v{-(h - 2 * ry)}"/>',
             f'<ellipse class="d-kasten" cx="{cx:g}" cy="{y + ry}" rx="{w / 2:g}" ry="{ry}"/>']
        mitte = y + ry + (h - ry) / 2
        for i, (txt, k) in enumerate(zip(DB, ('d-t', 'd-d', 'd-d'))):
            s.append(f'<text class="{k}" x="{cx:g}" y="{mitte - 12 + i * 18:g}" text-anchor="middle">{escape(txt)}</text>')
        self.teile.append(''.join(s))

    def pfeil(self, punkte, betrieb=False, beide=False, spitze=True):
        k = 'd-p b' if betrieb else 'd-p'
        m = f'{self.p}-sb' if betrieb else f'{self.p}-s'
        pts = ' '.join(f'{x:g},{y:g}' for x, y in punkte)
        attr = (f' marker-end="url(#{m})"' if spitze else '') + (f' marker-start="url(#{m})"' if beide else '')
        self.teile.append(f'<polyline class="{k}" points="{pts}"{attr}/>')

    def text(self, x, y, txt, betrieb=False, anker='middle'):
        k = 'd-l b' if betrieb else 'd-l'
        self.teile.append(f'<text class="{k}" x="{x}" y="{y}" text-anchor="{anker}">{escape(txt)}</text>')

    def svg(self, w, h, thema, klasse='', gross=False):
        stil = STIL_KLASSEN % THEMEN[thema]
        if gross:   # Hochformat fürs Smartphone: etwas größere Schrift, damit sie verkleinert lesbar bleibt
            stil += '.d-t{font-size:15.5px}.d-d{font-size:13px}.d-f{font-size:12px}.d-l{font-size:12.5px}'
        if thema != 'seite':
            stil += 'text{font-family:%s}' % SCHRIFT
        marker = ''.join(
            f'<marker id="{self.p}-{n}" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="8" markerHeight="8" '
            f'orient="auto-start-reverse"><path class="{k}" d="M0,0.6 L7.6,4 L0,7.4 z"/></marker>'
            for n, k in (('s', 'd-s'), ('sb', 'd-s b')))
        k = f' class="{klasse}"' if klasse else ''
        return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}"{k} role="img" '
                f'aria-labelledby="{self.p}-titel {self.p}-text">'
                f'<title id="{self.p}-titel">{escape(TITEL)}</title><desc id="{self.p}-text">{escape(BESCHREIBUNG)}</desc>'
                f'<style>{stil}</style><defs>{marker}</defs>' + ''.join(self.teile) + '</svg>')


def quer(thema, praefix='dq'):
    z = Zeichnung(praefix)
    z.bahn(4, 4, 856, 272, BAHN_A)
    z.bahn(4, 296, 856, 244, BAHN_B)
    z.kasten('pdf', 20, 110, 130)
    z.kasten('parser', 180, 110, 210)
    z.kasten('teilen', 430, 52, 200)
    z.kasten('fragen', 430, 168, 200)
    z.kasten('vektor', 670, 110, 170)
    z.zylinder(880, 160, 150, 250)
    z.pfeil([(150, 145), (178, 145)])
    z.pfeil([(390, 145), (410, 145), (410, 87), (428, 87)])
    z.pfeil([(410, 145), (410, 203), (428, 203)])
    z.pfeil([(630, 87), (650, 87), (650, 135), (668, 135)])
    z.pfeil([(630, 203), (650, 203), (650, 155), (668, 155)])
    z.pfeil([(840, 145), (860, 145), (860, 205), (878, 205)])
    z.pfeil([(285, 180), (285, 258), (878, 258)])
    z.text(530, 252, 'Text, Fußnoten, Volltextindex')
    z.kasten('assist', 20, 355, 240)
    z.kasten('server', 670, 355, 170)
    z.kasten('einbett', 655, 455, 200)
    z.pfeil([(260, 380), (668, 380)], betrieb=True)
    z.pfeil([(670, 402), (262, 402)], betrieb=True)
    z.text(465, 372, 'Frage und Werkzeugaufrufe · MCP über HTTPS', betrieb=True)
    z.text(465, 420, 'Fundstellen mit Seite und Link', betrieb=True)
    z.pfeil([(842, 390), (878, 390)], betrieb=True, beide=True)
    z.pfeil([(755, 425), (755, 453)], betrieb=True)
    z.text(765, 444, 'Frage', betrieb=True, anker='start')
    return z.svg(1040, 544, thema, 'd-quer')


def hoch(thema, praefix='dh'):
    z = Zeichnung(praefix)
    z.bahn(4, 4, 452, 424, BAHN_A)
    z.kasten('pdf', 130, 40, 200)
    z.kasten('parser', 110, 135, 240)
    z.kasten('teilen', 20, 240, 200)
    z.kasten('fragen', 240, 240, 200)
    z.kasten('vektor', 130, 345, 200)
    z.zylinder(150, 440, 160, 104)
    z.pfeil([(230, 110), (230, 133)])
    z.pfeil([(230, 205), (230, 222), (120, 222), (120, 238)])
    z.pfeil([(230, 222), (340, 222), (340, 238)])
    z.pfeil([(120, 310), (120, 327), (230, 327), (230, 343)])
    z.pfeil([(340, 310), (340, 327), (230, 327)], spitze=False)
    z.pfeil([(230, 415), (230, 438)])
    z.pfeil([(350, 170), (448, 170), (448, 500), (312, 500)])
    z.text(399, 162, 'Text, Fußnoten')
    z.bahn(4, 560, 452, 248, BAHN_B)
    z.kasten('server', 20, 600, 200)
    z.kasten('einbett', 240, 600, 200)
    z.kasten('assist', 20, 722, 300)
    z.pfeil([(190, 598), (190, 546)], betrieb=True, beide=True)
    z.pfeil([(220, 635), (238, 635)], betrieb=True)
    z.pfeil([(90, 720), (90, 672)], betrieb=True)
    z.pfeil([(130, 672), (130, 720)], betrieb=True)
    z.text(142, 700, 'MCP über HTTPS', betrieb=True, anker='start')
    return z.svg(460, 812, thema, 'd-hoch', gross=True)


def suche(thema, praefix='ds'):
    """Ablauf einer Anfrage an bedeutung_suchen (docs/suche.md)."""
    z = Zeichnung(praefix)
    z.kasten(('Frage', 'in eigenen Worten', None), 20, 110, 150)
    z.kasten(('Frage einbetten', 'mit Suchanweisung', None), 200, 110, 200)
    z.kasten(('Erzeugte Fragen', '64 Dim. vorwählen, 384 sortieren', 'afd_frage_vektor'), 440, 20, 250)
    z.kasten(('Originaltext', 'Vektorindex, 300 Textteile', 'afd_vektor'), 440, 110, 250)
    z.kasten(('Volltext', 'FULLTEXT, kurze Wörter per RegExp', 'afd_absatz'), 440, 200, 250)
    z.kasten(('Rank Fusion', 'Gewichte 1,0 · 0,35 · 0,25', None), 730, 110, 200)
    z.kasten(('Fundstellen', 'Seite, Ausschnitt, PDF-Link', None), 970, 110, 200)
    z.kasten(('Angaben zur GFF', 'nur bei passender Frage', 'afd_herausgeberin'), 440, 300, 250)
    z.pfeil([(170, 145), (198, 145)])
    z.pfeil([(400, 145), (420, 145), (420, 55), (438, 55)])
    z.pfeil([(420, 145), (438, 145)])
    z.pfeil([(95, 180), (95, 235), (438, 235)])
    z.pfeil([(300, 180), (300, 335), (438, 335)])
    for y in (55, 145, 235):
        z.pfeil([(690, y), (710, y), (710, 145), (728, 145)], betrieb=True)
    z.pfeil([(930, 145), (968, 145)], betrieb=True)
    z.pfeil([(690, 335), (1070, 335), (1070, 182)], betrieb=True)
    z.text(185, 230, 'Wörter der Frage', anker='start')
    return z.svg(1190, 390, thema)


def diagramm_seite():
    """Beide Fassungen für die Projektseite; CSS zeigt je nach Breite eine davon."""
    return quer('seite') + hoch('seite')


if __name__ == '__main__':
    ziel = Path(__file__).resolve().parent.parent / 'docs' / 'bilder'
    ziel.mkdir(parents=True, exist_ok=True)
    for thema in ('hell', 'dunkel'):
        (ziel / f'ablauf-{thema}.svg').write_text(quer(thema) + '\n', encoding='utf-8')
        (ziel / f'suche-{thema}.svg').write_text(suche(thema) + '\n', encoding='utf-8')
    print('geschrieben:', ziel)
