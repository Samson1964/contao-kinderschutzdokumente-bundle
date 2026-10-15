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
 * Berechnet, bis wann ein geprüfter Nachweis gilt.
 *
 * Regel aus der Spezifikation: Ein Nachweis gilt bis einschließlich des Tages
 * vor dem gleichen Kalendertag n Monate nach dem Ausstellungsdatum. Gibt es
 * diesen Kalendertag im Zielmonat nicht (29.02., 31.), gilt er bis zum
 * Monatsende. Beispiel: ausgestellt am 15.03.2026, 24 Monate → gültig bis
 * 14.03.2028; ausgestellt am 29.02.2028 → gültig bis 28.02.2030.
 *
 * PHPs „+24 months“ ist dafür unbrauchbar, weil es überzählige Tage in den
 * Folgemonat schiebt (31.01. + 1 Monat = 03.03.).
 */
final class Gueltigkeit
{
	/**
	 * Liefert den letzten gültigen Tag eines Nachweises.
	 *
	 * @param int $ausstellungsdatum Zeitstempel des Ausstellungstags
	 * @param int $monate            Gültigkeit der Dokumentart in Monaten;
	 *                               0 bedeutet unbegrenzt
	 *
	 * @return int|null Zeitstempel des letzten gültigen Tags (0:00 Uhr),
	 *                  oder null bei unbegrenzter Gültigkeit
	 */
	public static function bis(int $ausstellungsdatum, int $monate): ?int
	{
		if ($monate <= 0) {
			return null;
		}

		$ausgestellt = Kalender::datum($ausstellungsdatum);
		$tag = (int) $ausgestellt->format('j');

		// Monate ab Januar des Jahres 0 zählen, damit der Jahreswechsel von
		// selbst entsteht
		$monatsindex = (int) $ausgestellt->format('Y') * 12 + (int) $ausgestellt->format('n') - 1 + $monate;
		$jahr = intdiv($monatsindex, 12);
		$monat = $monatsindex % 12 + 1;

		$ersterImZielmonat = $ausgestellt->setDate($jahr, $monat, 1)->setTime(0, 0);
		$tageImZielmonat = (int) $ersterImZielmonat->format('t');

		if ($tag > $tageImZielmonat) {
			return $ersterImZielmonat->setDate($jahr, $monat, $tageImZielmonat)->getTimestamp();
		}

		return Kalender::plusTage($ersterImZielmonat->setDate($jahr, $monat, $tag)->getTimestamp(), -1);
	}
}
