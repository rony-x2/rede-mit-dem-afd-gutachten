#!/usr/bin/env python3
"""Baut index.html der Projektseite aus vorlage.html: Logos inline, Piktogramme, Unterhaltung."""
import html, re, sys
from pathlib import Path

HIER = Path(__file__).parent
LOGOS = Path(sys.argv[1]) if len(sys.argv) > 1 else HIER / 'logos'
ADRESSE = 'https://poc.deutschkaemer.info/afd-gutachten/mcp'
PDF = 'https://freiheitsrechte.org/uploads/publications/Demokratie/GFF_AfD-Gutachten.pdf'

IKONEN = {
    'frage': '<path d="M4 5.5h16v10.5H10l-5 4v-4H4z"/><path d="M8.5 10.8h.01M12 10.8h.01M15.5 10.8h.01"/>',
    'assistent': '<path d="M11 3.5l1.9 5.1 5.1 1.9-5.1 1.9L11 17.5l-1.9-5.1L4 10.5l5.1-1.9z"/><path d="M18 14.5l.8 1.9 1.9.8-1.9.8-.8 1.9-.8-1.9-1.9-.8 1.9-.8z"/>',
    'gutachten': '<path d="M8 3h8l4 4v12.5H8z"/><path d="M16 3v4h4"/><path d="M11 11h6M11 14h6M11 17h3.5"/><path d="M5 7v14.5h11"/>',
    'beleg': '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    'hinweis': '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.6v.2"/>',
    'pruefen': '<circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.4 15.4L21 21"/><path d="M7.8 10.6l2 2 3.5-3.7"/>',
    'post': '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.8 6.5L12 12.8l8.2-6.3"/>',
    'schloss': '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/><path d="M12 14.5v2.5"/>',
    'code': '<path d="M8 7l-5 5 5 5M16 7l5 5-5 5M13.5 4.5l-3 15"/>',
    'werkzeug': '<path d="M14.7 6.3a4 4 0 0 0 5 5l-8.9 8.9a2.1 2.1 0 0 1-3-3z"/><path d="M14.7 6.3l2.8-2.8a4 4 0 0 1 3 3l-2.8 2.8"/>',
    'start': '<path d="M5 12h14M13 6l6 6-6 6"/>',
    # Projektzeichen „Rede mit dem AfD-Gutachten“: Sprechblase mit Waage (Logo-Familie, siehe seite/logo)
    'projekt': '<path d="M7.5 3.5h9a4 4 0 0 1 4 4v6a4 4 0 0 1-4 4h-5.5l-4.5 3.5v-3.5a4 4 0 0 1-4-4v-6a4 4 0 0 1 4-4z"/><path d="M12 6.3v7.4M9.6 13.7h4.8M7.6 8h8.8M7.6 8L6.2 11.2M7.6 8L9 11.2M6.2 11.2a1.4 1.4 0 0 0 2.8 0M16.4 8L15 11.2M16.4 8L17.8 11.2M15 11.2a1.4 1.4 0 0 0 2.8 0"/>',
}


def ikon(name, klasse='ikon'):
    return (f'<svg class="{klasse}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" '
            f'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{IKONEN[name]}</svg>')


def logo(datei, klasse='logo'):
    svg = (LOGOS / datei).read_text(encoding='utf-8')
    assert not re.search(r'<script|\son\w+=', svg, re.I)
    svg = re.sub(r'<\?xml.*?\?>', '', svg, flags=re.S)
    svg = re.sub(r'<title>.*?</title>', '', svg, flags=re.S)
    svg = re.sub(r'\s(?:class|data-name)="[^"]*"', '', svg)
    benutzt = set(re.findall(r'url\(#([^)]+)\)', svg))   # Verläufe u. Ä. brauchen ihre ID
    svg = re.sub(r'\sid="([^"]*)"', lambda m: m[0] if m[1] in benutzt else '', svg)
    if not re.search(r'<svg[^>]*\sfill=', svg):
        svg = svg.replace('<svg', '<svg fill="currentColor"', 1)
    svg = svg.replace('<svg', f'<svg class="{klasse}" aria-hidden="true" focusable="false"', 1)
    return re.sub(r'\s*\n\s*', ' ', svg.strip())


h = lambda s: html.escape(s, quote=True)

# Antworten aus dem Gutachten (abgefragt über bedeutung_suchen der Schnittstelle am 20.11.2026, nach Umstellung
# auf die Fragen-Vektoren), gekürzt.
GESPRAECHE = [
    dict(frage='Zu welchem Ergebnis kommt das Gutachten?', werkzeug='bedeutung_suchen',
         antwort='Die GFF kommt zu dem Ergebnis, dass die AfD verfassungswidrig im Sinne von Art. 21 Abs. 2 GG ist: '
                 'Die Partei gehe darauf aus, die freiheitliche demokratische Grundordnung zu beeinträchtigen, indem sie '
                 'das Demokratieprinzip und die Menschenwürdegarantie systematisch missachte.',
         beleg=('Zusammenfassung, S. 16', f'{PDF}#page=18')),
    dict(frage='Was sagt das Gutachten zur „Remigration“?', werkzeug='bedeutung_suchen',
         antwort='Es dokumentiert die Definition, auf die Björn Höcke 2024 verwies: Remigration umfasse „alle Maßnahmen '
                 'und Anreize zu einer rechtsstaatlichen und gesetzeskonformen Rückführung ausreisepflichtiger Ausländer“. '
                 'Die Forderung nach „millionenfacher Remigration“ bezieht die GFF zugunsten der Partei nur auf '
                 'Ausländer*innen; in der angestrebten Abschiebepolitik sieht sie aber die Gefahr von Abschiebungen in Folter.',
         beleg=('S. 178, S. 790, S. 1572', f'{PDF}#page=180')),
    dict(frage='Welche Rolle spielt das Vorfeld der Partei?', werkzeug='bedeutung_suchen',
         antwort='Laut Gutachten setzt die Neue Rechte zuerst auf den vorpolitischen Raum von Kultur und Zivilgesellschaft, '
                 'um dort die Voraussetzungen für politische Macht zu schaffen. Untersucht werden Identitäre Bewegung, '
                 'Institut für Staatspolitik, Ein Prozent und Compact; als Beispiel zitiert es Maximilian Krah: '
                 '„ich wäre nicht Spitzenkandidat ohne Ihrer beider Hilfe“.',
         beleg=('Teil 1 G.I, S. 358–359', f'{PDF}#page=360')),
    dict(frage='Hat die GFF bei der Auswertung KI eingesetzt?', werkzeug='bedeutung_suchen',
         antwort='Ja, vor allem als Vorfilter: Quelloffene Sprachmodelle, lokal auf eigener Hardware betrieben, prüften jede '
                 'Texteinheit auf Relevanz und ordneten sie einer Prüfungsdimension zu; Jurist*innen und '
                 'Sozialwissenschaftler*innen prüften die Treffer. Für die rechtliche Einordnung entwarf KI nur vereinzelt '
                 'Vorschläge, die ausnahmslos überarbeitet wurden.',
         beleg=('Einführung D, S. 149 und 167', f'{PDF}#page=169')),
]

BEISPIELE = [
    'Wie begründet das Gutachten sein Ergebnis? Bitte mit Seitenangaben.',
    'Wie prüft das Gutachten den Maßstab der Menschenwürde, und welche Fallgruppen unterscheidet es?',
    'Was versteht die AfD unter „Remigration“? Welche Äußerungen dokumentiert das Gutachten dazu?',
    'Will die AfD Ausländer*innen ausweisen? Was belegt das Gutachten dazu, und wie bewertet es das rechtlich?',
    'Wie schätzt das Gutachten die bildungspolitischen Forderungen der Partei ein?',
    'Welche Belege führt das Gutachten zu einem Thema an? Zeig mir die Fußnoten mit Quellen.',
    'Was steht in Art. 21 Abs. 2 GG, und wie legt das Gutachten „darauf ausgehen“ aus?',
    'Was steht auf Seite 412?',
]


def gespraech(n, g):
    erst = n == 0
    return f'''          <div class="g{' aktiv' if erst else ''}"{'' if erst else ' aria-hidden="true"'}>
            <div class="blase frage"><span class="frage-text" data-text="{h(g['frage'])}">{h(g['frage'])}</span></div>
            <div class="werkzeug-zeile">{ikon('gutachten')}<span class="nw">sucht im Gutachten</span><code>{h(g['werkzeug'])}</code><span class="tippen"><i></i><i></i><i></i></span></div>
            <div class="antwort-zeile">
              <span class="avatar">{ikon('assistent')}</span>
              <div class="blase antwort">{h(g['antwort'])}<br><a class="beleg" href="{h(g['beleg'][1])}"{'' if erst else ' tabindex="-1"'}>{ikon('beleg')}{h(g['beleg'][0])}</a></div>
            </div>
          </div>'''


text = (HIER / 'vorlage.html').read_text(encoding='utf-8')
text = text.replace('{{GESPRAECHE}}', '\n'.join(gespraech(n, g) for n, g in enumerate(GESPRAECHE)))
text = text.replace('{{PUNKTE}}', '\n'.join(
    f'            <button class="g-punkt" type="button" aria-label="Beispiel {n + 1}" aria-current="{"true" if n == 0 else "false"}"></button>'
    for n in range(len(GESPRAECHE))))
text = text.replace('{{BEISPIELE}}', '\n'.join(
    f'          <li>{ikon("frage")}<span>{h(b)}</span></li>' for b in BEISPIELE))
text = text.replace('{{ADRESSE}}', ADRESSE)
text = re.sub(r'\{\{logo:([^:}]+)(?::([^}]+))?\}\}', lambda m: logo(m[1], m[2] or 'logo'), text)
text = re.sub(r'\{\{ikon:([^:}]+)(?::([^}]+))?\}\}', lambda m: ikon(m[1], m[2] or 'ikon'), text)
assert '{{' not in text, re.findall(r'\{\{[^}]*\}\}', text)
assert '—' not in text, 'Geviertstrich gefunden'
(HIER / 'index.html').write_text(text, encoding='utf-8')
print('ok', len(text))
