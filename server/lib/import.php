<?php
/**
 * import.php – legt die Tabellen in poc_db neu an und lädt die Daten aus dem Parser.
 *
 *   php ~/poc/afd/import.php ~/poc/afd/daten
 *
 * Erwartet abschnitte.json, absaetze.json, fussnoten.json (aus parse.py).
 * Läuft nur auf der Kommandozeile, nutzt den Zugang mit Vollzugriff.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/db.php';

$dir = rtrim($argv[1] ?? (__DIR__ . '/daten'), '/');
$lade = static function (string $f) use ($dir): array {
    $j = json_decode((string)file_get_contents("$dir/$f"), true, 512, JSON_THROW_ON_ERROR);
    echo "$f: " . count($j) . "\n";
    return $j;
};
$abschnitte = $lade('abschnitte.json');
$absaetze   = $lade('absaetze.json');
$fussnoten  = $lade('fussnoten.json');

$db = afd_db('admin');
$schema = (string)file_get_contents(__DIR__ . '/schema.sql');
foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $schema)))) as $sql) {
    $db->exec($sql);
}
echo "Tabellen angelegt\n";

// Kennzahlen je Abschnitt
$anz = $zei = $kinder = [];
foreach ($absaetze as $a) {
    $id = (int)$a['abschnitt'];
    $anz[$id] = ($anz[$id] ?? 0) + 1;
    $zei[$id] = ($zei[$id] ?? 0) + mb_strlen($a['text']);
}
foreach ($abschnitte as $s) {
    if ($s['eltern']) {
        $kinder[(int)$s['eltern']] = ($kinder[(int)$s['eltern']] ?? 0) + 1;
    }
}

$db->beginTransaction();
$st = $db->prepare('INSERT INTO afd_abschnitt (id, eltern, rang, nummer, titel, pfad, seite_von, seite_bis, pdf_von, pdf_bis,
    absaetze, zeichen, unterabschnitte, titel_fn) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ($abschnitte as $s) {
    $id = (int)$s['id'];
    $st->execute([$id, $s['eltern'], $s['rang'], mb_substr($s['nummer'], 0, 160), mb_substr($s['titel'], 0, 600),
        implode(' › ', array_slice($s['pfad'], 0, -1)), $s['seite_von'], $s['seite_bis'], $s['pdf_von'], $s['pdf_bis'],
        $anz[$id] ?? 0, $zei[$id] ?? 0, $kinder[$id] ?? 0,
        empty($s['titel_fn']) ? null : implode(',', $s['titel_fn'])]);
}
$vw0 = $db->prepare('INSERT INTO afd_verweis (nr, absatz_id, abschnitt_id) VALUES (?, NULL, ?)');
foreach ($abschnitte as $s) {
    foreach ($s['titel_fn'] ?? [] as $n) {
        $vw0->execute([(int)$n, (int)$s['id']]);
    }
}
$db->commit();
echo "Abschnitte: " . count($abschnitte) . "\n";

$db->beginTransaction();
$st = $db->prepare('INSERT INTO afd_absatz (id, abschnitt_id, nr, typ, seite, seite_bis, pdf, pdf_bis, text) VALUES (?,?,?,?,?,?,?,?,?)');
$vw = $db->prepare('INSERT INTO afd_verweis (nr, absatz_id, abschnitt_id) VALUES (?,?,?)');
$i = 0;
foreach ($absaetze as $a) {
    $st->execute([++$i, $a['abschnitt'], $a['nr'], $a['typ'], $a['seite'], $a['seite_bis'], $a['pdf'], $a['pdf_bis'], $a['text']]);
    preg_match_all('/\[\^(\d+)\]/', $a['text'], $m);
    foreach (array_unique($m[1]) as $n) {
        $vw->execute([(int)$n, $i, $a['abschnitt']]);
    }
    if ($i % 3000 === 0) {
        $db->commit();
        $db->beginTransaction();
    }
}
$db->commit();
echo "Absätze: $i\n";

$db->beginTransaction();
$st = $db->prepare('INSERT INTO afd_fussnote (nr, seite, pdf, text, urls, datenbank) VALUES (?,?,?,?,?,?)');
foreach ($fussnoten as $f) {
    $st->execute([$f['nr'], $f['seite'], $f['pdf'], $f['text'],
        $f['urls'] ? json_encode($f['urls'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $f['datenbank']]);
}
$db->commit();
echo "Fußnoten: " . count($fussnoten) . "\n";

$meta = [
    'titel'       => 'Ist die Alternative für Deutschland verfassungswidrig? Gutachten zur Frage der Verfassungswidrigkeit der Partei „Alternative für Deutschland“ nach Artikel 21 Absatz 2 des Grundgesetzes',
    'herausgeber' => 'Gesellschaft für Freiheitsrechte e.V. (GFF)',
    'datum'       => '25. Juni 2026',
    'pdf_stand'   => '06.10.2026',
    'lizenz'      => 'CC BY 4.0',
    'lizenz_url'  => 'https://creativecommons.org/licenses/by/4.0/deed.de',
    'pdf_url'     => 'https://freiheitsrechte.org/uploads/publications/Demokratie/GFF_AfD-Gutachten.pdf',
    'online_url'  => 'https://afd-gutachten.de/',
    'gff_url'     => 'https://freiheitsrechte.org/themen/starke-grundrechte-fuer-eine-lebendige-demokratie/afd-gutachten',
    'datenbank_url' => 'https://fragdenstaat.de/afd-datenbank/',
    'importiert'  => date('Y-m-d H:i'),
];
$st = $db->prepare('INSERT INTO afd_meta (schluessel, wert) VALUES (?, ?)');
foreach ($meta as $k => $v) {
    $st->execute([$k, $v]);
}
echo "Fertig.\n";
