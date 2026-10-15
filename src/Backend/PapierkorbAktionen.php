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
use Psr\Log\LoggerInterface;
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
 *
 * Die Meldung sagt, was wirklich geschehen ist: Eine Bestätigung gibt es nur,
 * wenn der Dienst etwas getan hat. War nichts zu tun (veralteter Link, die
 * Datei wurde schon von jemand anderem bearbeitet), erscheint ein Hinweis; ein
 * Löschfehler erscheint als Fehlermeldung statt als Fehlerseite.
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
	 * @param LoggerInterface        $protokoll    Contao-Systemprotokoll, für Löschfehler
	 */
	public function __construct(
		private readonly Papierkorb $papierkorb,
		private readonly Zugang $zugang,
		private readonly ContaoCsrfTokenManager $tokenManager,
		private readonly RequestStack $requestStack,
		private readonly UrlGeneratorInterface $router,
		private readonly string $tokenName,
		private readonly LoggerInterface $protokoll,
	) {
	}

	/**
	 * Legt die Datei eines Nachweises in den Papierkorb (Liste „Nachweise“).
	 *
	 * Die ID des Nachweises kommt aus dem Query-Parameter „id“. Hat der
	 * Nachweis keine Datei oder liegt sie schon im Papierkorb, ändert sich
	 * nichts, und die Maske zeigt einen Hinweis statt der Bestätigung.
	 *
	 * Seiteneffekte: setzt den Einwurfzeitpunkt am Nachweis (über den
	 * Papierkorb-Dienst), legt eine Meldung in die Sitzung und leitet um.
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Prüferfreigabe
	 * @throws RedirectResponseException                          Immer (Rücksprung zur Liste)
	 */
	public function inPapierkorb(): void
	{
		$request = $this->pruefeAnfrage();
		$erfolg = $this->papierkorb->hineinlegen((int) $request->query->get('id'), time());

		$this->zurueck($request, $erfolg ? 'inKorbGelegt' : 'nichtImKorb', $erfolg ? 'confirm' : 'info');
	}

	/**
	 * Holt eine Datei aus dem Papierkorb zurück.
	 *
	 * Die ID des Nachweises kommt aus dem Query-Parameter „id“. Liegt die
	 * Datei nicht (mehr) im Papierkorb, ändert sich nichts, und die Maske
	 * zeigt einen Hinweis statt der Bestätigung „wiederhergestellt“.
	 *
	 * Seiteneffekte: setzt den Einwurfzeitpunkt am Nachweis zurück (über den
	 * Papierkorb-Dienst), legt eine Meldung in die Sitzung und leitet um.
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Prüferfreigabe
	 * @throws RedirectResponseException                          Immer (Rücksprung zur Liste)
	 */
	public function wiederherstellen(): void
	{
		$request = $this->pruefeAnfrage();
		$erfolg = $this->papierkorb->wiederherstellen((int) $request->query->get('id'));

		$this->zurueck($request, $erfolg ? 'wiederhergestellt' : 'nichtsZuTun', $erfolg ? 'confirm' : 'info');
	}

	/**
	 * Löscht eine Datei im Papierkorb endgültig.
	 *
	 * Die ID des Nachweises kommt aus dem Query-Parameter „id“. Drei Ausgänge:
	 * Wurde gelöscht, gibt es die Bestätigung. Liegt die Datei nicht (mehr) im
	 * Papierkorb, ändert sich nichts, und es erscheint ein Hinweis. Lässt sich
	 * die Datei nicht löschen (der Dienst wirft dann eine RuntimeException,
	 * etwa wegen fehlender Dateirechte), erscheint eine Fehlermeldung, und der
	 * Datensatz bleibt unverändert im Papierkorb.
	 *
	 * Die Ausnahme wird hier abgefangen, damit der Prüfer eine Meldung in der
	 * gewohnten Liste sieht statt einer Fehlerseite. Der Text der Ausnahme
	 * enthält den Dateipfad; weder die Meldung noch der Protokolleintrag
	 * nennen ihn, denn das Systemprotokoll steht allen Administratoren offen.
	 * Protokolliert wird nur die Nachweis-ID.
	 *
	 * Seiteneffekte: löscht die Datei vom Datenträger und ändert den
	 * Datensatz (über den Papierkorb-Dienst), schreibt bei einem Fehler einen
	 * Eintrag ins Systemprotokoll, legt eine Meldung in die Sitzung und
	 * leitet um.
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Prüferfreigabe
	 * @throws RedirectResponseException                          Immer (Rücksprung zur Liste)
	 */
	public function loeschen(): void
	{
		$request = $this->pruefeAnfrage();
		$id = (int) $request->query->get('id');

		// Nur der Dienstaufruf steht im try: Die Umleitung von zurueck() ist
		// selbst eine RuntimeException und würde hier sonst verschluckt.
		try {
			$erfolg = $this->papierkorb->endgueltigLoeschen($id, time());
		} catch (\RuntimeException) {
			$this->protokoll->error(sprintf('Kinderschutz: Datei von Nachweis ID %d konnte nicht endgültig gelöscht werden', $id));

			$this->zurueck($request, 'loeschenFehlgeschlagen', 'error');
		}

		$this->zurueck($request, $erfolg ? 'geloescht' : 'nichtsZuTun', $erfolg ? 'confirm' : 'info');
	}

	/**
	 * Leert den ganzen Papierkorb.
	 *
	 * Der Dienst überspringt Dateien, die sich nicht löschen lassen, und
	 * protokolliert sie selbst; gemeldet wird die Zahl der tatsächlich
	 * gelöschten Dateien (auch 0 ist eine zutreffende Bestätigung).
	 *
	 * Seiteneffekte: löscht Dateien vom Datenträger und ändert Datensätze
	 * (über den Papierkorb-Dienst), legt eine Meldung in die Sitzung und
	 * leitet um.
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Prüferfreigabe
	 * @throws RedirectResponseException                          Immer (Rücksprung zur Liste)
	 */
	public function leeren(): void
	{
		$request = $this->pruefeAnfrage();
		$anzahl = $this->papierkorb->leeren(time());

		$this->zurueck($request, 'geleert', 'confirm', $anzahl);
	}

	/**
	 * Prüft Berechtigung und Anfragetoken, bevor eine Aktion etwas tut.
	 *
	 * Zuerst die Prüferfreigabe, dann das Token „rt“ aus der Adresse gegen
	 * den Token-Namen der Installation. Ist das Token ungültig oder fehlt, gibt
	 * es eine Fehlermeldung und die Rückkehr zur Liste; die Aktion läuft dann
	 * nicht.
	 *
	 * Seiteneffekte: legt bei ungültigem Token eine Fehlermeldung in die
	 * Sitzung.
	 *
	 * @return Request Die laufende Anfrage
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Berechtigung
	 * @throws RedirectResponseException                          Bei ungültigem Token (Rücksprung zur Liste)
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
	 * Der Text kommt aus MSC.kinderschutz_be; fehlt der Schlüssel, erscheint
	 * der Schlüssel selbst, damit der Fehler sichtbar wird. „%d“ im Text
	 * ersetzt $anzahl.
	 *
	 * Seiteneffekte: legt die Meldung in die Sitzung (Flash-Bag von Contao).
	 *
	 * @param Request $request    Die laufende Anfrage (liefert „do“)
	 * @param string  $schluessel Schlüssel unter kinderschutz_be
	 * @param string  $art        „confirm“ (Bestätigung), „info“ (Hinweis, es wurde
	 *                            nichts geändert) oder „error“ (Fehlermeldung)
	 * @param int     $anzahl     Zahl für Meldungen mit %d
	 *
	 * @throws RedirectResponseException Immer; Contao leitet damit um
	 */
	private function zurueck(Request $request, string $schluessel, string $art, int $anzahl = 0): never
	{
		$text = sprintf($GLOBALS['TL_LANG']['MSC']['kinderschutz_be'][$schluessel] ?? $schluessel, $anzahl);

		match ($art) {
			'error' => Message::addError($text),
			'info' => Message::addInfo($text),
			default => Message::addConfirmation($text),
		};

		throw new RedirectResponseException($this->router->generate('contao_backend', array('do' => (string) $request->query->get('do'))));
	}
}
