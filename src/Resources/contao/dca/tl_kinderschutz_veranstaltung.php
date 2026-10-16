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
 * Tabelle tl_kinderschutz_veranstaltung: Veranstaltungen wie
 * „JWM Kreta 11/2026“, für die die Unterlagen gelten müssen.
 *
 * Das Feld „teilnehmer“ hat keine Spalte. Es wird über load- und
 * save_callback aus tl_kinderschutz_teilnahme gelesen und dorthin
 * geschrieben (EventListener\DataContainer\VeranstaltungListener).
 * Datumsfelder sind „int NULL“ wie in tl_calendar_events, damit ein leeres
 * Ende nicht als 01.01.1970 erscheint.
 */
$GLOBALS['TL_DCA']['tl_kinderschutz_veranstaltung'] = array
(
	'config' => array
	(
		'dataContainer'    => DC_Table::class,
		'enableVersioning' => true,
		'permissions'      => array(),
		'sql'              => array
		(
			'keys' => array
			(
				'id' => 'primary',
			)
		)
	),

	'list' => array
	(
		'sorting' => array
		(
			'mode'        => DataContainer::MODE_SORTED,
			'fields'      => array('startDate'),
			// Nach Tag, nicht SORT_DESC: Contao 4.13 formatiert den
			// Gruppenkopf nur bei Tag-/Monats-/Jahresflags als Datum und
			// zeigte sonst den rohen Zeitstempel (5.7 hätte beides gekonnt)
			'flag'        => DataContainer::SORT_DAY_DESC,
			'panelLayout' => 'search,limit',
		),
		'label' => array
		(
			'fields' => array('name'),
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
			'toggle' => array
			(
				'href'    => 'act=toggle&amp;field=waehlbar',
				'icon'    => 'visible.svg',
				'primary' => true,
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
		'default' => '{veranstaltung_legend},name,ort;{zeit_legend},startDate,endDate;{teilnehmer_legend},waehlbar,teilnehmer',
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
		'name' => array
		(
			'exclude'   => false,
			'search'    => true,
			'inputType' => 'text',
			'eval'      => array('mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'),
			'sql'       => "varchar(255) NOT NULL default ''",
		),
		'ort' => array
		(
			'exclude'   => false,
			'search'    => true,
			'inputType' => 'text',
			'eval'      => array('maxlength' => 255, 'tl_class' => 'w50'),
			'sql'       => "varchar(255) NOT NULL default ''",
		),
		'startDate' => array
		(
			'exclude'   => false,
			'inputType' => 'text',
			'eval'      => array('rgxp' => 'date', 'mandatory' => true, 'datepicker' => true, 'tl_class' => 'w50 wizard'),
			'sql'       => 'int(10) unsigned NULL',
		),
		'endDate' => array
		(
			'exclude'   => false,
			'inputType' => 'text',
			'eval'      => array('rgxp' => 'date', 'datepicker' => true, 'tl_class' => 'w50 wizard'),
			'sql'       => 'int(10) unsigned NULL',
		),
		'waehlbar' => array
		(
			'exclude'   => false,
			'toggle'    => true,
			'inputType' => 'checkbox',
			'eval'      => array('tl_class' => 'clr'),
			'sql'       => "char(1) NOT NULL default ''",
		),
		'teilnehmer' => array
		(
			'exclude'   => false,
			'inputType' => 'checkbox',
			'eval'      => array('multiple' => true, 'doNotSaveEmpty' => true, 'doNotCopy' => true, 'tl_class' => 'clr'),
		)
	)
);
