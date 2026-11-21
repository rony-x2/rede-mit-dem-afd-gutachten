<?php
/**
 * import_herausgeberin.php – Angaben über die Herausgeberin (GFF) und die Entstehung des Gutachtens
 * in poc_db laden, getrennt vom Gutachtentext.
 *
 *   php ~/poc/afd/import_herausgeberin.php [herausgeberin.json] [herausgeberin_vektoren.jsonl]
 *
 * Einträge aus dem Gutachten (CC BY 4.0), eigene Zusammenfassungen der Selbstdarstellung auf
 * freiheitsrechte.org (Originaltexte © GFF, nicht übernommen) und des Wikipedia-Artikels (CC BY-SA 4.0).
 * Vektoren optional (einbetten.py --ein herausgeberin_einbettung.jsonl), Feld absatz_id = id des Eintrags,
 * teil 0 = Text, ab teil 1 je eine typische Frage, eingebettet mit derselben Anweisung wie Suchanfragen
 * (Vergleich Frage–Frage; hebt die Treffer bei kurzen Fragen wie „Wer hat das bezahlt?“).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/db.php';

$eintraege = json_decode((string)file_get_contents($argv[1] ?? __DIR__ . '/herausgeberin.json'), true, 16, JSON_THROW_ON_ERROR);
$db = afd_db('admin');
$db->exec('DROP TABLE IF EXISTS afd_herausgeberin_vektor');
$db->exec('DROP TABLE IF EXISTS afd_herausgeberin');
$db->exec('CREATE TABLE afd_herausgeberin (
    id      SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    titel   VARCHAR(200) NOT NULL,
    art     VARCHAR(100) NOT NULL,
    quelle  VARCHAR(300) NOT NULL,
    url     VARCHAR(300) NOT NULL,
    lizenz  VARCHAR(200) NOT NULL,
    stand   VARCHAR(100) NOT NULL,
    text    TEXT         NOT NULL,
    FULLTEXT KEY ft_text (titel, text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT="Angaben über die GFF, nicht Teil des Gutachtentextes"');
$db->exec('CREATE TABLE afd_herausgeberin_vektor (
    eintrag_id SMALLINT UNSIGNED NOT NULL,
    teil       TINYINT UNSIGNED NOT NULL COMMENT "0 = Text, ab 1 = typische Fragen (mit Anweisung wie Suchanfragen eingebettet)",
    vektor     VECTOR(384) NOT NULL,
    PRIMARY KEY (eintrag_id, teil),
    VECTOR INDEX ix_vektor (vektor) M=16 DISTANCE=cosine
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci');
$st = $db->prepare('INSERT INTO afd_herausgeberin (id, titel, art, quelle, url, lizenz, stand, text) VALUES (?,?,?,?,?,?,?,?)');
foreach ($eintraege as $e) {
    $st->execute([$e['id'], $e['titel'], $e['art'], $e['quelle'], $e['url'], $e['lizenz'], $e['stand'], $e['text']]);
}
echo 'Einträge: ' . count($eintraege) . "\n";

$vdatei = $argv[2] ?? __DIR__ . '/herausgeberin_vektoren.jsonl';
if (is_file($vdatei)) {
    $sv = $db->prepare('INSERT INTO afd_herausgeberin_vektor (eintrag_id, teil, vektor) VALUES (?, ?, VEC_FromText(?))');
    $k = 0;
    foreach (file($vdatei) as $z) {
        $d = json_decode($z, true);
        $bin = base64_decode((string)($d['v'] ?? ''), true);
        if ($bin === false || strlen($bin) !== 4 * 384) {
            continue;
        }
        $sv->execute([(int)$d['absatz_id'], (int)($d['teil'] ?? 0), '[' . implode(',', array_map(static fn($x) => sprintf('%.7f', $x), array_values(unpack('g*', $bin)))) . ']']);
        $k++;
    }
    echo "Vektoren: $k\n";
}
