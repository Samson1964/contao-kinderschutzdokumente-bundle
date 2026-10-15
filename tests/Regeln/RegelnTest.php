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
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Berechtigung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Erinnerungsplan;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Papierkorbfrist;

/**
 * Prüft die kleinen Regeln: Berechtigung, Papierkorbfrist, Erinnerungsplan.
 */
class RegelnTest extends TestCase
{
	/**
	 * Hauptadmin und freigegebene Benutzer dürfen, alle anderen nicht.
	 */
	public function testBerechtigung(): void
	{
		$berechtigung = new Berechtigung(1);

		$this->assertTrue($berechtigung->istHauptadmin(1));
		$this->assertFalse($berechtigung->istHauptadmin(2));
		$this->assertTrue($berechtigung->darfPruefen(1, false));
		$this->assertTrue($berechtigung->darfPruefen(7, true));
		$this->assertFalse($berechtigung->darfPruefen(7, false));
		$this->assertFalse($berechtigung->darfPruefen(0, true));
	}

	/**
	 * Der Hauptadmin lässt sich umstellen.
	 */
	public function testAndererHauptadmin(): void
	{
		$berechtigung = new Berechtigung(5);

		$this->assertFalse($berechtigung->darfPruefen(1, false));
		$this->assertTrue($berechtigung->darfPruefen(5, false));
	}

	/**
	 * Eine Datei vom 1.10. 15 Uhr wird bei 30 Tagen am 31.10. gelöscht.
	 */
	public function testPapierkorbfrist(): void
	{
		$frist = new Papierkorbfrist(30);
		$seit = strtotime('2026-10-01 15:00');

		$this->assertSame(strtotime('2026-10-31 00:00'), $frist->loeschtAm($seit));

		// Am 30.10. noch nicht fällig, am 31.10. um 3 Uhr schon
		$this->assertFalse($seit < $frist->grenze(strtotime('2026-10-30 23:00')));
		$this->assertTrue($seit < $frist->grenze(strtotime('2026-10-31 03:00')));
	}

	/**
	 * Eine Frist von 0 Tagen wird wie ein Tag behandelt.
	 */
	public function testPapierkorbfristMindestensEinTag(): void
	{
		$frist = new Papierkorbfrist(0);

		$this->assertSame(strtotime('2026-10-02 00:00'), $frist->loeschtAm(strtotime('2026-10-01 15:00')));
	}

	/**
	 * Vor-Ablauf-Mail innerhalb des Vorlaufs, aber nur einmal.
	 */
	public function testErinnerungVorAblauf(): void
	{
		$plan = new Erinnerungsplan(56, 42);
		$heute = strtotime('2026-10-15 00:00');

		$this->assertNull($plan->ablauf(array($this->geprueft('2027-06-30')), $heute));

		$faellig = $plan->ablauf(array($this->geprueft('2026-11-30')), $heute);
		$this->assertSame(Erinnerungsplan::VOR_ABLAUF, $faellig[0]);
		$this->assertSame(1, $faellig[1]->id);

		$this->assertNull($plan->ablauf(array($this->geprueft('2026-11-30', $heute)), $heute));
	}

	/**
	 * Ablaufmail ab dem Tag nach dem letzten gültigen Tag, höchstens 14 Tage lang.
	 */
	public function testErinnerungAblauf(): void
	{
		$plan = new Erinnerungsplan(56, 42);
		$gueltigBis = '2026-10-14';

		$amLetztenTag = $plan->ablauf(array($this->geprueft($gueltigBis)), strtotime('2026-10-14 00:00'));
		$this->assertSame(Erinnerungsplan::VOR_ABLAUF, $amLetztenTag[0]);

		$amTagDanach = $plan->ablauf(array($this->geprueft($gueltigBis)), strtotime('2026-10-15 00:00'));
		$this->assertSame(Erinnerungsplan::ABLAUF, $amTagDanach[0]);

		$this->assertSame(Erinnerungsplan::ABLAUF, $plan->ablauf(array($this->geprueft($gueltigBis)), strtotime('2026-10-29 00:00'))[0]);
		$this->assertNull($plan->ablauf(array($this->geprueft($gueltigBis)), strtotime('2026-10-30 00:00')));
		$this->assertNull($plan->ablauf(array($this->geprueft($gueltigBis, 0, 1)), strtotime('2026-10-15 00:00')));
	}

	/**
	 * Keine Erinnerung bei wartender Erneuerung, unbegrenzter Gültigkeit oder Beanstandung.
	 */
	public function testKeineErinnerung(): void
	{
		$plan = new Erinnerungsplan(56, 42);
		$heute = strtotime('2026-10-15 00:00');
		$wartend = new Nachweis(2, 5, 10, Nachweis::EINGEREICHT, 0, 100, 0, '', null);
		$unbegrenzt = new Nachweis(3, 5, 10, Nachweis::GEPRUEFT, strtotime('2020-01-01'), 0, 50, '', null);
		$beanstandet = new Nachweis(4, 5, 10, Nachweis::GEPRUEFT, strtotime('2025-01-01'), 0, 50, 'ja', strtotime('2026-11-01'));

		$this->assertNull($plan->ablauf(array($this->geprueft('2026-11-30'), $wartend), $heute));
		$this->assertNull($plan->ablauf(array($unbegrenzt), $heute));
		$this->assertNull($plan->ablauf(array($beanstandet), $heute));
		$this->assertNull($plan->ablauf(array(), $heute));
	}

	/**
	 * Veranstaltungsmail ab sechs Wochen vor Beginn bis zum Vortag, einmal.
	 */
	public function testVeranstaltungFaellig(): void
	{
		$plan = new Erinnerungsplan(56, 42);
		$beginn = strtotime('2026-11-26 00:00');

		$this->assertFalse($plan->veranstaltungFaellig($beginn, strtotime('2026-10-14 00:00'), 0));
		$this->assertTrue($plan->veranstaltungFaellig($beginn, strtotime('2026-10-15 00:00'), 0));
		$this->assertTrue($plan->veranstaltungFaellig($beginn, strtotime('2026-11-25 00:00'), 0));
		$this->assertFalse($plan->veranstaltungFaellig($beginn, strtotime('2026-11-26 00:00'), 0));
		$this->assertFalse($plan->veranstaltungFaellig($beginn, strtotime('2026-10-20 00:00'), 12345));
	}

	/**
	 * Baut einen geprüften Nachweis mit ID 1.
	 *
	 * @param string $gueltigBis        Letzter gültiger Tag JJJJ-MM-TT
	 * @param int    $erinnertVorAblauf Merker der Vorab-Mail
	 * @param int    $erinnertAblauf    Merker der Ablaufmail
	 *
	 * @return Nachweis Der Nachweis
	 */
	private function geprueft(string $gueltigBis, int $erinnertVorAblauf = 0, int $erinnertAblauf = 0): Nachweis
	{
		return new Nachweis(1, 5, 10, Nachweis::GEPRUEFT, strtotime('2024-10-15'), 0, 50, 'nein', strtotime($gueltigBis.' 00:00'), '', $erinnertVorAblauf, $erinnertAblauf);
	}
}
