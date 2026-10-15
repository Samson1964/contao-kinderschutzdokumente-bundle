<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\DataContainer;
use Contao\Message;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Verhindert das Löschen einer Dokumentart, zu der es Nachweise gibt.
 *
 * Sonst stünden Prüfvermerke ohne Art in der Datenbank; deaktivieren
 * („published“ aus) erfüllt denselben Zweck ohne Datenverlust.
 */
class DokumentartListener
{
	/**
	 * @param NachweisRepository    $nachweise Zählt die Nachweise
	 * @param UrlGeneratorInterface $router    Baut den Rücksprung
	 */
	public function __construct(
		private readonly NachweisRepository $nachweise,
		private readonly UrlGeneratorInterface $router,
	) {
	}

	/**
	 * Bricht das Löschen mit einer Meldung ab, wenn es Nachweise gibt.
	 *
	 * @param DataContainer $dc Der Data Container
	 *
	 * @throws RedirectResponseException Wenn es Nachweise zu dieser Art gibt
	 */
	#[AsCallback(table: 'tl_kinderschutz_dokumentart', target: 'config.ondelete')]
	public function __invoke(DataContainer $dc): void
	{
		if (0 === $this->nachweise->anzahlFuerArt((int) $dc->id)) {
			return;
		}

		Message::addError($GLOBALS['TL_LANG']['MSC']['kinderschutz_be']['artGesperrt']);

		throw new RedirectResponseException($this->router->generate('contao_backend', array('do' => 'kinderschutz_dokumentarten')));
	}
}
