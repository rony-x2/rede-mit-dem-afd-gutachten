<?php
/**
 * MCP-Schnittstelle zum GFF-Gutachten „Ist die AfD verfassungswidrig?“
 *   Connector-Adresse: https://poc.deutschkaemer.info/afd-gutachten/mcp
 * Programmcode und Zugangsdaten liegen außerhalb des Webroots (~/poc/afd, ~/poc/config.php).
 */
declare(strict_types=1);
$home = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '';
require ($home ?: (getenv('HOME') ?: dirname(__DIR__, 3))) . '/poc/afd/mcp_server.php';
afd_mcp_bedienen();
