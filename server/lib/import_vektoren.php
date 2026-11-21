<?php
/**
 * import_vektoren.php – Vektoren (aus einbetten.py) in poc_db laden.
 *
 *   php ~/poc/afd/import_vektoren.php ~/poc/afd/daten/vektoren.jsonl
 *
 * Legt afd_vektor neu an (VECTOR(384), Kosinus-Index wie rede_vektor bei plenarprotokolle.digital).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/db.php';
const DIM = 384;

$datei = $argv[1] ?? (__DIR__ . '/daten/vektoren.jsonl');
$db = afd_db('admin');
$db->exec('DROP TABLE IF EXISTS afd_vektor');
$db->exec('CREATE TABLE afd_vektor (
    absatz_id INT UNSIGNED      NOT NULL,
    teil      TINYINT UNSIGNED  NOT NULL,
    start     INT UNSIGNED      NOT NULL COMMENT "Zeichenversatz im Absatztext",
    laenge    INT UNSIGNED      NOT NULL,
    vektor    VECTOR(' . DIM . ') NOT NULL,
    PRIMARY KEY (absatz_id, teil),
    VECTOR INDEX ix_vektor (vektor) M=16 DISTANCE=cosine
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
  COMMENT="Qwen3-Embedding-0.6B, 384 Dimensionen, normiert (einbetten.py)"');

$st = $db->prepare('INSERT INTO afd_vektor (absatz_id, teil, start, laenge, vektor) VALUES (?, ?, ?, ?, VEC_FromText(?))');
$fh = fopen($datei, 'rb') ?: exit("Datei fehlt: $datei\n");
$n = 0;
$fehler = 0;
$db->beginTransaction();
while (($z = fgets($fh)) !== false) {
    $d = json_decode($z, true);
    $bin = base64_decode((string)($d['v'] ?? ''), true);
    if (!is_array($d) || $bin === false || strlen($bin) !== 4 * DIM) {
        $fehler++;
        continue;
    }
    $v = '[' . implode(',', array_map(static fn($x) => sprintf('%.7f', $x), array_values(unpack('g*', $bin)))) . ']';
    $st->execute([(int)$d['absatz_id'], (int)$d['teil'], (int)$d['start'], (int)$d['laenge'], $v]);
    if (++$n % 2000 === 0) {
        $db->commit();
        $db->beginTransaction();
        echo "$n\n";
    }
}
$db->commit();
$db->prepare('REPLACE INTO afd_meta (schluessel, wert) VALUES (?, ?)')->execute(['vektoren', "$n Abschnitte, Qwen3-Embedding-0.6B (384), " . date('Y-m-d H:i')]);
echo "Vektoren: $n, fehlerhaft: $fehler\n";
