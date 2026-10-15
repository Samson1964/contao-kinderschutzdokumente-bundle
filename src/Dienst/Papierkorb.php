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
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Papierkorbfrist;

/**
 * Papierkorb für Dateien: hineinlegen, wiederherstellen, endgültig löschen.
 *
 * Der Papierkorb verschiebt keine Dateien, er setzt nur „papierkorbSeit“.
 * Endgültiges Löschen entfernt die Datei vom Datenträger, leert „datei“ und
 * setzt „dateiGeloeschtAm“; der Datensatz mit dem Prüfvermerk bleibt.
 * Jeder Schritt landet im Contao-Systemprotokoll.
 */
class Papierkorb
{
	/**
	 * @param NachweisRepository $nachweise     Zugriff auf tl_kinderschutz
	 * @param Dateiablage        $ablage        Die abgelegten Dateien
	 * @param Einstellungen      $einstellungen Liefert die Frist
	 * @param LoggerInterface    $protokoll     Contao-Systemprotokoll
	 */
	public function __construct(
		private readonly NachweisRepository $nachweise,
		private readonly Dateiablage $ablage,
		private readonly Einstellungen $einstellungen,
		private readonly LoggerInterface $protokoll,
	) {
	}

	/**
	 * Legt die Datei eines Nachweises in den Papierkorb.
	 *
	 * @param int $id    ID des Nachweises
	 * @param int $jetzt Aktueller Zeitpunkt
	 *
	 * @return bool true, wenn die Datei jetzt im Papierkorb liegt; false, wenn
	 *              es keine Datei gibt oder sie schon dort liegt
	 */
	public function hineinlegen(int $id, int $jetzt): bool
	{
		$zeile = $this->nachweise->zeile($id);

		if (null === $zeile || '' === (string) $zeile['datei'] || (int) $zeile['papierkorbSeit'] > 0) {
			return false;
		}

		$this->nachweise->aendern($id, array('papierkorbSeit' => $jetzt));
		$this->protokoll->info(sprintf('Kinderschutz: Datei von Nachweis ID %d in den Papierkorb gelegt', $id));

		return true;
	}

	/**
	 * Holt eine Datei aus dem Papierkorb zurück.
	 *
	 * @param int $id ID des Nachweises
	 *
	 * @return bool true, wenn die Datei wieder sichtbar ist
	 */
	public function wiederherstellen(int $id): bool
	{
		$zeile = $this->nachweise->zeile($id);

		if (null === $zeile || '' === (string) $zeile['datei'] || 0 === (int) $zeile['papierkorbSeit']) {
			return false;
		}

		$this->nachweise->aendern($id, array('papierkorbSeit' => 0));
		$this->protokoll->info(sprintf('Kinderschutz: Datei von Nachweis ID %d aus dem Papierkorb wiederhergestellt', $id));

		return true;
	}

	/**
	 * Löscht die Datei eines Nachweises im Papierkorb endgültig.
	 *
	 * @param int $id    ID des Nachweises
	 * @param int $jetzt Aktueller Zeitpunkt
	 *
	 * @return bool true, wenn eine Datei gelöscht wurde; false, wenn der
	 *              Nachweis keine Datei im Papierkorb hat
	 *
	 * @throws \RuntimeException Wenn die Datei sich nicht löschen lässt. Der
	 *                           Datensatz bleibt dann unverändert. Nur die
	 *                           Sammelläufe (leeren(), faelligeLoeschen())
	 *                           fangen das je Eintrag ab.
	 */
	public function endgueltigLoeschen(int $id, int $jetzt): bool
	{
		$zeile = $this->nachweise->zeile($id);

		if (null === $zeile || '' === (string) $zeile['datei'] || 0 === (int) $zeile['papierkorbSeit']) {
			return false;
		}

		$this->ablage->loesche((string) $zeile['datei']);
		$this->nachweise->aendern($id, array('datei' => '', 'papierkorbSeit' => 0, 'dateiGeloeschtAm' => $jetzt));
		$this->protokoll->info(sprintf('Kinderschutz: Datei von Nachweis ID %d endgültig gelöscht', $id));

		return true;
	}

	/**
	 * Leert den ganzen Papierkorb.
	 *
	 * Dateien, die sich nicht löschen lassen, werden übersprungen und
	 * protokolliert (siehe loescheAlle()).
	 *
	 * @param int $jetzt Aktueller Zeitpunkt
	 *
	 * @return int Anzahl tatsächlich gelöschter Dateien
	 */
	public function leeren(int $jetzt): int
	{
		return $this->loescheAlle($this->nachweise->imPapierkorb(), $jetzt);
	}

	/**
	 * Löscht alle Dateien, deren Frist abgelaufen ist (täglicher Cronjob).
	 *
	 * Dateien, die sich nicht löschen lassen, werden übersprungen und
	 * protokolliert (siehe loescheAlle()); der nächste Lauf versucht sie erneut.
	 *
	 * @param int $jetzt Aktueller Zeitpunkt
	 *
	 * @return int Anzahl tatsächlich gelöschter Dateien
	 */
	public function faelligeLoeschen(int $jetzt): int
	{
		$frist = new Papierkorbfrist($this->einstellungen->papierkorbTage());

		return $this->loescheAlle($this->nachweise->imPapierkorb($frist->grenze($jetzt)), $jetzt);
	}

	/**
	 * Liefert den Tag, an dem eine Datei im Papierkorb gelöscht wird.
	 *
	 * @param int $papierkorbSeit Zeitpunkt des Einwurfs
	 *
	 * @return int Zeitstempel des Tags, 0:00 Uhr
	 */
	public function loeschtAm(int $papierkorbSeit): int
	{
		return (new Papierkorbfrist($this->einstellungen->papierkorbTage()))->loeschtAm($papierkorbSeit);
	}

	/**
	 * Legt alle Dateien eines gelöschten Mitglieds in den Papierkorb.
	 *
	 * @param int $mitglied ID des Mitglieds
	 * @param int $jetzt    Aktueller Zeitpunkt
	 *
	 * @return int Anzahl der Dateien
	 */
	public function mitgliedEntfernt(int $mitglied, int $jetzt): int
	{
		$anzahl = 0;

		foreach ($this->nachweise->mitDatei($mitglied) as $id) {
			$anzahl += $this->hineinlegen($id, $jetzt) ? 1 : 0;
		}

		return $anzahl;
	}

	/**
	 * Löscht eine Liste von Dateien endgültig.
	 *
	 * Die Schleife ist gegen Fehler einzelner Dateien geschützt: Lässt sich
	 * eine Datei nicht löschen (die Ablage wirft dann eine RuntimeException,
	 * etwa bei einer gesperrten Datei unter Windows), wird das für diesen
	 * Eintrag protokolliert und mit dem nächsten weitergemacht. Sonst bliebe
	 * ein einziger hängender Eintrag Nacht für Nacht vorn in der Liste, und
	 * der Cronjob käme an die dahinter nie heran. Der gescheiterte Eintrag
	 * bleibt unverändert im Papierkorb und ist beim nächsten Lauf wieder dran.
	 *
	 * Seiteneffekte: löscht Dateien vom Datenträger, ändert die Datensätze
	 * (siehe endgueltigLoeschen()) und schreibt je Fehler einen Eintrag ins
	 * Systemprotokoll. Der Eintrag nennt nur die Nachweis-ID, weder den Pfad
	 * noch den Text der Ausnahme, denn der enthält den Pfad. Andere Fehler
	 * als eine RuntimeException (etwa der Datenbank) werden nicht verschluckt.
	 *
	 * @param list<int> $ids   IDs der Nachweise
	 * @param int       $jetzt Aktueller Zeitpunkt
	 *
	 * @return int Anzahl tatsächlich gelöschter Dateien, ohne die gescheiterten
	 */
	private function loescheAlle(array $ids, int $jetzt): int
	{
		$anzahl = 0;

		foreach ($ids as $id) {
			try {
				$anzahl += $this->endgueltigLoeschen($id, $jetzt) ? 1 : 0;
			} catch (\RuntimeException) {
				$this->protokoll->error(sprintf('Kinderschutz: Datei von Nachweis ID %d konnte nicht endgültig gelöscht werden', $id));
			}
		}

		return $anzahl;
	}
}
