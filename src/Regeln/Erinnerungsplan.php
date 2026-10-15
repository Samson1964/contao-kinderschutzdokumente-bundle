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
 * Entscheidet, welche Erinnerungsmail heute fällig ist.
 *
 * Die Merker erinnertVorAblauf und erinnertAblauf am Nachweis bzw. erinnertAm
 * an der Teilnahme verhindern doppelten Versand. Wer schon eine neue
 * Einreichung abgegeben hat, wird nicht mehr erinnert.
 */
final class Erinnerungsplan
{
	/** Mail „läuft bald ab“ */
	public const VOR_ABLAUF = 'vorAblauf';

	/** Mail „ist abgelaufen“ */
	public const ABLAUF = 'ablauf';

	/** Wie viele Tage nach dem Ablauf die Ablaufmail höchstens noch geht */
	public const NACHLAUF_TAGE = 14;

	/**
	 * @param int $vorlaufAblaufTage        Tage vor dem Ablauf für die erste Mail
	 * @param int $vorlaufVeranstaltungTage Tage vor Veranstaltungsbeginn für die
	 *                                      Mail über fehlende Unterlagen
	 */
	public function __construct(
		private readonly int $vorlaufAblaufTage,
		private readonly int $vorlaufVeranstaltungTage,
	) {
	}

	/**
	 * Prüft, ob zu einer Person und Dokumentart eine Ablaufmail fällig ist.
	 *
	 * @param list<Nachweis> $nachweise Alle Nachweise der Person zu dieser Art
	 * @param int            $heute     Zeitstempel des heutigen Tags, 0:00 Uhr
	 *
	 * @return array{0: string, 1: Nachweis}|null Art der Mail (VOR_ABLAUF oder
	 *                                            ABLAUF) und der Nachweis, an dem
	 *                                            der Versand vermerkt wird; null,
	 *                                            wenn nichts fällig ist
	 */
	public function ablauf(array $nachweise, int $heute): ?array
	{
		$massgeblich = StatusRechner::massgeblich($nachweise);

		if (null === $massgeblich || null === $massgeblich->gueltigBis || 'ja' === $massgeblich->einschlaegig) {
			return null;
		}

		// Wer schon erneuert hat, braucht keine Erinnerung
		if (null !== StatusRechner::wartend($nachweise)) {
			return null;
		}

		if ($heute > $massgeblich->gueltigBis) {
			$letzterVersandtag = Kalender::plusTage($massgeblich->gueltigBis, 1 + self::NACHLAUF_TAGE);

			if (0 === $massgeblich->erinnertAblauf && $heute <= $letzterVersandtag) {
				return array(self::ABLAUF, $massgeblich);
			}

			return null;
		}

		if (0 === $massgeblich->erinnertVorAblauf && $massgeblich->gueltigBis < Kalender::plusTage($heute, $this->vorlaufAblaufTage)) {
			return array(self::VOR_ABLAUF, $massgeblich);
		}

		return null;
	}

	/**
	 * Prüft, ob die Mail über fehlende Unterlagen vor einer Veranstaltung fällig ist.
	 *
	 * Ob der Person tatsächlich etwas fehlt, entscheidet der Aufrufer mit dem
	 * StatusRechner; hier geht es nur um den Zeitpunkt.
	 *
	 * @param int $beginn     Zeitstempel des ersten Veranstaltungstags
	 * @param int $heute      Zeitstempel des heutigen Tags, 0:00 Uhr
	 * @param int $erinnertAm Merker an der Teilnahme, 0 = noch nicht verschickt
	 *
	 * @return bool true ab dem Vorlauf bis zum Vortag des Beginns, einmal
	 */
	public function veranstaltungFaellig(int $beginn, int $heute, int $erinnertAm): bool
	{
		$beginn = Kalender::tag($beginn);

		return 0 === $erinnertAm
			&& $heute < $beginn
			&& $heute >= Kalender::plusTage($beginn, -$this->vorlaufVeranstaltungTage);
	}
}
