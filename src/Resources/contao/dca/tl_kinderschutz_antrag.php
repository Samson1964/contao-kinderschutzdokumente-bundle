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
 * Tabelle tl_kinderschutz_antrag: Anträge auf Freischaltung.
 *
 * Ein Mitglied außerhalb der Gruppe der freigeschalteten Mitglieder stellt
 * im Frontend-Modul einen Antrag (Stand „offen“). Der Prüfer schaltet frei
 * oder lehnt ab; „Neu“ heißt hier „Mitglied freischalten“ und legt einen
 * Antrag ohne Stand an, der beim Speichern direkt freigeschaltet wird. Die
 * Folgen (Gruppe, Teilnahmen, Mails) zieht AntragListener nach dem
 * Speichern über Dienst\Freischaltung.
 *
 * Paletten: „default“ für Anträge aus dem Frontend (Infokasten und
 * Entscheidung), „direkt“ für neue Datensätze aus dem Backend; der
 * Listener wählt im onload. Nach der Entscheidung bleibt nur der
 * Infokasten.
 */
$GLOBALS['TL_DCA']['tl_kinderschutz_antrag'] = array
(
	'config' => array
	(
		'dataContainer' => DC_Table::class,
		'notCopyable'   => true,
		'permissions'   => array(),
		'sql'           => array
		(
			'keys' => array
			(
				'id'     => 'primary',
				'member' => 'index',
				'status' => 'index',
			)
		)
	),

	'list' => array
	(
		'sorting' => array
		(
			'mode'        => DataContainer::MODE_SORTED,
			// Offene zuerst: „offen“ > „freigeschaltet“ > „abgelehnt“
			'fields'      => array('status', 'beantragtAm DESC'),
			'flag'        => DataContainer::SORT_DESC,
			'panelLayout' => 'filter;search,limit',
		),
		'label' => array
		(
			'fields' => array('member'),
		),
		'operations' => array
		(
			'edit' => array
			(
				'href'    => 'act=edit',
				'icon'    => 'edit.svg',
				'primary' => true,
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
		'default' => '{antrag_legend},info;{entscheidung_legend},status,grund',
		'direkt'  => '{direkt_legend},member,wofuer',
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
		'member' => array
		(
			'exclude'   => false,
			'inputType' => 'select',
			'eval'      => array('mandatory' => true, 'chosen' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'),
			'sql'       => "int(10) unsigned NOT NULL default '0'",
		),
		'wofuer' => array
		(
			'exclude'   => false,
			'search'    => true,
			'inputType' => 'textarea',
			'eval'      => array('maxlength' => 500, 'tl_class' => 'clr'),
			'sql'       => 'text NULL',
		),
		'veranstaltungen' => array
		(
			'sql' => 'blob NULL',
		),
		'status' => array
		(
			'exclude'   => false,
			'filter'    => true,
			'inputType' => 'select',
			'options'   => array('offen', 'freigeschaltet', 'abgelehnt'),
			'reference' => &$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['stati'],
			'eval'      => array('tl_class' => 'w50'),
			'sql'       => "varchar(16) NOT NULL default ''",
		),
		'grund' => array
		(
			'exclude'   => false,
			'inputType' => 'textarea',
			'eval'      => array('tl_class' => 'clr'),
			'sql'       => 'text NULL',
		),
		'beantragtAm' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'entschiedenAm' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'entschiedenVon' => array
		(
			'sql' => "int(10) unsigned NOT NULL default '0'",
		),
		'direkt' => array
		(
			'sql' => "char(1) NOT NULL default ''",
		),
		// Ohne Spalte: Infokasten über input_field_callback (AntragListener::info)
		'info' => array
		(
			'exclude' => false,
		)
	)
);
