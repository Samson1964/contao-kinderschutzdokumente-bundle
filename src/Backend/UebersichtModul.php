<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Backend;

use Contao\BackendModule;
use Contao\System;

/**
 * Backend-Modul „Übersicht“ (do=kinderschutz_uebersicht): die Matrix Personen ×
 * Dokumentarten.
 *
 * Contao erzeugt Module mit „callback“ in $GLOBALS['BE_MOD'] per „new“ –
 * in 4.13 wie in 5.7 (Backend::getBackendModule). Die Daten kommen deshalb
 * aus dem öffentlichen Dienst Backend\Uebersicht, der auch die Berechtigung
 * prüft.
 */
class UebersichtModul extends BackendModule
{
	/**
	 * @var string Name des Templates
	 */
	protected $strTemplate = 'be_kinderschutz_uebersicht';

	/**
	 * Füllt das Template mit der Matrix.
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Berechtigung
	 */
	protected function compile(): void
	{
		$container = System::getContainer();
		$request = $container->get('request_stack')->getCurrentRequest();

		System::loadLanguageFile('default');

		foreach ($container->get(Uebersicht::class)->daten($request, time()) as $name => $wert) {
			$this->Template->$name = $wert;
		}
	}
}
