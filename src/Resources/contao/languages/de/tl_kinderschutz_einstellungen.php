<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['allgemein_legend'] = 'Allgemein';
$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['fristen_legend'] = 'Fristen';

$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['kinderschutzGruppe'] = array('Gruppe der freigeschalteten Mitglieder', 'Mitglieder dieser Gruppe sehen die Checkliste und stehen in der Übersicht; „Freischalten“ nimmt in diese Gruppe auf. Bitte eine eigene Gruppe wählen, nicht die Gruppe, in die jede Registrierung fällt.');
$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['kinderschutzSeite'] = array('Seite mit „Meine Unterlagen“', 'Angeboten werden die Seiten, auf denen das Frontend-Modul „Kinderschutz: Meine Unterlagen“ als Inhaltselement eingebunden ist; über das Seitenlayout eingebundene Module werden nicht erkannt. Die Mails verlinken hierher; die Domain muss am Startpunkt eingetragen sein.');
$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['seiteOhneModul'] = 'ohne Frontend-Modul';
$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['kinderschutzAbsender'] = array('Absenderadresse', 'Leer: die E-Mail-Adresse des Systemadministrators.');
$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['kinderschutzPapierkorbTage'] = array('Papierkorbfrist in Tagen', 'Danach werden Dateien im Papierkorb endgültig gelöscht. Leer: 30.');
$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['kinderschutzVorlaufAblauf'] = array('Erinnerung vor Ablauf in Wochen', 'Leer: 8.');
$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['kinderschutzVorlaufVeranstaltung'] = array('Erinnerung vor Veranstaltungen in Wochen', 'Leer: 6.');

// Je Mailart: Abschnittstitel und erlaubte Platzhalter
$kinderschutzMails = array
(
	'neu'             => array('Neue Einreichung (an die Prüfer)', '{mitglied}, {dokumentart}, {link}'),
	'abgelehnt'       => array('Unterlage abgelehnt', '{name}, {dokumentart}, {grund}, {link}'),
	'vorAblauf'       => array('Unterlage läuft bald ab', '{name}, {dokumentart}, {datum}, {link}'),
	'ablauf'          => array('Unterlage abgelaufen', '{name}, {dokumentart}, {datum}, {link}'),
	'veranstaltung'   => array('Fehlende Unterlagen vor einer Veranstaltung', '{name}, {veranstaltung}, {zeitraum}, {liste}, {link}'),
	'antrag'          => array('Neuer Antrag (an die Prüfer)', '{mitglied}, {wofuer}, {veranstaltungen}, {link}'),
	'freigeschaltet'  => array('Mitglied freigeschaltet', '{name}, {link}'),
	'antragAbgelehnt' => array('Antrag abgelehnt', '{name}, {grund}, {link}'),
);

foreach ($kinderschutzMails as $kinderschutzArt => $kinderschutzAngaben) {
	$kinderschutzFeld = 'kinderschutzMail'.ucfirst($kinderschutzArt);
	$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['mail'.ucfirst($kinderschutzArt).'_legend'] = 'Mail: '.$kinderschutzAngaben[0];
	$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen'][$kinderschutzFeld.'Betreff'] = array('Betreff', 'Leer: der Standardbetreff (grau im Feld).');
	$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen'][$kinderschutzFeld.'Text'] = array('Text', 'Leer: der Standardtext (grau im Feld). Reiner Text ohne HTML. Platzhalter: '.$kinderschutzAngaben[1].'.');
}

unset($kinderschutzMails, $kinderschutzArt, $kinderschutzAngaben, $kinderschutzFeld);
