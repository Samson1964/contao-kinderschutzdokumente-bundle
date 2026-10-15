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
	 * Immer: „gültig bis“ neu berechnen, weil der Prüfer das Ausstellungsdatum
	 * korrigiert haben kann. Gerechnet wird mit der Monatszahl, die der
	 * Nachweis beim Prüfen festgehalten hat (Spalte „gueltigkeit“). Ändert
	 * jemand später die Monatszahl der Dokumentart, wirkt das nur auf neue
	 * Entscheidungen und rührt bestehende Fristen nicht an.
	 *
	 * Bei einem neuen Status (er unterscheidet sich von „verarbeitet“)
	 * zusätzlich:
	 *
	 * - Der Status wird als verarbeitet vermerkt; bei „geprüft“ und
	 *   „abgelehnt“ kommen Entscheidungszeitpunkt und Prüfer hinzu, bei
	 *   „eingereicht“ werden beide wieder geleert.
	 * - „geprüft“ übernimmt die aktuelle Monatszahl der Dokumentart in den
	 *   Nachweis. Die Datei kommt in den Papierkorb, wenn die Dokumentart das
	 *   verlangt, sonst wird sie aus dem Papierkorb geholt.
	 * - „abgelehnt“ legt die Datei bei jeder Art in den Papierkorb und
	 *   schickt der Person eine Mail mit dem Ablehnungsgrund.
	 * - „eingereicht“ (ein Nachweis wird zurückgestuft) holt die Datei aus
	 *   dem Papierkorb, damit die Frist des Cronjobs eine Einreichung nicht
	 *   löscht, die wieder auf Prüfung wartet.
	 *
	 * Seiteneffekte: schreibt in tl_kinderschutz, setzt oder löst den
	 * Papierkorbvermerk, schreibt ins Systemprotokoll und verschickt bei einer
	 * neuen Ablehnung eine Mail. Die Entscheidung ist auch dann gespeichert,
	 * wenn die Mail scheitert; sie wird nicht erneut versucht, weil
	 * „verarbeitet“ schon auf „abgelehnt“ steht. Darum meldet der
	 * Rückgabewert den Misserfolg, und der Aufrufer weist den Prüfer darauf
	 * hin.
	 *
	 * @param int $id       ID des Nachweises
	 * @param int $benutzer ID des Prüfers in tl_user
	 * @param int $jetzt    Aktueller Zeitpunkt
	 *
	 * @return bool false genau dann, wenn bei einem neuen Status „abgelehnt“
	 *              die Mail an die Person nicht verschickt wurde (Versand
	 *              gescheitert oder Mitglied nicht mehr vorhanden); in allen
	 *              anderen Fällen true, auch wenn es nichts zu tun gab
	 */
	public function nachSpeichern(int $id, int $benutzer, int $jetzt): bool
	{
		$zeile = $this->nachweise->zeile($id);
		$art = null === $zeile ? null : $this->arten->eine((int) $zeile['dokumentart']);

		if (null === $zeile || null === $art) {
			return true;
		}

		$status = (string) $zeile['status'];
		$neu = $status !== (string) $zeile['verarbeitet'];
		$monate = (int) $zeile['gueltigkeit'];
		$werte = array();

		if ($neu) {
			$entschieden = in_array($status, array(Nachweis::GEPRUEFT, Nachweis::ABGELEHNT), true);
			$werte['verarbeitet'] = $status;
			$werte['geprueftAm'] = $entschieden ? $jetzt : 0;
			$werte['geprueftVon'] = $entschieden ? $benutzer : 0;

			// Erst eine neue Entscheidung „geprüft“ übernimmt die Monatszahl der Art
			if (Nachweis::GEPRUEFT === $status) {
				$monate = $art->gueltigkeit;
				$werte['gueltigkeit'] = $monate;
			}
		}

		$werte['gueltigBis'] = Nachweis::GEPRUEFT === $status ? Gueltigkeit::bis((int) $zeile['ausstellungsdatum'], $monate) : null;
		$this->nachweise->aendern($id, $werte);

		if (!$neu) {
			return true;
		}

		$mailGesendet = true;

		if (Nachweis::GEPRUEFT === $status) {
			$this->protokoll->info(sprintf('Kinderschutz: Nachweis ID %d (%s) als geprüft vermerkt', $id, $art->name));

			if ($art->papierkorbNachPruefung) {
				$this->papierkorb->hineinlegen($id, $jetzt);
			} else {
				// Lag die Datei nach einer früheren Ablehnung im Papierkorb, kommt sie zurück
				$this->papierkorb->wiederherstellen($id);
			}
		}

		if (Nachweis::ABGELEHNT === $status) {
			$this->protokoll->info(sprintf('Kinderschutz: Nachweis ID %d (%s) abgelehnt', $id, $art->name));
			$this->papierkorb->hineinlegen($id, $jetzt);
			$person = $this->personen->mitglied((int) $zeile['member']);
			$mailGesendet = null !== $person && $this->benachrichtigung->abgelehnt($person, $art->name, (string) $zeile['ablehnungsgrund']);
		}

		if (Nachweis::EINGEREICHT === $status) {
			$this->papierkorb->wiederherstellen($id);
		}

		return $mailGesendet;
	}
}
