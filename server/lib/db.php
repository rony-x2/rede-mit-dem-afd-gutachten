<?php
/**
 * Datenbankzugang für den PoC „AfD-Gutachten“.
 * Zugangsdaten liegen außerhalb des Webroots in ~/poc/config.php (Rolle 'lesen' oder 'admin').
 */
declare(strict_types=1);

function afd_home(): string
{
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = posix_getpwuid(posix_geteuid());
        if (!empty($pw['dir'])) {
            return rtrim($pw['dir'], '/');
        }
    }
    return rtrim((string)(getenv('HOME') ?: dirname(__DIR__, 3)), '/');
}

/** Einstellungen aus ~/poc/config.php (Vorlage: server/config.beispiel.php im Repository). */
function afd_config(): array
{
    static $c = null;
    return $c ??= require afd_home() . '/poc/config.php';
}

function afd_db(string $rolle = 'lesen'): PDO
{
    static $verb = [];
    if (isset($verb[$rolle])) {
        return $verb[$rolle];
    }
    $c = afd_config();
    $z = $c[$rolle] ?? throw new RuntimeException("Rolle $rolle fehlt in config.php");
    $pdo = new PDO(
        'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4',
        $z['user'], $z['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]
    );
    if ($rolle === 'lesen') {
        $pdo->exec('SET SESSION max_statement_time = 15');
    }
    return $verb[$rolle] = $pdo;
}
