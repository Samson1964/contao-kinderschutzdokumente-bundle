<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Input;

/**
 * Liefert ein ContaoFramework, dessen Input-Adapter feste Adressparameter kennt.
 *
 * Steht für das, was Contao über Input::get() liest – in 4.13 nach der
 * Normalisierung von Werten und Schlüsseln. Die Tests geben deshalb den
 * Wert vor, den Contao sieht; was im rohen Symfony-Request steht, spielt für
 * die Sperren keine Rolle mehr.
 */
trait EingabeAttrappe
{
	/**
	 * Baut die Framework-Attrappe.
	 *
	 * @param array<string, mixed> $abfrage Parameter, wie Input::get() sie
	 *                                      liefert; fehlende ergeben null
	 *
	 * @return ContaoFramework Die Attrappe; jeder andere Adapter ist ein echter
	 */
	private function eingabe(array $abfrage): ContaoFramework
	{
		$input = $this->getMockBuilder(Adapter::class)
			->setConstructorArgs(array(Input::class))
			->addMethods(array('get'))
			->getMock();
		$input->method('get')->willReturnCallback(static fn (string $name) => $abfrage[$name] ?? null);

		$framework = $this->createMock(ContaoFramework::class);
		$framework->method('getAdapter')->willReturnCallback(static fn (string $klasse) => Input::class === $klasse ? $input : new Adapter($klasse));

		return $framework;
	}
}
