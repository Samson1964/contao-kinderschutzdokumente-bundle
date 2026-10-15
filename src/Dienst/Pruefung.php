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
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\DokumentartRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Gueltigkeit;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;

/**
 * Wertet das Speichern der Prüfmaske aus.
 *
 * Contao speichert die Felder selbst; danach ruft der onsubmit_callback
 * nachSpeichern() auf. Die Spalte „verarbeitet“ merkt sich, für welchen
 * Status die Folgen (Datum, Papierkorb, Mail) schon gezogen wurden. So löst
 * erneutes Speichern derselben Entscheidung keine zweite Mail aus, eine
 * geänderte Entscheidung aber schon.
 */
class Pruefung
{
	/**
	 * @param NachweisRepository    $nachweise        Zugriff auf tl_kinderschutz
	 * @param DokumentartRepository $arten            Gültigkeit und Papierkorbregel
	 * @param PersonenRepository    $personen         Empfänger der Ablehnung
	 * @param Papierkorb            $papierkorb       Nimmt geprüfte EFZ und
	 *                                                abgelehnte Dateien auf
	 * @param Benachrichtigung      $benachrichtigung Mail bei Ablehnung
	 * @param LoggerInterface       $protokoll        Contao-Systemprotokoll
	 */
	public function __construct(
		private readonly NachweisRepository $nachweise,
		private readonly DokumentartRepository $arten,
		private readonly PersonenRepository $personen,
		private readonly Papierkorb $papierkorb,
		private readonly Benachrichtigung $benachrichtigung,
		private readonly LoggerInterface $protokoll,
	) {
	}

	/**
	 * Prüft die Eingaben der Prüfmaske vor dem Speichern.
	 *
	 * @param string $status             Gewählter Status
	 * @param string $grund              Eingegebener Ablehnungsgrund
	 * @param string $einschlaegig       '', 'nein' oder 'ja'
	 * @param bool   $einschlaegigNoetig Die Dokumentart fragt einschlägige Einträge ab
	 *
	 * @return string|null Schlüssel der Fehlermeldung („grundFehlt“,
	 *                     „einschlaegigFehlt“), oder null
	 */
	public static function eingabefehler(string $status, string $grund, string $einschlaegig, bool $einschlaegigNoetig): ?string
	{
		if (Nachweis::ABGELEHNT === $status && '' === trim($grund)) {
			return 'grundFehlt';
		}

		if (Nachweis::GEPRUEFT === $status && $einschlaegigNoetig && !in_array($einschlaegig, array('ja', 'nein'), true)) {
			return 'einschlaegigFehlt';
		}

		return null;
	}

	/**
	 * Zieht nach dem Speichern die Folgen der Entscheidung.
	 *
	 * Immer: „gültig bis“ neu berechnen (das Ausstellungsdatum kann korrigiert
	 * worden sein). Bei einem neuen Status zusätzlich: Entscheidungszeitpunkt
	 * und Prüfer setzen, bei „geprüft“ die Datei in den Papierkorb, wenn die
	 * Dokumentart das verlangt, bei „abgelehnt“ immer, plus Mail an die Person.
	 *
	 * @param int $id       ID des Nachweises
	 * @param int $benutzer ID des Prüfers in tl_user
	 * @param int $jetzt    Aktueller Zeitpunkt
	 */
	public function nachSpeichern(int $id, int $benutzer, int $jetzt): void
	{
		$zeile = $this->nachweise->zeile($id);
		$art = null === $zeile ? null : $this->arten->eine((int) $zeile['dokumentart']);

		if (null === $zeile || null === $art) {
			return;
		}

		$status = (string) $zeile['status'];
		$neu = $status !== (string) $zeile['verarbeitet'];
		$werte = array(
			'gueltigBis' => Nachweis::GEPRUEFT === $status ? Gueltigkeit::bis((int) $zeile['ausstellungsdatum'], $art->gueltigkeit) : null,
		);

		if ($neu) {
			$entschieden = in_array($status, array(Nachweis::GEPRUEFT, Nachweis::ABGELEHNT), true);
			$werte['verarbeitet'] = $status;
			$werte['geprueftAm'] = $entschieden ? $jetzt : 0;
			$werte['geprueftVon'] = $entschieden ? $benutzer : 0;
		}

		$this->nachweise->aendern($id, $werte);

		if (!$neu) {
			return;
		}

		if (Nachweis::GEPRUEFT === $status) {
			$this->protokoll->info(sprintf('Kinderschutz: Nachweis ID %d (%s) als geprüft vermerkt', $id, $art->name));

			if ($art->papierkorbNachPruefung) {
				$this->papierkorb->hineinlegen($id, $jetzt);
			}
		}

		if (Nachweis::ABGELEHNT === $status) {
			$this->protokoll->info(sprintf('Kinderschutz: Nachweis ID %d (%s) abgelehnt', $id, $art->name));
			$this->papierkorb->hineinlegen($id, $jetzt);
			$person = $this->personen->mitglied((int) $zeile['member']);

			if (null !== $person) {
				$this->benachrichtigung->abgelehnt($person, $art->name, (string) $zeile['ablehnungsgrund']);
			}
		}
	}
}
