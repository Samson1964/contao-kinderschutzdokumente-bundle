<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Router für den eingebauten PHP-Webserver im Prüfstand.
 *
 * Vorhandene Dateien liefert der Server selbst aus, alles Übrige geht an
 * Contaos index.php – auch die Datei-Route des Bundles, deren Pfad keine
 * echte Datei ist.
 */

$wurzel = $_SERVER['DOCUMENT_ROOT'] ?? '';
$pfad = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ('' !== $wurzel && '/' !== $pfad && is_file($wurzel.$pfad)) {
	return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $wurzel.'/index.php';

require $wurzel.'/index.php';
