<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln;

/**
 * Berechnet aus den Nachweisen einer Person den Stand je Dokumentart.
 *
 * Die Regeln stehen in der Spezifikation unter „Statuslogik“ und greifen in
 * fester Reihenfolge: beanstandet, gültig/läuft bald ab, eingereicht,
 * abgelehnt, abgelaufen, fehlt. Ersetzte Nachweise zählen nie mit.
 */
final class StatusRechner
{
	/**
	 * @param int $vorlaufTage Erinnerungsvorlauf in Tagen; läuft ein gültiger
	 *                         Nachweis innerhalb dieser Frist nach dem Stichtag
	 *                         ab, heißt der Stand „läuft bald ab“
	 */
	public function __construct(private readonly int $vorlaufTage)
	{
	}

	/**
	 * Liefert den maßgeblichen Nachweis: den geprüften mit dem jüngsten
	 * Ausstellungsdatum.
	 *
	 * Bei gleichem Ausstellungsdatum gewinnt der zuletzt geprüfte, danach die
	 * höhere ID. Ein älterer, länger gültiger Nachweis verdrängt also nie
	 * einen jüngeren – wichtig, wenn das jüngere EFZ einschlägige Einträge hat.
	 *
	 * @param list<Nachweis> $nachweise Nachweise einer Person zu einer Art
	 *
	 * @return Nachweis|null Der maßgebliche Nachweis, oder null, wenn keiner
	 *                       geprüft ist
	 */
	public static function massgeblich(array $nachweise): ?Nachweis
	{
		$bester = null;

		foreach ($nachweise as $nachweis) {
			if (Nachweis::GEPRUEFT !== $nachweis->status) {
				continue;
			}

			if (null === $bester || array($nachweis->ausstellungsdatum, $nachweis->geprueftAm, $nachweis->id) > array($bester->ausstellungsdatum, $bester->geprueftAm, $bester->id)) {
				$bester = $nachweis;
			}
		}

		return $bester;
	}

	/**
	 * Liefert die jüngste Einreichung, die auf Prüfung wartet.
	 *
	 * @param list<Nachweis> $nachweise Nachweise einer Person zu einer Art
	 *
	 * @return Nachweis|null Die wartende Einreichung mit der höchsten ID, oder null
	 */
	public static function wartend(array $nachweise): ?Nachweis
	{
		$juengster = null;

		foreach ($nachweise as $nachweis) {
			if (Nachweis::EINGEREICHT === $nachweis->status && (null === $juengster || $nachweis->id > $juengster->id)) {
				$juengster = $nachweis;
			}
		}

		return $juengster;
	}

	/**
	 * Berechnet den Stand einer Person zu einer Dokumentart.
	 *
	 * @param list<Nachweis> $nachweise Alle Nachweise der Person zu dieser Art,
	 *                                  in beliebiger Reihenfolge
	 * @param int            $stichtag  Zeitstempel des Stichtags, 0:00 Uhr
	 *
	 * @return Zellstatus Der Stand samt maßgeblichem und wartendem Nachweis
	 */
	public function zelle(array $nachweise, int $stichtag): Zellstatus
	{
		$nachweise = array_values(array_filter(
			$nachweise,
			static fn (Nachweis $nachweis): bool => Nachweis::ERSETZT !== $nachweis->status
		));

		$massgeblich = self::massgeblich($nachweise);
		$wartend = self::wartend($nachweise);
		$letzter = null;

		foreach ($nachweise as $nachweis) {
			if (null === $letzter || $nachweis->id > $letzter->id) {
				$letzter = $nachweis;
			}
		}

		if (null !== $massgeblich && 'ja' === $massgeblich->einschlaegig) {
			return new Zellstatus(Zellstatus::BEANSTANDET, $massgeblich, $wartend, $letzter);
		}

		if (null !== $massgeblich && (null === $massgeblich->gueltigBis || $massgeblich->gueltigBis >= $stichtag)) {
			$laeuftAb = null !== $massgeblich->gueltigBis && $massgeblich->gueltigBis < Kalender::plusTage($stichtag, $this->vorlaufTage);

			return new Zellstatus($laeuftAb ? Zellstatus::LAEUFT_AB : Zellstatus::GUELTIG, $massgeblich, $wartend, $letzter);
		}

		if (null !== $wartend) {
			return new Zellstatus(Zellstatus::EINGEREICHT, $massgeblich, $wartend, $letzter);
		}

		if (null !== $letzter && Nachweis::ABGELEHNT === $letzter->status) {
			return new Zellstatus(Zellstatus::ABGELEHNT, $massgeblich, null, $letzter);
		}

		if (null !== $massgeblich) {
			return new Zellstatus(Zellstatus::ABGELAUFEN, $massgeblich, null, $letzter);
		}

		return new Zellstatus(Zellstatus::FEHLT, null, null, $letzter);
	}

	/**
	 * Berechnet die Stände einer Person zu allen übergebenen Dokumentarten.
	 *
	 * @param list<Dokumentart>          $arten     Die aktiven Dokumentarten
	 * @param array<int, list<Nachweis>> $nachweise Nachweise der Person, nach
	 *                                              Dokumentart-ID gruppiert
	 * @param int                        $stichtag  Zeitstempel des Stichtags
	 *
	 * @return array<int, Zellstatus> Stand je Dokumentart-ID, in der
	 *                                Reihenfolge von $arten
	 */
	public function zeile(array $arten, array $nachweise, int $stichtag): array
	{
		$zellen = array();

		foreach ($arten as $art) {
			$zellen[$art->id] = $this->zelle($nachweise[$art->id] ?? array(), $stichtag);
		}

		return $zellen;
	}

	/**
	 * Liefert die Pflicht-Dokumentarten, die am Stichtag nicht gültig sind.
	 *
	 * @param list<Dokumentart>      $arten  Die aktiven Dokumentarten
	 * @param array<int, Zellstatus> $zellen Stand je Dokumentart-ID aus zeile()
	 *
	 * @return list<Dokumentart> Die fehlenden Pflichtarten; leer heißt vollständig
	 */
	public function fehlend(array $arten, array $zellen): array
	{
		$fehlend = array();

		foreach ($arten as $art) {
			if ($art->pflicht && !(isset($zellen[$art->id]) && $zellen[$art->id]->istGueltig())) {
				$fehlend[] = $art;
			}
		}

		return $fehlend;
	}

	/**
	 * Sagt, ob eine Person am Stichtag vollständig ist.
	 *
	 * @param list<Dokumentart>      $arten  Die aktiven Dokumentarten
	 * @param array<int, Zellstatus> $zellen Stand je Dokumentart-ID aus zeile()
	 *
	 * @return bool true, wenn jede Pflichtart „gültig“ oder „läuft bald ab“ ist
	 */
	public function vollstaendig(array $arten, array $zellen): bool
	{
		return array() === $this->fehlend($arten, $zellen);
	}

	/**
	 * Liefert den Stichtag einer Veranstaltung: ihren letzten Tag.
	 *
	 * Die Unterlagen müssen die ganze Veranstaltung über gelten. Ohne Ende
	 * zählt der Beginn.
	 *
	 * @param int $beginn Zeitstempel des ersten Tags
	 * @param int $ende   Zeitstempel des letzten Tags, 0 = nicht gesetzt
	 *
	 * @return int Zeitstempel des Stichtags, 0:00 Uhr
	 */
	public static function stichtagVeranstaltung(int $beginn, int $ende): int
	{
		return Kalender::tag($ende > 0 ? $ende : $beginn);
	}
}
