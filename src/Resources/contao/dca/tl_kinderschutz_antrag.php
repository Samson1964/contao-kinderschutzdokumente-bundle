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
		// Ohne pid und sorting gibt es nichts zu verschieben; eine
		// nachgebaute „act=cut“-Adresse endete sonst mit HTTP 500
		'notSortable'   => true,
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
			// Nicht gespeicherte Entwürfe aus „Mitglied freischalten“
			// (tstamp 0) ausblenden: Contao 5.7 löscht sie nur beim
			// Verlassen über „Zurück“, sonst stünden sie als „Mitglied
			// gelöscht (#0)“ in der Liste. 4.13 räumt sie selbst ab.
			'filter'      => array(array('tstamp>?', 0)),
		),
		'label' => array
		(
			'fields' => array('member'),
		),
		// Bewusst leer: Contao 5.7 ergänzt für jede bearbeit- oder löschbare
		// DC_Table-Tabelle „Mehrere bearbeiten“ (DefaultGlobalOperationsListener).
		// Eine leere Liste lässt der Listener unangetastet (`[] === $dca`).
		// Anträge werden einzeln entschieden, denn jede Entscheidung zieht
		// Gruppe, Teilnahmen und Mails nach. „Neu“ (hier „Mitglied
		// freischalten“) erzeugt DC_Table getrennt davon; Contao 4.13 kennt
		// den Sammelknopf ohnehin nur, wenn die DCA ihn nennt.
		'global_operations' => array(),
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
		// decodeEntities wie bei den Mailtexten der Einstellungen: Die
		// Begründung geht wörtlich in eine Textmail. Was Contao trotzdem
		// kodiert ablegt (4.13 etwa „<“ und „(“), entkodiert
		// Benachrichtigung::sende() vor dem Einsetzen.
		'grund' => array
		(
			'exclude'   => false,
			'inputType' => 'textarea',
			'eval'      => array('decodeEntities' => true, 'tl_class' => 'clr'),
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
