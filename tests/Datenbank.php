<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Eine SQLite-Datenbank im Arbeitsspeicher mit den Tabellen des Bundles und
 * den benötigten Spalten aus tl_member und tl_user.
 *
 * Die Spalten der eigenen Tabellen entsprechen den DCA-Dateien
 * (DatenbankSchemaTest prüft das), die Typen sind vereinfacht. Deshalb
 * schreibt das Bundle nur SQL, das MySQL und SQLite gleichermaßen verstehen.
 */
final class Datenbank
{
	private const SCHEMA = array(
		"CREATE TABLE tl_member (id INTEGER PRIMARY KEY AUTOINCREMENT, firstname TEXT NOT NULL DEFAULT '', lastname TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', groups BLOB NULL, disable TEXT NOT NULL DEFAULT '', start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')",
		"CREATE TABLE tl_user (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', admin TEXT NOT NULL DEFAULT '', disable TEXT NOT NULL DEFAULT '', kinderschutzPruefer TEXT NOT NULL DEFAULT '')",
		"CREATE TABLE tl_kinderschutz_dokumentart (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL DEFAULT '', hinweis TEXT NULL, pflicht TEXT NOT NULL DEFAULT '', gueltigkeit INTEGER NOT NULL DEFAULT 0, reihenfolge INTEGER NOT NULL DEFAULT 0, papierkorbNachPruefung TEXT NOT NULL DEFAULT '', einschlaegigAbfragen TEXT NOT NULL DEFAULT '', published TEXT NOT NULL DEFAULT '')",
		"CREATE TABLE tl_kinderschutz (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, member INTEGER NOT NULL DEFAULT 0, dokumentart INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT '', ausstellungsdatum INTEGER NULL, einschlaegig TEXT NOT NULL DEFAULT '', ablehnungsgrund TEXT NULL, bemerkung TEXT NULL, quelle TEXT NOT NULL DEFAULT '', verarbeitet TEXT NOT NULL DEFAULT '', datei TEXT NOT NULL DEFAULT '', dateiname TEXT NOT NULL DEFAULT '', dateityp TEXT NOT NULL DEFAULT '', dateigroesse INTEGER NOT NULL DEFAULT 0, hochgeladenAm INTEGER NOT NULL DEFAULT 0, geprueftAm INTEGER NOT NULL DEFAULT 0, geprueftVon INTEGER NOT NULL DEFAULT 0, gueltigBis INTEGER NULL, papierkorbSeit INTEGER NOT NULL DEFAULT 0, dateiGeloeschtAm INTEGER NOT NULL DEFAULT 0, erinnertVorAblauf INTEGER NOT NULL DEFAULT 0, erinnertAblauf INTEGER NOT NULL DEFAULT 0)",
		"CREATE TABLE tl_kinderschutz_veranstaltung (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL DEFAULT '', ort TEXT NOT NULL DEFAULT '', startDate INTEGER NULL, endDate INTEGER NULL, waehlbar TEXT NOT NULL DEFAULT '')",
		"CREATE TABLE tl_kinderschutz_teilnahme (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, veranstaltung INTEGER NOT NULL DEFAULT 0, member INTEGER NOT NULL DEFAULT 0, quelle TEXT NOT NULL DEFAULT '', angelegtAm INTEGER NOT NULL DEFAULT 0, erinnertAm INTEGER NOT NULL DEFAULT 0, UNIQUE (veranstaltung, member))",
	);

	/**
	 * Legt eine frische Datenbank mit allen Tabellen an.
	 *
	 * @return Connection Die Verbindung; jede Verbindung hat ihre eigene,
	 *                    leere Datenbank
	 */
	public static function verbindung(): Connection
	{
		$verbindung = DriverManager::getConnection(array('driver' => 'pdo_sqlite', 'memory' => true));

		foreach (self::SCHEMA as $anweisung) {
			$verbindung->executeStatement($anweisung);
		}

		return $verbindung;
	}

	/**
	 * Legt ein Mitglied an.
	 *
	 * @param Connection     $verbindung Die Testdatenbank
	 * @param string         $vorname    Vorname
	 * @param string         $nachname   Nachname
	 * @param list<int>      $gruppen    IDs der Mitgliedergruppen
	 * @param array<string, string> $mehr Weitere Spalten (disable, start, stop, email)
	 *
	 * @return int Die ID des Mitglieds
	 */
	public static function mitglied(Connection $verbindung, string $vorname, string $nachname, array $gruppen = array(1), array $mehr = array()): int
	{
		$verbindung->insert('tl_member', array_merge(array(
			'firstname' => $vorname,
			'lastname'  => $nachname,
			'email'     => strtolower($vorname.'.'.$nachname).'@example.org',
			// Contao speichert Gruppen als serialisierte Liste von Zeichenketten
			'groups'    => serialize(array_map('strval', $gruppen)),
		), $mehr));

		return (int) $verbindung->lastInsertId();
	}

	/**
	 * Legt eine aktive Dokumentart an.
	 *
	 * @param Connection $verbindung  Die Testdatenbank
	 * @param string     $name        Anzeigename
	 * @param int        $gueltigkeit Monate, 0 = unbegrenzt
	 * @param bool       $efz         Papierkorb nach Prüfung und Abfrage
	 *                                einschlägiger Einträge
	 * @param bool       $pflicht     Pflichtdokument
	 * @param int        $reihenfolge Sortierung
	 *
	 * @return int Die ID der Dokumentart
	 */
	public static function dokumentart(Connection $verbindung, string $name, int $gueltigkeit = 24, bool $efz = false, bool $pflicht = true, int $reihenfolge = 0): int
	{
		$verbindung->insert('tl_kinderschutz_dokumentart', array(
			'name'                   => $name,
			'gueltigkeit'            => $gueltigkeit,
			'pflicht'                => $pflicht ? '1' : '',
			'papierkorbNachPruefung' => $efz ? '1' : '',
			'einschlaegigAbfragen'   => $efz ? '1' : '',
			'reihenfolge'            => $reihenfolge,
			'published'              => '1',
		));

		return (int) $verbindung->lastInsertId();
	}

	/**
	 * Legt einen Nachweis an.
	 *
	 * @param Connection           $verbindung Die Testdatenbank
	 * @param array<string, mixed> $werte      Spalten; member, dokumentart und
	 *                                         status sind Pflicht, tstamp ist 1
	 *                                         (gespeichert), wenn nicht angegeben
	 *
	 * @return int Die ID des Nachweises
	 */
	public static function nachweis(Connection $verbindung, array $werte): int
	{
		$verbindung->insert('tl_kinderschutz', array_merge(array('tstamp' => 1), $werte));

		return (int) $verbindung->lastInsertId();
	}

	/**
	 * Legt eine Veranstaltung an.
	 *
	 * @param Connection $verbindung Die Testdatenbank
	 * @param string     $name       Name
	 * @param string     $beginn     Erster Tag JJJJ-MM-TT
	 * @param string     $ende       Letzter Tag JJJJ-MM-TT oder ''
	 * @param bool       $waehlbar   Im Frontend wählbar
	 *
	 * @return int Die ID der Veranstaltung
	 */
	public static function veranstaltung(Connection $verbindung, string $name, string $beginn, string $ende = '', bool $waehlbar = true): int
	{
		$verbindung->insert('tl_kinderschutz_veranstaltung', array(
			'name'      => $name,
			'startDate' => strtotime($beginn.' 00:00'),
			'endDate'   => '' === $ende ? null : strtotime($ende.' 00:00'),
			'waehlbar'  => $waehlbar ? '1' : '',
		));

		return (int) $verbindung->lastInsertId();
	}
}
