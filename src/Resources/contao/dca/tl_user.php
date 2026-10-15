<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

use Contao\CoreBundle\DataContainer\PaletteManipulator;

/*
 * Prüferfreigabe am Backend-Benutzer.
 *
 * Das Feld steht in allen Paletten außer „login“ (eigenes Profil), damit sich
 * niemand selbst freigeben kann. Für alle außer dem Hauptadmin entfernt
 * EventListener\DataContainer\BenutzerListener es beim Laden wieder – aus den
 * Paletten, aus dem Filter der Benutzerliste und aus der Ansicht „Anzeigen“;
 * ein save_callback weist Änderungen durch andere zusätzlich ab.
 *
 * doNotCopy: Beim Kopieren eines Benutzers (DC_Table::copy()) setzt Contao das
 * Feld auf den Leerwert zurück. Ohne das Kennzeichen erbte die Kopie eines
 * Prüfers die Freigabe, und es entstünde ein neuer Prüfer, den der Hauptadmin
 * nie freigegeben hat.
 */
$GLOBALS['TL_DCA']['tl_user']['fields']['kinderschutzPruefer'] = array
(
	'exclude'   => false,
	'filter'    => true,
	'inputType' => 'checkbox',
	'eval'      => array('tl_class' => 'w50', 'doNotCopy' => true),
	'sql'       => "char(1) NOT NULL default ''",
);

$manipulator = PaletteManipulator::create()
	->addLegend('kinderschutz_legend', 'account_legend', PaletteManipulator::POSITION_BEFORE)
	->addField('kinderschutzPruefer', 'kinderschutz_legend', PaletteManipulator::POSITION_APPEND);

foreach (array('admin', 'default', 'group', 'extend', 'custom') as $palette) {
	if (isset($GLOBALS['TL_DCA']['tl_user']['palettes'][$palette])) {
		$manipulator->applyToPalette($palette, 'tl_user');
	}
}

unset($manipulator, $palette);
