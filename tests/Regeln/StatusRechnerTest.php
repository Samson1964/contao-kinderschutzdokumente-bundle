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
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Kalender;
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
	 * Liefert beide Listenreihenfolgen: wie gebaut und umgekehrt.
	 *
	 * Das Ergebnis darf nie davon abhängen, in welcher Reihenfolge die
	 * Datenbank die Nachweise liefert. Wer nur eine Richtung prüft, übersieht
	 * Umsetzungen, die zufällig den ersten oder den letzten Eintrag nehmen.
	 *
	 * @return array<string, array{0: bool}> Je Fall: ob die Liste umgedreht wird
	 */
	public function reihenfolgen(): array
	{
		return array(
			'wie gebaut' => array(false),
			'umgekehrt'  => array(true),
		);
	}

	/**
	 * Maßgeblich ist das jüngere Ausstellungsdatum, nicht die höhere ID und
	 * nicht die Position in der Liste.
	 *
	 * Der ältere Nachweis (ID 7) hat die höhere ID und gilt länger. Er darf
	 * den jüngeren (ID 3) nicht verdrängen, sonst bliebe ein neueres EFZ mit
	 * einschlägigen Einträgen hinter einem älteren sauberen verborgen.
	 *
	 * @dataProvider reihenfolgen
	 *
	 * @param bool $umgekehrt true = Liste in umgekehrter Reihenfolge übergeben
	 */
	public function testAeltererNachweisMitHoehererIdIstNichtMassgeblich(bool $umgekehrt): void
	{
		$liste = array(
			$this->nachweis(3, Nachweis::GEPRUEFT, '2026-06-01', '2028-05-31'),
			$this->nachweis(7, Nachweis::GEPRUEFT, '2025-01-10', '2029-01-09'),
		);
		$liste = $umgekehrt ? array_reverse($liste) : $liste;

		$zelle = $this->rechner->zelle($liste, $this->heute);

		$this->assertSame(3, $zelle->massgeblich->id);
		$this->assertSame(strtotime('2028-05-31 00:00'), $zelle->massgeblich->gueltigBis);
		$this->assertSame(Zellstatus::GUELTIG, $zelle->status);
		$this->assertSame(3, StatusRechner::massgeblich($liste)->id);
	}

	/**
	 * Bei gleichem Ausstellungsdatum gewinnt der zuletzt geprüfte Nachweis,
	 * auch wenn er die niedrigere ID hat.
	 *
	 * Praxisfall: Ein Nachweis wird nach einer Korrektur erneut bewertet. Der
	 * Prüfzeitpunkt entscheidet, nicht die Reihenfolge des Hochladens. Der
	 * zuletzt geprüfte ist hier sauber, der früher geprüfte beanstandet.
	 *
	 * @dataProvider reihenfolgen
	 *
	 * @param bool $umgekehrt true = Liste in umgekehrter Reihenfolge übergeben
	 */
	public function testBeiGleichemAusstellungsdatumGewinntDerZuletztGepruefte(bool $umgekehrt): void
	{
		$liste = array(
			$this->nachweis(2, Nachweis::GEPRUEFT, '2026-03-01', '2028-02-29', 'nein', '', 10, strtotime('2026-09-01 10:00')),
			$this->nachweis(4, Nachweis::GEPRUEFT, '2026-03-01', '2028-02-28', 'ja', '', 10, strtotime('2026-04-01 10:00')),
		);
		$liste = $umgekehrt ? array_reverse($liste) : $liste;

		$zelle = $this->rechner->zelle($liste, $this->heute);

		$this->assertSame(2, $zelle->massgeblich->id);
		$this->assertSame(Zellstatus::GUELTIG, $zelle->status);
		$this->assertSame(2, StatusRechner::massgeblich($liste)->id);
	}

	/**
	 * Sind Ausstellungsdatum und Prüfzeitpunkt gleich, entscheidet die höhere ID.
	 *
	 * Das ist die letzte Stufe der Gleichstandsregel; ohne sie hinge das
	 * Ergebnis von der Listenreihenfolge ab.
	 *
	 * @dataProvider reihenfolgen
	 *
	 * @param bool $umgekehrt true = Liste in umgekehrter Reihenfolge übergeben
	 */
	public function testBeiGleichemPruefzeitpunktGewinntDieHoehereId(bool $umgekehrt): void
	{
		$pruefung = strtotime('2026-09-01 10:00');
		$liste = array(
			$this->nachweis(5, Nachweis::GEPRUEFT, '2026-03-01', '2028-02-29', 'nein', '', 10, $pruefung),
			$this->nachweis(9, Nachweis::GEPRUEFT, '2026-03-01', '2028-02-28', 'ja', '', 10, $pruefung),
		);
		$liste = $umgekehrt ? array_reverse($liste) : $liste;

		$zelle = $this->rechner->zelle($liste, $this->heute);

		$this->assertSame(9, $zelle->massgeblich->id);
		$this->assertSame(Zellstatus::BEANSTANDET, $zelle->status);
	}

	/**
	 * Warten mehrere Einreichungen, gilt die jüngste (höchste ID) als wartend.
	 *
	 * Sie ist es, die der Prüfer zuerst sehen soll; zugleich ist sie der
	 * jüngste Nachweis überhaupt und damit der „letzte“ der Zelle.
	 *
	 * @dataProvider reihenfolgen
	 *
	 * @param bool $umgekehrt true = Liste in umgekehrter Reihenfolge übergeben
	 */
	public function testJuengsteWartendeEinreichungZaehlt(bool $umgekehrt): void
	{
		$liste = array(
			$this->nachweis(3, Nachweis::EINGEREICHT),
			$this->nachweis(8, Nachweis::EINGEREICHT),
		);
		$liste = $umgekehrt ? array_reverse($liste) : $liste;

		$zelle = $this->rechner->zelle($liste, $this->heute);

		$this->assertSame(Zellstatus::EINGEREICHT, $zelle->status);
		$this->assertSame(8, $zelle->wartend->id);
		$this->assertSame(8, $zelle->letzter->id);
		$this->assertSame(8, StatusRechner::wartend($liste)->id);
	}

	/**
	 * Eine alte Ablehnung färbt die Zelle nicht, wenn danach ein Nachweis
	 * geprüft wurde, der inzwischen abgelaufen ist.
	 *
	 * Abgelehnt heißt es nur, wenn die jüngste Einreichung abgelehnt wurde.
	 * Hier ist die jüngste ein geprüfter, abgelaufener Nachweis; der Stand
	 * lautet deshalb „abgelaufen“.
	 *
	 * @dataProvider reihenfolgen
	 *
	 * @param bool $umgekehrt true = Liste in umgekehrter Reihenfolge übergeben
	 */
	public function testAlteAblehnungVorAbgelaufenemNachweis(bool $umgekehrt): void
	{
		$liste = array(
			$this->nachweis(1, Nachweis::ABGELEHNT, '', null, '', 'unleserlich'),
			$this->nachweis(2, Nachweis::GEPRUEFT, '2024-10-15', '2026-10-14'),
		);
		$liste = $umgekehrt ? array_reverse($liste) : $liste;

		$zelle = $this->rechner->zelle($liste, $this->heute);

		$this->assertSame(Zellstatus::ABGELAUFEN, $zelle->status);
		$this->assertSame(2, $zelle->massgeblich->id);
		$this->assertSame(2, $zelle->letzter->id);
	}

	/**
	 * Der Vorlauf rechnet in Kalendertagen und nicht in 86400 Sekunden.
	 *
	 * Zwischen dem 1. März und dem 26. April 2027 liegt die Umstellung auf
	 * Sommerzeit (28. März). 56 Tage nach dem Stichtag sind deshalb der
	 * 26. April um 0:00 Uhr, mit 86400 Sekunden gerechnet aber erst 1:00 Uhr.
	 * Ein Nachweis, der genau am 26. April endet, liegt damit nicht mehr
	 * innerhalb des Vorlaufs; der Tag davor schon.
	 */
	public function testVorlaufUeberDieFruehjahrsumstellung(): void
	{
		$stichtag = strtotime('2027-03-01 00:00');
		$vorlaufEnde = strtotime('2027-04-26 00:00');

		// Vorbedingung: Die Zeitzone muss die Umstellung wirklich enthalten
		$this->assertSame($vorlaufEnde, Kalender::plusTage($stichtag, 56));
		$this->assertNotSame($vorlaufEnde, $stichtag + 56 * 86400);

		$amEnde = $this->rechner->zelle(array($this->nachweis(1, Nachweis::GEPRUEFT, '2025-04-27', '2027-04-26')), $stichtag);
		$einTagDavor = $this->rechner->zelle(array($this->nachweis(1, Nachweis::GEPRUEFT, '2025-04-26', '2027-04-25')), $stichtag);

		$this->assertSame(Zellstatus::GUELTIG, $amEnde->status);
		$this->assertSame(Zellstatus::LAEUFT_AB, $einTagDavor->status);
	}

	/**
	 * Baut einen Nachweis für die Tests.
	 *
	 * Ohne Angabe von $geprueftAm ist der Prüfzeitpunkt an die ID gekoppelt
	 * (2000 + ID bei geprüften Nachweisen, sonst 0). Wer den Gleichstand beim
	 * Ausstellungsdatum prüfen will, gibt $geprueftAm ausdrücklich an, damit
	 * Prüfzeitpunkt und ID gegenläufig sein können.
	 *
	 * @param int         $id              ID, bestimmt die Reihenfolge
	 * @param string      $status          Gespeicherter Status
	 * @param string      $ausgestellt     Ausstellungsdatum JJJJ-MM-TT oder ''
	 * @param string|null $gueltigBis      Letzter gültiger Tag oder null
	 * @param string      $einschlaegig    '', 'nein' oder 'ja'
	 * @param string      $grund           Ablehnungsgrund
	 * @param int         $dokumentart     ID der Dokumentart
	 * @param int|null    $geprueftAm      Prüfzeitpunkt als Zeitstempel; null =
	 *                                     an die ID gekoppelt (siehe oben)
	 *
	 * @return Nachweis Der Nachweis von Mitglied 5
	 */
	private function nachweis(int $id, string $status, string $ausgestellt = '', ?string $gueltigBis = null, string $einschlaegig = '', string $grund = '', int $dokumentart = 10, ?int $geprueftAm = null): Nachweis
	{
		return new Nachweis(
			$id,
			5,
			$dokumentart,
			$status,
			'' === $ausgestellt ? 0 : strtotime($ausgestellt.' 00:00'),
			1000 + $id,
			$geprueftAm ?? (Nachweis::GEPRUEFT === $status ? 2000 + $id : 0),
			$einschlaegig,
			null === $gueltigBis ? null : strtotime($gueltigBis.' 00:00'),
			$grund,
		);
	}
}
