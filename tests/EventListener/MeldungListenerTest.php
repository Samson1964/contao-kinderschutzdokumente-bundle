<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EventListener;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Contao\System;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\DokumentartRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Papierkorb;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Pruefung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer\NachweisListener;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\MeldungListener;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockFileSessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prüft, dass der Hinweis auf eine gescheiterte Ablehnungsmail die
 * Weiterleitung nach „Speichern und schließen“ überlebt.
 *
 * Contaos DC_Table ruft nach den onsubmit-Callbacks Message::reset() auf und
 * leitet dann weiter. Im Prüfstand 5.7 erschien der Hinweis deshalb nie.
 *
 * Jede Anfrage bekommt hier eine eigene Session auf derselben Sitzungsdatei
 * (MockFileSessionStorage mit fester ID), wird wie vom Backend gestartet und
 * am Ende gespeichert. So ist nachgestellt, was zwischen POST und Folgeseite
 * wirklich übrig bleibt. Contaos Message läuft echt über den statischen
 * Container. Der enthält absichtlich keinen Scope-Matcher: Fragte Message
 * nach dem Bereich, statt „BE“ übergeben zu bekommen, scheiterte der Test
 * (in 4.13 wäre der Standardwert die Konstante TL_MODE).
 */
class MeldungListenerTest extends TestCase
{
	/** Feste Sitzungs-ID, damit jede Anfrage dieselbe Sitzungsdatei liest */
	private const SITZUNG = 'kinderschutztest';

	/** @var string Verzeichnis der Sitzungsdatei */
	private string $ablage;

	/**
	 * Lädt die Sprachdatei und legt ein leeres Sitzungsverzeichnis an.
	 */
	protected function setUp(): void
	{
		$GLOBALS['TL_LANG'] = array();
		include __DIR__.'/../../src/Resources/contao/languages/de/default.php';

		$this->ablage = sys_get_temp_dir().'/kinderschutz-sitzung-'.bin2hex(random_bytes(6));
	}

	/**
	 * Räumt Sprache, statischen Container und Sitzungsdatei ab.
	 */
	protected function tearDown(): void
	{
		unset($GLOBALS['TL_LANG']);

		(new \ReflectionProperty(System::class, 'objContainer'))->setValue(null, null);

		foreach (glob($this->ablage.'/*') ?: array() as $datei) {
			unlink($datei);
		}

		if (is_dir($this->ablage)) {
			rmdir($this->ablage);
		}
	}

	/**
	 * Der Hinweis erscheint auf der Folgeseite genau einmal.
	 *
	 * Mit „Speichern und schließen“ (Message::reset() vor der Weiterleitung)
	 * ging er bisher verloren; mit „Speichern“ kam er an und muss es weiter
	 * tun. In der POST-Anfrage selbst liegt bis zum Ende der Antwort nichts
	 * im Flash-Speicher. Die übernächste Seite zeigt ihn nicht mehr.
	 *
	 * @dataProvider knoepfe
	 *
	 * @param bool $zuruecksetzen DC_Table ruft vor der Weiterleitung Message::reset() auf
	 */
	public function testHinweisErscheintAufDerFolgeseiteGenauEinmal(bool $zuruecksetzen): void
	{
		$meldungen = new MeldungListener();

		$post = $this->anfrage();
		$this->nachweisListener($meldungen, false)->nachSpeichern($this->dc(4));
		$this->assertSame(array(), $post->getFlashBag()->peekAll(), 'Während der Anfrage wartet der Hinweis im MeldungListener');

		if ($zuruecksetzen) {
			Message::reset();
		}

		$meldungen->beiAntwort($this->antwort(HttpKernelInterface::MAIN_REQUEST));
		$post->save();

		$text = $GLOBALS['TL_LANG']['MSC']['kinderschutz_be']['mailGescheitert'];

		$folgeseite = $this->anfrage();
		$this->assertSame('<p class="tl_error">'.$text.'</p>', Message::generateUnwrapped('BE'));
		$folgeseite->save();

		$this->anfrage();
		$this->assertSame('', Message::generateUnwrapped('BE'));
	}

	/**
	 * Die beiden Wege aus der Prüfmaske.
	 *
	 * @return array<string, array{bool}> Je Knopf, ob DC_Table zurücksetzt
	 */
	public static function knoepfe(): array
	{
		return array(
			'Speichern'               => array(false),
			'Speichern und schließen' => array(true),
		);
	}

	/**
	 * Scheitern mehrere Mails in einer Anfrage, kommt der Hinweis nur einmal.
	 *
	 * In „Mehrere bearbeiten“ ruft DC_Table den onsubmit-Callback je
	 * Datensatz auf.
	 */
	public function testMehrereFehlschlaegeErgebenEinenHinweis(): void
	{
		$meldungen = new MeldungListener();
		$sitzung = $this->anfrage();
		$listener = $this->nachweisListener($meldungen, false);

		$listener->nachSpeichern($this->dc(4));
		$listener->nachSpeichern($this->dc(5));
		Message::reset();
		$meldungen->beiAntwort($this->antwort(HttpKernelInterface::MAIN_REQUEST));

		$this->assertSame(array($GLOBALS['TL_LANG']['MSC']['kinderschutz_be']['mailGescheitert']), $sitzung->getFlashBag()->peek('contao.BE.error'));
	}

	/**
	 * Ging die Mail hinaus, gibt es keinen Hinweis.
	 */
	public function testVerschickteMailErgibtKeinenHinweis(): void
	{
		$meldungen = new MeldungListener();
		$sitzung = $this->anfrage();

		$this->nachweisListener($meldungen, true)->nachSpeichern($this->dc(4));
		$meldungen->beiAntwort($this->antwort(HttpKernelInterface::MAIN_REQUEST));

		$this->assertSame(array(), $sitzung->getFlashBag()->peekAll());
	}

	/**
	 * Die Antwort einer Unteranfrage reicht nichts weiter.
	 *
	 * Unteranfragen antworten vor der Hauptanfrage, also vor einem
	 * Message::reset() in DC_Table. Erst die Antwort der Hauptanfrage
	 * übergibt den Hinweis.
	 */
	public function testUnteranfrageReichtNichtsWeiter(): void
	{
		$meldungen = new MeldungListener();
		$sitzung = $this->anfrage();
		$meldungen->vormerken('Hinweis');

		$meldungen->beiAntwort($this->antwort(HttpKernelInterface::SUB_REQUEST));
		$this->assertSame(array(), $sitzung->getFlashBag()->peekAll());

		$meldungen->beiAntwort($this->antwort(HttpKernelInterface::MAIN_REQUEST));
		$this->assertSame(array('Hinweis'), $sitzung->getFlashBag()->peek('contao.BE.error'));

		// Die Vormerkung ist verbraucht
		$meldungen->beiAntwort($this->antwort(HttpKernelInterface::MAIN_REQUEST));
		$this->assertSame(array('Hinweis'), $sitzung->getFlashBag()->peek('contao.BE.error'));
	}

	/**
	 * reset() (kernel.reset zwischen zwei Anfragen) verwirft Vormerkungen.
	 */
	public function testResetVerwirftVormerkungen(): void
	{
		$meldungen = new MeldungListener();
		$sitzung = $this->anfrage();
		$meldungen->vormerken('Hinweis');

		$meldungen->reset();
		$meldungen->beiAntwort($this->antwort(HttpKernelInterface::MAIN_REQUEST));

		$this->assertSame(array(), $sitzung->getFlashBag()->peekAll());
	}

	/**
	 * Beginnt eine Backend-Anfrage auf der gemeinsamen Sitzungsdatei.
	 *
	 * Die Sitzung wird gleich gestartet, wie es im Backend die Firewall tut
	 * (das Anmeldetoken liegt in der Sitzung); Contaos Message gibt aus einer
	 * nicht gestarteten Sitzung nichts aus. Der statische Contao-Container
	 * bekommt nur den Anfragestapel.
	 *
	 * Seiteneffekt: ersetzt den Container von Contao\System.
	 *
	 * @return Session Die Sitzung dieser Anfrage
	 */
	private function anfrage(): Session
	{
		$speicher = new MockFileSessionStorage($this->ablage);
		$speicher->setId(self::SITZUNG);

		$sitzung = new Session($speicher);
		$sitzung->start();

		$anfrage = new Request(array('do' => 'kinderschutz_nachweise'));
		$anfrage->setSession($sitzung);

		$anfragen = new RequestStack();
		$anfragen->push($anfrage);

		$container = new Container();
		$container->set('request_stack', $anfragen);
		System::setContainer($container);

		return $sitzung;
	}

	/**
	 * Das Ereignis kernel.response für eine Weiterleitung.
	 *
	 * @param int $art HttpKernelInterface::MAIN_REQUEST oder SUB_REQUEST
	 *
	 * @return ResponseEvent Das Ereignis
	 */
	private function antwort(int $art): ResponseEvent
	{
		return new ResponseEvent($this->createMock(HttpKernelInterface::class), new Request(), $art, new Response('', Response::HTTP_SEE_OTHER));
	}

	/**
	 * Ein Data Container für einen Nachweis.
	 *
	 * @param int $id ID des Nachweises
	 *
	 * @return DataContainer Die Attrappe
	 */
	private function dc(int $id): DataContainer
	{
		$dc = $this->createMock(DataContainer::class);
		$dc->method('__get')->willReturnMap(array(array('id', $id)));

		return $dc;
	}

	/**
	 * Baut den NachweisListener mit Attrappen.
	 *
	 * @param MeldungListener $meldungen      Nimmt die Hinweise entgegen
	 * @param bool            $mailVerschickt Rückgabe von Pruefung::nachSpeichern()
	 *
	 * @return NachweisListener Der Listener
	 */
	private function nachweisListener(MeldungListener $meldungen, bool $mailVerschickt): NachweisListener
	{
		$pruefung = $this->createMock(Pruefung::class);
		$pruefung->method('nachSpeichern')->willReturn($mailVerschickt);

		return new NachweisListener(
			$this->createMock(Zugang::class),
			$pruefung,
			$this->createMock(NachweisRepository::class),
			$this->createMock(DokumentartRepository::class),
			$this->createMock(PersonenRepository::class),
			$this->createMock(Papierkorb::class),
			$this->createMock(Einstellungen::class),
			$this->createMock(UrlGeneratorInterface::class),
			$meldungen,
			$this->createMock(ContaoFramework::class),
		);
	}
}
