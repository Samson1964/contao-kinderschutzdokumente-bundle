<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

$GLOBALS['TL_LANG']['tl_kinderschutz']['vorschau_legend'] = 'Einreichung';
$GLOBALS['TL_LANG']['tl_kinderschutz']['person_legend'] = 'Person und Dokumentart';
$GLOBALS['TL_LANG']['tl_kinderschutz']['pruefung_legend'] = 'Prüfung';
$GLOBALS['TL_LANG']['tl_kinderschutz']['intern_legend'] = 'Interne Bemerkung';

$GLOBALS['TL_LANG']['tl_kinderschutz']['member'] = array('Person', 'Mitglied der Gruppe der freigeschalteten Mitglieder.');
$GLOBALS['TL_LANG']['tl_kinderschutz']['dokumentart'] = array('Dokumentart', 'Welche Unterlage dieser Nachweis belegt.');
$GLOBALS['TL_LANG']['tl_kinderschutz']['status'] = array('Entscheidung', '„Geprüft“ setzt die Gültigkeit; „Abgelehnt“ schickt der Person die Begründung.');
$GLOBALS['TL_LANG']['tl_kinderschutz']['ausstellungsdatum'] = array('Ausstellungsdatum', 'Datum auf dem Dokument; die Gültigkeit läuft ab diesem Tag.');
$GLOBALS['TL_LANG']['tl_kinderschutz']['einschlaegig'] = array('Einschlägige Einträge', 'Nur beim Führungszeugnis: Liegen Einträge nach § 72a SGB VIII vor?');
$GLOBALS['TL_LANG']['tl_kinderschutz']['ablehnungsgrund'] = array('Ablehnungsgrund', 'Pflicht bei Ablehnung; geht wörtlich per Mail an die Person.');
$GLOBALS['TL_LANG']['tl_kinderschutz']['bemerkung'] = array('Bemerkung', 'Nur für Prüfer sichtbar.');

$GLOBALS['TL_LANG']['tl_kinderschutz']['stati'] = array
(
	'eingereicht' => 'eingereicht',
	'geprueft'    => 'geprüft',
	'abgelehnt'   => 'abgelehnt',
	'ersetzt'     => 'ersetzt',
);

$GLOBALS['TL_LANG']['tl_kinderschutz']['einschlaegigOptionen'] = array
(
	'nein' => 'nein',
	'ja'   => 'ja',
);

$GLOBALS['TL_LANG']['tl_kinderschutz']['edit'] = array('Prüfen', 'Nachweis ID %s prüfen');
$GLOBALS['TL_LANG']['tl_kinderschutz']['papierkorb'] = array('In den Papierkorb', 'Datei von Nachweis ID %s in den Papierkorb legen');
$GLOBALS['TL_LANG']['tl_kinderschutz']['papierkorbFrage'] = 'Die Datei in den Papierkorb legen? Der Prüfvermerk bleibt erhalten.';
$GLOBALS['TL_LANG']['tl_kinderschutz']['wiederherstellen'] = array('Wiederherstellen', 'Datei von Nachweis ID %s wiederherstellen');
$GLOBALS['TL_LANG']['tl_kinderschutz']['loeschen'] = array('Endgültig löschen', 'Datei von Nachweis ID %s endgültig löschen');
$GLOBALS['TL_LANG']['tl_kinderschutz']['loeschenFrage'] = 'Die Datei endgültig löschen? Das lässt sich nicht rückgängig machen.';
$GLOBALS['TL_LANG']['tl_kinderschutz']['leeren'] = array('Papierkorb leeren', 'Alle Dateien im Papierkorb endgültig löschen');
$GLOBALS['TL_LANG']['tl_kinderschutz']['leerenFrage'] = 'Alle Dateien im Papierkorb endgültig löschen? Das lässt sich nicht rückgängig machen.';
