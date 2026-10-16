<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Legt in einer Contao-Testinstallation alles an, was der Prüfstand braucht.
 *
 * - Mitgliedergruppe „[Test] Kinderschutz“ mit den Mitgliedern ks-anna und
 *   ks-bernd (Passwort bei jedem Aufruf neu)
 * - Backend-Benutzer ks-pruefer (kein Administrator, Prüferfreigabe) und
 *   ks-admin (Administrator ohne Freigabe), Passwort ebenso neu
 * - Dokumentarten EFZ, Ehrenkodex, Schulungsnachweis (nur wenn noch keine da sind)
 * - Veranstaltungen „[Test] JWM“ (wählbar) und „[Test] Kaderlehrgang“
 *   (nicht wählbar, ks-bernd vom Prüfer zugeordnet)
 * - Seite „[Test] Kinderschutz“ (Alias kinderschutz-test) mit Anmeldemodul
 *   und „Meine Unterlagen“
 * - Einstellungen: Gruppe, Seite, Absender
 *
 * Die Zugangsdaten stehen danach in pruefstand/zugang-<kennung>.local.md,
 * das Git ignoriert – nie in Chat oder Commit. Wiederholbar: Vorhandenes
 * wird an Benutzernamen, Namen und Alias erkannt und aktualisiert.
 *
 * Contao 5.7 speichert Ja/Nein-Felder des Kerns (disable, admin, login,
 * published) als boolean, 4.13 als char(1). Der Standardwert von
 * tl_member.disable verrät, welche Fassung läuft.
 *
 * Aufruf: /c/xampp/php/php.exe pruefstand/testdaten.php F:/Claude/contao-test 57
 */

use Contao\Config;
use Contao\ManagerBundle\HttpKernel\ContaoKernel;
use Symfony\Component\Console\Input\ArgvInput;

$projekt = rtrim(str_replace('\\', '/', $argv[1] ?? ''), '/');
$kennung = preg_replace('/[^0-9a-z]/', '', (string) ($argv[2] ?? ''));

if ('' === $projekt || '' === $kennung || !is_file($projekt.'/vendor/autoload.php')) {
	fwrite(STDERR, "Aufruf: php testdaten.php <contao-verzeichnis> <kennung, etwa 57 oder 413>\n");

	exit(1);
}

require $projekt.'/vendor/autoload.php';

$kernel = ContaoKernel::fromInput($projekt, new ArgvInput(array('x', '--env=prod')));
$kernel->boot();
$container = $kernel->getContainer();
$db = $container->get('database_connection');
$container->get('contao.framework')->initialize();
$jetzt = time();

$standard = $db->fetchOne("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tl_member' AND COLUMN_NAME='disable'");
$nein = is_numeric($standard) ? 0 : '';
$ja = is_numeric($standard) ? 1 : '1';

/**
 * Legt eine Zeile an oder aktualisiert sie.
 *
 * @param object               $db      Die DBAL-Verbindung
 * @param string               $tabelle Tabelle
 * @param string               $spalte  Erkennungsspalte
 * @param string               $wert    Erkennungswert
 * @param array<string, mixed> $werte   Spalten
 *
 * @return int Die ID
 */
function ablegen(object $db, string $tabelle, string $spalte, string $wert, array $werte): int
{
	$id = $db->fetchOne('SELECT id FROM '.$tabelle.' WHERE '.$spalte.' = ?', array($wert));

	if (false === $id) {
		$db->insert($tabelle, $werte + array($spalte => $wert));

		return (int) $db->lastInsertId();
	}

	$db->update($tabelle, $werte, array('id' => $id));

	return (int) $id;
}

$zugang = array();

// Mitgliedergruppe und Mitglieder
$gruppe = ablegen($db, 'tl_member_group', 'name', '[Test] Kinderschutz', array('tstamp' => $jetzt));

foreach (array('ks-anna' => array('Anna', 'Adler'), 'ks-bernd' => array('Bernd', 'Bach')) as $name => list($vorname, $nachname)) {
	$passwort = bin2hex(random_bytes(8));
	ablegen($db, 'tl_member', 'username', $name, array(
		'tstamp'    => $jetzt,
		'dateAdded' => $jetzt,
		'firstname' => $vorname,
		'lastname'  => $nachname,
		'email'     => $name.'@example.org',
		'password'  => password_hash($passwort, PASSWORD_DEFAULT),
		'login'     => $ja,
		'disable'   => $nein,
		'groups'    => serialize(array((string) $gruppe)),
	));
	$zugang[] = "Mitglied $name / $passwort";
}

// Backend-Benutzer
foreach (array('ks-pruefer' => array('Prüferin Test', $nein, '1'), 'ks-admin' => array('Admin ohne Freigabe', $ja, '')) as $name => list($anzeige, $admin, $pruefer)) {
	$passwort = bin2hex(random_bytes(8));
	ablegen($db, 'tl_user', 'username', $name, array(
		'tstamp'              => $jetzt,
		'dateAdded'           => $jetzt,
		'name'                => $anzeige,
		'email'               => $name.'@example.org',
		'language'            => 'de',
		'password'            => password_hash($passwort, PASSWORD_DEFAULT),
		'admin'               => $admin,
		'disable'             => $nein,
		'pwChange'            => $nein,
		'kinderschutzPruefer' => $pruefer,
	));
	$zugang[] = "Backend $name / $passwort";
}

// Dokumentarten, nur beim ersten Mal
if (0 === (int) $db->fetchOne('SELECT COUNT(*) FROM tl_kinderschutz_dokumentart')) {
	$arten = array(
		array('Erweitertes Führungszeugnis', 'Nach § 30a BZRG, bei Vorlage nicht älter als drei Monate.', 24, '1', 1),
		array('Ehrenkodex', 'Unterschrieben.', 0, '', 2),
		array('Schulungsnachweis', 'Teilnahmebescheinigung der Kinderschutz-Schulung.', 0, '', 3),
	);

	foreach ($arten as list($artName, $hinweis, $monate, $efz, $reihenfolge)) {
		$db->insert('tl_kinderschutz_dokumentart', array(
			'tstamp'                 => $jetzt,
			'name'                   => $artName,
			'hinweis'                => $hinweis,
			'pflicht'                => '1',
			'gueltigkeit'            => $monate,
			'reihenfolge'            => $reihenfolge,
			'papierkorbNachPruefung' => $efz,
			'einschlaegigAbfragen'   => $efz,
			'published'              => '1',
		));
	}
}

// Veranstaltungen
$tag = static fn (int $tage): int => (int) strtotime(date('Y-m-d', $jetzt).' 00:00 '.sprintf('%+d', $tage).' days');
ablegen($db, 'tl_kinderschutz_veranstaltung', 'name', '[Test] JWM', array('tstamp' => $jetzt, 'ort' => 'Heraklion', 'startDate' => $tag(30), 'endDate' => $tag(40), 'waehlbar' => '1'));
$kader = ablegen($db, 'tl_kinderschutz_veranstaltung', 'name', '[Test] Kaderlehrgang', array('tstamp' => $jetzt, 'ort' => 'Berlin', 'startDate' => $tag(20), 'endDate' => $tag(22), 'waehlbar' => ''));
$bernd = (int) $db->fetchOne("SELECT id FROM tl_member WHERE username = 'ks-bernd'");

if (false === $db->fetchOne('SELECT id FROM tl_kinderschutz_teilnahme WHERE veranstaltung = ? AND member = ?', array($kader, $bernd))) {
	$db->insert('tl_kinderschutz_teilnahme', array('tstamp' => $jetzt, 'veranstaltung' => $kader, 'member' => $bernd, 'quelle' => 'backend', 'angelegtAm' => $jetzt));
}

// Seite mit Anmeldemodul und „Meine Unterlagen“
$wurzel = (int) $db->fetchOne("SELECT id FROM tl_page WHERE type = 'root' ORDER BY sorting LIMIT 1");
$theme = (int) $db->fetchOne('SELECT id FROM tl_theme ORDER BY id LIMIT 1');
$seite = ablegen($db, 'tl_page', 'alias', 'kinderschutz-test', array('tstamp' => $jetzt, 'pid' => $wurzel, 'sorting' => 99999, 'title' => '[Test] Kinderschutz', 'type' => 'regular', 'published' => $ja));
$artikel = ablegen($db, 'tl_article', 'alias', 'kinderschutz-test', array('tstamp' => $jetzt, 'pid' => $seite, 'sorting' => 128, 'title' => '[Test] Kinderschutz', 'inColumn' => 'main', 'published' => $ja));
$anmeldung = ablegen($db, 'tl_module', 'name', '[Test] Kinderschutz Anmeldung', array('tstamp' => $jetzt, 'pid' => $theme, 'type' => 'login'));
$unterlagen = ablegen($db, 'tl_module', 'name', '[Test] Kinderschutz Unterlagen', array('tstamp' => $jetzt, 'pid' => $theme, 'type' => 'kinderschutz_unterlagen'));

foreach (array(1 => $anmeldung, 2 => $unterlagen) as $reihenfolge => $modul) {
	if (false === $db->fetchOne("SELECT id FROM tl_content WHERE pid = ? AND ptable = 'tl_article' AND type = 'module' AND module = ?", array($artikel, $modul))) {
		$db->insert('tl_content', array('pid' => $artikel, 'ptable' => 'tl_article', 'sorting' => $reihenfolge * 128, 'tstamp' => $jetzt, 'type' => 'module', 'module' => $modul));
	}
}

// Einstellungen
Config::persist('kinderschutzGruppe', $gruppe);
Config::persist('kinderschutzSeite', $seite);
Config::persist('kinderschutzAbsender', 'kinderschutz@example.org');

file_put_contents(
	__DIR__.'/zugang-'.$kennung.'.local.md',
	"# Testzugang Kinderschutz (nicht versioniert)\n\nInstallation: $projekt\nSeite: /kinderschutz-test (Alias)\n\n- ".implode("\n- ", $zugang)."\n"
);

printf("Gruppe %d, Seite %d, Module %d/%d angelegt. Zugang: pruefstand/zugang-%s.local.md\n", $gruppe, $seite, $anmeldung, $unterlagen, $kennung);
