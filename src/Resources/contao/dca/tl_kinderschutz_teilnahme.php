<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

use Contao\DC_Table;

/*
 * Tabelle tl_kinderschutz_teilnahme: wer an welcher Veranstaltung teilnimmt.
 *
 * Diese DCA beschreibt nur das Schema. Bearbeitet wird die Zuordnung über das
 * virtuelle Feld „teilnehmer“ an der Veranstaltung und über das
 * Frontend-Modul. Eine eigene Tabelle statt eines serialisierten Feldes
 * verhindert, dass Frontend und Backend einander überschreiben.
 */
$GLOBALS['TL_DCA']['tl_kinderschutz_teilnahme'] = array
(
	'config' => array
	(
		'dataContainer' => DC_Table::class,
		'closed'        => true,
		'notEditable'   => true,
		'permissions'   => array(),
		'sql'           => array
		(
			'keys' => array
			(
				'id'                  => 'primary',
				'veranstaltung,member' => 'unique',
			)
		)
	),

	'fields' => array
	(
		'id' => array
		(
			'sql' => 'int(10) unsigned NOT NULL auto_increment',
		),
		'tstamp' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'veranstaltung' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'member' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'quelle' => array
		(
			'sql' => "varchar(16) NOT NULL default ''",
		),
		'angelegtAm' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'erinnertAm' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		)
	)
);
