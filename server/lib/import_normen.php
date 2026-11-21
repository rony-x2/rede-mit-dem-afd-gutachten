<?php
/**
 * import_normen.php – Rechtsgrundlagen (Wortlaut von Normen) in poc_db laden, getrennt vom Gutachtentext.
 *
 *   php ~/poc/afd/import_normen.php [normen.json] [norm_vektoren.jsonl]
 *
 * Normen sind amtliche Werke (§ 5 UrhG). Quelle: gesetze-im-internet.de.
 * Vektoren optional (aus einbetten.py mit --ein norm_einbettung.jsonl), Feld absatz_id = id der Norm.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/db.php';

$normen = json_decode((string)file_get_contents($argv[1] ?? __DIR__ . '/normen.json'), true, 16, JSON_THROW_ON_ERROR);
$db = afd_db('admin');
$db->exec('DROP TABLE IF EXISTS afd_norm_vektor');
$db->exec('DROP TABLE IF EXISTS afd_norm');
$db->exec('CREATE TABLE afd_norm (
    id          SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    gesetz      VARCHAR(200) NOT NULL,
    kurz        VARCHAR(20)  NOT NULL,
    artikel     VARCHAR(20)  NOT NULL,
    absatz      TINYINT UNSIGNED NULL,
    bezeichnung VARCHAR(60)  NOT NULL,
    text        TEXT         NOT NULL,
    url         VARCHAR(300) NOT NULL,
    FULLTEXT KEY ft_text (text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT="Rechtsgrundlagen, nicht Teil des Gutachtens"');
$db->exec('CREATE TABLE afd_norm_vektor (
    norm_id SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    vektor  VECTOR(384) NOT NULL,
    VECTOR INDEX ix_vektor (vektor) M=16 DISTANCE=cosine
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci');
$st = $db->prepare('INSERT INTO afd_norm (id, gesetz, kurz, artikel, absatz, bezeichnung, text, url) VALUES (?,?,?,?,?,?,?,?)');
foreach ($normen as $n) {
    $st->execute([$n['id'], $n['gesetz'], $n['kurz'], $n['artikel'], $n['absatz'], $n['bezeichnung'], $n['text'], $n['url']]);
}
echo 'Normen: ' . count($normen) . "\n";

$vdatei = $argv[2] ?? __DIR__ . '/daten/norm_vektoren.jsonl';
if (is_file($vdatei)) {
    $sv = $db->prepare('INSERT INTO afd_norm_vektor (norm_id, vektor) VALUES (?, VEC_FromText(?))');
    $k = 0;
    foreach (file($vdatei) as $z) {
        $d = json_decode($z, true);
        $bin = base64_decode((string)($d['v'] ?? ''), true);
        if ($bin === false || strlen($bin) !== 4 * 384) {
            continue;
        }
        $sv->execute([(int)$d['absatz_id'], '[' . implode(',', array_map(static fn($x) => sprintf('%.7f', $x), array_values(unpack('g*', $bin)))) . ']']);
        $k++;
    }
    echo "Norm-Vektoren: $k\n";
}
