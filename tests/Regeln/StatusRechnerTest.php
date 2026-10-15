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
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Dokumentart;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\StatusRechner;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Zellstatus;

/**
 * Prüft die Statusregeln in ihrer Reihenfolge, die Vollständigkeit und den
 * Stichtag einer Veranstaltung.
 */
class StatusRechnerTest extends TestCase
{
	private StatusRechner $rechner;

	private int $heute;

	/**
	 * Vorlauf 56 Tage (acht Wochen), Stichtag 15.10.2026.
	 */
	protected function setUp(): void
	{
		$this->rechner = new StatusRechner(56);
		$this->heute = strtotime('2026-10-15 00:00');
	}

	/**
	 * Ohne Nachweise fehlt die Art.
	 */
	public function testOhneNachweisFehlt(): void
	{
		$zelle = $this->rechner->zelle(array(), $this->heute);

		$this->assertSame(Zellstatus::FEHLT, $zelle->status);
		$this->assertFalse($zelle->wartet());
	}

	/**
	 * Eine Einreichung ohne geprüften Nachweis ist „eingereicht“.
	 */
	public function testNurEingereicht(): void
	{
		$zelle = $this->rechner->zelle(array($this->nachweis(1, Nachweis::EINGEREICHT)), $this->heute);

		$this->assertSame(Zellstatus::EINGEREICHT, $zelle->status);
		$this->assertTrue($zelle->wartet());
		$this->assertSame(1, $zelle->wartend->id);
	}

	/**
	 * Ein geprüfter Nachweis, der weit über den Vorlauf hinaus gilt.
	 */
	public function testGueltig(): void
	{
		$zelle = $this->rechner->zelle(array($this->nachweis(1, Nachweis::GEPRUEFT, '2026-01-10', '2028-01-09')), $this->heute);

		$this->assertSame(Zellstatus::GUELTIG, $zelle->status);
		$this->assertTrue($zelle->istGueltig());
	}

	/**
	 * Unbegrenzte Gültigkeit läuft nie ab.
	 */
	public function testUnbegrenztGueltig(): void
	{
		$zelle = $this->rechner->zelle(array($this->nachweis(1, Nachweis::GEPRUEFT, '2010-01-10', null)), $this->heute);

		$this->assertSame(Zellstatus::GUELTIG, $zelle->status);
	}

	/**
	 * Ablauf innerhalb des Vorlaufs ergibt „läuft bald ab“, auch genau am Stichtag.
	 */
	public function testLaeuftBaldAb(): void
	{
		$bald = $this->rechner->zelle(array($this->nachweis(1, Nachweis::GEPRUEFT, '2024-11-20', '2026-11-19')), $this->heute);
		$amStichtag = $this->rechner->zelle(array($this->nachweis(1, Nachweis::GEPRUEFT, '2024-10-16', '2026-10-15')), $this->heute);

		$this->assertSame(Zellstatus::LAEUFT_AB, $bald->status);
		$this->assertSame(Zellstatus::LAEUFT_AB, $amStichtag->status);
		$this->assertTrue($bald->istGueltig());
	}

	/**
	 * Genau am Ende des Vorlaufs ist der Nachweis wieder schlicht gültig.
	 */
	public function testGrenzeDesVorlaufs(): void
	{
		// 15.10.2026 + 56 Tage = 10.12.2026
		$zelle = $this->rechner->zelle(array($this->nachweis(1, Nachweis::GEPRUEFT, '2024-12-11', '2026-12-10')), $this->heute);

		$this->assertSame(Zellstatus::GUELTIG, $zelle->status);
	}

	/**
	 * Abgelaufen ist, wessen letzter gültiger Tag vor dem Stichtag liegt.
	 */
	public function testAbgelaufen(): void
	{
		$zelle = $this->rechner->zelle(array($this->nachweis(1, Nachweis::GEPRUEFT, '2024-10-15', '2026-10-14')), $this->heute);

		$this->assertSame(Zellstatus::ABGELAUFEN, $zelle->status);
		$this->assertFalse($zelle->istGueltig());
	}

	/**
	 * Ein EFZ mit einschlägigen Einträgen ist beanstandet, auch wenn es gilt.
	 */
	public function testBeanstandetGehtVor(): void
	{
		$zelle = $this->rechner->zelle(array($this->nachweis(1, Nachweis::GEPRUEFT, '2026-01-10', '2028-01-09', 'ja')), $this->heute);

		$this->assertSame(Zellstatus::BEANSTANDET, $zelle->status);
		$this->assertFalse($zelle->istGueltig());
	}

	/**
	 * Ein jüngeres beanstandetes EFZ verdrängt ein älteres sauberes.
	 */
	public function testJuengeresAusstellungsdatumIstMassgeblich(): void
	{
		$zelle = $this->rechner->zelle(array(
			$this->nachweis(1, Nachweis::GEPRUEFT, '2026-01-10', '2028-01-09', 'nein'),
			$this->nachweis(2, Nachweis::GEPRUEFT, '2026-06-01', '2028-05-31', 'ja'),
		), $this->heute);

		$this->assertSame(Zellstatus::BEANSTANDET, $zelle->status);
		$this->assertSame(2, $zelle->massgeblich->id);
	}

	/**
	 * Gültig bleibt gültig, wenn daneben eine Erneuerung wartet.
	 */
	public function testGueltigMitWartenderErneuerung(): void
	{
		$zelle = $this->rechner->zelle(array(
			$this->nachweis(1, Nachweis::GEPRUEFT, '2026-01-10', '2028-01-09'),
			$this->nachweis(2, Nachweis::EINGEREICHT),
		), $this->heute);

		$this->assertSame(Zellstatus::GUELTIG, $zelle->status);
		$this->assertTrue($zelle->wartet());
	}

	/**
	 * Abgelaufen plus wartende Einreichung ergibt „eingereicht“.
	 */
	public function testAbgelaufenMitWartenderEinreichung(): void
	{
		$zelle = $this->rechner->zelle(array(
			$this->nachweis(1, Nachweis::GEPRUEFT, '2024-01-10', '2026-01-09'),
			$this->nachweis(2, Nachweis::EINGEREICHT),
		), $this->heute);

		$this->assertSame(Zellstatus::EINGEREICHT, $zelle->status);
	}

	/**
	 * Die jüngste Einreichung abgelehnt, nichts Gültiges: „abgelehnt“.
	 */
	public function testAbgelehnt(): void
	{
		$zelle = $this->rechner->zelle(array(
			$this->nachweis(1, Nachweis::GEPRUEFT, '2024-01-10', '2026-01-09'),
			$this->nachweis(2, Nachweis::ABGELEHNT, '', null, '', 'unleserlich'),
		), $this->heute);

		$this->assertSame(Zellstatus::ABGELEHNT, $zelle->status);
		$this->assertSame('unleserlich', $zelle->letzter->ablehnungsgrund);
	}

	/**
	 * Eine alte Ablehnung zählt nicht mehr, wenn danach etwas eingereicht wurde.
	 */
	public function testAbgelehntDannNeuEingereicht(): void
	{
		$zelle = $this->rechner->zelle(array(
			$this->nachweis(1, Nachweis::ABGELEHNT),
			$this->nachweis(2, Nachweis::EINGEREICHT),
		), $this->heute);

		$this->assertSame(Zellstatus::EINGEREICHT, $zelle->status);
	}

	/**
	 * Ersetzte Einreichungen werden ignoriert.
	 */
	public function testErsetztZaehltNicht(): void
	{
		$zelle = $this->rechner->zelle(array($this->nachweis(1, Nachweis::ERSETZT)), $this->heute);

		$this->assertSame(Zellstatus::FEHLT, $zelle->status);
		$this->assertNull($zelle->letzter);
	}

	/**
	 * Vollständig ist, wer alle Pflichtarten gültig hat; freiwillige Arten zählen nicht.
	 */
	public function testVollstaendigUndFehlend(): void
	{
		$arten = array(
			new Dokumentart(10, 'EFZ', '', true, 24, true, true),
			new Dokumentart(11, 'Ehrenkodex', '', true, 0, false, false),
			new Dokumentart(12, 'Freiwillig', '', false, 0, false, false),
		);

		$zellen = $this->rechner->zeile($arten, array(
			10 => array($this->nachweis(1, Nachweis::GEPRUEFT, '2026-01-10', '2028-01-09', 'nein', '', 10)),
		), $this->heute);

		$this->assertSame(array(10, 11, 12), array_keys($zellen));
		$this->assertFalse($this->rechner->vollstaendig($arten, $zellen));
		$this->assertSame(array('Ehrenkodex'), array_map(static fn (Dokumentart $art): string => $art->name, $this->rechner->fehlend($arten, $zellen)));

		$zellen = $this->rechner->zeile($arten, array(
			10 => array($this->nachweis(1, Nachweis::GEPRUEFT, '2026-01-10', '2028-01-09', 'nein', '', 10)),
			11 => array($this->nachweis(2, Nachweis::GEPRUEFT, '2026-01-10', null, '', '', 11)),
		), $this->heute);

		$this->assertTrue($this->rechner->vollstaendig($arten, $zellen));
	}

	/**
	 * Der Stichtag einer Veranstaltung ist ihr letzter Tag, ohne Ende der erste.
	 */
	public function testStichtagVeranstaltung(): void
	{
		$beginn = strtotime('2026-11-02 00:00');
		$ende = strtotime('2026-11-12 00:00');

		$this->assertSame($ende, StatusRechner::stichtagVeranstaltung($beginn, $ende));
		$this->assertSame($beginn, StatusRechner::stichtagVeranstaltung($beginn, 0));
	}

	/**
	 * Ein Nachweis, der am ersten Veranstaltungstag noch gilt, aber vor dem
	 * letzten abläuft, reicht nicht.
	 */
	public function testAblaufWaehrendDerVeranstaltung(): void
	{
		$stichtag = StatusRechner::stichtagVeranstaltung(strtotime('2026-11-02'), strtotime('2026-11-12'));
		$zelle = $this->rechner->zelle(array($this->nachweis(1, Nachweis::GEPRUEFT, '2024-11-06', '2026-11-05')), $stichtag);

		$this->assertSame(Zellstatus::ABGELAUFEN, $zelle->status);
	}

	/**
	 * Baut einen Nachweis für die Tests.
	 *
	 * @param int         $id              ID, bestimmt die Reihenfolge
	 * @param string      $status          Gespeicherter Status
	 * @param string      $ausgestellt     Ausstellungsdatum JJJJ-MM-TT oder ''
	 * @param string|null $gueltigBis      Letzter gültiger Tag oder null
	 * @param string      $einschlaegig    '', 'nein' oder 'ja'
	 * @param string      $grund           Ablehnungsgrund
	 * @param int         $dokumentart     ID der Dokumentart
	 *
	 * @return Nachweis Der Nachweis von Mitglied 5
	 */
	private function nachweis(int $id, string $status, string $ausgestellt = '', ?string $gueltigBis = null, string $einschlaegig = '', string $grund = '', int $dokumentart = 10): Nachweis
	{
		return new Nachweis(
			$id,
			5,
			$dokumentart,
			$status,
			'' === $ausgestellt ? 0 : strtotime($ausgestellt.' 00:00'),
			1000 + $id,
			Nachweis::GEPRUEFT === $status ? 2000 + $id : 0,
			$einschlaegig,
			null === $gueltigBis ? null : strtotime($gueltigBis.' 00:00'),
			$grund,
		);
	}
}
