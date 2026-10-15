<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\System;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Backend\PapierkorbAktionen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Papierkorb;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * Prüft die Papierkorb-Aktionen im Backend, ohne den Contao-Kernel zu starten.
 *
 * Contaos Meldungen (Message::add*) sind statisch und lesen die Sitzung aus
 * System::getContainer(). Der Test setzt dafür einen kleinen Container mit
 * Anfragestapel und Arbeitsspeicher-Sitzung und liest die Meldungen aus dem
 * Flash-Bag der Sitzung zurück; am Produktivcode ist dafür nichts gekapselt.
 * Die Dienste (Papierkorb, Zugang, Anfragetoken) sind Mocks, die Sprachdatei
 * des Bundles wird echt geladen. Der Test läuft gegen den Contao-Kern im
 * vendor/ des Bundles (5.7).
 */
class PapierkorbAktionenTest extends TestCase
{
	/** Name des Anfragetokens, wie ihn der Parameter contao.csrf_token_name liefert */
	private const TOKEN_NAME = 'contao_csrf_token';

	/** Der Tokenwert, den der Token-Manager im Test als gültig anerkennt */
	private const TOKEN = 'gueltig';

	/** Die Adresse, an die jede Aktion zurückleitet (UrlGenerator-Mock) */
	private const LISTE = '/contao?do=kinderschutz_papierkorb';

	private Session $session;

	private RequestStack $requestStack;

	/** @var Papierkorb&MockObject */
	private Papierkorb $papierkorb;

	/**
	 * Stellt Sprachtexte, Contao-Container und Sitzung bereit.
	 *
	 * Der Container kennt nur, was Message braucht: den Anfragestapel und den
	 * Scope-Matcher, der jede Anfrage dem Backend zuordnet (Flash-Bag-Schlüssel
	 * „contao.BE.…“).
	 */
	protected function setUp(): void
	{
		$GLOBALS['TL_LANG'] = array();
		include __DIR__.'/../../src/Resources/contao/languages/de/default.php';

		$this->session = new Session(new MockArraySessionStorage());
		$this->requestStack = new RequestStack();
		$this->papierkorb = $this->createMock(Papierkorb::class);

		$matcher = $this->createMock(ScopeMatcher::class);
		$matcher->method('isBackendRequest')->willReturn(true);

		$container = new Container();
		$container->set('request_stack', $this->requestStack);
		$container->set('contao.routing.scope_matcher', $matcher);
		System::setContainer($container);
	}

	/**
	 * Räumt Sprachtexte und den statischen Contao-Container weg.
	 *
	 * System::setContainer() nimmt kein null an; deshalb wird die geschützte
	 * statische Eigenschaft zurückgesetzt, damit kein Test einen Container
	 * des vorigen sieht.
	 */
	protected function tearDown(): void
	{
		unset($GLOBALS['TL_LANG']);

		(new \ReflectionProperty(System::class, 'objContainer'))->setValue(null, null);
	}

	/**
	 * Die vier Aktionen mit dem Dienstaufruf, den sie auslösen, und der
	 * Bestätigung, die sie bei Erfolg zeigen.
	 *
	 * @return array<string, array{0: string, 1: string, 2: list<object>, 3: bool|int, 4: string, 5: int}>
	 *         Aktion, Dienstmethode, erwartete Argumente, Rückgabe des Dienstes
	 *         bei Erfolg, Sprachschlüssel der Bestätigung, Zahl für „%d“
	 */
	public static function erfolgsfaelle(): array
	{
		$zeitpunkt = Assert::isType('int');

		return array(
			'in den Papierkorb legen' => array('inPapierkorb', 'hineinlegen', array(Assert::identicalTo(7), $zeitpunkt), true, 'inKorbGelegt', 0),
			'wiederherstellen'        => array('wiederherstellen', 'wiederherstellen', array(Assert::identicalTo(7)), true, 'wiederhergestellt', 0),
			'endgültig löschen'       => array('loeschen', 'endgueltigLoeschen', array(Assert::identicalTo(7), $zeitpunkt), true, 'geloescht', 0),
			'Papierkorb leeren'       => array('leeren', 'leeren', array($zeitpunkt), 3, 'geleert', 3),
		);
	}

	/**
	 * Die Aktionen, die einen Nachweis bearbeiten, mit dem Hinweis, den sie
	 * zeigen, wenn der Dienst „nichts zu tun“ (false) meldet.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 *         Aktion, Dienstmethode, Sprachschlüssel des Hinweises
	 */
	public static function leerlaeufe(): array
	{
		return array(
			'in den Papierkorb legen' => array('inPapierkorb', 'hineinlegen', 'nichtImKorb'),
			'wiederherstellen'        => array('wiederherstellen', 'wiederherstellen', 'nichtsZuTun'),
			'endgültig löschen'       => array('loeschen', 'endgueltigLoeschen', 'nichtsZuTun'),
		);
	}

	/**
	 * Alle vier Aktionen, jede mit jedem ungültigen Tokenwert.
	 *
	 * @return array<string, array{0: string, 1: string}> Aktion, Tokenwert in „rt“
	 */
	public static function ungueltigeTokens(): array
	{
		$faelle = array();

		foreach (array('inPapierkorb', 'wiederherstellen', 'loeschen', 'leeren') as $aktion) {
			$faelle[$aktion.' mit falschem Token'] = array($aktion, 'falsch');
			$faelle[$aktion.' ohne Token'] = array($aktion, '');
		}

		return $faelle;
	}

	/**
	 * Alle vier Aktionen.
	 *
	 * @return array<string, array{0: string}> Name der Aktion
	 */
	public static function alleAktionen(): array
	{
		return array(
			'inPapierkorb'     => array('inPapierkorb'),
			'wiederherstellen' => array('wiederherstellen'),
			'loeschen'         => array('loeschen'),
			'leeren'           => array('leeren'),
		);
	}

	/**
	 * Ohne Prüferfreigabe wird keine Aktion ausgeführt.
	 *
	 * Das Anfragetoken ist gültig, die Freigabe fehlt: Zugang::verlange()
	 * wirft, die Aktion lässt die Ausnahme durch, der Papierkorb-Dienst wird
	 * nicht berührt und es entsteht keine Meldung. Fängt eine Aktion ab, die
	 * die Freigabe nicht prüft.
	 *
	 * @dataProvider alleAktionen
	 *
	 * @param string $aktion Name der Aktion
	 */
	public function testOhnePruefungsfreigabeKeineAktion(string $aktion): void
	{
		$zugang = $this->createMock(Zugang::class);
		$zugang->expects($this->once())->method('verlange')->willThrowException(new AccessDeniedException('Kein Zugang.'));
		$this->papierkorb->expects($this->never())->method($this->anything());
		$this->anfrage(self::TOKEN);

		try {
			$this->aktionen($zugang)->$aktion();
			$this->fail('Ohne Freigabe muss eine AccessDeniedException kommen.');
		} catch (AccessDeniedException) {
			$this->assertSame(array(), $this->meldungen());
		}
	}

	/**
	 * Mit Freigabe, aber ungültigem oder fehlendem Anfragetoken geschieht
	 * nichts außer der Rückkehr zur Liste mit einer Fehlermeldung.
	 *
	 * Die Links kommen per GET; ohne diese Prüfung ließe sich eine Löschung
	 * über einen untergeschobenen Link auslösen. Der Token-Manager erkennt
	 * nur den Namen „contao_csrf_token“ mit dem Wert „gueltig“ an.
	 *
	 * @dataProvider ungueltigeTokens
	 *
	 * @param string $aktion Name der Aktion
	 * @param string $token  Wert von „rt“ in der Anfrage
	 */
	public function testUngueltigesTokenFuehrtKeineAktionAus(string $aktion, string $token): void
	{
		$this->papierkorb->expects($this->never())->method($this->anything());
		$this->anfrage($token);

		$ort = $this->umleitung(fn () => $this->aktionen()->$aktion());

		$this->assertSame(self::LISTE, $ort);
		$this->assertSame(array('contao.BE.error' => array($this->text('ungueltigesToken'))), $this->meldungen());
	}

	/**
	 * Mit Freigabe und gültigem Token läuft die Aktion, bestätigt und leitet um.
	 *
	 * Geprüft werden der Dienstaufruf mit der ID aus der Anfrage (als Zahl),
	 * die Bestätigung als einzige Meldung, die Rückkehr zur Liste und dass
	 * nichts als Fehler protokolliert wird.
	 *
	 * @dataProvider erfolgsfaelle
	 *
	 * @param string       $aktion       Name der Aktion
	 * @param string       $dienst       Methode des Papierkorb-Dienstes
	 * @param list<object> $argumente    Erwartete Argumente des Dienstaufrufs
	 * @param bool|int     $rueckgabe    Rückgabe des Dienstes
	 * @param string       $schluessel   Sprachschlüssel der Bestätigung
	 * @param int          $anzahl       Zahl, die in die Meldung gehört
	 */
	public function testGueltigeAnfrageFuehrtAktionAus(string $aktion, string $dienst, array $argumente, bool|int $rueckgabe, string $schluessel, int $anzahl): void
	{
		$this->papierkorb->expects($this->once())->method($dienst)->with(...$argumente)->willReturn($rueckgabe);
		$protokoll = $this->createMock(LoggerInterface::class);
		$protokoll->expects($this->never())->method('error');
		$this->anfrage(self::TOKEN);

		$ort = $this->umleitung(fn () => $this->aktionen(null, $protokoll)->$aktion());

		$this->assertSame(self::LISTE, $ort);
		$this->assertSame(array('contao.BE.confirm' => array($this->text($schluessel, $anzahl))), $this->meldungen());
	}

	/**
	 * Meldet der Dienst „nichts zu tun“ (false), erscheint ein Hinweis statt
	 * der Bestätigung.
	 *
	 * Sonst behauptete die Oberfläche „wiederhergestellt“ oder „gelöscht“,
	 * obwohl nichts geschehen ist – etwa bei einem veralteten Link oder wenn
	 * ein zweiter Prüfer die Datei schon bearbeitet hat.
	 *
	 * @dataProvider leerlaeufe
	 *
	 * @param string $aktion     Name der Aktion
	 * @param string $dienst     Methode des Papierkorb-Dienstes
	 * @param string $schluessel Sprachschlüssel des Hinweises
	 */
	public function testNichtsZuTunMeldetHinweisStattBestaetigung(string $aktion, string $dienst, string $schluessel): void
	{
		$this->papierkorb->expects($this->once())->method($dienst)->willReturn(false);
		$this->anfrage(self::TOKEN);

		$ort = $this->umleitung(fn () => $this->aktionen()->$aktion());

		$this->assertSame(self::LISTE, $ort);
		$this->assertSame(array('contao.BE.info' => array($this->text($schluessel))), $this->meldungen());
	}

	/**
	 * Lässt sich die Datei nicht löschen, zeigt die Maske eine Fehlermeldung
	 * statt einer Fehlerseite.
	 *
	 * Der Dienst wirft eine RuntimeException, deren Text den Dateipfad
	 * enthält. Der Benutzer sieht nur den Hinweis auf die Dateirechte, das
	 * Systemprotokoll bekommt genau einen Fehlereintrag mit der Nachweis-ID;
	 * beide nennen weder Pfad noch Ausnahmetext. Außer der Umleitung kommt
	 * keine Ausnahme heraus.
	 */
	public function testLoeschfehlerWirdGemeldetUndOhnePfadProtokolliert(): void
	{
		$pfad = '7/0123456789abcdef0123456789abcdef.pdf';
		$this->papierkorb->expects($this->once())->method('endgueltigLoeschen')
			->willThrowException(new \RuntimeException('Datei konnte nicht gelöscht werden: '.$pfad));
		$protokolliert = array();
		$protokoll = $this->createMock(LoggerInterface::class);
		$protokoll->expects($this->once())->method('error')->willReturnCallback(static function ($meldung) use (&$protokolliert): void {
			$protokolliert[] = (string) $meldung;
		});
		$this->anfrage(self::TOKEN);

		$ort = $this->umleitung(fn () => $this->aktionen(null, $protokoll)->loeschen());

		$this->assertSame(self::LISTE, $ort);

		$meldungen = $this->meldungen();
		$this->assertSame(array('contao.BE.error' => array($this->text('loeschenFehlgeschlagen'))), $meldungen);
		$this->assertStringNotContainsString('.pdf', $meldungen['contao.BE.error'][0]);
		$this->assertStringNotContainsString('/', $meldungen['contao.BE.error'][0]);

		$this->assertCount(1, $protokolliert);
		$this->assertStringContainsString('Nachweis ID 7', $protokolliert[0]);
		$this->assertStringNotContainsString('.pdf', $protokolliert[0]);
		$this->assertStringNotContainsString('0123456789abcdef', $protokolliert[0]);
		$this->assertStringNotContainsString('konnte nicht gelöscht werden:', $protokolliert[0]);
	}

	/**
	 * Legt eine Backend-Anfrage auf den Anfragestapel.
	 *
	 * Die Anfrage trägt wie Contaos key-Callback do, key und id sowie das
	 * Anfragetoken „rt“ im Query-String und die Arbeitsspeicher-Sitzung.
	 *
	 * @param string $token Wert von „rt“
	 *
	 * @return Request Die Anfrage
	 */
	private function anfrage(string $token): Request
	{
		$request = new Request(array('do' => 'kinderschutz_papierkorb', 'key' => 'aktion', 'id' => '7', 'rt' => $token));
		$request->setSession($this->session);
		$this->requestStack->push($request);

		return $request;
	}

	/**
	 * Baut die Aktionsklasse mit Mocks für alle Dienste.
	 *
	 * Der Token-Manager erkennt genau den Namen self::TOKEN_NAME mit dem Wert
	 * self::TOKEN an; der UrlGenerator liefert „/contao?“ plus die Parameter.
	 *
	 * @param Zugang|null          $zugang    Mock für die Freigabe; ohne Angabe lässt er jeden durch
	 * @param LoggerInterface|null $protokoll Mock für das Systemprotokoll; ohne Angabe ein leerer
	 *
	 * @return PapierkorbAktionen Die Klasse unter Test
	 */
	private function aktionen(?Zugang $zugang = null, ?LoggerInterface $protokoll = null): PapierkorbAktionen
	{
		$tokenManager = $this->createMock(ContaoCsrfTokenManager::class);
		$tokenManager->method('isTokenValid')->willReturnCallback(
			static fn (CsrfToken $token): bool => self::TOKEN_NAME === $token->getId() && self::TOKEN === $token->getValue()
		);

		$router = $this->createMock(UrlGeneratorInterface::class);
		$router->method('generate')->willReturnCallback(
			static fn (string $route, array $parameter = array()): string => '/contao?'.http_build_query($parameter)
		);

		return new PapierkorbAktionen(
			$this->papierkorb,
			$zugang ?? $this->createMock(Zugang::class),
			$tokenManager,
			$this->requestStack,
			$router,
			self::TOKEN_NAME,
			$protokoll ?? $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * Führt eine Aktion aus und liefert das Ziel ihrer Umleitung.
	 *
	 * Jede Aktion endet mit einer RedirectResponseException; jede andere
	 * Ausnahme reicht der Helfer durch und lässt den Test scheitern.
	 *
	 * @param callable(): void $aktion Der Aufruf der Aktion
	 *
	 * @return string Der Location-Header der Umleitung
	 */
	private function umleitung(callable $aktion): string
	{
		try {
			$aktion();
		} catch (RedirectResponseException $umleitung) {
			return (string) $umleitung->getResponse()->headers->get('Location');
		}

		$this->fail('Die Aktion hätte zur Liste umleiten müssen.');
	}

	/**
	 * Liest alle Meldungen aus dem Flash-Bag der Sitzung, ohne sie zu entfernen.
	 *
	 * @return array<string, list<string>> Meldungen je Schlüssel „contao.BE.<typ>“
	 */
	private function meldungen(): array
	{
		return $this->session->getFlashBag()->peekAll();
	}

	/**
	 * Liefert einen Meldungstext aus der deutschen Sprachdatei.
	 *
	 * Fehlt der Schlüssel, würde die Aktion den Schlüssel selbst anzeigen;
	 * der Helfer lässt den Test dann scheitern, statt denselben Ersatz
	 * einzusetzen.
	 *
	 * @param string $schluessel Schlüssel unter MSC.kinderschutz_be
	 * @param int    $anzahl     Zahl für „%d“ im Text
	 *
	 * @return string Der fertige Text
	 */
	private function text(string $schluessel, int $anzahl = 0): string
	{
		$text = $GLOBALS['TL_LANG']['MSC']['kinderschutz_be'][$schluessel] ?? $schluessel;
		$this->assertNotSame($schluessel, $text, 'Sprachschlüssel fehlt: '.$schluessel);

		return sprintf($text, $anzahl);
	}
}
