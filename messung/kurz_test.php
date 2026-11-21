<?php
// kurz_test.php – exakte Vorauswahl über 128 Dimensionen (Matryoshka), dann genaue Sortierung mit 384: Zeit und Trefferquote
declare(strict_types=1);
require __DIR__ . '/db.php';
$db = afd_db('admin');
$dim = (int)($argv[1] ?? 128);
$db->exec('DROP TABLE IF EXISTS afd_frage_kurz');
$db->exec("CREATE TABLE afd_frage_kurz (absatz_id INT UNSIGNED NOT NULL, teil SMALLINT UNSIGNED NOT NULL, vektor VECTOR($dim) NOT NULL,
  PRIMARY KEY (absatz_id, teil)) ENGINE=InnoDB");
$t = microtime(true);
$ins = $db->prepare('INSERT INTO afd_frage_kurz VALUES (?, ?, VEC_FromText(?))');
$db->beginTransaction();
foreach ($db->query('SELECT absatz_id, teil, VEC_ToText(vektor) v FROM afd_frage_vektor', PDO::FETCH_ASSOC) as $r) {
    $v = array_slice(json_decode($r['v'], true), 0, $dim);
    $n = sqrt(array_sum(array_map(static fn($x) => $x * $x, $v))) ?: 1;
    $ins->execute([$r['absatz_id'], $r['teil'], '[' . implode(',', array_map(static fn($x) => sprintf('%.6f', $x / $n), $v)) . ']']);
}
$db->commit();
echo "Kurzfassung $dim Dim. angelegt in " . round(microtime(true) - $t, 1) . " s\n";
$ms = []; $gleich1 = 0; $ueber10 = 0;
foreach (json_decode((string)file_get_contents(__DIR__ . '/vektoren_test.json'), true) as $v) {
    $vt = '[' . implode(',', array_map(static fn($x) => sprintf('%.6f', $x), $v)) . ']';
    $k = array_slice($v, 0, $dim); $n = sqrt(array_sum(array_map(static fn($x) => $x * $x, $k)));
    $kt = '[' . implode(',', array_map(static fn($x) => sprintf('%.6f', $x / $n), $k)) . ']';
    $t = microtime(true);
    $st = $db->prepare('SELECT SQL_NO_CACHE absatz_id, teil FROM (SELECT absatz_id, teil, VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) + 0 d FROM afd_frage_kurz) x ORDER BY d LIMIT 400');
    $st->execute([$kt]);
    $kand = $st->fetchAll(PDO::FETCH_NUM);
    $in = implode(',', array_map(static fn($r) => '(' . (int)$r[0] . ',' . (int)$r[1] . ')', $kand));
    $st = $db->prepare("SELECT SQL_NO_CACHE absatz_id, teil, VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) d FROM afd_frage_vektor WHERE (absatz_id, teil) IN ($in) ORDER BY d LIMIT 10");
    $st->execute([$vt]);
    $zwei = $st->fetchAll(PDO::FETCH_NUM);
    $ms[] = (microtime(true) - $t) * 1000;
    $st = $db->prepare('SELECT SQL_NO_CACHE absatz_id, teil FROM (SELECT absatz_id, teil, VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) + 0 d FROM afd_frage_vektor) x ORDER BY d LIMIT 10');
    $st->execute([$vt]);
    $ex = $st->fetchAll(PDO::FETCH_NUM);
    $gleich1 += ($ex[0][0] == $zwei[0][0] && $ex[0][1] == $zwei[0][1]) ? 1 : 0;
    $ueber10 += count(array_intersect(array_map(static fn($r) => "$r[0]/$r[1]", $ex), array_map(static fn($r) => "$r[0]/$r[1]", $zwei)));
}
sort($ms);
printf("zweistufig: bester Treffer gleich %d/%d, Top-10 gleich %.0f %%, Median %.0f ms, max %.0f ms\n", $gleich1, count($ms), $ueber10 / (10 * count($ms)) * 100, $ms[intdiv(count($ms), 2)], end($ms));
