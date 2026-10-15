<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Legt in einer Contao-Testinstallation nur die Tabellen und Felder dieses
 * Bundles an.
 *
 * contao:migrate würde auch ausstehende Schemaänderungen anderer Bundles
 * einspielen, an denen womöglich parallel gearbeitet wird. Dieses Skript
 * fragt die ausstehenden Befehle mit --dry-run ab und führt nur die aus, die
 * „kinderschutz“ enthalten – je einzeln über dbal:run-sql.
 *
 * Vorsicht: Doctrine fasst Änderungen an einer Tabelle zu einem ALTER TABLE
 * zusammen. Hat ein anderes Bundle gleichzeitig ausstehende Felder in
 * tl_user, stünden sie im selben Befehl. Deshalb zuerst mit --nur-zeigen
 * aufrufen und die Befehle lesen.
 *
 * Aufruf: /c/xampp/php/php.exe pruefstand/schema-anlegen.php F:/Claude/contao-test [--nur-zeigen]
 *
 * Die Konsole wird über proc_open mit Argumentliste gestartet, nicht über
 * exec(): cmd.exe würde Prozentzeichen in den SQL-Befehlen verschlucken.
 */

$projekt = rtrim(str_replace('\\', '/', $argv[1] ?? ''), '/');
$nurZeigen = in_array('--nur-zeigen', $argv, true);

if ('' === $projekt || !is_file($projekt.'/vendor/bin/contao-console')) {
	fwrite(STDERR, "Aufruf: php schema-anlegen.php <contao-verzeichnis> [--nur-zeigen]\n");

	exit(1);
}

/**
 * Startet die Contao-Konsole der Testinstallation.
 *
 * @param string             $projekt   Verzeichnis der Contao-Installation
 * @param array<int, string> $argumente Befehl und Optionen
 *
 * @return array{0: int, 1: string, 2: string} Rückgabewert, Standardausgabe, Fehlerausgabe
 */
function konsole(string $projekt, array $argumente): array
{
	$prozess = proc_open(
		array_merge(array(PHP_BINARY, $projekt.'/vendor/bin/contao-console'), $argumente),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes,
		$projekt
	);

	$ausgabe = (string) stream_get_contents($pipes[1]);
	$fehler = (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return array(proc_close($prozess), $ausgabe, $fehler);
}

[$code, $ausgabe, $fehler] = konsole($projekt, array('contao:migrate', '--schema-only', '--dry-run', '--format=ndjson', '--no-interaction'));

if (0 !== $code) {
	fwrite(STDERR, "contao:migrate --dry-run ist fehlgeschlagen:\n$fehler$ausgabe");

	exit(1);
}

$befehle = array();

foreach (explode("\n", $ausgabe) as $zeile) {
	$daten = json_decode(trim($zeile), true);

	if (is_array($daten) && 'schema-pending' === ($daten['type'] ?? '')) {
		$befehle = $daten['commands'];
	}
}

$eigene = array_values(array_filter($befehle, static fn (string $befehl): bool => false !== stripos($befehl, 'kinderschutz')));
printf("%d ausstehende Schemabefehle, davon %d für dieses Bundle.\n", count($befehle), count($eigene));

foreach ($eigene as $befehl) {
	echo '  ', $befehl, "\n";

	if ($nurZeigen) {
		continue;
	}

	[$code, , $fehler] = konsole($projekt, array('dbal:run-sql', $befehl, '--no-interaction'));

	if (0 !== $code) {
		fwrite(STDERR, "Fehlgeschlagen:\n$fehler\n");

		exit(1);
	}
}

echo $nurZeigen ? "Nichts ausgeführt (--nur-zeigen).\n" : "Fertig.\n";
