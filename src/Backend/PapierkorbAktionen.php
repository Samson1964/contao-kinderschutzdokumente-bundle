<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\Message;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Papierkorb;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * Die Papierkorb-Aktionen im Backend, als key-Callbacks aus
 * $GLOBALS['BE_MOD'] (do=…&key=…&id=…).
 *
 * Die Links kommen per GET. Deshalb prüft jede Aktion selbst das
 * Anfragetoken „rt“, das Contao (Backend::addToUrl) in 4.13 und 5.7 an die
 * Operationslinks hängt – sonst ließe sich eine Löschung über einen
 * untergeschobenen Link auslösen. Nach der Aktion geht es mit einer Meldung
 * zur Liste zurück.
 */
class PapierkorbAktionen
{
	/**
	 * @param Papierkorb             $papierkorb   Führt die Aktionen aus
	 * @param Zugang                 $zugang       Prüft die Berechtigung
	 * @param ContaoCsrfTokenManager $tokenManager Prüft das Anfragetoken
	 * @param RequestStack           $requestStack Liefert die Anfrage
	 * @param UrlGeneratorInterface  $router       Baut den Rücksprung
	 * @param string                 $tokenName    Parameter contao.csrf_token_name
	 */
	public function __construct(
		private readonly Papierkorb $papierkorb,
		private readonly Zugang $zugang,
		private readonly ContaoCsrfTokenManager $tokenManager,
		private readonly RequestStack $requestStack,
		private readonly UrlGeneratorInterface $router,
		private readonly string $tokenName,
	) {
	}

	/**
	 * Legt die Datei eines Nachweises in den Papierkorb (Liste „Nachweise“).
	 */
	public function inPapierkorb(): void
	{
		$request = $this->pruefeAnfrage();
		$erfolg = $this->papierkorb->hineinlegen((int) $request->query->get('id'), time());

		$this->zurueck($request, $erfolg ? 'inKorbGelegt' : 'nichtImKorb', $erfolg);
	}

	/**
	 * Holt eine Datei aus dem Papierkorb zurück.
	 */
	public function wiederherstellen(): void
	{
		$request = $this->pruefeAnfrage();
		$this->papierkorb->wiederherstellen((int) $request->query->get('id'));

		$this->zurueck($request, 'wiederhergestellt', true);
	}

	/**
	 * Löscht eine Datei im Papierkorb endgültig.
	 */
	public function loeschen(): void
	{
		$request = $this->pruefeAnfrage();
		$this->papierkorb->endgueltigLoeschen((int) $request->query->get('id'), time());

		$this->zurueck($request, 'geloescht', true);
	}

	/**
	 * Leert den ganzen Papierkorb.
	 */
	public function leeren(): void
	{
		$request = $this->pruefeAnfrage();
		$anzahl = $this->papierkorb->leeren(time());

		$this->zurueck($request, 'geleert', true, $anzahl);
	}

	/**
	 * Prüft Berechtigung und Anfragetoken.
	 *
	 * @return Request Die laufende Anfrage
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Berechtigung
	 */
	private function pruefeAnfrage(): Request
	{
		$this->zugang->verlange();
		$request = $this->requestStack->getCurrentRequest();

		if (null === $request || !$this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, (string) $request->query->get('rt')))) {
			Message::addError($GLOBALS['TL_LANG']['MSC']['kinderschutz_be']['ungueltigesToken'] ?? 'Ungültiges Anfragetoken.');

			throw new RedirectResponseException($this->router->generate('contao_backend', array('do' => (string) $request?->query->get('do'))));
		}

		return $request;
	}

	/**
	 * Kehrt mit einer Meldung zur Liste des Moduls zurück.
	 *
	 * @param Request $request   Die laufende Anfrage (liefert „do“)
	 * @param string  $schluessel Schlüssel unter kinderschutz_be
	 * @param bool    $erfolg    Bestätigung (true) oder Hinweis (false)
	 * @param int     $anzahl    Zahl für Meldungen mit %d
	 *
	 * @throws RedirectResponseException Immer; Contao leitet damit um
	 */
	private function zurueck(Request $request, string $schluessel, bool $erfolg, int $anzahl = 0): never
	{
		$text = sprintf($GLOBALS['TL_LANG']['MSC']['kinderschutz_be'][$schluessel] ?? $schluessel, $anzahl);

		$erfolg ? Message::addConfirmation($text) : Message::addInfo($text);

		throw new RedirectResponseException($this->router->generate('contao_backend', array('do' => (string) $request->query->get('do'))));
	}
}
