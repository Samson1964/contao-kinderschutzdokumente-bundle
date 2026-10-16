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
 * Tabelle tl_kinderschutz_dokumentart: die Arten von Unterlagen, die
 * eingereicht werden (EFZ, Ehrenkodex, Schulungsnachweis …).
 *
 * „permissions“ ist absichtlich leer und alle Felder tragen
 * „exclude => false“: Die Kinderschutz-Module schalten Contaos Modul- und
 * Tabellenrechte ab und prüfen selbst (siehe Spezifikation, „Rechte“).
 * Callbacks hängen per #[AsCallback] an den Listenern.
 */
$GLOBALS['TL_DCA']['tl_kinderschutz_dokumentart'] = array
(
	'config' => array
	(
		'dataContainer'    => DC_Table::class,
		'enableVersioning' => true,
		// Ohne pid und sorting gibt es nichts zu verschieben. Die Sperre
		// mit 403 setzt Sicherheit\Verschiebesperre im onload; notSortable
		// allein ergäbe in 4.13 HTTP 500 und blendet hier nur Knöpfe aus
		'notSortable'      => true,
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
			'fields'      => array('reihenfolge', 'name'),
			'flag'        => DataContainer::SORT_ASC,
			'panelLayout' => 'limit',
		),
		'label' => array
		(
			'fields'      => array('name', 'pflicht', 'gueltigkeit'),
			'showColumns' => true,
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
				'href'    => 'act=toggle&amp;field=published',
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
		'default' => '{art_legend},name,hinweis;{regel_legend},pflicht,gueltigkeit,reihenfolge;{datenschutz_legend},papierkorbNachPruefung,einschlaegigAbfragen;{publish_legend},published',
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
			'eval'      => array('mandatory' => true, 'maxlength' => 128, 'tl_class' => 'w50'),
			'sql'       => "varchar(128) NOT NULL default ''",
		),
		'hinweis' => array
		(
			'exclude'   => false,
			'inputType' => 'textarea',
			'eval'      => array('style' => 'height:60px', 'tl_class' => 'clr'),
			'sql'       => 'text NULL',
		),
		'pflicht' => array
		(
			'exclude'   => false,
			'filter'    => true,
			'inputType' => 'checkbox',
			'eval'      => array('tl_class' => 'w50 m12'),
			'sql'       => "char(1) NOT NULL default ''",
		),
		'gueltigkeit' => array
		(
			'exclude'   => false,
			'inputType' => 'text',
			'eval'      => array('rgxp' => 'natural', 'maxval' => 600, 'maxlength' => 3, 'tl_class' => 'w50'),
			'sql'       => "smallint(5) unsigned NOT NULL default '0'",
		),
		'reihenfolge' => array
		(
			'exclude'   => false,
			'inputType' => 'text',
			'eval'      => array('rgxp' => 'natural', 'maxlength' => 5, 'tl_class' => 'w50'),
			'sql'       => "int(10) unsigned NOT NULL default '0'",
		),
		'papierkorbNachPruefung' => array
		(
			'exclude'   => false,
			'inputType' => 'checkbox',
			'eval'      => array('tl_class' => 'w50 m12'),
			'sql'       => "char(1) NOT NULL default ''",
		),
		'einschlaegigAbfragen' => array
		(
			'exclude'   => false,
			'inputType' => 'checkbox',
			'eval'      => array('tl_class' => 'w50 m12'),
			'sql'       => "char(1) NOT NULL default ''",
		),
		'published' => array
		(
			'exclude'   => false,
			'filter'    => true,
			'toggle'    => true,
			'inputType' => 'checkbox',
			'eval'      => array('doNotCopy' => true),
			'sql'       => "char(1) NOT NULL default ''",
		)
	)
);
