<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EventListener;

use Contao\Config;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\SeitenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer\EinstellungenListener;

/**
 * Prüft Zugang, Platzhalter und Übergangslogik der Einstellungsmaske.
 */
class EinstellungenListenerTest extends TestCase
{
	/**
	 * Lädt die DCA und einen Standardtext.
	 */
	protected function setUp(): void
	{
		$GLOBALS['TL_DCA'] = array();
		$GLOBALS['TL_LANG'] = array();
		include __DIR__.'/../../src/Resources/contao/dca/tl_kinderschutz_einstellungen.php';
		$GLOBALS['TL_LANG']['MSC']['kinderschutz_mail'] = array('antrag' => array('Betreff X', "Text\nY"));
	}

	/**
	 * Räumt die Globalen weg.
	 */
	protected function tearDown(): void
	{
		unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
	}

	/**
	 * Der Standardtext steht als Platzhalter in den Feldern.
	 */
	public function testPlatzhalterAusDemStandardtext(): void
	{
		$zugang = $this->createMock(Zugang::class);
		$zugang->expects($this->once())->method('verlange');

		$this->listener($zugang)->beimLaden();

		$felder = $GLOBALS['TL_DCA']['tl_kinderschutz_einstellungen']['fields'];
		$this->assertSame('Betreff X', $felder['kinderschutzMailAntragBetreff']['eval']['placeholder']);
		$this->assertSame("Text\nY", $felder['kinderschutzMailAntragText']['eval']['placeholder']);
		$this->assertArrayNotHasKey('placeholder', $felder['kinderschutzMailNeuText']['eval']);
	}

	/**
	 * Ohne Freigabe bricht das Laden ab, bevor etwas gesetzt wird.
	 */
	public function testOhneFreigabeAbbruch(): void
	{
		$zugang = $this->createMock(Zugang::class);
		$zugang->method('verlange')->willThrowException(new AccessDeniedException('nein'));

		try {
			$this->listener($zugang)->beimLaden();
			$this->fail('Erwartet: AccessDeniedException');
		} catch (AccessDeniedException) {
			$this->assertArrayNotHasKey('placeholder', $GLOBALS['TL_DCA']['tl_kinderschutz_einstellungen']['fields']['kinderschutzMailAntragText']['eval']);
		}
	}

	/**
	 * Im Übergang belegt die wirksame alte Gruppe die leere Auswahl vor.
	 */
	public function testGruppeWirdImUebergangVorbelegt(): void
	{
		$einstellungen = $this->createMock(Einstellungen::class);
		$einstellungen->method('gruppe')->willReturn(7);

		$listener = $this->listener(null, $einstellungen);

		$this->assertSame(7, $listener->gruppeLaden(''));
		$this->assertSame(7, $listener->gruppeLaden(null));
		$this->assertSame(7, $listener->gruppeLaden('0'));
		$this->assertSame('3', $listener->gruppeLaden('3'));
	}

	/**
	 * Ohne jede Gruppe bleibt das Feld leer.
	 */
	public function testOhneGruppeBleibtDasFeldLeer(): void
	{
		$einstellungen = $this->createMock(Einstellungen::class);
		$einstellungen->method('gruppe')->willReturn(0);

		$this->assertSame('', $this->listener(null, $einstellungen)->gruppeLaden(''));
	}

	/**
	 * Ohne Änderung abgesendet: der alte Schlüssel geht, der Wert wird ausdrücklich geschrieben.
	 *
	 * DC_File schriebe „kinderschutzGruppe“ nicht, weil es den vorbelegten Wert
	 * lose mit dem Eingabewert vergleicht (7 != "7" ist falsch); ohne
	 * persist() wäre die Gruppe nach dem Entfernen des alten Schlüssels weg.
	 */
	public function testSpeichernEntferntDenAltenSchluesselUndSchreibtDenWert(): void
	{
		$adapter = $this->adapter('a:1:{i:0;s:1:"7";}');
		$adapter->expects($this->once())->method('persist')->with('kinderschutzGruppe', 7);
		$adapter->expects($this->once())->method('remove')->with('kinderschutzGruppen');

		$this->assertSame('7', $this->listener(null, null, $adapter)->gruppeSpeichern('7'));
	}

	/**
	 * Wurde die Auswahl geleert, geht nur der alte Schlüssel.
	 */
	public function testSpeichernGeleertEntferntNurDenAltenSchluessel(): void
	{
		$adapter = $this->adapter('a:1:{i:0;s:1:"7";}');
		$adapter->expects($this->never())->method('persist');
		$adapter->expects($this->once())->method('remove')->with('kinderschutzGruppen');

		$this->assertSame('', $this->listener(null, null, $adapter)->gruppeSpeichern(''));
	}

	/**
	 * Ist der alte Schlüssel schon weg, wird nichts entfernt.
	 */
	public function testSpeichernRuehrtNichtsAnOhneAltenSchluessel(): void
	{
		$adapter = $this->adapter(null);
		$adapter->expects($this->never())->method('remove');
		$adapter->expects($this->never())->method('persist');

		$this->assertSame('4', $this->listener(null, null, $adapter)->gruppeSpeichern('4'));
	}

	/**
	 * Die Seitenauswahl bietet die Seiten mit dem Modul an, als „Titel (Alias, ID)“.
	 */
	public function testSeitenOptionenAusDenModulseiten(): void
	{
		$seiten = $this->createMock(SeitenRepository::class);
		$seiten->method('mitUnterlagenModul')->willReturn(array(
			array('id' => 7, 'title' => 'Kinderschutz', 'alias' => 'kinderschutz'),
			array('id' => 9, 'title' => 'Ohne Alias', 'alias' => ''),
		));
		$seiten->expects($this->never())->method('seite');
		$einstellungen = $this->createMock(Einstellungen::class);
		$einstellungen->method('seite')->willReturn(7);

		$this->assertSame(array(7 => 'Kinderschutz (kinderschutz, 7)', 9 => 'Ohne Alias (9)'), $this->listener(null, $einstellungen, null, $seiten)->seitenOptionen());
	}

	/**
	 * Seitentitel kommen maskiert in die Auswahl, auch die gespeicherte Seite; kodierte nicht doppelt.
	 */
	public function testSeitenOptionenMaskiert(): void
	{
		$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['seiteOhneModul'] = 'ohne Frontend-Modul';
		$seiten = $this->createMock(SeitenRepository::class);
		$seiten->method('mitUnterlagenModul')->willReturn(array(
			array('id' => 7, 'title' => '<img src=x>Unterlagen', 'alias' => 'unterlagen'),
		));
		$seiten->method('seite')->with(12)->willReturn(array('id' => 12, 'title' => 'Tom &amp; Jerry "alt"', 'alias' => 'alt'));
		$einstellungen = $this->createMock(Einstellungen::class);
		$einstellungen->method('seite')->willReturn(12);

		$this->assertSame(array(
			7  => '&lt;img src=x&gt;Unterlagen (unterlagen, 7)',
			12 => 'Tom &amp; Jerry &quot;alt&quot; (alt, 12) – ohne Frontend-Modul',
		), $this->listener(null, $einstellungen, null, $seiten)->seitenOptionen());
	}

	/**
	 * Die gespeicherte Seite bleibt wählbar, auch ohne gefundenes Modul oder gelöscht.
	 *
	 * @dataProvider gespeicherteSeiten
	 *
	 * @param array{id: int, title: string, alias: string}|null $seite    Die gespeicherte Seite aus der Datenbank
	 * @param string                                              $erwartet Beschriftung der gespeicherten Seite
	 */
	public function testGespeicherteSeiteBleibtWaehlbar(?array $seite, string $erwartet): void
	{
		$GLOBALS['TL_LANG']['tl_kinderschutz_einstellungen']['seiteOhneModul'] = 'ohne Frontend-Modul';
		$seiten = $this->createMock(SeitenRepository::class);
		$seiten->method('mitUnterlagenModul')->willReturn(array(array('id' => 7, 'title' => 'Kinderschutz', 'alias' => 'kinderschutz')));
		$seiten->method('seite')->with(12)->willReturn($seite);
		$einstellungen = $this->createMock(Einstellungen::class);
		$einstellungen->method('seite')->willReturn(12);

		$this->assertSame(array(7 => 'Kinderschutz (kinderschutz, 7)', 12 => $erwartet), $this->listener(null, $einstellungen, null, $seiten)->seitenOptionen());
	}

	/**
	 * Gespeicherte Seite ohne Modul: vorhanden oder gelöscht.
	 *
	 * @return array<string, array{0: array{id: int, title: string, alias: string}|null, 1: string}>
	 */
	public static function gespeicherteSeiten(): array
	{
		return array(
			'vorhanden' => array(array('id' => 12, 'title' => 'Startseite', 'alias' => 'start'), 'Startseite (start, 12) – ohne Frontend-Modul'),
			'gelöscht'  => array(null, '#12 – ohne Frontend-Modul'),
		);
	}

	/**
	 * Ohne gespeicherte Seite kommt nichts hinzu.
	 */
	public function testOhneGespeicherteSeiteNurModulseiten(): void
	{
		$seiten = $this->createMock(SeitenRepository::class);
		$seiten->method('mitUnterlagenModul')->willReturn(array());
		$seiten->expects($this->never())->method('seite');
		$einstellungen = $this->createMock(Einstellungen::class);
		$einstellungen->method('seite')->willReturn(0);

		$this->assertSame(array(), $this->listener(null, $einstellungen, null, $seiten)->seitenOptionen());
	}

	/**
	 * Baut eine Attrappe des Config-Adapters, deren get() den alten Schlüssel liefert.
	 *
	 * @param string|null $alt Wert von „kinderschutzGruppen“, oder null wenn nicht vorhanden
	 *
	 * @return MockObject Der Adapter mit get() und remove()
	 */
	private function adapter(?string $alt): MockObject
	{
		$adapter = $this->getMockBuilder(Adapter::class)->disableOriginalConstructor()->addMethods(array('get', 'remove', 'persist'))->getMock();
		$adapter->method('get')->with('kinderschutzGruppen')->willReturn($alt);

		return $adapter;
	}

	/**
	 * Baut den Listener mit Attrappen für alles, was der Test nicht vorgibt.
	 *
	 * @param Zugang|null        $zugang        Prüferfreigabe, ersatzweise eine Attrappe
	 * @param Einstellungen|null $einstellungen Lesezugriff, ersatzweise eine Attrappe
	 * @param object|null        $adapter       Config-Adapter, ersatzweise ein leeres Framework
	 * @param SeitenRepository|null $seiten     Seiten mit dem Modul, ersatzweise eine Attrappe
	 *
	 * @return EinstellungenListener Der Listener
	 */
	private function listener(?Zugang $zugang = null, ?Einstellungen $einstellungen = null, ?object $adapter = null, ?SeitenRepository $seiten = null): EinstellungenListener
	{
		$framework = $this->createMock(ContaoFramework::class);

		if (null !== $adapter) {
			$framework->method('getAdapter')->with(Config::class)->willReturn($adapter);
		}

		return new EinstellungenListener(
			$zugang ?? $this->createMock(Zugang::class),
			$einstellungen ?? $this->createMock(Einstellungen::class),
			$framework,
			$seiten ?? $this->createMock(SeitenRepository::class),
		);
	}
}
