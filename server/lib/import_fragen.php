<?php
/**
 * import_fragen.php – Vektoren der vorab erzeugten typischen Fragen je Textabschnitt laden (Tabelle afd_frage_vektor).
 *
 *   php ~/poc/afd/import_fragen.php fragen_vektoren.jsonl [--neu]
 *
 * Herkunft: anreichern.py (lokales Sprachmodell erzeugt 3–5 Fragen je Abschnitt) und fragen_datei.py (jede Frage
 * einzeln, mit derselben Anweisung wie Suchanfragen eingebettet, Qwen3-Embedding-0.6B, 384 Dimensionen).
 * Kennung: absatz_id + teil, wobei teil = Absatzteil * 100 + Nummer der Frage.
 * Ohne --neu werden vorhandene Zeilen behalten und nur neue ergänzt (z. B. später der Anhang).
 *
 * Zusätzlich afd_frage_kurz: dieselben Vektoren auf 64 Dimensionen gekürzt (Matryoshka) für eine exakte, schnelle
 * Vorauswahl. Der MariaDB-Vektorindex übersah bei diesen sehr ähnlichen Fragen den besten Treffer (4 von 46 Testfragen);
 * die zweistufige exakte Suche (64 Dimensionen vorauswählen, mit 384 genau sortieren) trifft wie die volle Suche.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/db.php';

$datei = $argv[1] ?? '';
if (!is_file($datei)) {
    fwrite(STDERR, "Datei fehlt: $datei\n");
    exit(1);
}
$db = afd_db('admin');
if (in_array('--neu', $argv, true)) {
    $db->exec('DROP TABLE IF EXISTS afd_frage_vektor');
    $db->exec('DROP TABLE IF EXISTS afd_frage_kurz');
}
$db->exec('CREATE TABLE IF NOT EXISTS afd_frage_vektor (
    absatz_id INT UNSIGNED      NOT NULL,
    teil      SMALLINT UNSIGNED NOT NULL COMMENT "Absatzteil * 100 + Nummer der Frage",
    vektor    VECTOR(384)       NOT NULL,
    PRIMARY KEY (absatz_id, teil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT="Typische Fragen je Abschnitt (Suchhilfe, nicht Teil des Gutachtens)"');
$db->exec('CREATE TABLE IF NOT EXISTS afd_frage_kurz (
    absatz_id INT UNSIGNED      NOT NULL,
    teil      SMALLINT UNSIGNED NOT NULL,
    vektor    VECTOR(64)        NOT NULL,
    PRIMARY KEY (absatz_id, teil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT="afd_frage_vektor auf 64 Dimensionen gekürzt (Vorauswahl)"');
$sk = $db->prepare('INSERT IGNORE INTO afd_frage_kurz (absatz_id, teil, vektor) VALUES (?, ?, VEC_FromText(?))');
$text = static fn(array $v): string => '[' . implode(',', array_map(static fn($x) => sprintf('%.7f', $x), $v)) . ']';

$st = $db->prepare('INSERT IGNORE INTO afd_frage_vektor (absatz_id, teil, vektor) VALUES (?, ?, VEC_FromText(?))');
$f = fopen($datei, 'r');
$n = $neu = 0;
$db->beginTransaction();
while (($z = fgets($f)) !== false) {
    $d = json_decode($z, true);
    $bin = base64_decode((string)($d['v'] ?? ''), true);
    if (!is_array($d) || $bin === false || strlen($bin) !== 4 * 384) {
        continue;
    }
    $v = array_values(unpack('g*', $bin));
    $st->execute([(int)$d['absatz_id'], (int)$d['teil'], $text($v)]);
    $neu += $st->rowCount();
    $k = array_slice($v, 0, 64);
    $norm = sqrt(array_sum(array_map(static fn($x) => $x * $x, $k))) ?: 1.0;
    $sk->execute([(int)$d['absatz_id'], (int)$d['teil'], $text(array_map(static fn($x) => $x / $norm, $k))]);
    if (++$n % 2000 === 0) {
        $db->commit();
        $db->beginTransaction();
        echo "$n gelesen, $neu neu\n";
    }
}
$db->commit();
echo "fertig: $n gelesen, $neu neu, gesamt " . $db->query('SELECT COUNT(*) FROM afd_frage_vektor')->fetchColumn() . "\n";
