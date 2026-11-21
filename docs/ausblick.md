# Ausblick: Chat direkt auf der Projektseite

Bisher braucht man einen eigenen KI-Assistenten (Claude, ChatGPT, Grok, LM Studio …), um mit dem Gutachten zu sprechen. Mit einer Chat-Oberfläche auf der Projektseite ginge das auch ohne Konto und ohne Einrichtung. Die MCP-Schnittstelle bliebe dafür unverändert. Hinzu kämen ein Sprachmodell, das sie benutzt, und eine Oberfläche dafür.

Dieses Dokument beschreibt zwei Wege. **Umgesetzt ist keiner davon.**

## Fall 1: über einen KI-Anbieter, etwa Claude

```
Browser ──► Chat-Endpunkt (PHP, poc.deutschkaemer.info) ──► Claude API ◄──► MCP-Schnittstelle
   ◄──────────── Antwort als Stream (Server-Sent Events) ◄──────┘
```

Ein Endpunkt auf dem Webserver nimmt die Frage entgegen. Er ruft die [Messages API](https://platform.claude.com/docs) mit dem MCP-Connector auf, sodass das Modell die Werkzeuge dieser Schnittstelle selbst benutzen kann. Die Werkzeugaufrufe kommen dabei von den Servern des Anbieters. Die Antwort wird als Stream an den Browser weitergereicht.

```json
{
  "model": "claude-sonnet-5-5",
  "max_tokens": 2000,
  "stream": true,
  "system": "Beantworte Fragen ausschließlich mit den Werkzeugen des AfD-Gutachtens …",
  "mcp_servers": [{ "type": "url", "name": "afd-gutachten", "url": "https://poc.deutschkaemer.info/afd-gutachten/mcp" }],
  "tools": [{ "type": "mcp_toolset", "mcp_server_name": "afd-gutachten" }],
  "messages": [{ "role": "user", "content": "…" }]
}
```

Dazu wird die Beta-Kopfzeile `anthropic-beta: mcp-client-2025-11-20` mitgeschickt. Feldnamen und Beta-Kennungen legt der Anbieter fest, maßgeblich ist seine Dokumentation.

**Was nötig wäre**

- API-Konto bei Anthropic. Der Schlüssel liegt nur auf dem Server, etwa in `~/poc/config.php`, nie im Browser. Ein Ausgabenlimit in der Console begrenzt die Kosten.
- Ein Chat-Endpunkt mit Anfragegrenzen, Längenbegrenzung der Frage und Weiterleitung des Streams.
- Datenschutz: Die Fragen gehen an Anthropic in die USA. Nötig sind ein Vertrag zur Auftragsverarbeitung und ein Hinweis in der Datenschutzerklärung.

**Kostenschätzung**

Eine typische Frage braucht zwei bis vier Werkzeugaufrufe. Bei jedem Schritt wird der bisherige Verlauf erneut als Eingabe berechnet:

| Schritt | Eingabe (Token, gerundet) |
|---|---:|
| Anweisungen, Werkzeugliste, Frage | 4.000 |
| nach `bedeutung_suchen` (≈ 2.500 Token Ergebnis) | 6.500 |
| nach `abschnitt_lesen` (≈ 8.000 Token Ergebnis) | 14.500 |
| **zusammen** | **≈ 25.000** |

Dazu kommen rund 1.000 Token Ausgabe. Die Listenpreise je Million Token stehen in US-Dollar (Stand November 2026). Umgerechnet ist mit rund 1,10 US-Dollar je Euro (EZB-Referenzkurs Mitte November 2026: 1,0989):

| Modell | Eingabe | Ausgabe | je Frage | 1.000 Fragen |
|---|---:|---:|---:|---:|
| Claude Haiku 5.5 | 0,10 US-$ | 0,50 US-$ | ≈ 0,3 Cent | ≈ 3 € |
| Claude Sonnet 5.5 | 2 US-$ | 10 US-$ | ≈ 5,5 Cent | ≈ 55 € |
| Claude Opus 5.5 | 4 US-$ | 20 US-$ | ≈ 11 Cent | ≈ 110 € |

Mit Prompt-Caching für Anweisungen und Werkzeugliste sinken die Eingabekosten weiter. Lange Abschnitte erhöhen sie. Quelle der Preise: [Claude-Preisliste](https://platform.claude.com/docs/en/about-claude/pricing).

## Fall 2: eigene Hardware mit eigenen Modellen

```
Browser ──► Chat-Endpunkt (PHP) ══ SSH-Tunnel / WireGuard ══► Vermittler ◄──► llama-server (Grafikkarte)
                                                                  │
                                                                  └──► MCP-Schnittstelle
```

Der Modellserver (llama-server, LM Studio) liefert Werkzeugaufrufe nur als Vorschlag zurück und führt sie nicht selbst aus. Diese Arbeit übernimmt ein kleiner **Vermittler**:

1. Er schickt Frage, Anweisungen und die Werkzeugliste der Schnittstelle (`tools/list`) an das Modell, über die OpenAI-artige Schnittstelle `/v1/chat/completions` mit `tools`.
2. Verlangt das Modell ein Werkzeug, ruft der Vermittler es über MCP auf (`tools/call`) und gibt das Ergebnis an das Modell zurück.
3. Das wiederholt sich, bis eine Antwort steht. Sie geht als Stream an den Browser.

**Was nötig wäre**

- Ein Rechner mit leistungsfähiger Grafikkarte, mindestens 24 GB Grafikspeicher.
- Ein Modell mit Werkzeugaufrufen und mindestens 32.000 Token Kontext, weil die Antworten der Schnittstelle lang sein können. In Frage kommen etwa Gemma 4 26B-A4B (Q4_K_M, 17 GB) oder ein Qwen3-Modell.
- Der Vermittler, als kleines Python- oder PHP-Programm.
- Eine gesicherte Verbindung vom Webserver zu diesem Rechner, etwa über einen SSH-Tunnel oder WireGuard. Der Rechner muss dauerhaft laufen.
- Eine Warteschlange: Eine Karte bedient nur wenige Unterhaltungen gleichzeitig (Slots in llama-server).

**Abwägung**

- Die Fragen verlassen den eigenen Betrieb nicht, und es fallen keine Gebühren je Frage an.
- Kleinere Modelle halten die Zitierregeln weniger sicher ein und verlieren bei langen Werkzeugantworten eher den Faden. Vor einer Freigabe sollten die Antworten mit dem [Testsatz](../messung/testsatz.json) geprüft werden, auf Fundstelle, Seitenangabe und Trennung von Beleg und Bewertung.
- Strom, Wartung und Ausfälle des eigenen Rechners sind zu bedenken.

## Checkliste: was beide Wege brauchen

- [ ] **Ein Chatfenster:** Antworten erscheinen Wort für Wort, Belege führen direkt auf die Seite in der PDF, alles auch auf dem Smartphone bedienbar.
- [ ] **Schutz vor Missbrauch:** Begrenzte Zahl an Anfragen je Anschluss, Bindung an das Thema Gutachten und ein Hinweis, wenn gerade zu viel los ist.
- [ ] **Transparenz:** Offen sagen, welches Modell antwortet, was gespeichert wird und dass KI sich irren kann.
- [ ] **Rechtlicher Rahmen:** Impressum und Datenschutzerklärung passend zum gewählten Weg, beim Anbieter zusätzlich ein Vertrag zur Auftragsverarbeitung.
- [ ] **Prüfung vor dem Start:** Die Antworten mit festen Testfragen gegenprüfen: richtige Fundstelle, Seitenangabe, Belege und Bewertung sauber getrennt.
- [ ] **Betreuung im Betrieb:** Jemand behält Kosten, Auslastung und Fehlermeldungen im Blick und reagiert auf Hinweise.

Beide Wege lassen sich verbinden: eigene Hardware als Normalfall, der Anbieter als Ausweichweg bei hoher Last oder wenn der eigene Rechner nicht erreichbar ist.
