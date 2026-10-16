<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

use Contao\DC_File;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;

/*
 * Einstellungen des Bundles im Kinderschutzbereich (Menüpunkt
 * „Einstellungen“). Wie tl_settings schreibt DC_File in die
 * localconfig.php; im Prüfstand in 4.13 und 5.7 für einen Prüfer ohne
 * Administratorrechte erprobt. Die Schlüssel der Fassungen 1.0/1.1 bleiben
 * gleich, nur „kinderschutzGruppe“ (eine Gruppe) ersetzt
 * „kinderschutzGruppen“ (Übergang in Dienst\Einstellungen::gruppe(); die
 * Callbacks dafür stehen in EinstellungenListener).
 *
 * Mailtexte: Config::persist() fasst Zeilenumbrüche zu Leerzeichen
 * zusammen; die Callbacks verpacken die Texte deshalb als JSON-Zeichenkette
 * (Einstellungen::verpacke()/entpacke()). Die Platzhalter mit dem
 * Standardtext setzt EinstellungenListener im onload.
 */
$GLOBALS['TL_DCA']['tl_kinderschutz_einstellungen'] = array
(
	'config' => array
	(
		'dataContainer' => DC_File::class,
		'closed'        => true,
	),

	'palettes' => array
	(
		'default' => '{allgemein_legend},kinderschutzGruppe,kinderschutzSeite,kinderschutzAbsender;{fristen_legend},kinderschutzPapierkorbTage,kinderschutzVorlaufAblauf,kinderschutzVorlaufVeranstaltung',
	),

	'fields' => array
	(
		'kinderschutzGruppe' => array
		(
			'exclude'    => false,
			'inputType'  => 'select',
			'foreignKey' => 'tl_member_group.name',
			'eval'       => array('includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'),
		),
		'kinderschutzSeite' => array
		(
			'exclude'   => false,
			// select statt pageTree: Der Seitenbaum braucht Rechte am Seitenmodul,
			// die ein Prüfer meist nicht hat. Die Optionen liefert der
			// EinstellungenListener (Seiten mit dem Frontend-Modul).
			'inputType' => 'select',
			'eval'      => array('includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'),
		),
		'kinderschutzAbsender' => array
		(
			'exclude'   => false,
			'inputType' => 'text',
			'eval'      => array('rgxp' => 'email', 'maxlength' => 255, 'tl_class' => 'w50'),
		),
		'kinderschutzPapierkorbTage' => array
		(
			'exclude'   => false,
			'inputType' => 'text',
			'eval'      => array('rgxp' => 'natural', 'minval' => 1, 'maxlength' => 4, 'tl_class' => 'w50'),
		),
		'kinderschutzVorlaufAblauf' => array
		(
			'exclude'   => false,
			'inputType' => 'text',
			'eval'      => array('rgxp' => 'natural', 'minval' => 1, 'maxlength' => 3, 'tl_class' => 'w50'),
		),
		'kinderschutzVorlaufVeranstaltung' => array
		(
			'exclude'   => false,
			'inputType' => 'text',
			'eval'      => array('rgxp' => 'natural', 'minval' => 1, 'maxlength' => 3, 'tl_class' => 'w50'),
		),
	),
);

// Je Mailart ein zugeklappter Abschnitt mit Betreff und Text
foreach (Einstellungen::MAILARTEN as $kinderschutzArt) {
	$kinderschutzFeld = 'kinderschutzMail'.ucfirst($kinderschutzArt);

	$GLOBALS['TL_DCA']['tl_kinderschutz_einstellungen']['palettes']['default'] .= ';{mail'.ucfirst($kinderschutzArt).'_legend:hide},'.$kinderschutzFeld.'Betreff,'.$kinderschutzFeld.'Text';

	$GLOBALS['TL_DCA']['tl_kinderschutz_einstellungen']['fields'][$kinderschutzFeld.'Betreff'] = array
	(
		'exclude'   => false,
		'inputType' => 'text',
		'eval'      => array('maxlength' => 255, 'decodeEntities' => true, 'tl_class' => 'long'),
	);

	$GLOBALS['TL_DCA']['tl_kinderschutz_einstellungen']['fields'][$kinderschutzFeld.'Text'] = array
	(
		'exclude'       => false,
		'inputType'     => 'textarea',
		'eval'          => array('decodeEntities' => true, 'tl_class' => 'clr'),
		'load_callback' => array(static fn ($wert): string => Einstellungen::entpacke($wert)),
		'save_callback' => array(static fn ($wert): string => Einstellungen::verpacke($wert)),
	);
}

unset($kinderschutzArt, $kinderschutzFeld);
