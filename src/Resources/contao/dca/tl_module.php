<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

/*
 * Palette des Frontend-Moduls „Kinderschutz: Meine Unterlagen“.
 *
 * Ohne eigene Felder: Gruppen und Seite stehen in den Einstellungen. Die
 * Kernfelder „guests“ und „space“ fehlen bewusst, weil Contao 5 sie nicht hat.
 */
$GLOBALS['TL_DCA']['tl_module']['palettes']['kinderschutz_unterlagen'] = '{title_legend},name,headline,type;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID';
