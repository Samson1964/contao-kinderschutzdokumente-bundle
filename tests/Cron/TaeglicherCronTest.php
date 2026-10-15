<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Cron\TaeglicherCron;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Erinnerungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Papierkorb;

/**
 * Prüft den täglichen Lauf: Registrierung bei Contao, Zusammenfassung im
 * Protokoll und vor allem, dass ein Fehler in einem Schritt die anderen nicht
 * aufhält.
 *
 * Die drei Dienstaufrufe sind Mocks mit festen Rückgaben; so lässt sich ein
 * einzelner Schritt gezielt scheitern lassen.
 */
class TaeglicherCronTest extends TestCase
{
	/** Zahl, die der Schritt jeweils zurückgibt, wenn er nicht scheitert */
	private const ERGEBNISSE = array(
		'faelligeLoeschen'         => 2,
		'ablaufVersenden'          => 3,
		'veranstaltungenVersenden' => 4,
	);

	/** Text, der nie in einem Protokolleintrag auftauchen darf (steckt in der Ausnahme) */
	private const GEHEIM = 'Datei /srv/ablage/5/abc.pdf gesperrt';

	/** @var Papierkorb&MockObject */
	private Papierkorb $papierkorb;

	/** @var Erinnerungen&MockObject */
	private Erinnerungen $erinnerungen;

	/**
	 * Legt die Mocks der beiden Dienste an.
	 */
	protected function setUp(): void
	{
		$this->papierkorb = $this->createMock(Papierkorb::class);
		$this->erinnerungen = $this->createMock(Erinnerungen::class);
	}

	/**
	 * Die drei Schritte, jeweils einmal als der scheiternde.
	 *
	 * @return array<string, array{0: string}> Name der Dienstmethode, die wirft
	 */
	public static function scheiternde(): array
	{
		return array(
			'Papierkorb leeren'   => array('faelligeLoeschen'),
			'Ablaufmails'         => array('ablaufVersenden'),
			'Veranstaltungsmails' => array('veranstaltungenVersenden'),
		);
	}

	/**
	 * Die Klasse ist bei Contao als täglicher Cronjob eingetragen.
	 *
	 * Ohne das Attribut #[AsCronJob('daily')] bliebe der Lauf stumm: Contao
	 * findet den Dienst nur darüber. Geprüft wird per Reflexion, weil der
	 * Kernel hier nicht läuft.
	 */
	public function testIstAlsTaeglicherCronjobRegistriert(): void
	{
		$attribute = (new \ReflectionClass(TaeglicherCron::class))->getAttributes(AsCronJob::class);

		$this->assertCount(1, $attribute);
		$this->assertSame('daily', $attribute[0]->newInstance()->interval);
	}

	/**
	 * Ohne Fehler laufen alle Schritte, und das Protokoll fasst sie zusammen.
	 *
	 * Die Zahlen gehören zu ihren Schritten: 2 gelöschte Dateien, 3
	 * Ablaufmails, 4 Veranstaltungsmails. Ein Fehlereintrag entsteht nicht.
	 */
	public function testAlleSchritteLaufenUndWerdenZusammengefasst(): void
	{
		$this->schritteAufbauen(null);
		$protokoll = $this->createMock(LoggerInterface::class);
		$protokoll->expects($this->never())->method('error');
		$protokoll->expects($this->once())->method('info')->with(
			'Kinderschutz: täglicher Lauf – 2 Dateien gelöscht, 3 Ablauf- und 4 Veranstaltungsmails'
		);

		(new TaeglicherCron($this->papierkorb, $this->erinnerungen, $protokoll))('cli');
	}

	/**
	 * Gab es nichts zu tun, bleibt das Protokoll still.
	 *
	 * Sonst stünde jeden Tag ein Eintrag „0 Dateien, 0 Mails“ im
	 * Systemprotokoll.
	 */
	public function testOhneArbeitKeinProtokolleintrag(): void
	{
		$this->papierkorb->expects($this->once())->method('faelligeLoeschen')->willReturn(0);
		$this->erinnerungen->expects($this->once())->method('ablaufVersenden')->willReturn(0);
		$this->erinnerungen->expects($this->once())->method('veranstaltungenVersenden')->willReturn(0);
		$protokoll = $this->createMock(LoggerInterface::class);
		$protokoll->expects($this->never())->method($this->anything());

		(new TaeglicherCron($this->papierkorb, $this->erinnerungen, $protokoll))('web');
	}

	/**
	 * Wirft ein Schritt, laufen die anderen beiden trotzdem, und genau ein
	 * Fehler wird protokolliert.
	 *
	 * Der Fehlereintrag nennt den Schritt und die Fehlerklasse, aber nicht
	 * den Text der Ausnahme: Der kann Dateipfade enthalten, und das
	 * Systemprotokoll steht allen Administratoren offen. Die Zusammenfassung
	 * zählt den gescheiterten Schritt als 0 und die übrigen mit ihren Zahlen.
	 *
	 * @dataProvider scheiternde
	 *
	 * @param string $schritt Name der Dienstmethode, die wirft
	 */
	public function testFehlerInEinemSchrittHaeltDieAnderenNichtAuf(string $schritt): void
	{
		$this->schritteAufbauen($schritt);

		$fehler = array();
		$info = array();
		$protokoll = $this->createMock(LoggerInterface::class);
		$protokoll->expects($this->once())->method('error')->willReturnCallback(static function ($meldung) use (&$fehler): void {
			$fehler[] = (string) $meldung;
		});
		$protokoll->expects($this->once())->method('info')->willReturnCallback(static function ($meldung) use (&$info): void {
			$info[] = (string) $meldung;
		});

		(new TaeglicherCron($this->papierkorb, $this->erinnerungen, $protokoll))();

		$this->assertCount(1, $fehler);
		$this->assertStringContainsString($schritt, $fehler[0]);
		$this->assertStringContainsString('RuntimeException', $fehler[0]);
		$this->assertStringNotContainsString('gesperrt', $fehler[0]);
		$this->assertStringNotContainsString('/srv/ablage', $fehler[0]);

		$ergebnisse = array_replace(self::ERGEBNISSE, array($schritt => 0));
		$this->assertSame(
			sprintf('Kinderschutz: täglicher Lauf – %d Dateien gelöscht, %d Ablauf- und %d Veranstaltungsmails', $ergebnisse['faelligeLoeschen'], $ergebnisse['ablaufVersenden'], $ergebnisse['veranstaltungenVersenden']),
			$info[0]
		);
	}

	/**
	 * Scheitern alle drei Schritte, kommt trotzdem keine Ausnahme heraus,
	 * und jeder Schritt hinterlässt einen eigenen Fehlereintrag.
	 *
	 * Der Cron-Lauf von Contao darf wegen eines Dienstfehlers nicht abbrechen;
	 * sonst verhindert ein Fehler dauerhaft auch alle künftigen Läufe der
	 * übrigen Cronjobs im selben Aufruf.
	 */
	public function testScheitertAllesBleibtDerLaufOhneAusnahme(): void
	{
		$this->papierkorb->expects($this->once())->method('faelligeLoeschen')->willThrowException(new \RuntimeException(self::GEHEIM));
		$this->erinnerungen->expects($this->once())->method('ablaufVersenden')->willThrowException(new \LogicException(self::GEHEIM));
		$this->erinnerungen->expects($this->once())->method('veranstaltungenVersenden')->willThrowException(new \TypeError(self::GEHEIM));

		$fehler = array();
		$protokoll = $this->createMock(LoggerInterface::class);
		$protokoll->expects($this->exactly(3))->method('error')->willReturnCallback(static function ($meldung) use (&$fehler): void {
			$fehler[] = (string) $meldung;
		});
		$protokoll->expects($this->never())->method('info');

		(new TaeglicherCron($this->papierkorb, $this->erinnerungen, $protokoll))();

		$this->assertStringContainsString('faelligeLoeschen', $fehler[0]);
		$this->assertStringContainsString('RuntimeException', $fehler[0]);
		$this->assertStringContainsString('ablaufVersenden', $fehler[1]);
		$this->assertStringContainsString('LogicException', $fehler[1]);
		$this->assertStringContainsString('veranstaltungenVersenden', $fehler[2]);
		$this->assertStringContainsString('TypeError', $fehler[2]);

		foreach ($fehler as $eintrag) {
			$this->assertStringNotContainsString('gesperrt', $eintrag);
		}
	}

	/**
	 * Richtet die drei Dienstaufrufe ein: jeder wird genau einmal gerufen.
	 *
	 * @param string|null $scheiternd Name der Dienstmethode, die eine
	 *                                RuntimeException mit einem Pfad im Text
	 *                                wirft; null, wenn keine wirft
	 */
	private function schritteAufbauen(?string $scheiternd): void
	{
		$dienste = array(
			'faelligeLoeschen'         => $this->papierkorb,
			'ablaufVersenden'          => $this->erinnerungen,
			'veranstaltungenVersenden' => $this->erinnerungen,
		);

		foreach ($dienste as $methode => $dienst) {
			$erwartung = $dienst->expects($this->once())->method($methode);

			if ($methode === $scheiternd) {
				$erwartung->willThrowException(new \RuntimeException(self::GEHEIM));
			} else {
				$erwartung->willReturn(self::ERGEBNISSE[$methode]);
			}
		}
	}
}
