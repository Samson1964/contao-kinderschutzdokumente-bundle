<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EventListener;

use Contao\CoreBundle\Exception\AccessDeniedException;
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
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\MeldungListener;
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
	 * Verschieben ist in Nachweisliste und Papierkorb gesperrt.
	 *
	 * „notSortable“ allein ergäbe in Contao 4.13 HTTP 500 statt 403.
	 *
	 * @dataProvider verschiebeaktionen
	 *
	 * @param string               $do      Wert von „do“
	 * @param array<string, string> $abfrage act und ggf. mode
	 */
	public function testVerschiebenGesperrt(string $do, array $abfrage): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->listener($do, false, $abfrage + array('id' => '4'))->beimLaden();
	}

	/**
	 * Die gesperrten Verschiebeaktionen.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public static function verschiebeaktionen(): array
	{
		return array(
			'Ausschneiden'                 => array('kinderschutz_nachweise', array('act' => 'cut')),
			'Mehrere ausschneiden'         => array('kinderschutz_nachweise', array('act' => 'cutAll')),
			'Einfügen nach Ausschneiden' => array('kinderschutz_nachweise', array('act' => 'paste', 'mode' => 'cut')),
			'Papierkorb'                   => array('kinderschutz_papierkorb', array('act' => 'cut')),
			'Ausschneiden groß'            => array('kinderschutz_nachweise', array('act' => 'Cut')),
			'Mehrere ausschneiden klein'   => array('kinderschutz_nachweise', array('act' => 'cutall')),
			'Einfügen gemischt'            => array('kinderschutz_nachweise', array('act' => 'PASTE', 'mode' => 'Cut')),
		);
	}

	/**
	 * Kopieren ist in Nachweisliste und Papierkorb gesperrt.
	 *
	 * „notCopyable“ allein ergab im Prüfstand 4.13 HTTP 500 („Table
	 * "tl_kinderschutz" is not copyable.“), in 5.7 dagegen 403.
	 *
	 * @dataProvider kopieraktionen
	 *
	 * @param string $do  Wert von „do“
	 * @param string $act Wert von „act“
	 */
	public function testKopierenGesperrt(string $do, string $act): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->listener($do, false, array('act' => $act, 'id' => '4'))->beimLaden();
	}

	/**
	 * Die gesperrten Kopieraktionen, auch in anderer Schreibweise.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function kopieraktionen(): array
	{
		return array(
			'Kopieren'                => array('kinderschutz_nachweise', 'copy'),
			'Mehrere kopieren'        => array('kinderschutz_nachweise', 'copyAll'),
			'Papierkorb'              => array('kinderschutz_papierkorb', 'copy'),
			'Kopieren groß'           => array('kinderschutz_nachweise', 'COPY'),
			'Mehrere kopieren klein'  => array('kinderschutz_nachweise', 'copyall'),
		);
	}

	/**
	 * Gegenprobe: „paste&mode=copy“ zeigt nur die Liste mit Zwischenablage
	 * und bleibt erlaubt; gesperrt wird erst der Kopiervorgang selbst.
	 */
	public function testEinfuegenNachKopierenBleibtErlaubt(): void
	{
		$this->listener('kinderschutz_nachweise', false, array('act' => 'paste', 'mode' => 'copy', 'id' => '4'))->beimLaden();

		$this->addToAssertionCount(1);
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
	 * In „Mehrere bearbeiten“ und „Überschreiben“ zählt die Art aus der Datenbank.
	 *
	 * Dort ist die Dokumentart gesperrt. Eine nachgebaute Anfrage, die
	 * trotzdem dokumentart (bzw. dokumentart_4) mit einer Art ohne Abfrage
	 * „Einschlägige Einträge“ schickt, umging bisher die Pflichtangabe, weil
	 * die Prüfung die Art zuerst aus dem Formular las.
	 *
	 * @dataProvider sammelaktionenMitEndung
	 *
	 * @param string $act    Wert von „act“
	 * @param string $endung Endung der Feldnamen in dieser Aktion
	 */
	public function testSammelbearbeitungLiestArtAusDerDatenbank(string $act, string $endung): void
	{
		$this->anfrage(array('status'.$endung => 'geprueft', 'einschlaegig'.$endung => '', 'dokumentart'.$endung => '2'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Bitte angeben, ob einschlägige Einträge vorliegen.');

		$this->listener('kinderschutz_nachweise', true, array('act' => $act))->statusPruefen('geprueft', $this->dc(4, 'status'.$endung));
	}

	/**
	 * Liefert die beiden Sammelaktionen samt Endung ihrer Feldnamen.
	 *
	 * „Mehrere bearbeiten“ hängt die ID des Datensatzes an, „Überschreiben“
	 * nicht.
	 *
	 * @return array<string, array{string, string}> Je Aktion „act“ und Endung
	 */
	public static function sammelaktionenMitEndung(): array
	{
		return array(
			'Mehrere bearbeiten' => array('editAll', '_4'),
			'Überschreiben'      => array('overrideAll', ''),
			'Mehrere bearbeiten klein' => array('editall', '_4'),
		);
	}

	/**
	 * Gegenprobe: Die Einzelmaske nimmt die Art weiter aus dem Formular.
	 *
	 * Bei einem neuen Prüfvermerk ohne Upload wählt der Prüfer die Art im
	 * selben Formular; sie steht beim Speichern des Status noch nicht in der
	 * Datenbank.
	 */
	public function testEinzelmaskeLiestArtAusDemFormular(): void
	{
		$this->anfrage(array('status' => 'geprueft', 'einschlaegig' => '', 'dokumentart' => '2'));

		$this->assertSame('geprueft', $this->listener('kinderschutz_nachweise', true, array('act' => 'edit', 'id' => '4'))->statusPruefen('geprueft', $this->dc(4, 'status')));
	}

	/**
	 * „Mehrere bearbeiten“ und „Überschreiben“ bieten Person und Art nicht an.
	 *
	 * Im Prüfstand 5.7 standen beide in der Feldauswahl; so ließ sich ein
	 * hochgeladenes Führungszeugnis einer anderen Person zuordnen. Contao
	 * listet dort jedes Feld mit inputType (oder input_field_callback), das
	 * nicht doNotShow trägt; die Bedingung ist hier nachgebaut. Geprüft wird
	 * außerdem, dass die Felder aus der Palette verschwinden und die übrigen
	 * Prüffelder wählbar bleiben.
	 *
	 * @dataProvider sammelaktionen
	 *
	 * @param string $act Wert von „act“
	 */
	public function testSammelbearbeitungSperrtPersonUndArt(string $act): void
	{
		$this->listener('kinderschutz_nachweise', false, array('act' => $act))->beimLaden();

		$dca = $GLOBALS['TL_DCA']['tl_kinderschutz'];
		$angeboten = array_keys(array_filter($dca['fields'], static fn (array $feld): bool => !($feld['eval']['doNotShow'] ?? false) && (isset($feld['inputType']) || isset($feld['input_field_callback']))));

		$this->assertNotContains('member', $angeboten);
		$this->assertNotContains('dokumentart', $angeboten);
		$this->assertSame(array('status', 'ausstellungsdatum', 'einschlaegig', 'ablehnungsgrund', 'bemerkung'), $angeboten);
		$this->assertStringNotContainsString('member', $dca['palettes']['default']);
		$this->assertStringNotContainsString('dokumentart', $dca['palettes']['default']);
		$this->assertStringContainsString('{pruefung_legend},status,ausstellungsdatum,einschlaegig,ablehnungsgrund', $dca['palettes']['default']);
	}

	/**
	 * Liefert die beiden Sammelaktionen von DC_Table.
	 *
	 * @return array<string, array{string}> Je Aktion der Wert von „act“
	 */
	public static function sammelaktionen(): array
	{
		return array(
			'Mehrere bearbeiten' => array('editAll'),
			'Überschreiben'      => array('overrideAll'),
			// $dc->$act() ist von der Schreibweise unabhängig
			'Mehrere bearbeiten klein' => array('editall'),
			'Überschreiben gemischt'   => array('OverrideAll'),
		);
	}

	/**
	 * Die Einzelmaske sperrt Person und Art nur bei hochgeladenen Nachweisen.
	 *
	 * Gegenprobe zur Sammelbearbeitung: Ein Prüfvermerk ohne Upload behält
	 * beide Felder samt Eingabefeld, ebenso die Auswahlseite act=select;
	 * ein Upload aus dem Frontend verliert sie nur aus der Palette.
	 */
	public function testEinzelmaskeSperrtNurUploads(): void
	{
		$palette = $GLOBALS['TL_DCA']['tl_kinderschutz']['palettes']['default'];

		$this->listener('kinderschutz_nachweise', false, array('act' => 'edit', 'id' => '4'), array('id' => 4, 'dokumentart' => 1, 'quelle' => 'backend'))->beimLaden();
		$this->listener('kinderschutz_nachweise', false, array('act' => 'select'))->beimLaden();

		$this->assertSame($palette, $GLOBALS['TL_DCA']['tl_kinderschutz']['palettes']['default']);
		$this->assertSame('select', $GLOBALS['TL_DCA']['tl_kinderschutz']['fields']['member']['inputType']);
		$this->assertSame('select', $GLOBALS['TL_DCA']['tl_kinderschutz']['fields']['dokumentart']['inputType']);

		$this->listener('kinderschutz_nachweise', false, array('act' => 'edit', 'id' => '4'), array('id' => 4, 'dokumentart' => 1, 'quelle' => 'frontend'))->beimLaden();

		$this->assertStringNotContainsString('member', $GLOBALS['TL_DCA']['tl_kinderschutz']['palettes']['default']);
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
	 * Nachweis gehört zur Dokumentart 1, einem Führungszeugnis; jede andere
	 * Art ist ein Ehrenkodex, der nie nach einschlägigen Einträgen fragt.
	 *
	 * @param string                    $do           Wert von „do“
	 * @param bool                      $einschlaegig Die Dokumentart fragt einschlägige Einträge ab
	 * @param array<string, string>     $abfrage      Weitere Werte der Adresse, etwa act und id
	 * @param array<string, mixed>|null $zeile        Der Nachweis, den das Repository liefert;
	 *                                                null nimmt ID 4 mit Dokumentart 1
	 *
	 * @return NachweisListener Der Listener
	 */
	private function listener(string $do, bool $einschlaegig = false, array $abfrage = array(), ?array $zeile = null): NachweisListener
	{
		$anfragen = new RequestStack();
		$anfragen->push(new Request(array_merge(array('do' => $do), $abfrage)));

		$nachweise = $this->createMock(NachweisRepository::class);
		$nachweise->method('zeile')->willReturn($zeile ?? array('id' => 4, 'dokumentart' => 1));

		$arten = $this->createMock(DokumentartRepository::class);
		$arten->method('eine')->willReturnCallback(static fn (int $id): Dokumentart => 1 === $id
			? new Dokumentart(1, 'Erweitertes Führungszeugnis', '', true, 24, $einschlaegig, $einschlaegig)
			: new Dokumentart($id, 'Ehrenkodex', '', true, 0, false, false));

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
			new MeldungListener(),
		);
	}
}
