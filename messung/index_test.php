<?php
// index_test.php – Vektorindex (genähert) gegen exakte Suche vergleichen. Aufruf: php index_test.php vektor.json
declare(strict_types=1);
require __DIR__ . '/db.php';
$v = json_decode((string)file_get_contents($argv[1]), true);
$vt = '[' . implode(',', array_map(static fn($x) => sprintf('%.6f', $x), $v)) . ']';
$db = afd_db('lesen');
$liste = static function (string $sql) use ($db, $vt): array {
    $t = microtime(true);
    $st = $db->prepare($sql);
    $st->execute([$vt]);
    $r = $st->fetchAll();
    return [round((microtime(true) - $t) * 1000), $r];
};
foreach (['20', '100', '400'] as $ef) {
    $db->exec("SET SESSION mhnsw_ef_search = $ef");
    [$ms, $r] = $liste('SELECT absatz_id, teil, VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) d FROM afd_frage_vektor ORDER BY d LIMIT 400');
    echo "Index ef=$ef ({$ms} ms): " . implode(' ', array_map(static fn($x) => $x['absatz_id'] . ':' . round(1 - $x['d'], 3), array_slice($r, 0, 5))) . "\n";
}
[$ms, $r] = $liste('SELECT absatz_id, teil, d FROM (SELECT absatz_id, teil, VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) + 0 d FROM afd_frage_vektor) x ORDER BY d LIMIT 400');
echo "Exakt ({$ms} ms): " . implode(' ', array_map(static fn($x) => $x['absatz_id'] . ':' . round(1 - $x['d'], 3), array_slice($r, 0, 5))) . "\n";
echo 'ef_search Vorgabe: ' . $db->query("SELECT @@GLOBAL.mhnsw_ef_search")->fetchColumn() . "\n";
