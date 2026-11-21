<?php
// exakt_zeit.php – Laufzeit der exakten Suche über afd_frage_vektor für die 46 Testvektoren (ohne Abfrage-Cache)
declare(strict_types=1);
require __DIR__ . '/db.php';
$db = afd_db('lesen');
$ms = [];
foreach (json_decode((string)file_get_contents(__DIR__ . '/vektoren_test.json'), true) as $v) {
    $vt = '[' . implode(',', array_map(static fn($x) => sprintf('%.6f', $x), $v)) . ']';
    $t = microtime(true);
    $st = $db->prepare('SELECT SQL_NO_CACHE absatz_id, teil, d FROM (SELECT absatz_id, teil, VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) + 0 d FROM afd_frage_vektor) x ORDER BY d LIMIT 400');
    $st->execute([$vt]);
    $st->fetchAll();
    $ms[] = (microtime(true) - $t) * 1000;
}
sort($ms);
printf("exakt über %d Zeilen: Median %.0f ms, max %.0f ms\n", $db->query('SELECT COUNT(*) FROM afd_frage_vektor')->fetchColumn(), $ms[intdiv(count($ms), 2)], end($ms));
