<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Controller;

use Psr\Log\LoggerInterface;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Ablage\Dateiablage;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Liefert eine hochgeladene Datei an einen Prüfer aus (Route kinderschutz_datei).
 *
 * Die Datei liegt außerhalb des Webroots; nur dieser Controller kommt an sie
 * heran. Er prüft die Berechtigung selbst, sendet „inline“ für die Vorschau,
 * verbietet jedes Zwischenspeichern und protokolliert jeden Abruf. Das
 * Protokoll nennt nur IDs, keine Namen, weil das Systemprotokoll allen
 * Administratoren offensteht.
 */
class DateiController
{
	/**
	 * @param Zugang             $zugang    Prüft die Berechtigung
	 * @param NachweisRepository $nachweise Liefert den Datensatz
	 * @param Dateiablage        $ablage    Liefert den Pfad
	 * @param LoggerInterface    $protokoll Contao-Systemprotokoll
	 */
	public function __construct(
		private readonly Zugang $zugang,
		private readonly NachweisRepository $nachweise,
		private readonly Dateiablage $ablage,
		private readonly LoggerInterface $protokoll,
	) {
	}

	/**
	 * Gibt die Datei eines Nachweises aus.
	 *
	 * Auch Dateien im Papierkorb werden ausgeliefert, damit der Prüfer vor dem
	 * Wiederherstellen hineinsehen kann.
	 *
	 * @param int $id ID des Nachweises
	 *
	 * @return Response Die Datei
	 *
	 * @throws \Contao\CoreBundle\Exception\AccessDeniedException Ohne Berechtigung
	 * @throws NotFoundHttpException                              Ohne Datei
	 */
	public function __invoke(int $id): Response
	{
		$this->zugang->verlange();

		$zeile = $this->nachweise->zeile($id);
		$pfad = null === $zeile ? null : $this->ablage->pfad((string) $zeile['datei']);

		if (null === $pfad) {
			throw new NotFoundHttpException('Keine Datei zu diesem Nachweis.');
		}

		$this->protokoll->info(sprintf('Kinderschutz: Datei von Nachweis ID %d (Mitglied ID %d) angesehen', $id, (int) $zeile['member']));

		$antwort = new BinaryFileResponse($pfad);
		$antwort->headers->set('Content-Type', (string) $zeile['dateityp']);
		$antwort->headers->set('X-Content-Type-Options', 'nosniff');
		$antwort->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'nachweis-'.$id.'.'.pathinfo($pfad, PATHINFO_EXTENSION));
		$antwort->setPrivate();
		$antwort->headers->addCacheControlDirective('no-store');

		return $antwort;
	}
}
