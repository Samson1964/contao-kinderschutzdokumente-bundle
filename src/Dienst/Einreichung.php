<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst;

use Psr\Log\LoggerInterface;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Ablage\Dateiablage;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\DokumentartRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Kalender;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\UploadPruefer;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Nimmt eine Einreichung aus dem Frontend entgegen.
 *
 * Ablauf: Dokumentart und Datum prüfen, Datei prüfen und ablegen, eine noch
 * ungeprüfte Einreichung derselben Art ersetzen (Datei in den Papierkorb),
 * Nachweis anlegen, Prüfer benachrichtigen.
 */
class Einreichung
{
	/**
	 * @param NachweisRepository    $nachweise        Zugriff auf tl_kinderschutz
	 * @param DokumentartRepository $arten            Die Dokumentarten
	 * @param PersonenRepository    $personen         Mitglied und Prüferadressen
	 * @param Dateiablage           $ablage           Speichert die Datei
	 * @param UploadPruefer         $pruefer          Prüft Typ und Größe
	 * @param Papierkorb            $papierkorb       Nimmt ersetzte Dateien auf
	 * @param Benachrichtigung      $benachrichtigung Mail an die Prüfer
	 * @param Berechtigung          $berechtigung     Kennt den Hauptadmin
	 * @param LoggerInterface       $protokoll        Contao-Systemprotokoll
	 */
	public function __construct(
		private readonly NachweisRepository $nachweise,
		private readonly DokumentartRepository $arten,
		private readonly PersonenRepository $personen,
		private readonly Dateiablage $ablage,
		private readonly UploadPruefer $pruefer,
		private readonly Papierkorb $papierkorb,
		private readonly Benachrichtigung $benachrichtigung,
		private readonly Berechtigung $berechtigung,
		private readonly LoggerInterface $protokoll,
	) {
	}

	/**
	 * Nimmt eine Datei entgegen.
	 *
	 * @param int          $mitglied          ID des angemeldeten Mitglieds
	 * @param int          $dokumentart       ID der Dokumentart aus dem Formular
	 * @param UploadedFile $datei             Die hochgeladene Datei
	 * @param int          $ausstellungsdatum Zeitstempel des Ausstellungstags,
	 *                                        0 wenn nicht lesbar
	 * @param int          $jetzt             Aktueller Zeitpunkt
	 *
	 * @return string|null Schlüssel der Fehlermeldung („art“, „datum“ oder
	 *                     einer aus dem UploadPruefer), oder null bei Erfolg
	 */
	public function einreichen(int $mitglied, int $dokumentart, UploadedFile $datei, int $ausstellungsdatum, int $jetzt): ?string
	{
		$art = null;

		foreach ($this->arten->aktive() as $aktive) {
			if ($aktive->id === $dokumentart) {
				$art = $aktive;
			}
		}

		if (null === $art) {
			return 'art';
		}

		if ($ausstellungsdatum <= 0 || $ausstellungsdatum > Kalender::tag($jetzt)) {
			return 'datum';
		}

		$fehler = $this->pruefer->fehler($datei->getPathname(), (int) $datei->getSize(), $datei->getError());

		if (null !== $fehler) {
			return $fehler;
		}

		$endung = (string) $this->pruefer->endung($datei->getPathname());
		$groesse = (int) $datei->getSize();
		// Nur zur Anzeige im Backend; gespeichert wird unter einem Zufallsnamen
		$originalname = mb_substr((string) preg_replace('/[^\pL\pN ()._-]+/u', '_', $datei->getClientOriginalName()), 0, 255);
		$relativ = $this->ablage->speichere($datei, $mitglied, $endung);

		foreach ($this->nachweise->wartende($mitglied, $dokumentart) as $alt) {
			$this->nachweise->aendern($alt, array('status' => Nachweis::ERSETZT, 'verarbeitet' => Nachweis::ERSETZT));
			$this->papierkorb->hineinlegen($alt, $jetzt);
		}

		$id = $this->nachweise->anlegen(array(
			'tstamp'            => $jetzt,
			'member'            => $mitglied,
			'dokumentart'       => $dokumentart,
			'status'            => Nachweis::EINGEREICHT,
			'verarbeitet'       => Nachweis::EINGEREICHT,
			'quelle'            => 'frontend',
			'datei'             => $relativ,
			'dateiname'         => $originalname,
			'dateityp'          => (string) array_search($endung, UploadPruefer::TYPEN, true),
			'dateigroesse'      => $groesse,
			'hochgeladenAm'     => $jetzt,
			'ausstellungsdatum' => Kalender::tag($ausstellungsdatum),
		));

		$this->protokoll->info(sprintf('Kinderschutz: Mitglied ID %d hat %s eingereicht (Nachweis ID %d)', $mitglied, $art->name, $id));

		$person = $this->personen->mitglied($mitglied);

		if (null !== $person) {
			$this->benachrichtigung->neueEinreichung($person, $art->name, $this->personen->prueferAdressen($this->berechtigung->hauptadmin()));
		}

		return null;
	}
}
