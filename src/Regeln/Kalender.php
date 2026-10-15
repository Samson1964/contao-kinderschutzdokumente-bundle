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
 * Rechnet mit Kalendertagen in der Zeitzone der Installation.
 *
 * Contao speichert Datumsfelder als Unix-Zeitstempel von 0:00 Uhr Ortszeit.
 * Alle Vergleiche im Bundle („gültig bis“ gegen Stichtag) laufen deshalb
 * über auf Mitternacht gesetzte Zeitstempel. Tage werden nie als 86400
 * Sekunden gerechnet, weil ein Tag mit Zeitumstellung 23 oder 25 Stunden hat.
 */
final class Kalender
{
	/**
	 * Setzt einen Zeitpunkt auf 0:00 Uhr desselben Kalendertags.
	 *
	 * @param int $zeitstempel Beliebiger Zeitpunkt
	 *
	 * @return int Zeitstempel von 0:00 Uhr Ortszeit an diesem Tag
	 */
	public static function tag(int $zeitstempel): int
	{
		return self::datum($zeitstempel)->setTime(0, 0)->getTimestamp();
	}

	/**
	 * Verschiebt einen Kalendertag um eine Anzahl Tage.
	 *
	 * @param int $tag  Zeitstempel eines Tages, 0:00 Uhr
	 * @param int $tage Anzahl Tage, negativ für zurück
	 *
	 * @return int Zeitstempel des Zieltags, 0:00 Uhr
	 */
	public static function plusTage(int $tag, int $tage): int
	{
		return self::datum($tag)->setTime(0, 0)->modify(sprintf('%+d days', $tage))->getTimestamp();
	}

	/**
	 * Wandelt einen Zeitstempel in ein Datumsobjekt der Ortszeitzone.
	 *
	 * Ein mit „@“ erzeugtes DateTimeImmutable steht in UTC; ohne den Wechsel
	 * der Zeitzone fiele 0:00 Uhr Ortszeit auf den Vortag.
	 *
	 * @param int $zeitstempel Beliebiger Zeitpunkt
	 *
	 * @return \DateTimeImmutable Der Zeitpunkt in der Zeitzone von PHP
	 */
	public static function datum(int $zeitstempel): \DateTimeImmutable
	{
		return (new \DateTimeImmutable('@'.$zeitstempel))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
	}
}
