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
 * Rechnet die Frist, nach der Dateien im Papierkorb endgültig gelöscht werden.
 *
 * Gerechnet wird in Kalendertagen: Eine Datei, die am 1. eines Monats um
 * 15 Uhr in den Papierkorb kam, wird bei 30 Tagen Frist vom ersten Cronlauf
 * am 31. gelöscht, unabhängig von der Uhrzeit.
 */
final class Papierkorbfrist
{
	/**
	 * @param int $tage Frist in Tagen, mindestens 1
	 */
	public function __construct(private readonly int $tage)
	{
	}

	/**
	 * Liefert den Tag, an dem die Datei endgültig gelöscht wird.
	 *
	 * @param int $papierkorbSeit Zeitpunkt, zu dem die Datei in den Papierkorb kam
	 *
	 * @return int Zeitstempel dieses Tags, 0:00 Uhr
	 */
	public function loeschtAm(int $papierkorbSeit): int
	{
		return Kalender::plusTage(Kalender::tag($papierkorbSeit), max(1, $this->tage));
	}

	/**
	 * Liefert die Grenze für die Datenbankabfrage.
	 *
	 * Alle Einträge mit 0 < papierkorbSeit < Grenze sind fällig.
	 *
	 * @param int $jetzt Aktueller Zeitpunkt
	 *
	 * @return int Zeitstempel 0:00 Uhr des Tages nach dem spätesten fälligen
	 *             Einwurftag
	 */
	public function grenze(int $jetzt): int
	{
		return Kalender::plusTage(Kalender::tag($jetzt), 1 - max(1, $this->tage));
	}
}
