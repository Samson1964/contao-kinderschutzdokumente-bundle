<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Baut die Links, die in den Mails stehen.
 */
class Verweise
{
	/**
	 * @param ContaoFramework       $framework     Zugang zu PageModel
	 * @param UrlGeneratorInterface $router        Erzeugt die Backend-Adresse
	 * @param Einstellungen         $einstellungen Liefert die Unterlagenseite
	 */
	public function __construct(
		private readonly ContaoFramework $framework,
		private readonly UrlGeneratorInterface $router,
		private readonly Einstellungen $einstellungen,
	) {
	}

	/**
	 * Liefert die absolute Adresse der Seite mit dem Frontend-Modul.
	 *
	 * PageModel::getAbsoluteUrl() gibt es in 4.13 und 5.7; in 5.7 meldet es
	 * eine Abkündigung, funktioniert aber. Ohne Domain am Startpunkt nimmt
	 * Contao im Cronjob den Host der Konsole – deshalb gehört die Domain in
	 * die Einrichtung (README).
	 *
	 * @return string Die Adresse, oder leer, wenn keine Seite eingestellt ist
	 */
	public function unterlagenseite(): string
	{
		$id = $this->einstellungen->seite();

		if ($id <= 0) {
			return '';
		}

		$this->framework->initialize();
		$seite = $this->framework->getAdapter(PageModel::class)->findByPk($id);

		return null === $seite ? '' : (string) $seite->getAbsoluteUrl();
	}

	/**
	 * Liefert die absolute Adresse der Übersicht im Backend.
	 *
	 * @param array<string, string> $parameter Zusätzliche Parameter, etwa ein Filter
	 *
	 * @return string Die Adresse
	 */
	public function uebersicht(array $parameter = array()): string
	{
		return $this->router->generate('contao_backend', array_merge(array('do' => 'kinderschutz_uebersicht'), $parameter), UrlGeneratorInterface::ABSOLUTE_URL);
	}
}
