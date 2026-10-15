<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Einstellungen des Bundles unter System → Einstellungen.
 *
 * tl_settings schreibt mit DC_File in die localconfig.php; das funktioniert
 * in 4.13 und 5.7 gleich. Gelesen werden die Werte über
 * Dienst\Einstellungen, das auch die Vorgaben kennt.
 */
$GLOBALS['TL_DCA']['tl_settings']['palettes']['default'] .= ';{kinderschutz_legend},kinderschutzGruppen,kinderschutzSeite,kinderschutzAbsender,kinderschutzPapierkorbTage,kinderschutzVorlaufAblauf,kinderschutzVorlaufVeranstaltung';

$GLOBALS['TL_DCA']['tl_settings']['fields']['kinderschutzGruppen'] = array
(
	'inputType'  => 'checkbox',
	'foreignKey' => 'tl_member_group.name',
	'eval'       => array('multiple' => true, 'tl_class' => 'clr'),
);

$GLOBALS['TL_DCA']['tl_settings']['fields']['kinderschutzSeite'] = array
(
	'inputType' => 'pageTree',
	'eval'      => array('fieldType' => 'radio', 'tl_class' => 'clr'),
);

$GLOBALS['TL_DCA']['tl_settings']['fields']['kinderschutzAbsender'] = array
(
	'inputType' => 'text',
	'eval'      => array('rgxp' => 'email', 'maxlength' => 255, 'tl_class' => 'w50'),
);

$GLOBALS['TL_DCA']['tl_settings']['fields']['kinderschutzPapierkorbTage'] = array
(
	'inputType' => 'text',
	'eval'      => array('rgxp' => 'natural', 'minval' => 1, 'maxlength' => 4, 'tl_class' => 'w50'),
);

$GLOBALS['TL_DCA']['tl_settings']['fields']['kinderschutzVorlaufAblauf'] = array
(
	'inputType' => 'text',
	'eval'      => array('rgxp' => 'natural', 'minval' => 1, 'maxlength' => 3, 'tl_class' => 'w50'),
);

$GLOBALS['TL_DCA']['tl_settings']['fields']['kinderschutzVorlaufVeranstaltung'] = array
(
	'inputType' => 'text',
	'eval'      => array('rgxp' => 'natural', 'minval' => 1, 'maxlength' => 3, 'tl_class' => 'w50'),
);
