<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Regeln;

use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Gueltigkeit;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Kalender;

/**
 * Prüft die Gültigkeitsregel samt Monatsenden und Schaltjahren.
 */
class GueltigkeitTest extends TestCase
{
	/**
	 * Die Beispiele aus der Spezifikation und weitere Randfälle.
	 *
	 * @dataProvider faelle
	 *
	 * @param string      $ausgestellt Ausstellungsdatum als JJJJ-MM-TT
	 * @param int         $monate      Gültigkeit in Monaten
	 * @param string|null $erwartet    Letzter gültiger Tag oder null
	 */
	public function testBis(string $ausgestellt, int $monate, ?string $erwartet): void
	{
		$bis = Gueltigkeit::bis(strtotime($ausgestellt.' 00:00'), $monate);

		$this->assertSame($erwartet, null === $bis ? null : date('Y-m-d H:i', $bis));
	}

	/**
	 * Liefert Ausstellungsdatum, Monate und erwarteten letzten Tag.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string|null}>
	 */
	public function faelle(): array
	{
		return array(
			'Beispiel 24 Monate'          => array('2026-03-15', 24, '2028-03-14 00:00'),
			'29. Februar ohne Schalttag'  => array('2028-02-29', 24, '2030-02-28 00:00'),
			'31. Januar plus ein Monat'   => array('2026-01-31', 1, '2026-02-28 00:00'),
			'unbegrenzt'                  => array('2026-01-01', 0, null),
			'Jahreswechsel'               => array('2026-11-10', 3, '2027-02-09 00:00'),
			'erster des Monats'           => array('2026-05-01', 12, '2027-04-30 00:00'),
			'31. auf Monat mit 31 Tagen'  => array('2026-01-31', 2, '2026-03-30 00:00'),
			'über Zeitumstellung hinweg'  => array('2026-03-29', 1, '2026-04-28 00:00'),
		);
	}

	/**
	 * Kalender::tag setzt jeden Zeitpunkt auf 0:00 Uhr Ortszeit.
	 */
	public function testKalenderTag(): void
	{
		$this->assertSame(strtotime('2026-10-25 00:00'), Kalender::tag(strtotime('2026-10-25 23:59:59')));
		$this->assertSame(strtotime('2026-10-26 00:00'), Kalender::plusTage(strtotime('2026-10-25 00:00'), 1));
		$this->assertSame(strtotime('2026-10-11 00:00'), Kalender::plusTage(strtotime('2026-10-25 00:00'), -14));
	}
}
