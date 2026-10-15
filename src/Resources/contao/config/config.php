<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

use Schachbulle\ContaoKinderschutzdokumenteBundle\Backend\PapierkorbAktionen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Backend\UebersichtModul;

/*
 * Menügruppe „Kinderschutz“ im Backend.
 *
 * Jedes Modul trägt „disablePermissionChecks“: Den Zugang regelt allein die
 * Prüferfreigabe (Dienst\Zugang), nicht die Modulrechte der Benutzergruppen.
 * Für Unberechtigte blendet EventListener\MenueListener die Gruppe aus, und
 * jede Maske, jede Aktion und die Datei-Route prüfen selbst.
 *
 * Der Gruppenschlüssel landet als CSS-Klasse im Markup; der Anzeigename kommt
 * aus TL_LANG['MOD']['kinderschutz'].
 */
$GLOBALS['BE_MOD']['kinderschutz'] = array
(
	'kinderschutz_uebersicht' => array
	(
		'callback'                => UebersichtModul::class,
		'disablePermissionChecks' => true,
		'stylesheet'              => 'bundles/contaokinderschutzdokumente/backend.css',
	),
	'kinderschutz_nachweise' => array
	(
		'tables'                  => array('tl_kinderschutz'),
		'disablePermissionChecks' => true,
		'stylesheet'              => 'bundles/contaokinderschutzdokumente/backend.css',
		// Datei in den Papierkorb legen (do=kinderschutz_nachweise&key=papierkorb&id=…)
		'papierkorb'              => array(PapierkorbAktionen::class, 'inPapierkorb'),
	),
	'kinderschutz_papierkorb' => array
	(
		'tables'                  => array('tl_kinderschutz'),
		'disablePermissionChecks' => true,
		'stylesheet'              => 'bundles/contaokinderschutzdokumente/backend.css',
		'wiederherstellen'        => array(PapierkorbAktionen::class, 'wiederherstellen'),
		'loeschen'                => array(PapierkorbAktionen::class, 'loeschen'),
		'leeren'                  => array(PapierkorbAktionen::class, 'leeren'),
	),
	'kinderschutz_veranstaltungen' => array
	(
		'tables'                  => array('tl_kinderschutz_veranstaltung'),
		'disablePermissionChecks' => true,
	),
	'kinderschutz_dokumentarten' => array
	(
		'tables'                  => array('tl_kinderschutz_dokumentart'),
		'disablePermissionChecks' => true,
	),
);
