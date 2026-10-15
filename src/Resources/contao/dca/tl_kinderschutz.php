<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

use Contao\DataContainer;
use Contao\DC_Table;

/*
 * Tabelle tl_kinderschutz: die Nachweise. Ein Datensatz ist eine Einreichung
 * einer Person zu einer Dokumentart oder ein vom Prüfer ohne Datei angelegter
 * Prüfvermerk.
 *
 * Zwei Backend-Module zeigen dieselbe Tabelle: „Nachweise“ (alle Datensätze,
 * Bearbeitungsmaske = Prüfmaske) und „Papierkorb“ (nur Datensätze, deren
 * Datei im Papierkorb liegt). Den Unterschied stellt der onload_callback in
 * EventListener\DataContainer\NachweisListener ein.
 *
 * Versionierung ist absichtlich aus: Jede Version wäre eine weitere Kopie von
 * Prüfvermerken wie „einschlägige Einträge“ in tl_version.
 *
 * Die Felder ohne inputType (Datei, Zeitpunkte, Merker) setzt nur der Code;
 * angezeigt werden sie im Kopf der Prüfmaske (Feld „vorschau“).
 *
 * Kein Feld trägt „search“: Die Backend-Suche von Contao 5 würde solche Felder
 * (etwa interne Bemerkungen) in ihren Index übernehmen.
 */
$GLOBALS['TL_DCA']['tl_kinderschutz'] = array
(
	'config' => array
	(
		'dataContainer' => DC_Table::class,
		'permissions'   => array(),
		'sql'           => array
		(
			'keys' => array
			(
				'id'                 => 'primary',
				'member,dokumentart' => 'index',
				'papierkorbSeit'     => 'index',
			)
		)
	),

	'list' => array
	(
		'sorting' => array
		(
			'mode'        => DataContainer::MODE_SORTED,
			'fields'      => array('tstamp'),
			'flag'        => DataContainer::SORT_DESC,
			'panelLayout' => 'filter;limit',
		),
		'label' => array
		(
			'fields' => array('member', 'dokumentart', 'status'),
		),
		'operations' => array
		(
			'edit' => array
			(
				'href'    => 'act=edit',
				'icon'    => 'edit.svg',
				'primary' => true,
			),
			'papierkorb' => array
			(
				'href'       => 'key=papierkorb',
				'icon'       => 'bundles/contaokinderschutzdokumente/papierkorb.svg',
				'attributes' => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['tl_kinderschutz']['papierkorbFrage'] ?? null) . '\'))return false"',
				'primary'    => true,
			),
			'delete' => array
			(
				'href'       => 'act=delete',
				'icon'       => 'delete.svg',
				'attributes' => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? null) . '\'))return false;Backend.getScrollOffset()"',
				'primary'    => true,
			),
			'show' => array
			(
				'href'    => 'act=show',
				'icon'    => 'show.svg',
				'primary' => true,
			)
		)
	),

	'palettes' => array
	(
		'default' => '{vorschau_legend},vorschau;{person_legend},member,dokumentart;{pruefung_legend},status,ausstellungsdatum,einschlaegig,ablehnungsgrund;{intern_legend:hide},bemerkung',
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
		'vorschau' => array
		(
			'exclude' => false,
		),
		'member' => array
		(
			'exclude'    => false,
			'filter'     => true,
			'inputType'  => 'select',
			'foreignKey' => "tl_member.CONCAT(lastname, ', ', firstname)",
			'eval'       => array('mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'),
			'sql'        => "int(10) unsigned NOT NULL default '0'",
		),
		'dokumentart' => array
		(
			'exclude'    => false,
			'filter'     => true,
			'inputType'  => 'select',
			'foreignKey' => 'tl_kinderschutz_dokumentart.name',
			'eval'       => array('mandatory' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'),
			'sql'        => "int(10) unsigned NOT NULL default '0'",
		),
		'status' => array
		(
			'exclude'   => false,
			'filter'    => true,
			'default'   => 'geprueft',
			'inputType' => 'select',
			'options'   => array('eingereicht', 'geprueft', 'abgelehnt'),
			'reference' => &$GLOBALS['TL_LANG']['tl_kinderschutz']['stati'],
			'eval'      => array('mandatory' => true, 'tl_class' => 'w50'),
			'sql'       => "varchar(16) NOT NULL default ''",
		),
		'ausstellungsdatum' => array
		(
			'exclude'   => false,
			'inputType' => 'text',
			'eval'      => array('rgxp' => 'date', 'mandatory' => true, 'datepicker' => true, 'tl_class' => 'w50 wizard'),
			'sql'       => 'int(10) unsigned NULL',
		),
		'einschlaegig' => array
		(
			'exclude'   => false,
			'filter'    => true,
			'inputType' => 'select',
			'options'   => array('nein', 'ja'),
			'reference' => &$GLOBALS['TL_LANG']['tl_kinderschutz']['einschlaegigOptionen'],
			'eval'      => array('includeBlankOption' => true, 'tl_class' => 'w50'),
			'sql'       => "varchar(4) NOT NULL default ''",
		),
		'ablehnungsgrund' => array
		(
			'exclude'   => false,
			'inputType' => 'textarea',
			'eval'      => array('style' => 'height:60px', 'tl_class' => 'clr'),
			'sql'       => 'text NULL',
		),
		'bemerkung' => array
		(
			'exclude'   => false,
			'inputType' => 'textarea',
			'eval'      => array('style' => 'height:60px', 'tl_class' => 'clr'),
			'sql'       => 'text NULL',
		),
		'quelle' => array
		(
			'default' => 'backend',
			'sql'     => "varchar(16) NOT NULL default ''",
		),
		'verarbeitet' => array
		(
			'sql' => "varchar(16) NOT NULL default ''",
		),
		'datei' => array
		(
			'sql' => "varchar(255) NOT NULL default ''",
		),
		'dateiname' => array
		(
			'sql' => "varchar(255) NOT NULL default ''",
		),
		'dateityp' => array
		(
			'sql' => "varchar(64) NOT NULL default ''",
		),
		'dateigroesse' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'hochgeladenAm' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'geprueftAm' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'geprueftVon' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'gueltigBis' => array
		(
			'sql' => 'int(10) unsigned NULL',
		),
		'papierkorbSeit' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'dateiGeloeschtAm' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'erinnertVorAblauf' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'erinnertAblauf' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		)
	)
);
