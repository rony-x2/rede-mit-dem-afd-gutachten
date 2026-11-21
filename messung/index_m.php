<?php
// index_m.php M – Testkopie afd_frage_test mit VECTOR INDEX M=…, Trefferquote gegen exakte Suche für Testfragen (vektoren.json)
declare(strict_types=1);
require __DIR__ . '/db.php';
$m = (int)($argv[1] ?? 48);
$db = afd_db('admin');
$t = microtime(true);
$db->exec('DROP TABLE IF EXISTS afd_frage_test');
$db->exec("CREATE TABLE afd_frage_test (absatz_id INT UNSIGNED NOT NULL, teil SMALLINT UNSIGNED NOT NULL, vektor VECTOR(384) NOT NULL,
  PRIMARY KEY (absatz_id, teil), VECTOR INDEX ix (vektor) M=$m DISTANCE=cosine) ENGINE=InnoDB");
$db->exec('INSERT INTO afd_frage_test SELECT absatz_id, teil, vektor FROM afd_frage_vektor');
echo "M=$m aufgebaut in " . round(microtime(true) - $t, 1) . " s\n";
$fragen = json_decode((string)file_get_contents(__DIR__ . '/vektoren_test.json'), true);
$top1 = $top10 = 0; $ms = 0;
foreach ($fragen as $v) {
    $vt = '[' . implode(',', array_map(static fn($x) => sprintf('%.6f', $x), $v)) . ']';
    $t = microtime(true);
    $st = $db->prepare('SELECT absatz_id, teil FROM afd_frage_test ORDER BY VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) LIMIT 400');
    $st->execute([$vt]); $idx = $st->fetchAll(PDO::FETCH_NUM);
    $ms += (microtime(true) - $t) * 1000;
    $st = $db->prepare('SELECT absatz_id, teil FROM (SELECT absatz_id, teil, VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) + 0 d FROM afd_frage_test) x ORDER BY d LIMIT 10');
    $st->execute([$vt]); $ex = $st->fetchAll(PDO::FETCH_NUM);
    $ix = array_map(static fn($r) => $r[0] . '/' . $r[1], $idx);
    $top1 += in_array($ex[0][0] . '/' . $ex[0][1], array_slice($ix, 0, 10), true) ? 1 : 0;
    $top10 += count(array_intersect(array_map(static fn($r) => $r[0] . '/' . $r[1], $ex), array_slice($ix, 0, 10)));
}
$n = count($fragen);
printf("bester exakter Treffer unter den Index-Top-10: %d/%d · Überschneidung Top-10: %.0f %% · Index Ø %.0f ms\n", $top1, $n, $top10 / (10 * $n) * 100, $ms / $n);
