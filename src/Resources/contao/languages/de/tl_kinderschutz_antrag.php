<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['antrag_legend'] = 'Antrag';
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['entscheidung_legend'] = 'Entscheidung';
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['direkt_legend'] = 'Mitglied ohne Antrag freischalten';

$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['member'] = array('Mitglied', 'Nur aktive Mitglieder, die noch nicht freigeschaltet sind.');
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['wofuer'] = array('Wofür', 'Im Frontend Pflicht; hier eine freiwillige Notiz.');
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['status'] = array('Entscheidung', '„freigeschaltet“ nimmt das Mitglied in die Gruppe der freigeschalteten Mitglieder auf, übernimmt die angekreuzten Veranstaltungen und schickt ihm eine Mail. Danach lässt sich die Entscheidung nicht mehr ändern.');
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['grund'] = array('Begründung', 'Pflicht beim Ablehnen; geht wörtlich per Mail an das Mitglied.');
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['info'] = array('Antrag', '');

$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['stati'] = array
(
	'offen'          => 'offen',
	'freigeschaltet' => 'freigeschaltet',
	'abgelehnt'      => 'abgelehnt',
);

$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['beantragtAm'] = 'Beantragt am';
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['veranstaltungenKopf'] = 'Angekreuzte Veranstaltungen';
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['keineVeranstaltungen'] = 'keine';
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['entschieden'] = 'am %s von %s';
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['direktVermerk'] = 'Direkt durch den Prüfer freigeschaltet';
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['direktKurz'] = 'direkt';

$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['new'] = array('Mitglied freischalten', 'Ein Mitglied ohne Antrag freischalten');
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['edit'] = array('Antrag bearbeiten', 'Antrag ID %s bearbeiten');
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['delete'] = array('Antrag löschen', 'Antrag ID %s löschen');
$GLOBALS['TL_LANG']['tl_kinderschutz_antrag']['show'] = array('Antrag anzeigen', 'Details des Antrags ID %s');
