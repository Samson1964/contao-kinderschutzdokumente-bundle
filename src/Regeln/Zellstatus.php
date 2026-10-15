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
 * Der berechnete Stand einer Person zu einer Dokumentart an einem Stichtag.
 *
 * Wird nie gespeichert. Die Matrix im Backend, die Checkliste im Frontend und
 * die Erinnerungsmails rechnen ihn bei jedem Aufruf neu aus den Nachweisen.
 */
final class Zellstatus
{
	public const FEHLT = 'fehlt';
	public const EINGEREICHT = 'eingereicht';
	public const ABGELEHNT = 'abgelehnt';
	public const GUELTIG = 'gueltig';
	public const LAEUFT_AB = 'laeuftAb';
	public const ABGELAUFEN = 'abgelaufen';
	public const BEANSTANDET = 'beanstandet';

	/**
	 * @param string         $status      Eine der Konstanten dieser Klasse
	 * @param Nachweis|null  $massgeblich Der geprüfte Nachweis mit dem jüngsten
	 *                                    Ausstellungsdatum, falls vorhanden
	 * @param Nachweis|null  $wartend     Die jüngste Einreichung, die auf
	 *                                    Prüfung wartet, falls vorhanden
	 * @param Nachweis|null  $letzter     Der jüngste Nachweis überhaupt (ohne
	 *                                    ersetzte), für den Ablehnungsgrund
	 */
	public function __construct(
		public readonly string $status,
		public readonly ?Nachweis $massgeblich = null,
		public readonly ?Nachweis $wartend = null,
		public readonly ?Nachweis $letzter = null,
	) {
	}

	/**
	 * Sagt, ob eine Einreichung auf Prüfung wartet.
	 *
	 * Gilt auch neben einem gültigen Nachweis; dann zeigt die Matrix das
	 * Zusatzzeichen „neue Einreichung wartet“.
	 *
	 * @return bool true, wenn ein Nachweis im Status „eingereicht“ existiert
	 */
	public function wartet(): bool
	{
		return null !== $this->wartend;
	}

	/**
	 * Sagt, ob der Stand für die Vollständigkeit zählt.
	 *
	 * @return bool true bei „gültig“ und „läuft bald ab“
	 */
	public function istGueltig(): bool
	{
		return self::GUELTIG === $this->status || self::LAEUFT_AB === $this->status;
	}
}
