<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst;

use Contao\BackendUser;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Wendet die Berechtigung auf den angemeldeten Backend-Benutzer an.
 *
 * Jede Tür des Bundles (Menü, DCA, Übersicht, Papierkorb, Datei-Route)
 * fragt hier. Das Admin-Kennzeichen von Contao spielt keine Rolle.
 */
class Zugang
{
	/**
	 * @param TokenStorageInterface $tokenStorage Liefert den angemeldeten Benutzer
	 * @param Berechtigung          $berechtigung Die Regel
	 */
	public function __construct(
		private readonly TokenStorageInterface $tokenStorage,
		private readonly Berechtigung $berechtigung,
	) {
	}

	/**
	 * Liefert die ID des angemeldeten Backend-Benutzers.
	 *
	 * @return int ID in tl_user, 0 ohne Backend-Anmeldung
	 */
	public function benutzerId(): int
	{
		$benutzer = $this->benutzer();

		return null === $benutzer ? 0 : (int) $benutzer->id;
	}

	/**
	 * Sagt, ob der angemeldete Benutzer prüfen darf.
	 *
	 * @return bool true für Hauptadmin und freigegebene Prüfer
	 */
	public function darfPruefen(): bool
	{
		$benutzer = $this->benutzer();

		return null !== $benutzer && $this->berechtigung->darfPruefen((int) $benutzer->id, '1' === (string) $benutzer->kinderschutzPruefer);
	}

	/**
	 * Sagt, ob der angemeldete Benutzer der Hauptadmin ist.
	 *
	 * @return bool true nur für den Hauptadmin
	 */
	public function istHauptadmin(): bool
	{
		return $this->berechtigung->istHauptadmin($this->benutzerId());
	}

	/**
	 * Bricht ab, wenn der angemeldete Benutzer nicht prüfen darf.
	 *
	 * @throws AccessDeniedException Wird von Contao als „Zugriff verweigert“
	 *                               (HTTP 403) angezeigt
	 */
	public function verlange(): void
	{
		if (!$this->darfPruefen()) {
			throw new AccessDeniedException('Kein Zugang zu den Kinderschutz-Unterlagen.');
		}
	}

	/**
	 * Liefert den angemeldeten Backend-Benutzer.
	 *
	 * @return BackendUser|null Der Benutzer, oder null im Frontend und ohne Anmeldung
	 */
	private function benutzer(): ?BackendUser
	{
		$benutzer = $this->tokenStorage->getToken()?->getUser();

		return $benutzer instanceof BackendUser ? $benutzer : null;
	}
}
