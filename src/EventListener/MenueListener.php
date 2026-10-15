<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener;

use Contao\CoreBundle\Event\MenuEvent;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Blendet die Menügruppe „Kinderschutz“ für Unberechtigte aus.
 *
 * Die Module tragen „disablePermissionChecks“, damit die Prüferfreigabe
 * ohne Gruppenrechte genügt. Dadurch baut Contao die Gruppe für jeden
 * Backend-Benutzer ins Menü. Dieser Listener läuft nach Contaos eigenem
 * (Priorität 10, 4.13 wie 5.7) und nimmt sie wieder heraus.
 */
#[AsEventListener(priority: -10)]
class MenueListener
{
	/**
	 * @param Zugang $zugang Die Zugangsprüfung
	 */
	public function __construct(private readonly Zugang $zugang)
	{
	}

	/**
	 * Entfernt die Gruppe aus dem Hauptmenü.
	 *
	 * @param MenuEvent $event Das Menü-Ereignis; auch für das Kopfzeilenmenü,
	 *                         das hier nicht interessiert
	 */
	public function __invoke(MenuEvent $event): void
	{
		$baum = $event->getTree();

		if ('mainMenu' !== $baum->getName() || $this->zugang->darfPruefen()) {
			return;
		}

		$baum->removeChild('kinderschutz');
	}
}
