<?php
/**
 * mcp_server.php – MCP-Schnittstelle zum GFF-Gutachten „Ist die AfD verfassungswidrig?“
 *
 * Transport „Streamable HTTP“ in der einfachsten Form (wie bei plenarprotokolle.digital):
 * jede Nachricht ein POST mit einem JSON-RPC-Objekt, jede Antwort ein JSON-Objekt,
 * keine Sitzungen, kein Ereignisstrom. Nur lesend, ohne Anmeldung.
 *
 * Schutz: Origin-Prüfung, Aufrufgrenzen über APCu (gesamt und je Adressbereich /24 bzw. /48),
 * Zeitgrenze je Abfrage (Datenbankrolle 'lesen'), Antworten gekürzt auf AFD_MAX_ZEICHEN.
 * Protokoll: Zeit, Werkzeug, gekürzte Argumente, Dauer – keine Adressen.
 *
 * Quelle: Gesellschaft für Freiheitsrechte e.V., Lizenz CC BY 4.0. Aufbereitung: Dennis Deutschkämer.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const AFD_VERSIONEN      = ['2025-11-25', '2025-06-18', '2025-03-26'];
const AFD_NAME           = 'afd-gutachten-gff';
const AFD_VERSION        = '0.3.1';
/** Icons für Clients (MCP 2025-11-25, Implementation.icons): Sprechblase mit Waage auf Rostrot. */
const AFD_ICONS = [
    ['src' => 'https://poc.deutschkaemer.info/afd-gutachten/favicon.svg', 'mimeType' => 'image/svg+xml', 'sizes' => ['any']],
    ['src' => 'https://poc.deutschkaemer.info/afd-gutachten/icon-512.png', 'mimeType' => 'image/png', 'sizes' => ['512x512']],
    ['src' => 'https://poc.deutschkaemer.info/afd-gutachten/icon-192.png', 'mimeType' => 'image/png', 'sizes' => ['192x192']],
    ['src' => 'https://poc.deutschkaemer.info/afd-gutachten/favicon-32.png', 'mimeType' => 'image/png', 'sizes' => ['32x32']],
];
const AFD_JE_MINUTE      = 240;
const AFD_CLIENT_MINUTE  = 120;
const AFD_CLIENT_STUNDE  = 1500;
const AFD_MAX_ZEICHEN    = 60000;
const AFD_PDF            = 'https://freiheitsrechte.org/uploads/publications/Demokratie/GFF_AfD-Gutachten.pdf';
const AFD_ORIGINS        = ['https://claude.ai', 'https://claude.com', 'https://www.claude.ai',
                            'https://chatgpt.com', 'https://chat.openai.com', 'https://poc.deutschkaemer.info'];
const AFD_QUELLE = 'Quelle: Gesellschaft für Freiheitsrechte e.V. (GFF), „Ist die Alternative für Deutschland verfassungswidrig?“, '
    . 'Rechtsgutachten vom 25. Juni 2026, Lizenz CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/deed.de). '
    . 'Maschinell aufbereitet (Gliederung, Seiten, Fußnoten) von Dennis Deutschkämer; inoffiziell, maßgeblich ist die PDF der GFF.';

final class AfdFehler extends RuntimeException {}     // Protokollfehler (JSON-RPC error)
final class AfdEingabe extends RuntimeException {}    // Werkzeugfehler, den das Modell beheben kann (isError)

function afd_mcp_bedienen(): never
{
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-store');
    $origin = rtrim((string)($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
    if ($origin !== '' && !in_array($origin, AFD_ORIGINS, true)) {
        afd_http(403, ['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Origin nicht erlaubt']]);
    }
    $methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($methode === 'GET') {
        if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/event-stream')) {
            header('Allow: POST');
            afd_http(405, null);
        }
        afd_infoseite();
    }
    if ($methode !== 'POST') {
        header('Allow: GET, POST');
        afd_http(405, null);
    }
    $pv = (string)($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '');
    if ($pv !== '' && !in_array($pv, AFD_VERSIONEN, true)) {
        afd_http(400, ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => "Protokollversion $pv wird nicht unterstützt"]]);
    }
    try {
        $n = json_decode((string)file_get_contents('php://input', false, null, 0, 1_000_000), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        afd_http(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Kein gültiges JSON']]);
    }
    if (!is_array($n) || array_is_list($n)) {
        afd_http(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Erwartet wird genau ein JSON-RPC-Objekt']]);
    }
    if (!array_key_exists('id', $n) || !isset($n['method'])) {
        afd_http(202, null);
    }
    $id = $n['id'];
    $m = (string)$n['method'];
    $p = is_array($n['params'] ?? null) ? $n['params'] : [];
    try {
        $erg = match ($m) {
            'initialize'               => afd_initialize($p),
            'ping'                     => new stdClass(),
            'tools/list'               => ['tools' => afd_werkzeugliste()],
            'tools/call'               => afd_aufruf($p),
            'resources/list'           => ['resources' => []],
            'resources/templates/list' => ['resourceTemplates' => []],
            'prompts/list'             => ['prompts' => []],
            default                    => throw new AfdFehler("Unbekannte Methode: $m", -32601),
        };
        afd_http(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $erg]);
    } catch (AfdFehler $e) {
        afd_http(200, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $e->getCode(), 'message' => $e->getMessage()]]);
    } catch (Throwable $e) {
        error_log('afd-gutachten mcp: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        afd_http(200, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'Interner Fehler']]);
    }
}

function afd_http(int $status, ?array $body): never
{
    http_response_code($status);
    if ($body !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

function afd_initialize(array $p): array
{
    $v = (string)($p['protocolVersion'] ?? '');
    return [
        'protocolVersion' => in_array($v, AFD_VERSIONEN, true) ? $v : AFD_VERSIONEN[0],
        'capabilities'    => ['tools' => ['listChanged' => false]],
        'serverInfo'      => ['name' => AFD_NAME, 'title' => 'Rede mit dem AfD-Gutachten', 'version' => AFD_VERSION,
                              'websiteUrl' => 'https://poc.deutschkaemer.info/afd-gutachten/',
                              'icons' => AFD_ICONS],
        'instructions'    => afd_anleitung(),
    ];
}

function afd_anleitung(): string
{
    $q = AFD_QUELLE;
    return <<<TXT
Volltext des Rechtsgutachtens „Ist die Alternative für Deutschland verfassungswidrig?“ der Gesellschaft für Freiheitsrechte e.V. (GFF) vom 25. Juni 2026: rund 1.580 Seiten Gutachten und 1.480 Seiten Anhang mit Belegen, gegliedert in 2.455 Abschnitte, mit 7.468 Fußnoten. Viele Fußnoten verlinken den Beleg in der AfD-Datenbank von FragDenStaat.

Einordnung: Das Gutachten ist die rechtliche Bewertung einer zivilgesellschaftlichen Organisation, keine gerichtliche Entscheidung. Antworten geben wieder, was das Gutachten feststellt und wie es begründet, und machen das kenntlich („laut Gutachten“, „die GFF kommt zu dem Ergebnis …“). Keine eigenen Bewertungen der Partei oder von Personen hinzufügen. Zitate im Anhang sind Äußerungen von Funktionär*innen, wie das Gutachten sie dokumentiert; sie sind als solche zu kennzeichnen. Die GFF weist selbst darauf hin, dass das Gutachten weder vollständig noch abschließend ist und nur öffentlich bekannte Informationen auswertet.

Fragen zur Partei selbst (z. B. „Was versteht die AfD unter Remigration?“, „Will die AfD Ausländer ausweisen?“): Diese Quelle kann nur beantworten, was das Gutachten dazu feststellt und belegt. Die Antwort daher so aufbauen: (1) was die im Gutachten dokumentierten Äußerungen, Programme und Anträge sagen – möglichst im Wortlaut der Belegzitate, mit Urheber, Datum und Fußnote/Beleg; (2) wie das Gutachten das rechtlich einordnet, ausdrücklich als dessen Bewertung. Nicht als feststehende Tatsache über die Partei formulieren, was das Gutachten folgert. Darauf hinweisen, dass die Bewertung umstritten ist und das Gutachten die Sicht der GFF wiedergibt; für eine Gesamteinordnung sind weitere Quellen nötig.

Fragen zur Herausgeberin (wer hinter dem Gutachten steht, wer es verfasst und bezahlt hat, wie die GFF arbeitet und sich finanziert): Werkzeug `herausgeberin`. Die Einträge sind nicht Teil des Gutachtentextes. Angaben aus dem Gutachten (Impressum, Zusammenfassung 2, Danksagung) mit Seite zitieren; Zusammenfassungen der GFF-Webseite als Selbstauskunft der GFF kennzeichnen, die Wikipedia-Zusammenfassung als solche (CC BY-SA 4.0). Keine eigene Wertung der GFF hinzufügen; für eine unabhängige Einordnung auf weitere Quellen verweisen.

Zitieren: immer mit Seitenzahl des Gutachtens (S. 412) und, wo sinnvoll, mit Gliederungsnummer (z. B. Teil 3 A.III.2.c)). Jede Antwort enthält einen Link auf die passende Seite der PDF. Fußnotenverweise stehen im Text als [^n]; mit `fussnote` die Belege nachschlagen.

Vorgehen: Für die Gesamtschau zuerst `ueberblick` (Fragestellung, Aufbau, Ergebnis-Abschnitte). Den Aufbau mit `inhaltsverzeichnis` erkunden. Inhaltliche Fragen mit `bedeutung_suchen` (Frage in eigenen Worten, findet auch andere Formulierungen) oder `suchen` (genaue Wörter aus dem Gutachten) beginnen – Treffer mit Seite und Gliederungsnummer –, dann mit `abschnitt_lesen` den Abschnitt im Zusammenhang lesen. `seite_lesen` liefert eine gedruckte Seite samt Fußnoten. Den Wortlaut der geprüften Norm (Art. 21 GG) liefert `rechtsgrundlage`; er ist Gesetzestext, nicht Teil des Gutachtens, und wird als solcher zitiert (z. B. „Art. 21 Abs. 2 GG“), nie mit einer Seitenzahl des Gutachtens. Die Kurzzusammenfassung steht in „Zusammenfassung 1“, das Ergebnis in „Teil 3 G“ und im „Schluss“.

Gliederung: Zusammenfassung · Einführung · Teil 1 (Entwicklung, Machtzentren, Vorfeld) · Teil 2 (Rechtliche Maßstäbe) · Teil 3 (Verfassungswidrigkeit: A Menschenwürde, B Demokratieprinzip, C Rechtsstaatsprinzip, D Beeinträchtigung der fdGO, E Wesensverwandtschaft mit dem Nationalsozialismus, F Daraufausgehen, G Ergebnis) · Schluss · Literaturverzeichnis · Anhang (A Methodik, B–E Belege).

Quellenangabe: {$q} Bei Antworten, die auf diesen Daten beruhen, einmal die GFF als Urheberin und die Lizenz CC BY 4.0 nennen.
TXT;
}

/* ------------------------------------------------------------------ Werkzeuge */

function afd_werkzeugliste(): array
{
    $ro = ['readOnlyHint' => true, 'openWorldHint' => false, 'destructiveHint' => false, 'idempotentHint' => true];
    $nummer = ['type' => 'string', 'description' => 'Gliederungsnummer wie im Gutachten, z. B. "Teil 3 A.III.2.c)" oder "Zusammenfassung 1", alternativ die Kennung (id) aus anderen Antworten'];
    return [
        ['name' => 'ueberblick', 'title' => 'Überblick',
         'description' => 'Worum es im Gutachten geht: Herausgeberin, Fragestellung, Umfang, Aufbau (oberste Gliederung mit Seiten), wo Zusammenfassung und Ergebnis stehen, Lizenz und Links. Zuerst aufrufen.',
         'inputSchema' => ['type' => 'object', 'properties' => new stdClass()], 'annotations' => $ro],
        ['name' => 'inhaltsverzeichnis', 'title' => 'Inhaltsverzeichnis',
         'description' => 'Gliederung des Gutachtens mit Seitenangaben. Ohne nummer die obersten Ebenen; mit nummer die Unterabschnitte dieses Abschnitts.',
         'inputSchema' => ['type' => 'object', 'properties' => [
             'nummer' => $nummer,
             'tiefe'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'default' => 2, 'description' => 'Wie viele Ebenen unterhalb angezeigt werden'],
         ]], 'annotations' => $ro],
        ['name' => 'suchen', 'title' => 'Im Gutachten suchen',
         'description' => 'Volltextsuche in Text, Belegzitaten und Fußnoten. Liefert Fundstellen mit Seite, Gliederungsnummer und Textausschnitt. Wörter aus dem Gutachten verwenden (deutsch); Wortanfang mit * (z. B. Remigration*), feste Wendung in Anführungszeichen.',
         'inputSchema' => ['type' => 'object', 'required' => ['anfrage'], 'properties' => [
             'anfrage' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 200],
             'bereich' => ['type' => 'string', 'enum' => ['alles', 'gutachten', 'anhang', 'fussnoten'], 'default' => 'alles',
                           'description' => 'gutachten = Hauptteil ohne Anhang; anhang = Belege und Methodik; fussnoten = nur Fußnotentexte'],
             'innerhalb' => ['type' => 'string', 'description' => 'Nur in diesem Abschnitt und seinen Unterabschnitten suchen (Gliederungsnummer, z. B. "Teil 3 B")'],
             'anzahl'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 15],
         ]], 'annotations' => $ro],
        ['name' => 'bedeutung_suchen', 'title' => 'Nach Bedeutung suchen',
         'description' => 'Suche nach Bedeutung (Vektoren): findet Passagen zu einer Frage in eigenen Worten, auch wenn andere Begriffe verwendet werden. Liefert Fundstellen mit Seite, Gliederungsnummer und Ausschnitt. Ist die Bedeutungssuche gerade nicht erreichbar, wird automatisch im Volltext gesucht.',
         'inputSchema' => ['type' => 'object', 'required' => ['frage'], 'properties' => [
             'frage'     => ['type' => 'string', 'minLength' => 3, 'maxLength' => 500, 'description' => 'Frage oder Thema in eigenen Worten (deutsch oder englisch)'],
             'bereich'   => ['type' => 'string', 'enum' => ['alles', 'gutachten', 'anhang'], 'default' => 'alles'],
             'innerhalb' => ['type' => 'string', 'description' => 'Nur in diesem Abschnitt und seinen Unterabschnitten (Gliederungsnummer)'],
             'anzahl'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'default' => 10],
         ]], 'annotations' => $ro],
        ['name' => 'rechtsgrundlage', 'title' => 'Rechtsgrundlage (Art. 21 GG)',
         'description' => 'Wortlaut der Rechtsgrundlage, an der das Gutachten prüft: Art. 21 GG (Parteien, Verfassungswidrigkeit, Ausschluss von staatlicher Finanzierung, Zuständigkeit des Bundesverfassungsgerichts). Gesetzestext, nicht Teil des Gutachtens.',
         'inputSchema' => ['type' => 'object', 'properties' => [
             'absatz' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'description' => 'Nur diesen Absatz (ohne Angabe: der ganze Artikel)'],
         ]], 'annotations' => $ro],
        ['name' => 'herausgeberin', 'title' => 'Über die Herausgeberin (GFF)',
         'description' => 'Angaben über die Gesellschaft für Freiheitsrechte (GFF) und die Entstehung des Gutachtens: Autor*innen und Vorstand laut Impressum, Initiative, Finanzierung und Projektpartner laut Gutachten, Selbstdarstellung der GFF (Arbeitsweise, Finanzen, Transparenz) und Wikipedia. Jeweils mit Quelle, Lizenz und Stand. Nicht Teil des Gutachtentextes.',
         'inputSchema' => ['type' => 'object', 'properties' => new stdClass()], 'annotations' => $ro],
        ['name' => 'abschnitt_lesen', 'title' => 'Abschnitt lesen',
         'description' => 'Text eines Abschnitts mit Gliederungspfad, Seitenangaben, Unterabschnitten und den Fußnoten des gelesenen Teils. Lange Abschnitte werden in Teilen geliefert (ab_absatz für die Fortsetzung).',
         'inputSchema' => ['type' => 'object', 'required' => ['nummer'], 'properties' => [
             'nummer'    => $nummer,
             'ab_absatz' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
             'fussnoten' => ['type' => 'boolean', 'default' => true, 'description' => 'Fußnotentexte mitliefern'],
         ]], 'annotations' => $ro],
        ['name' => 'seite_lesen', 'title' => 'Seite lesen',
         'description' => 'Eine gedruckte Seite des Gutachtens (Seitenzahl wie im PDF-Kopf, 1 bis 3060): Überschriften, Absätze und Fußnoten der Seite.',
         'inputSchema' => ['type' => 'object', 'required' => ['seite'], 'properties' => [
             'seite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3060],
         ]], 'annotations' => $ro],
        ['name' => 'fussnote', 'title' => 'Fußnoten nachschlagen',
         'description' => 'Text einer oder mehrerer Fußnoten mit Links und – falls vorhanden – dem Beleg in der AfD-Datenbank von FragDenStaat, dazu Seite und Abschnitt, in dem sie stehen.',
         'inputSchema' => ['type' => 'object', 'required' => ['nr'], 'properties' => [
             'nr' => ['description' => 'Fußnotennummer oder Liste von Nummern (höchstens 30)',
                      'oneOf' => [['type' => 'integer'], ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 30]]],
         ]], 'annotations' => $ro],
        ['name' => 'search', 'title' => 'Search (ChatGPT)',
         'description' => 'Search the GFF legal opinion on whether the AfD is unconstitutional (German full text). Returns sections with id, title and url. Use German keywords.',
         'inputSchema' => ['type' => 'object', 'required' => ['query'], 'properties' => ['query' => ['type' => 'string']]],
         'annotations' => $ro],
        ['name' => 'fetch', 'title' => 'Fetch (ChatGPT)',
         'description' => 'Fetch the full text of a section by id from search results (e.g. "abschnitt-123").',
         'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
         'annotations' => $ro],
    ];
}

function afd_aufruf(array $p): array
{
    $name = (string)($p['name'] ?? '');
    $args = is_array($p['arguments'] ?? null) ? $p['arguments'] : [];
    afd_grenzen();
    $t0 = microtime(true);
    try {
        $daten = match ($name) {
            'ueberblick'         => afd_w_ueberblick(),
            'inhaltsverzeichnis' => afd_w_inhalt($args),
            'suchen'             => afd_w_suchen($args),
            'bedeutung_suchen'   => afd_w_bedeutung($args),
            'rechtsgrundlage'    => afd_w_norm($args),
            'herausgeberin'      => afd_w_herausgeberin(),
            'abschnitt_lesen'    => afd_w_abschnitt($args),
            'seite_lesen'        => afd_w_seite($args),
            'fussnote'           => afd_w_fussnote($args),
            'search'             => afd_w_search($args),
            'fetch'              => afd_w_fetch($args),
            default              => throw new AfdFehler("Unbekanntes Werkzeug: $name", -32602),
        };
        $fehler = null;
    } catch (AfdEingabe $e) {
        $daten = null;
        $fehler = $e->getMessage();
    }
    afd_log($name, $args, $t0, $fehler);
    if ($fehler !== null) {
        return ['content' => [['type' => 'text', 'text' => $fehler]], 'isError' => true];
    }
    // ChatGPT erwartet bei search/fetch das Ergebnis als JSON-Text
    if (!in_array($name, ['search', 'fetch'], true)) {
        $daten['quelle'] = AFD_QUELLE;
    }
    $json = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT);
    if (mb_strlen($json) > AFD_MAX_ZEICHEN) {
        $json = mb_substr($json, 0, AFD_MAX_ZEICHEN) . "\n… (gekürzt)";
    }
    return ['content' => [['type' => 'text', 'text' => $json]]];
}

/* ------------------------------------------------------------------ Hilfen */

function afd_pdf_link(int $pdfSeite): string
{
    return AFD_PDF . '#page=' . $pdfSeite;
}

/** Abschnitt per Gliederungsnummer oder Kennung finden. */
function afd_abschnitt_finden(string $nummer): array
{
    $n = trim($nummer);
    if ($n === '') {
        throw new AfdEingabe('Gliederungsnummer fehlt.');
    }
    $db = afd_db();
    if (preg_match('/^(?:abschnitt-)?(\d+)$/', $n, $m)) {
        $r = $db->prepare('SELECT * FROM afd_abschnitt WHERE id = ?');
        $r->execute([(int)$m[1]]);
        if ($row = $r->fetch()) {
            return $row;
        }
    }
    // Schreibweisen angleichen: „Teil 3, A. III. 2.“ → „Teil 3 A.III.2“
    $norm = static fn(string $s): string => rtrim(preg_replace('/\s+/', '', mb_strtolower($s)), '.');
    $r = $db->prepare('SELECT * FROM afd_abschnitt WHERE nummer = ?');
    $r->execute([$n]);
    if ($row = $r->fetch()) {
        return $row;
    }
    $alle = $db->query('SELECT id, nummer, titel FROM afd_abschnitt')->fetchAll();
    $ziel = $norm($n);
    foreach ($alle as $a) {
        if ($norm($a['nummer']) === $ziel) {
            $r = $db->prepare('SELECT * FROM afd_abschnitt WHERE id = ?');
            $r->execute([$a['id']]);
            return $r->fetch();
        }
    }
    // Titel als letzter Versuch
    foreach ($alle as $a) {
        if (mb_strtolower($a['titel']) === mb_strtolower($n)) {
            $r = $db->prepare('SELECT * FROM afd_abschnitt WHERE id = ?');
            $r->execute([$a['id']]);
            return $r->fetch();
        }
    }
    throw new AfdEingabe("Abschnitt „{$n}“ nicht gefunden. Gliederungsnummern wie im Gutachten verwenden, z. B. „Teil 3 A.III“ oder „Zusammenfassung 1“; `inhaltsverzeichnis` zeigt sie.");
}

/** Alle Abschnitts-IDs eines Teilbaums (einschließlich Wurzel). Die IDs sind in Dokumentreihenfolge vergeben. */
function afd_teilbaum(array $abschnitt): array
{
    $db = afd_db();
    $r = $db->prepare('SELECT MIN(id) FROM afd_abschnitt WHERE id > ? AND rang <= ?');
    $r->execute([$abschnitt['id'], $abschnitt['rang']]);
    $ende = $r->fetchColumn();
    if (!$ende) {
        $ende = (int)$db->query('SELECT MAX(id) + 1 FROM afd_abschnitt')->fetchColumn();
    }
    return [(int)$abschnitt['id'], (int)$ende - 1];
}

function afd_kurz(array $a): array
{
    return [
        'nummer' => $a['nummer'], 'titel' => $a['titel'],
        'seiten' => afd_seiten($a['seite_von'], $a['seite_bis']),
        'pdf'    => afd_pdf_link((int)$a['pdf_von']),
    ];
}

function afd_seiten($von, $bis): string
{
    if ($von === null) {
        return '';
    }
    return ($bis === null || (int)$bis === (int)$von) ? "S. {$von}" : "S. {$von}–{$bis}";
}

function afd_ausschnitt(string $text, array $woerter, int $breite = 260): string
{
    $pos = null;
    foreach ($woerter as $w) {
        $w = trim($w, '"*+-()~<> ');
        if (mb_strlen($w) < 2) {
            continue;
        }
        $p = mb_stripos($text, $w);
        if ($p !== false && ($pos === null || $p < $pos)) {
            $pos = $p;
        }
    }
    $pos ??= 0;
    $start = max(0, $pos - intdiv($breite, 2));
    $aus = mb_substr($text, $start, $breite);
    return ($start > 0 ? '…' : '') . trim($aus) . ($start + $breite < mb_strlen($text) ? '…' : '');
}

/** Suchbegriff für MATCH … AGAINST im BOOLEAN MODE aufbereiten. */
function afd_boolesch(string $q): array
{
    $q = trim(preg_replace('/\s+/', ' ', $q));
    $phrasen = [];
    preg_match_all('/"([^"]+)"/u', $q, $m);
    foreach ($m[1] as $ph) {
        $phrasen[] = $ph;
    }
    $rest = trim(preg_replace('/"[^"]*"/u', ' ', $q));
    $teile = [];
    foreach ($phrasen as $ph) {
        $teile[] = '+"' . str_replace('"', '', $ph) . '"';
    }
    $woerter = [];
    foreach (preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) as $w) {
        $w = preg_replace('/[^\p{L}\p{N}*\-]/u', '', $w);
        if (mb_strlen(rtrim($w, '*')) < 3) {
            continue;
        }
        $woerter[] = rtrim($w, '*');
        $teile[] = '+' . (str_contains($w, '-') ? '"' . rtrim($w, '*') . '"' : $w);
    }
    if (!$teile) {
        throw new AfdEingabe('Die Anfrage braucht mindestens ein Wort mit drei Buchstaben.');
    }
    return [implode(' ', $teile), array_merge($phrasen, $woerter)];
}

/* ------------------------------------------------------------------ Werkzeuge im Einzelnen */

function afd_w_ueberblick(): array
{
    $db = afd_db();
    $meta = array_column($db->query('SELECT schluessel, wert FROM afd_meta')->fetchAll(), 'wert', 'schluessel');
    $oben = $db->query('SELECT * FROM afd_abschnitt WHERE rang <= 1 ORDER BY id')->fetchAll();
    $aufbau = [];
    $teil = null;
    foreach ($oben as $a) {
        if ((int)$a['rang'] === 0) {
            $teil = count($aufbau);
            $aufbau[] = afd_kurz($a) + ['abschnitte' => []];
        } elseif ($teil !== null) {
            $aufbau[$teil]['abschnitte'][] = $a['nummer'] . ' ' . $a['titel'] . ' (' . afd_seiten($a['seite_von'], $a['seite_bis']) . ')';
        }
    }
    $z = $db->query('SELECT (SELECT COUNT(*) FROM afd_abschnitt) abschnitte, (SELECT COUNT(*) FROM afd_absatz) absaetze,
        (SELECT COUNT(*) FROM afd_fussnote) fussnoten, (SELECT COUNT(*) FROM afd_fussnote WHERE datenbank IS NOT NULL) mit_beleg')->fetch();
    return [
        'titel'        => $meta['titel'] ?? '',
        'herausgeberin'=> $meta['herausgeber'] ?? '',
        'datum'        => $meta['datum'] ?? '',
        'fragestellung'=> 'Ob die AfD nach Art. 21 Abs. 2 GG verfassungswidrig ist und ein Parteiverbotsverfahren vor dem Bundesverfassungsgericht Aussicht auf Erfolg hätte.',
        'umfang'       => "3.060 Seiten (Gutachten S. 1–1.676, Anhang S. 1.676–3.060), {$z['abschnitte']} Abschnitte, {$z['absaetze']} Absätze, {$z['fussnoten']} Fußnoten, davon {$z['mit_beleg']} mit Beleg in der FragDenStaat-Datenbank",
        'einordnung'   => 'Rechtliche Bewertung einer zivilgesellschaftlichen Organisation, keine gerichtliche Entscheidung. Nach Angaben der GFF weder vollständig noch abschließend; ausgewertet wurden nur öffentlich bekannte Informationen.',
        'wo_steht_was' => [
            'Kurzzusammenfassung' => 'Zusammenfassung 1 (S. 16–22)',
            'Leseanleitung'       => 'Leseanleitung (S. 12–15)',
            'Methodik'            => 'Einführung D (S. 145 ff.) und Anhang A',
            'Ergebnis'            => 'Teil 3 G und Schluss',
            'Belege'              => 'Anhang B–E (Äußerungen, thematisch geordnet)',
        ],
        'rechtsgrundlage' => [
            'norm'    => 'Art. 21 Abs. 2 GG',
            'wortlaut'=> 'Parteien, die nach ihren Zielen oder nach dem Verhalten ihrer Anhänger darauf ausgehen, die freiheitliche demokratische Grundordnung zu beeinträchtigen oder zu beseitigen oder den Bestand der Bundesrepublik Deutschland zu gefährden, sind verfassungswidrig.',
            'hinweis' => 'Ganzer Artikel mit dem Werkzeug rechtsgrundlage. ' . AFD_NORM_HINWEIS,
        ],
        'herausgeberin_mehr' => 'Autor*innen, Initiative, Finanzierung und Angaben über die GFF mit dem Werkzeug herausgeberin.',
        'aufbau'       => $aufbau,
        'links'        => [
            'pdf'       => $meta['pdf_url'] ?? AFD_PDF,
            'online'    => $meta['online_url'] ?? '',
            'gff'       => $meta['gff_url'] ?? '',
            'datenbank' => $meta['datenbank_url'] ?? '',
            'lizenz'    => $meta['lizenz_url'] ?? '',
        ],
        'stand_pdf'    => $meta['pdf_stand'] ?? '',
    ];
}

function afd_w_inhalt(array $a): array
{
    $db = afd_db();
    $tiefe = max(1, min(4, (int)($a['tiefe'] ?? 2)));
    if (!empty($a['nummer'])) {
        $wurzel = afd_abschnitt_finden((string)$a['nummer']);
        [$von, $bis] = afd_teilbaum($wurzel);
        $r = $db->prepare('SELECT * FROM afd_abschnitt WHERE id > ? AND id <= ? AND rang <= ? ORDER BY id');
        $r->execute([$von, $bis, (int)$wurzel['rang'] + $tiefe]);
        $basis = (int)$wurzel['rang'];
        $kopf = afd_kurz($wurzel) + ['pfad' => $wurzel['pfad']];
    } else {
        $r = $db->prepare('SELECT * FROM afd_abschnitt WHERE rang < ? ORDER BY id');
        $r->execute([$tiefe]);
        $basis = -1;
        $kopf = null;
    }
    $liste = [];
    foreach ($r->fetchAll() as $s) {
        $einzug = str_repeat('  ', max(0, (int)$s['rang'] - $basis - 1));
        $liste[] = $einzug . $s['nummer'] . ' – ' . $s['titel'] . ' (' . afd_seiten($s['seite_von'], $s['seite_bis']) . ')'
            . ((int)$s['unterabschnitte'] > 0 && (int)$s['rang'] - $basis >= $tiefe ? ' [+' . $s['unterabschnitte'] . ']' : '');
    }
    if (count($liste) > 400) {
        $liste = array_slice($liste, 0, 400);
        $liste[] = '… (gekürzt – mit nummer einen Teilbereich wählen oder tiefe verringern)';
    }
    return array_filter(['abschnitt' => $kopf, 'gliederung' => $liste, 'hinweis' => '[+n] = weitere Unterabschnitte; mit inhaltsverzeichnis(nummer) aufklappen, mit abschnitt_lesen lesen.']);
}

function afd_w_suchen(array $a): array
{
    $anfrage = trim((string)($a['anfrage'] ?? ''));
    if (mb_strlen($anfrage) < 2) {
        throw new AfdEingabe('Bitte einen Suchbegriff angeben.');
    }
    $bereich = (string)($a['bereich'] ?? 'alles');
    $anzahl = max(1, min(50, (int)($a['anzahl'] ?? 15)));
    [$bool, $woerter] = afd_boolesch($anfrage);
    if (!empty($a['oder'])) {
        $bool = str_replace('+', '', $bool);   // Rückfall der Bedeutungssuche: jedes Wort optional, Relevanz sortiert
    }
    $db = afd_db();
    $anhang = (int)$db->query("SELECT id FROM afd_abschnitt WHERE rang = 0 AND nummer = 'Anhang'")->fetchColumn();
    $bedingung = '';
    $param = [$bool, $bool];
    if (!empty($a['innerhalb'])) {
        [$von, $bis] = afd_teilbaum(afd_abschnitt_finden((string)$a['innerhalb']));
        $bedingung = ' AND x.abschnitt_id BETWEEN ? AND ?';
        $param[] = $von;
        $param[] = $bis;
    } elseif ($bereich === 'gutachten' && $anhang) {
        $bedingung = ' AND x.abschnitt_id < ?';
        $param[] = $anhang;
    } elseif ($bereich === 'anhang' && $anhang) {
        $bedingung = ' AND x.abschnitt_id >= ?';
        $param[] = $anhang;
    }
    $treffer = [];
    $gesamt = 0;
    if ($bereich !== 'fussnoten') {
        $sql = "SELECT x.id, x.abschnitt_id, x.typ, x.seite, x.pdf, x.text, s.nummer, s.titel,
                       MATCH(x.text) AGAINST (? IN BOOLEAN MODE) AS rel
                FROM afd_absatz x JOIN afd_abschnitt s ON s.id = x.abschnitt_id
                WHERE MATCH(x.text) AGAINST (? IN BOOLEAN MODE) $bedingung
                ORDER BY rel DESC, x.id LIMIT 200";
        $r = $db->prepare($sql);
        $r->execute($param);
        $rows = $r->fetchAll();
        $gesamt += count($rows);
        foreach (array_slice($rows, 0, $anzahl) as $x) {
            $treffer[] = [
                'abschnitt' => $x['nummer'], 'titel' => $x['titel'],
                'seite' => $x['seite'] !== null ? 'S. ' . $x['seite'] : '',
                'art' => $x['typ'] === 'zitat' ? 'Belegzitat' : 'Text',
                'ausschnitt' => afd_ausschnitt($x['text'], $woerter),
                'pdf' => afd_pdf_link((int)$x['pdf']),
            ];
        }
    }
    if ($bereich === 'fussnoten' || ($bereich === 'alles' && count($treffer) < $anzahl)) {
        $r = $db->prepare('SELECT nr, seite, pdf, text, datenbank, MATCH(text) AGAINST (? IN BOOLEAN MODE) rel FROM afd_fussnote
                           WHERE MATCH(text) AGAINST (? IN BOOLEAN MODE) ORDER BY rel DESC, nr LIMIT 100');
        $r->execute([$bool, $bool]);
        $fn = $r->fetchAll();
        $gesamt += count($fn);
        foreach (array_slice($fn, 0, $anzahl - count($treffer)) as $f) {
            $treffer[] = array_filter([
                'fussnote' => (int)$f['nr'], 'seite' => 'S. ' . $f['seite'], 'art' => 'Fußnote',
                'ausschnitt' => afd_ausschnitt($f['text'], $woerter), 'beleg' => $f['datenbank'],
                'pdf' => afd_pdf_link((int)$f['pdf']),
            ]);
        }
    }
    $normen = in_array($bereich, ['alles', 'gutachten'], true) && empty($a['innerhalb']) ? afd_norm_volltext($bool) : [];
    $gff = in_array($bereich, ['alles', 'gutachten'], true) && empty($a['innerhalb']) ? afd_herausgeberin_volltext($bool) : [];
    return array_filter([
        'anfrage' => $anfrage,
        'treffer_gesamt' => $gesamt >= 200 ? 'mehr als 200' : $gesamt,
        'rechtsgrundlage' => $normen ?: null,
        'herausgeberin' => $gff ? ['hinweis' => AFD_GFF_HINWEIS, 'eintraege' => $gff] : null,
        'treffer' => $treffer,
        'hinweis' => $treffer ? 'Fundstellen mit abschnitt_lesen im Zusammenhang lesen; [^n] = Fußnote, mit fussnote nachschlagen.'
                              : 'Keine Treffer. Andere oder kürzere Wörter versuchen, Wortanfang mit * (z. B. Abschieb*).',
    ], static fn($v) => $v !== null);
}

/** Rechtsgrundlagen (Tabelle afd_norm), getrennt vom Gutachtentext. */
const AFD_NORM_HINWEIS = 'Gesetzestext (amtliches Werk), nicht Teil des Gutachtens – nicht mit Seitenzahl des Gutachtens zitieren.';

function afd_fragen_da(): bool
{
    static $da = null;
    return $da ??= (bool)afd_db()->query("SHOW TABLES LIKE 'afd_frage_kurz'")->fetchColumn();
}

function afd_normen_da(): bool
{
    static $da = null;
    return $da ??= (bool)afd_db()->query("SHOW TABLES LIKE 'afd_norm'")->fetchColumn();
}

function afd_w_norm(array $a): array
{
    if (!afd_normen_da()) {
        throw new AfdEingabe('Die Rechtsgrundlagen sind noch nicht geladen.');
    }
    $db = afd_db();
    if (!empty($a['absatz'])) {
        $r = $db->prepare('SELECT * FROM afd_norm WHERE absatz = ? ORDER BY id');
        $r->execute([(int)$a['absatz']]);
    } else {
        $r = $db->query('SELECT * FROM afd_norm ORDER BY id');
    }
    $rows = $r->fetchAll();
    if (!$rows) {
        throw new AfdEingabe('Diesen Absatz gibt es nicht (Art. 21 GG hat fünf Absätze).');
    }
    return [
        'gesetz' => $rows[0]['gesetz'],
        'artikel' => 'Art. ' . $rows[0]['artikel'] . ' ' . $rows[0]['kurz'],
        'wortlaut' => array_map(static fn($n) => ['absatz' => (int)$n['absatz'], 'bezeichnung' => $n['bezeichnung'], 'text' => $n['text']], $rows),
        'quelle' => $rows[0]['url'],
        'hinweis' => AFD_NORM_HINWEIS . ' Wie das Gutachten die Merkmale auslegt, steht in Teil 2 (Rechtliche Maßstäbe), z. B. Teil 2 A („Freiheitliche demokratische Grundordnung“), B („Ziele“ und „Verhalten ihrer Anhänger“), C („Beseitigen“ oder „Beeinträchtigen“), E („Daraufausgehen“).',
    ];
}

/** Normtreffer für eine Volltext-Anfrage (Boolescher Ausdruck). */
function afd_norm_volltext(string $bool): array
{
    if (!afd_normen_da()) {
        return [];
    }
    $r = afd_db()->prepare('SELECT bezeichnung, text, url FROM afd_norm WHERE MATCH(text) AGAINST (? IN BOOLEAN MODE) ORDER BY id');
    $r->execute([$bool]);
    return array_map(static fn($n) => ['rechtsgrundlage' => $n['bezeichnung'], 'art' => 'Gesetzestext', 'text' => $n['text'],
        'quelle' => $n['url'], 'hinweis' => AFD_NORM_HINWEIS], $r->fetchAll());
}

/** Angaben über die Herausgeberin (Tabelle afd_herausgeberin), getrennt vom Gutachtentext. */
const AFD_GFF_HINWEIS = 'Angaben über die Herausgeberin, nicht Teil des Gutachtentextes. Art und Quelle je Eintrag beachten: '
    . 'Angaben aus dem Gutachten mit Seite zitieren, Zusammenfassungen der GFF-Webseite als Selbstauskunft kennzeichnen, '
    . 'Wikipedia als solche (CC BY-SA 4.0). Keine eigene Wertung der GFF hinzufügen.';

function afd_herausgeberin_da(): bool
{
    static $da = null;
    return $da ??= (bool)afd_db()->query("SHOW TABLES LIKE 'afd_herausgeberin'")->fetchColumn();
}

function afd_gff_eintrag(array $e, ?float $naehe = null): array
{
    return array_filter(['titel' => $e['titel'], 'art' => $e['art'], 'naehe' => $naehe, 'text' => $e['text'],
        'quelle' => $e['quelle'], 'url' => $e['url'], 'lizenz' => $e['lizenz'], 'stand' => $e['stand']], static fn($v) => $v !== null);
}

function afd_w_herausgeberin(): array
{
    if (!afd_herausgeberin_da()) {
        throw new AfdEingabe('Die Angaben zur Herausgeberin sind noch nicht geladen.');
    }
    $rows = afd_db()->query('SELECT * FROM afd_herausgeberin ORDER BY id')->fetchAll();
    return [
        'herausgeberin' => 'Gesellschaft für Freiheitsrechte e.V. (GFF), Berlin',
        'eintraege' => array_map(static fn($e) => afd_gff_eintrag($e), $rows),
        'hinweis' => AFD_GFF_HINWEIS . ' Für eine unabhängige Einordnung der GFF weitere Quellen heranziehen.',
    ];
}

function afd_herausgeberin_volltext(string $bool): array
{
    if (!afd_herausgeberin_da()) {
        return [];
    }
    $r = afd_db()->prepare('SELECT * FROM afd_herausgeberin WHERE MATCH(titel, text) AGAINST (? IN BOOLEAN MODE) ORDER BY id LIMIT 3');
    $r->execute([$bool]);
    return array_map(static fn($e) => afd_gff_eintrag($e), $r->fetchAll());
}

/**
 * Suche nach Bedeutung. Den Vektor der Frage rechnet der Vektor-Arbeiter von plenarprotokolle.digital
 * (gleiches Modell, gleiche Dimension) über dessen Anfragekanal im selben Webspace.
 */
const AFD_GEWICHT_VOLLTEXT = 0.25;
/** Gewichte der Reciprocal Rank Fusion: Fragen-Vektoren (Frage–Frage), Originaltext (Frage–Text), Volltext. */
const AFD_GEWICHT_FRAGEN = 1.0;
const AFD_GEWICHT_TEXT   = 0.35;
const AFD_VEK_ANWEISUNG = "Instruct: Given a question about a German legal opinion on whether the AfD party is unconstitutional, retrieve passages of the opinion that address it\nQuery: ";

function afd_anfrage_vektor(string $frage): ?array
{
    // Prüfstand auf der Kommandozeile: vorab berechnete Vektoren einspeisen
    if (PHP_SAPI === 'cli' && isset($GLOBALS['AFD_TESTVEKTOR'][$frage])) {
        return $GLOBALS['AFD_TESTVEKTOR'][$frage];
    }
    // Eigener Einbettungsdienst (OpenAI-artige Schnittstelle, z. B. llama-server --embedding), falls eingetragen
    $url = (string)(afd_config()['einbettung_url'] ?? '');
    if ($url !== '') {
        return afd_vektor_von($url, AFD_VEK_ANWEISUNG . $frage);
    }
    // sonst über den Einbettungsweg von plenarprotokolle.digital auf demselben Server
    $lib = afd_home() . '/public_html/bundestag/lib/vektor_lib.php';
    if (!is_file($lib)) {
        return null;
    }
    require_once $lib;
    if (!function_exists('bt_vek_anfrage')) {
        return null;
    }
    try {
        $v = bt_vek_anfrage($frage, 8.0, AFD_VEK_ANWEISUNG);
    } catch (Throwable) {
        return null;
    }
    return (is_array($v) && count($v) === 384) ? $v : null;
}

/** Vektor über /v1/embeddings holen, auf 384 Dimensionen kürzen (Matryoshka) und normieren. */
function afd_vektor_von(string $url, string $text): ?array
{
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => json_encode(['input' => $text, 'model' => 'qwen3-embedding-0.6b']),
        'timeout'       => 8,
        'ignore_errors' => true,
    ]]);
    $roh = @file_get_contents(rtrim($url, '/') . '/embeddings', false, $ctx);
    $v = $roh === false ? null : (json_decode($roh, true)['data'][0]['embedding'] ?? null);
    if (!is_array($v) || count($v) < 384) {
        return null;
    }
    $v = array_slice(array_map('floatval', $v), 0, 384);
    $n = sqrt(array_sum(array_map(static fn($x) => $x * $x, $v)));
    return $n > 0 ? array_map(static fn($x) => $x / $n, $v) : null;
}

function afd_w_bedeutung(array $a): array
{
    $frage = trim((string)($a['frage'] ?? ''));
    if (mb_strlen($frage) < 3) {
        throw new AfdEingabe('Bitte eine Frage oder ein Thema angeben.');
    }
    $anzahl = max(1, min(30, (int)($a['anzahl'] ?? 10)));
    $bereich = (string)($a['bereich'] ?? 'alles');
    $db = afd_db();
    $q = afd_anfrage_vektor($frage);
    if ($q === null) {
        // Rückfall: Volltextsuche mit den Wörtern der Frage (jedes Wort optional)
        $woerter = array_filter(preg_split('/[^\p{L}\p{N}\-]+/u', $frage), static fn($w) => mb_strlen($w) >= 4);
        if (!$woerter) {
            throw new AfdEingabe('Die Bedeutungssuche ist gerade nicht erreichbar, und die Frage enthält keine Suchwörter. Bitte `suchen` mit Begriffen aus dem Gutachten verwenden.');
        }
        $erg = afd_w_suchen(['anfrage' => implode(' ', array_slice($woerter, 0, 6)), 'bereich' => $bereich === 'alles' ? 'alles' : $bereich,
                             'innerhalb' => $a['innerhalb'] ?? null, 'anzahl' => $anzahl, 'oder' => true]);
        $erg['hinweis'] = 'Die Suche nach Bedeutung ist gerade nicht erreichbar (der Rechner, der die Fragen einbettet, ist offline). '
            . 'Ersatzweise wurde im Volltext nach einzelnen Wörtern der Frage gesucht. ' . ($erg['hinweis'] ?? '');
        $erg['verfahren'] = 'volltext';
        return $erg;
    }
    $anhang = (int)$db->query("SELECT id FROM afd_abschnitt WHERE rang = 0 AND nummer = 'Anhang'")->fetchColumn();
    [$von, $bis] = !empty($a['innerhalb']) ? afd_teilbaum(afd_abschnitt_finden((string)$a['innerhalb'])) : [0, PHP_INT_MAX];
    $vtext = '[' . implode(',', array_map(static fn($x) => sprintf('%.6f', $x), $q)) . ']';
    // 1) Erzeugte Fragen je Absatz (mit derselben Anweisung eingebettet wie die Suchanfrage: Frage–Frage-Vergleich).
    //    Sie treffen Alltagsformulierungen deutlich besser als der Absatztext selbst (Messung 20.11.2026).
    //    Zweistufig und exakt: 400 Kandidaten über die auf 64 Dimensionen gekürzten Vektoren, dann genau mit 384 sortieren.
    //    (Der Vektorindex von MariaDB übersah bei diesen sehr ähnlichen Fragen gelegentlich den besten Treffer.)
    $fragen = [];
    $besteFrage = 0.0;
    if (afd_fragen_da()) {
        $k = array_slice($q, 0, 64);
        $n = sqrt(array_sum(array_map(static fn($x) => $x * $x, $k))) ?: 1.0;
        $ktext = '[' . implode(',', array_map(static fn($x) => sprintf('%.6f', $x / $n), $k)) . ']';
        $sk = $db->prepare('SELECT absatz_id, teil FROM (SELECT absatz_id, teil, VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) + 0 d
                            FROM afd_frage_kurz) x ORDER BY d LIMIT 400');
        $sk->execute([$ktext]);
        $kand = $sk->fetchAll(PDO::FETCH_NUM);
        if ($kand) {
            $in = implode(',', array_map(static fn($r) => '(' . (int)$r[0] . ',' . (int)$r[1] . ')', $kand));
            $sf = $db->prepare("SELECT absatz_id, teil, VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) d FROM afd_frage_vektor
                                WHERE (absatz_id, teil) IN ($in) ORDER BY d");
            $sf->execute([$vtext]);
            foreach ($sf->fetchAll() as $r) {
                $besteFrage = max($besteFrage, 1 - (float)$r['d']);
                $fragen[(int)$r['absatz_id']] ??= (int)$r['teil'];
            }
        }
    }
    // 2) Originaltext (Frage–Text)
    $st = $db->prepare('SELECT absatz_id, teil, start, laenge, VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) d FROM afd_vektor ORDER BY d LIMIT 300');
    $st->execute([$vtext]);
    $beste = [];
    foreach ($st->fetchAll() as $r) {
        $beste[(int)$r['absatz_id']] ??= $r;
    }
    if (!$beste && !$fragen) {
        return ['frage' => $frage, 'treffer' => [], 'hinweis' => 'Keine Vektoren vorhanden.'];
    }
    // 3) Reciprocal Rank Fusion aus Fragen, Originaltext und Volltext.
    //    Der Volltext lässt auch Abkürzungen und Fachbegriffe greifen (KI, fdGO, Art. 21).
    $rang = [];
    $i = 0;
    foreach (array_keys($fragen) as $id) {
        $rang[$id] = AFD_GEWICHT_FRAGEN / (60 + ++$i);
    }
    $i = 0;
    foreach (array_keys($beste) as $id) {
        $rang[$id] = ($rang[$id] ?? 0) + ($fragen ? AFD_GEWICHT_TEXT : 1.0) / (60 + ++$i);
    }
    $woerter = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $frage), static fn($w) => mb_strlen($w) >= 2
        && !in_array(mb_strtolower($w), ['der', 'die', 'das', 'und', 'oder', 'was', 'wie', 'wer', 'wird', 'will', 'eine', 'einen', 'ein', 'des', 'dem', 'den',
            'mit', 'von', 'für', 'auf', 'ist', 'sind', 'zur', 'zum', 'im', 'in', 'an', 'zu', 'es', 'sie', 'er', 'unter', 'über', 'nach', 'bei', 'welche', 'welcher',
            'welches', 'gutachten', 'gutachtens', 'afd', 'partei', 'wirklich', 'versteht', 'bedeutet', 'rolle', 'spielt'], true)));
    $kurz = array_filter($woerter, static fn($w) => mb_strlen($w) < 3);   // z. B. „KI“: unter der Mindestlänge des Volltextindex
    $lang = array_diff($woerter, $kurz);
    $volltext = [];
    if ($lang) {
        // Natural-Language-Modus: seltene Wörter zählen mehr als häufige (IDF), kein Wortanfang-Joker
        $vt = $db->prepare('SELECT id FROM afd_absatz WHERE MATCH(text) AGAINST (? IN NATURAL LANGUAGE MODE) LIMIT 60');
        $vt->execute([implode(' ', $lang)]);
        $volltext = array_map('intval', $vt->fetchAll(PDO::FETCH_COLUMN));
    }
    foreach ($kurz as $w) {
        $vt = $db->prepare('SELECT id FROM afd_absatz WHERE text REGEXP ? LIMIT 60');
        $vt->execute(['(^|[^[:alnum:]])' . preg_quote($w) . '([^[:alnum:]]|$)']);
        $volltext = array_merge($volltext, array_map('intval', $vt->fetchAll(PDO::FETCH_COLUMN)));
    }
    // Gewichtung Volltext: halb
    foreach (array_values(array_unique($volltext)) as $j => $id) {
        $rang[$id] = ($rang[$id] ?? 0) + AFD_GEWICHT_VOLLTEXT / (60 + $j + 1);
    }
    arsort($rang);
    $rang = array_slice($rang, 0, 120, true);
    // Ausschnitt und inhaltliche Nähe immer aus dem Originaltext: für Treffer, die nur über Fragen oder Volltext kamen,
    // den passenden Teil des Absatzes nachladen (bei Fragen den Teil, zu dem die Frage gehört).
    $fehlen = array_values(array_diff(array_keys($rang), array_keys($beste)));
    if ($fehlen) {
        $nl = $db->prepare('SELECT absatz_id, teil, start, laenge, VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) d FROM afd_vektor
                            WHERE absatz_id IN (' . implode(',', array_map('intval', $fehlen)) . ') ORDER BY d');
        $nl->execute([$vtext]);
        foreach ($nl->fetchAll() as $r) {
            $id = (int)$r['absatz_id'];
            if (isset($fragen[$id]) && intdiv($fragen[$id], 100) === (int)$r['teil']) {
                $beste[$id] = $r;          // Teil der passenden Frage hat Vorrang
            } else {
                $beste[$id] ??= $r;
            }
        }
    }
    foreach (array_keys($rang) as $id) {
        $beste[$id] ??= ['absatz_id' => $id, 'start' => 0, 'laenge' => 600, 'd' => null];
    }
    $beste = array_replace(array_fill_keys(array_keys($rang), null), array_intersect_key($beste, $rang));
    $in = implode(',', array_map('intval', array_keys($beste)));
    $rows = $db->query("SELECT x.id, x.abschnitt_id, x.typ, x.seite, x.pdf, x.text, s.nummer, s.titel
                        FROM afd_absatz x JOIN afd_abschnitt s ON s.id = x.abschnitt_id WHERE x.id IN ($in)")->fetchAll();
    $nachId = array_column($rows, null, 'id');
    $treffer = [];
    foreach ($beste as $id => $r) {
        $x = $nachId[$id] ?? null;
        if (!$x) {
            continue;
        }
        $ab = (int)$x['abschnitt_id'];
        if ($ab < $von || $ab > $bis || ($bereich === 'gutachten' && $anhang && $ab >= $anhang) || ($bereich === 'anhang' && $anhang && $ab < $anhang)) {
            continue;
        }
        $aus = mb_substr($x['text'], (int)$r['start'], (int)$r['laenge']);
        if (mb_strlen($aus) > 600) {
            $aus = mb_substr($aus, 0, 600) . '…';
        }
        $treffer[] = [
            'abschnitt' => $x['nummer'], 'titel' => $x['titel'],
            'seite' => $x['seite'] !== null ? 'S. ' . $x['seite'] : '',
            'art' => $x['typ'] === 'zitat' ? 'Belegzitat' : 'Text',
            'naehe' => $r['d'] === null ? null : round(1 - (float)$r['d'], 3),
            'ausschnitt' => $aus,
            'pdf' => afd_pdf_link((int)$x['pdf']),
        ];
        if (count($treffer) >= $anzahl) {
            break;
        }
    }
    // Rechtsgrundlage: Normen, die der Frage ähnlich oder näher als der beste Gutachtentreffer sind
    $normen = [];
    if (afd_normen_da() && $bereich !== 'anhang' && empty($a['innerhalb'])) {
        $bester = max(array_merge([0.0], array_map(static fn($t) => (float)($t['naehe'] ?? 0), $treffer))) ?: 1.0;
        $nv = $db->prepare('SELECT n.bezeichnung, n.text, n.url, VEC_DISTANCE_COSINE(v.vektor, VEC_FromText(?)) d
                            FROM afd_norm_vektor v JOIN afd_norm n ON n.id = v.norm_id ORDER BY d LIMIT 3');
        $nv->execute([$vtext]);
        foreach ($nv->fetchAll() as $n) {
            $naehe = round(1 - (float)$n['d'], 3);
            if ($naehe >= 0.7 || $naehe >= $bester) {
                $normen[] = ['rechtsgrundlage' => $n['bezeichnung'], 'art' => 'Gesetzestext', 'naehe' => $naehe, 'text' => $n['text'], 'quelle' => $n['url']];
            }
        }
    }
    // Herausgeberin: Einträge, die der Frage deutlich näher sind als der Durchschnitt (z. B. „Wer steckt hinter dem Gutachten?“)
    $gff = [];
    if (afd_herausgeberin_da() && $bereich !== 'anhang' && empty($a['innerhalb'])) {
        $bester ??= max(array_merge([0.0], array_map(static fn($t) => (float)($t['naehe'] ?? 0), $treffer))) ?: 1.0;
        // teil 0 = Text des Eintrags (Vergleich Frage–Text), teil ≥ 1 = typische Fragen, eingebettet wie Suchanfragen
        // (Vergleich Frage–Frage, deshalb höhere Schwelle)
        $hv = $db->prepare('SELECT h.*, v.teil, VEC_DISTANCE_COSINE(v.vektor, VEC_FromText(?)) d
                            FROM afd_herausgeberin_vektor v JOIN afd_herausgeberin h ON h.id = v.eintrag_id ORDER BY d LIMIT 12');
        $hv->execute([$vtext]);
        $gesehen = [];
        foreach ($hv->fetchAll() as $e) {
            $naehe = round(1 - (float)$e['d'], 3);
            // Frage–Frage: nur, wenn die GFF-Frage mindestens so gut passt wie die beste Frage zum Gutachten selbst
            $passt = (int)$e['teil'] === 0 ? ($naehe >= 0.75 || $naehe >= $bester) : ($naehe >= 0.85 && $naehe >= $besteFrage - 0.005);
            if (!$passt || isset($gesehen[$e['id']]) || count($gff) >= 2) {
                continue;
            }
            $gesehen[$e['id']] = true;
            $gff[] = afd_gff_eintrag($e, $naehe);
        }
    }
    return array_filter([
        'frage' => $frage, 'verfahren' => 'bedeutung',
        'rechtsgrundlage' => $normen ? ['hinweis' => AFD_NORM_HINWEIS, 'normen' => $normen] : null,
        'herausgeberin' => $gff ? ['hinweis' => AFD_GFF_HINWEIS, 'eintraege' => $gff] : null,
        'treffer' => $treffer,
        'hinweis' => 'Sortiert nach Bedeutung und Wortübereinstimmung zusammen (Hybridsuche, auch über vorab erzeugte typische Fragen je Absatz); naehe = inhaltliche Nähe des Absatztextes zur Frage (1 = sehr nah). Fundstellen mit abschnitt_lesen im Zusammenhang lesen; Nähe ist kein Beleg – Aussagen immer am Text prüfen.',
    ], static fn($v) => $v !== null);
}

function afd_w_abschnitt(array $a): array
{
    $s = afd_abschnitt_finden((string)($a['nummer'] ?? ''));
    $ab = max(1, (int)($a['ab_absatz'] ?? 1));
    $mitFn = (bool)($a['fussnoten'] ?? true);
    $db = afd_db();
    $r = $db->prepare('SELECT nr, typ, seite, pdf, text FROM afd_absatz WHERE abschnitt_id = ? AND nr >= ? ORDER BY nr');
    $r->execute([$s['id'], $ab]);
    $absaetze = [];
    $laenge = 0;
    $weiter = null;
    $refs = [];
    $letzteSeite = null;
    foreach ($r->fetchAll() as $x) {
        if ($laenge > 32000 && $absaetze) {
            $weiter = (int)$x['nr'];
            break;
        }
        $t = $x['text'];
        if ($x['seite'] !== null && $x['seite'] !== $letzteSeite) {
            $t = '[S. ' . $x['seite'] . '] ' . $t;
            $letzteSeite = $x['seite'];
        }
        if ($x['typ'] === 'zitat') {
            $t = '> ' . $t;
        }
        $absaetze[] = $t;
        $laenge += mb_strlen($t);
        preg_match_all('/\[\^(\d+)\]/', $x['text'], $m);
        array_push($refs, ...array_map('intval', $m[1]));
    }
    if ($s['titel_fn']) {
        array_push($refs, ...array_map('intval', explode(',', $s['titel_fn'])));
    }
    $u = $db->prepare('SELECT * FROM afd_abschnitt WHERE eltern = ? ORDER BY id');
    $u->execute([$s['id']]);
    $unter = array_map(static fn($k) => $k['nummer'] . ' – ' . $k['titel'] . ' (' . afd_seiten($k['seite_von'], $k['seite_bis']) . ')', $u->fetchAll());
    $fn = [];
    if ($mitFn && $refs) {
        $refs = array_values(array_unique($refs));
        $in = implode(',', array_fill(0, count($refs), '?'));
        $f = $db->prepare("SELECT nr, text, datenbank FROM afd_fussnote WHERE nr IN ($in) ORDER BY nr");
        $f->execute($refs);
        foreach ($f->fetchAll() as $row) {
            $fn[(string)$row['nr']] = $row['text'] . ($row['datenbank'] ? ' – Beleg: ' . $row['datenbank'] : '');
        }
    }
    $nachbar = static function (string $sql) use ($db, $s): ?string {
        $q = $db->prepare($sql);
        $q->execute([$s['id']]);
        $n = $q->fetch();
        return $n ? $n['nummer'] . ' – ' . $n['titel'] : null;
    };
    return array_filter([
        'nummer' => $s['nummer'], 'titel' => $s['titel'], 'pfad' => $s['pfad'],
        'seiten' => afd_seiten($s['seite_von'], $s['seite_bis']), 'pdf' => afd_pdf_link((int)$s['pdf_von']),
        'text' => $absaetze ? implode("\n\n", $absaetze) : ($ab > 1 ? '(keine weiteren Absätze)' : '(Dieser Abschnitt hat keinen eigenen Text, nur Unterabschnitte.)'),
        'weiter_ab_absatz' => $weiter,
        'unterabschnitte' => $unter,
        'fussnoten' => $fn ?: null,
        'vorheriger' => $nachbar('SELECT nummer, titel FROM afd_abschnitt WHERE id < ? ORDER BY id DESC LIMIT 1'),
        'naechster'  => $nachbar('SELECT nummer, titel FROM afd_abschnitt WHERE id > ? ORDER BY id LIMIT 1'),
    ], static fn($v) => $v !== null && $v !== [] && $v !== '');
}

function afd_w_seite(array $a): array
{
    $seite = (int)($a['seite'] ?? 0);
    if ($seite < 1 || $seite > 3060) {
        throw new AfdEingabe('Seitenzahl zwischen 1 und 3060 angeben (Seitenzahl im Kopf der PDF-Seite).');
    }
    $db = afd_db();
    $pdf = $seite + 2;   // PDF-Seite 1–2: Titel und Impressum ohne Seitenzahl
    $h = $db->prepare('SELECT nummer, titel FROM afd_abschnitt WHERE pdf_von = ? ORDER BY id');
    $h->execute([$pdf]);
    $r = $db->prepare('SELECT x.typ, x.pdf, x.pdf_bis, x.text, s.nummer FROM afd_absatz x JOIN afd_abschnitt s ON s.id = x.abschnitt_id
                       WHERE x.pdf <= ? AND x.pdf_bis >= ? ORDER BY x.id');
    $r->execute([$pdf, $pdf]);
    $text = [];
    foreach ($r->fetchAll() as $x) {
        $vorher = (int)$x['pdf'] < $pdf ? '(Fortsetzung von S. ' . ((int)$x['pdf'] - 2) . ') ' : '';
        $nachher = (int)$x['pdf_bis'] > $pdf ? ' (weiter auf S. ' . ($seite + 1) . ')' : '';
        $text[] = '[' . $x['nummer'] . '] ' . ($x['typ'] === 'zitat' ? '> ' : '') . $vorher . $x['text'] . $nachher;
    }
    $f = $db->prepare('SELECT nr, text, datenbank FROM afd_fussnote WHERE pdf = ? ORDER BY nr');
    $f->execute([$pdf]);
    $fn = [];
    foreach ($f->fetchAll() as $row) {
        $fn[(string)$row['nr']] = $row['text'] . ($row['datenbank'] ? ' – Beleg: ' . $row['datenbank'] : '');
    }
    return array_filter([
        'seite' => $seite, 'pdf' => afd_pdf_link($pdf),
        'ueberschriften' => array_map(static fn($x) => $x['nummer'] . ' – ' . $x['titel'], $h->fetchAll()),
        'text' => $text ? implode("\n\n", $text) : '(kein Fließtext auf dieser Seite)',
        'fussnoten' => $fn ?: null,
        'hinweis' => 'Absätze, die über die Seitengrenze laufen, erscheinen vollständig.',
    ]);
}

function afd_w_fussnote(array $a): array
{
    $nr = $a['nr'] ?? null;
    $liste = is_array($nr) ? $nr : [$nr];
    $liste = array_values(array_unique(array_filter(array_map('intval', $liste), static fn($n) => $n > 0)));
    if (!$liste) {
        throw new AfdEingabe('Fußnotennummer angeben (1 bis 7468).');
    }
    $liste = array_slice($liste, 0, 30);
    $db = afd_db();
    $in = implode(',', array_fill(0, count($liste), '?'));
    $r = $db->prepare("SELECT * FROM afd_fussnote WHERE nr IN ($in) ORDER BY nr");
    $r->execute($liste);
    $aus = [];
    $wo = $db->prepare('SELECT s.nummer, s.titel FROM afd_verweis v JOIN afd_abschnitt s ON s.id = v.abschnitt_id WHERE v.nr = ? LIMIT 1');
    foreach ($r->fetchAll() as $f) {
        $wo->execute([$f['nr']]);
        $s = $wo->fetch();
        $aus[] = array_filter([
            'nr' => (int)$f['nr'], 'seite' => 'S. ' . $f['seite'], 'text' => $f['text'],
            'beleg_datenbank' => $f['datenbank'], 'links' => $f['urls'] ? json_decode($f['urls'], true) : null,
            'abschnitt' => $s ? $s['nummer'] . ' – ' . $s['titel'] : null,
            'pdf' => afd_pdf_link((int)$f['pdf']),
        ]);
    }
    if (!$aus) {
        throw new AfdEingabe('Keine dieser Fußnoten gefunden (gültig: 1 bis 7468).');
    }
    return ['fussnoten' => $aus];
}

/* ChatGPT: search/fetch nach dessen Vorgaben (Ergebnis als JSON-Text). */
function afd_w_search(array $a): array
{
    $q = trim((string)($a['query'] ?? ''));
    [$bool] = afd_boolesch($q);
    $db = afd_db();
    $r = $db->prepare('SELECT s.id, s.nummer, s.titel, s.pdf_von, MAX(MATCH(x.text) AGAINST (? IN BOOLEAN MODE)) rel
                       FROM afd_absatz x JOIN afd_abschnitt s ON s.id = x.abschnitt_id
                       WHERE MATCH(x.text) AGAINST (? IN BOOLEAN MODE) GROUP BY s.id ORDER BY rel DESC LIMIT 20');
    $r->execute([$bool, $bool]);
    return ['results' => array_map(static fn($s) => [
        'id' => 'abschnitt-' . $s['id'], 'title' => $s['nummer'] . ' – ' . $s['titel'], 'url' => afd_pdf_link((int)$s['pdf_von']),
    ], $r->fetchAll())];
}

function afd_w_fetch(array $a): array
{
    $d = afd_w_abschnitt(['nummer' => (string)($a['id'] ?? ''), 'fussnoten' => true]);
    $text = $d['text'];
    if (!empty($d['fussnoten'])) {
        $text .= "\n\nFußnoten:\n" . implode("\n", array_map(static fn($k, $v) => "[^$k] $v", array_keys($d['fussnoten']), $d['fussnoten']));
    }
    return [
        'id' => 'abschnitt-' . afd_abschnitt_finden((string)$a['id'])['id'],
        'title' => $d['nummer'] . ' – ' . $d['titel'],
        'text' => $text . "\n\n" . AFD_QUELLE,
        'url' => $d['pdf'],
        'metadata' => ['seiten' => $d['seiten'] ?? '', 'pfad' => $d['pfad'] ?? ''],
    ];
}

/* ------------------------------------------------------------------ Grenzen und Protokoll */

function afd_grenzen(): void
{
    if (!function_exists('apcu_inc')) {
        return;
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $bereich = str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 3)) : implode('.', array_slice(explode('.', $ip), 0, 3));
    $min = intdiv(time(), 60);
    $std = intdiv(time(), 3600);
    $checks = [
        ["afd:g:$min", 120, AFD_JE_MINUTE],
        ['afd:c:' . md5($bereich) . ":$min", 120, AFD_CLIENT_MINUTE],
        ['afd:c:' . md5($bereich) . ":h$std", 7200, AFD_CLIENT_STUNDE],
    ];
    foreach ($checks as [$k, $ttl, $max]) {
        apcu_add($k, 0, $ttl);
        if (apcu_inc($k) > $max) {
            throw new AfdFehler('Zu viele Anfragen – bitte kurz warten.', -32000);
        }
    }
}

function afd_log(string $werkzeug, array $args, float $t0, ?string $fehler): void
{
    $dir = afd_home() . '/poc/afd/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $zeile = date('Y-m-d H:i:s') . "\t" . $werkzeug . "\t" . mb_substr(json_encode($args, JSON_UNESCAPED_UNICODE), 0, 300)
        . "\t" . (int)round((microtime(true) - $t0) * 1000) . 'ms' . ($fehler ? "\tFEHLER: " . mb_substr($fehler, 0, 120) : '') . "\n";
    @file_put_contents($dir . '/' . date('Y-m') . '.log', $zeile, FILE_APPEND | LOCK_EX);
    // Aufbewahrung: Monatsdateien nach spätestens drei Monaten löschen (Datenschutzhinweis der Projektseite).
    if (random_int(1, 200) === 1) {
        $grenze = date('Y-m', strtotime('first day of -2 months'));
        foreach (glob($dir . '/[0-9][0-9][0-9][0-9]-[0-9][0-9].log') ?: [] as $datei) {
            if (basename($datei, '.log') < $grenze) {
                @unlink($datei);
            }
        }
    }
}

/* ------------------------------------------------------------------ Infoseite (GET im Browser) */

function afd_infoseite(): never
{
    header('Content-Type: text/html; charset=utf-8');
    $url = 'https://poc.deutschkaemer.info/afd-gutachten/mcp';
    echo <<<HTML
<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>MCP-Schnittstelle – Rede mit dem AfD-Gutachten</title><meta name="robots" content="noindex">
<link rel="icon" href="/afd-gutachten/favicon.svg" type="image/svg+xml"><link rel="icon" href="/afd-gutachten/favicon-32.png" sizes="32x32" type="image/png"><link rel="apple-touch-icon" href="/afd-gutachten/apple-touch-icon.png">
<link rel="stylesheet" href="/style.css"></head><body><main class="rahmen" style="padding:48px 0">
<p class="kicker">MCP-Schnittstelle</p><h1>AfD-Gutachten der GFF</h1>
<p>Diese Adresse ist für Sprachmodelle gedacht (Model Context Protocol). In Claude oder ChatGPT als benutzerdefinierten Connector eintragen:</p>
<p><code>$url</code></p>
<p>Mehr dazu auf der <a href="/afd-gutachten/">Projektseite</a>.</p></main></body></html>
HTML;
    exit;
}
