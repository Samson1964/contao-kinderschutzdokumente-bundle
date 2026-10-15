<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EventListener;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\DataContainer;
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
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Dokumentart;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prüft, wie der NachweisListener die Liste als Papierkorb einrichtet und
 * die Eingaben der Prüfmaske liest.
 *
 * Für die Eingabeprüfung braucht Contaos Input::post() einen Container mit
 * Anfragestapel und Scope-Matcher; der Test setzt ihn wie der
 * PapierkorbAktionenTest und räumt ihn danach wieder ab.
 */
class NachweisListenerTest extends TestCase
{
	/**
	 * Lädt die DCA-Datei von tl_kinderschutz und die Sprachdatei.
	 */
	protected function setUp(): void
	{
		$GLOBALS['TL_DCA'] = array();
		$GLOBALS['TL_LANG'] = array();
		include __DIR__.'/../../src/Resources/contao/languages/de/default.php';
		include __DIR__.'/../../src/Resources/contao/dca/tl_kinderschutz.php';
	}

	/**
	 * Räumt die gesetzten Globalen und den statischen Contao-Container ab.
	 */
	protected function tearDown(): void
	{
		unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);

		(new \ReflectionProperty(System::class, 'objContainer'))->setValue(null, null);
	}

	/**
	 * Der Papierkorb gruppiert nach dem Tag, an dem die Datei hineinkam.
	 *
	 * Contao schreibt über jede Gruppe der Liste den Wert der ersten
	 * Sortierspalte. Mit einer reinen Aufsteigend-Sortierung stand dort der
	 * rohe Zeitstempel („1792096649“), im Prüfstand 5.7 gesehen. Erst eine
	 * Tagessortierung lässt Contao den Wert als Datum ausgeben.
	 */
	public function testPapierkorbGruppiertNachTag(): void
	{
		$this->listener('kinderschutz_papierkorb')->beimLaden();

		$sortierung = $GLOBALS['TL_DCA']['tl_kinderschutz']['list']['sorting'];
		$this->assertSame(array('papierkorbSeit'), $sortierung['fields']);
		$this->assertSame(DataContainer::SORT_DAY_ASC, $sortierung['flag']);
		$this->assertTrue($GLOBALS['TL_DCA']['tl_kinderschutz']['config']['notCopyable']);
	}

	/**
	 * Die Nachweisliste behält ihre Sortierung nach dem Zeitstempel.
	 */
	public function testNachweislisteBleibtUnveraendert(): void
	{
		$vorher = $GLOBALS['TL_DCA']['tl_kinderschutz']['list']['sorting'];

		$this->listener('kinderschutz_nachweise')->beimLaden();

		$this->assertSame($vorher, $GLOBALS['TL_DCA']['tl_kinderschutz']['list']['sorting']);
	}

	/**
	 * In „Mehrere bearbeiten“ liest die Prüfung die Felder des jeweiligen
	 * Datensatzes.
	 *
	 * Contao hängt dort an jeden Feldnamen „_ID“ an (ablehnungsgrund_4). Die
	 * Prüfung las nur „ablehnungsgrund“ und lehnte im Prüfstand 5.7 jede
	 * Ablehnung mit „Bitte eine Begründung … angeben“ ab, obwohl eine dastand.
	 */
	public function testMehrereBearbeitenLiestFelderMitIdEndung(): void
	{
		$this->anfrage(array('status_4' => 'abgelehnt', 'ablehnungsgrund_4' => 'Teilnahme nicht nachgewiesen.'));
		$this->assertSame('abgelehnt', $this->listener('kinderschutz_nachweise')->statusPruefen('abgelehnt', $this->dc(4, 'status_4')));

		$this->anfrage(array('status_4' => 'geprueft', 'einschlaegig_4' => 'nein'));
		$this->assertSame('geprueft', $this->listener('kinderschutz_nachweise', true)->statusPruefen('geprueft', $this->dc(4, 'status_4')));
	}

	/**
	 * Fehlt die Begründung des jeweiligen Datensatzes, bleibt es beim Fehler –
	 * auch wenn zufällig ein Feld ohne Endung mitkäme.
	 */
	public function testMehrereBearbeitenOhneBegruendungBleibtFehler(): void
	{
		$this->anfrage(array('status_4' => 'abgelehnt', 'ablehnungsgrund_4' => '', 'ablehnungsgrund' => 'fremd'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Bitte eine Begründung für die Ablehnung angeben');

		$this->listener('kinderschutz_nachweise')->statusPruefen('abgelehnt', $this->dc(4, 'status_4'));
	}

	/**
	 * In der normalen Prüfmaske heißen die Felder wie im DCA.
	 */
	public function testPruefmaskeLiestFelderOhneEndung(): void
	{
		$this->anfrage(array('status' => 'abgelehnt', 'ablehnungsgrund' => 'Unterschrift fehlt.'));
		$this->assertSame('abgelehnt', $this->listener('kinderschutz_nachweise')->statusPruefen('abgelehnt', $this->dc(4, 'status')));

		$this->anfrage(array('status' => 'geprueft', 'einschlaegig' => ''));
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Bitte angeben, ob einschlägige Einträge vorliegen.');

		$this->listener('kinderschutz_nachweise', true)->statusPruefen('geprueft', $this->dc(4, 'status'));
	}

	/**
	 * Legt eine POST-Anfrage ins Backend und stellt Contao den Container bereit.
	 *
	 * @param array<string, string> $daten Formularfelder
	 */
	private function anfrage(array $daten): void
	{
		$anfragen = new RequestStack();
		$anfragen->push(new Request(array('do' => 'kinderschutz_nachweise'), $daten));

		$matcher = $this->createMock(ScopeMatcher::class);
		$matcher->method('isBackendRequest')->willReturn(true);

		$container = new Container();
		$container->set('request_stack', $anfragen);
		$container->set('contao.routing.scope_matcher', $matcher);
		System::setContainer($container);
	}

	/**
	 * Ein Data Container für das Feld „status“.
	 *
	 * @param int    $id        ID des Nachweises
	 * @param string $inputName Name des Eingabefelds, in „Mehrere bearbeiten“
	 *                          mit angehängter ID
	 *
	 * @return DataContainer Die Attrappe
	 */
	private function dc(int $id, string $inputName): DataContainer
	{
		$dc = $this->createMock(DataContainer::class);
		$dc->method('__get')->willReturnMap(array(array('id', $id), array('field', 'status'), array('inputName', $inputName)));

		return $dc;
	}

	/**
	 * Baut den Listener für eine Anfrage an ein Backend-Modul.
	 *
	 * Alle Dienste sind Attrappen; die Zugangsprüfung lässt durch. Der
	 * Nachweis gehört zur Dokumentart 1.
	 *
	 * @param string $do           Wert von „do“
	 * @param bool   $einschlaegig Die Dokumentart fragt einschlägige Einträge ab
	 *
	 * @return NachweisListener Der Listener
	 */
	private function listener(string $do, bool $einschlaegig = false): NachweisListener
	{
		$anfragen = new RequestStack();
		$anfragen->push(new Request(array('do' => $do)));

		$nachweise = $this->createMock(NachweisRepository::class);
		$nachweise->method('zeile')->willReturn(array('id' => 4, 'dokumentart' => 1));

		$arten = $this->createMock(DokumentartRepository::class);
		$arten->method('eine')->willReturn(new Dokumentart(1, 'Erweitertes Führungszeugnis', '', true, 24, $einschlaegig, $einschlaegig));

		return new NachweisListener(
			$this->createMock(Zugang::class),
			$this->createMock(Pruefung::class),
			$nachweise,
			$arten,
			$this->createMock(PersonenRepository::class),
			$this->createMock(Papierkorb::class),
			$this->createMock(Einstellungen::class),
			$this->createMock(UrlGeneratorInterface::class),
			$anfragen,
		);
	}
}
