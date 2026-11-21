#!/usr/bin/env python3
"""
vorschaubild.py – Vorschaubild für Open Graph (1200 × 630 px), wenn die Seite geteilt wird.

  python3 vorschaubild.py            schreibt server/web/og.png (braucht Playwright mit Chromium)

Das Bild wird als kleine HTML-Seite gesetzt und mit Chromium abfotografiert: Projektzeichen, Titel,
Untertitel und Adresse, im dunklen Farbschema der Seite.
"""
import asyncio
from pathlib import Path

from playwright.async_api import async_playwright

HIER = Path(__file__).parent
ZEICHEN = ('<svg viewBox="0 0 24 24" width="168" height="168"><rect width="24" height="24" rx="5.5" fill="#b5523b"/>'
           '<g fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
           'transform="translate(1.2 1) scale(.9)"><path d="M7.5 3.5h9a4 4 0 0 1 4 4v6a4 4 0 0 1-4 4h-5.5l-4.5 3.5v-3.5'
           'a4 4 0 0 1-4-4v-6a4 4 0 0 1 4-4z"/><path d="M12 6.3v7.4M9.6 13.7h4.8M7.6 8h8.8M7.6 8L6.2 11.2M7.6 8L9 11.2'
           'M6.2 11.2a1.4 1.4 0 0 0 2.8 0M16.4 8L15 11.2M16.4 8L17.8 11.2M15 11.2a1.4 1.4 0 0 0 2.8 0"/></g></svg>')

HTML = f'''<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><style>
html, body {{ margin: 0; width: 1200px; height: 630px; }}
body {{ box-sizing: border-box; padding: 72px 84px; display: flex; flex-direction: column; justify-content: space-between;
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #e6e8ec;
  background: radial-gradient(90% 120% at 0% 0%, #2a1c19 0%, #0f1115 55%), #0f1115; }}
.kopf {{ display: flex; align-items: center; gap: 44px; }}
.kicker {{ margin: 0 0 14px; font-size: 24px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #5eb8a8; }}
h1 {{ margin: 0; font-size: 80px; line-height: 1.02; letter-spacing: -.01em; }}
p.unter {{ margin: 0; max-width: 980px; font-size: 32px; line-height: 1.35; color: #b4bcc8; }}
.fuss {{ display: flex; justify-content: space-between; align-items: center; font-size: 26px; color: #9aa3b2; }}
.fuss code {{ font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; color: #e6e8ec; }}
.pillen span {{ margin-left: 10px; padding: 6px 16px; border: 1.5px solid #2c3340; border-radius: 999px; font-size: 22px; }}
</style></head><body>
<div class="kopf">{ZEICHEN}<div><p class="kicker">Proof of Concept</p><h1>Rede mit dem<br>AfD-Gutachten</h1></div></div>
<p class="unter">Das Rechtsgutachten der GFF mit Claude, ChatGPT, Grok oder einem lokalen Modell befragen – Antworten mit Seitenangabe und Link in die PDF.</p>
<div class="fuss"><code>poc.deutschkaemer.info/afd-gutachten</code><div class="pillen"><span>3.060 Seiten</span><span>7.467 Fußnoten</span></div></div>
</body></html>'''


async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch()
        pg = await b.new_page(viewport={'width': 1200, 'height': 630})
        await pg.set_content(HTML)
        await pg.screenshot(path=str(HIER.parent / 'server' / 'web' / 'og.png'))
        await b.close()
    try:  # verlustfrei kleiner speichern
        from PIL import Image
        Image.open(HIER.parent / 'server' / 'web' / 'og.png').save(HIER.parent / 'server' / 'web' / 'og.png', optimize=True)
    except ImportError:
        pass


asyncio.run(main())
print('geschrieben:', HIER.parent / 'server' / 'web' / 'og.png')
