<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Bootstrap für die Unit-Tests.
 *
 * Die Tests brauchen Doctrine DBAL (SQLite im Arbeitsspeicher) und
 * Symfony-Komponenten aus dem eigenen vendor/. Die Zeitzone wird fest auf
 * Berlin gesetzt, weil alle Datumsregeln mit Mitternacht Ortszeit rechnen
 * und die Erwartungswerte sonst von der php.ini abhingen.
 */

$autoload = __DIR__.'/../vendor/autoload.php';

if (!is_file($autoload)) {
	fwrite(STDERR, "vendor/autoload.php fehlt. Bitte zuerst im Bundle-Verzeichnis ausführen:\n/c/xampp/php/php.exe C:/Users/samso/bin/composer.phar update --no-security-blocking\n");

	exit(1);
}

date_default_timezone_set('Europe/Berlin');

require $autoload;
