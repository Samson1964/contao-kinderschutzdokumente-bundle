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
 * tl_user, stünden sie im selben Befehl; und contao:migrate --dry-run
 * liefert in 5.7 auch DROP-Anteile. Ein „ALTER TABLE tl_user ADD
 * kinderschutzPruefer …, DROP <fremde Spalte>“ enthielte „kinderschutz“ und
 * löschte eine fremde Spalte. Deshalb prüft das Skript alle eigenen Befehle,
 * bevor es den ersten ausführt, und bricht ohne jede Ausführung ab, wenn
 * einer davon gesperrt ist (siehe sperrgrund()). Mit --nur-zeigen listet es
 * die Befehle samt Sperrgrund und führt nichts aus; der Rückgabewert ist
 * auch dann 1, wenn etwas gesperrt ist.
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

/**
 * Prüft einen eigenen Schemabefehl, bevor irgendetwas ausgeführt wird.
 *
 * Erlaubt ist alles, was nur Tabellen dieses Bundles (tl_kinderschutz*)
 * anlegt oder ändert, und in einer fremden Tabelle genau eine Änderung an
 * der Bundle-Spalte kinderschutzPruefer (ADD, MODIFY oder CHANGE ohne
 * Umbenennung). „Genau eine“ heißt: kein Komma im Befehl, denn MySQL
 * trennt mehrere Änderungen eines ALTER TABLE mit Kommas. Gesperrt ist
 * jedes DROP – auch in eigenen Tabellen –, alles, was eine fremde Tabelle
 * darüber hinaus ändert, und alles, dessen Tabelle sich nicht sicher
 * erkennen lässt.
 *
 * Die Prüfung ist absichtlich grob und streng: Lieber bricht sie bei einem
 * harmlosen Befehl ab (etwa einem Komma in einem Spaltenkommentar oder dem
 * Wort „drop“ in einem Vorgabewert), als einen gemeinsamen Prüfstand zu
 * beschädigen. Dann bleibt der Weg, den Befehl von Hand zu prüfen und
 * einzeln per dbal:run-sql auszuführen.
 *
 * @param string $befehl Ein SQL-Befehl aus contao:migrate --dry-run
 *
 * @return string|null Der Sperrgrund, oder null, wenn der Befehl erlaubt ist
 */
function sperrgrund(string $befehl): ?string
{
	if (preg_match('/\bDROP\b/i', $befehl)) {
		return 'enthält DROP';
	}

	if (!preg_match('/^\s*(?:CREATE\s+TABLE|ALTER\s+TABLE|CREATE\s+(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?INDEX\s+`?\w+`?\s+ON)\s+`?(\w+)`?/i', $befehl, $treffer)) {
		return 'Tabelle nicht erkennbar';
	}

	if (preg_match('/^tl_kinderschutz/i', $treffer[1])) {
		return null;
	}

	$spalte = '`?kinderschutzPruefer`?';
	$aenderung = '(?:ADD(?:\s+COLUMN)?\s+'.$spalte.'|MODIFY(?:\s+COLUMN)?\s+'.$spalte.'|CHANGE(?:\s+COLUMN)?\s+'.$spalte.'\s+'.$spalte.')';

	if (preg_match('/^\s*ALTER\s+TABLE\s+`?\w+`?\s+'.$aenderung.'\s+[^,;]+$/i', $befehl)) {
		return null;
	}

	return sprintf('ändert in der fremden Tabelle %s mehr als die Spalte kinderschutzPruefer', $treffer[1]);
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

// Erst alle eigenen Befehle prüfen, dann ausführen: Ein gesperrter Befehl
// an dritter Stelle darf nicht erst auffallen, wenn zwei schon gelaufen sind
$gesperrt = 0;

foreach ($eigene as $befehl) {
	$grund = sperrgrund($befehl);
	echo null === $grund ? '  ' : '  GESPERRT ('.$grund.'): ', $befehl, "\n";
	$gesperrt += null === $grund ? 0 : 1;
}

if ($gesperrt > 0) {
	fwrite(STDERR, sprintf(
		"Abbruch, nichts ausgeführt: %d der eigenen Befehle %s gesperrt (siehe oben).\n"
		."Doctrine fasst die Änderungen einer Tabelle zu einem Befehl zusammen; ein DROP oder\n"
		."eine fremde Spalte im selben Befehl träfe Daten anderer Bundles im gemeinsamen Prüfstand.\n"
		."Bitte die Befehle von Hand prüfen und nur den Anteil dieses Bundles einzeln per\n"
		."dbal:run-sql ausführen.\n",
		$gesperrt,
		1 === $gesperrt ? 'ist' : 'sind'
	));

	exit(1);
}

if ($nurZeigen) {
	echo "Nichts ausgeführt (--nur-zeigen).\n";

	exit(0);
}

foreach ($eigene as $befehl) {
	[$code, , $fehler] = konsole($projekt, array('dbal:run-sql', $befehl, '--no-interaction'));

	if (0 !== $code) {
		fwrite(STDERR, "Fehlgeschlagen:\n  $befehl\n$fehler\n");

		exit(1);
	}
}

echo "Fertig.\n";
