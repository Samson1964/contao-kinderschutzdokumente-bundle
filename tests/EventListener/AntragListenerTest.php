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
use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\AntragRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einstellungen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Freischaltung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\DataContainer\AntragListener;
use Schachbulle\ContaoKinderschutzdokumenteBundle\EventListener\MeldungListener;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\EingabeAttrappe;

/**
 * Prüft Zugang, Palettenwahl, Eingabeprüfung und Rückmeldung der Maske „Anträge“.
 */
class AntragListenerTest extends TestCase
{
	use EingabeAttrappe;

	/**
	 * Legt eine kleine DCA und die nötigen Texte in die Globalen.
	 */
	protected function setUp(): void
	{
		$GLOBALS['TL_DCA']['tl_kinderschutz_antrag'] = array(
			'config'   => array(),
			'palettes' => array('default' => '{antrag_legend},info;{entscheidung_legend},status,grund', 'direkt' => '{direkt_legend},member,wofuer'),
			'fields'   => array(),
		);
		$GLOBALS['TL_LANG']['MSC']['kinderschutz_be'] = array('antragGrundFehlt' => 'Grund fehlt', 'antragMailGescheitert' => 'Mail weg', 'mitgliedGeloescht' => 'Mitglied gelöscht (#%d)', 'schonFreigeschaltet' => 'Schon frei');
		$GLOBALS['TL_LANG']['tl_kinderschutz_antrag'] = array(
			'member'               => array('Mitglied', ''),
			'wofuer'               => array('Wofür', ''),
			'status'               => array('Entscheidung', ''),
			'grund'                => array('Begründung', ''),
			'stati'                => array('offen' => 'offen', 'freigeschaltet' => 'freigeschaltet', 'abgelehnt' => 'abgelehnt'),
			'beantragtAm'          => 'Beantragt am',
			'veranstaltungenKopf'  => 'Angekreuzte Veranstaltungen',
			'keineVeranstaltungen' => 'keine',
			'entschieden'          => 'am %s von %s',
			'direktVermerk'        => 'Direkt durch den Prüfer freigeschaltet',
			'direktKurz'           => 'direkt',
		);
	}

	/**
	 * Räumt die Globalen weg.
	 */
	protected function tearDown(): void
	{
		unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
	}

	/**
	 * Ohne Freigabe bricht schon das Laden ab.
	 */
	public function testOhneFreigabeZugriffVerweigert(): void
	{
		$zugang = $this->createMock(Zugang::class);
		$zugang->method('verlange')->willThrowException(new AccessDeniedException('nein'));

		$this->expectException(AccessDeniedException::class);
		$this->listener(array('zugang' => $zugang))->beimLaden($this->dc(1));
	}

	/**
	 * Anträge lassen sich weder auswählen noch gesammelt bearbeiten, löschen
	 * oder kopieren; die Liste bietet das gar nicht an.
	 *
	 * @dataProvider sammelaktionen
	 *
	 * @param string $act Die Aktion
	 */
	public function testSammelaktionenGesperrt(string $act): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->listener(array('act' => $act))->beimLaden($this->dc(0));
	}

	/**
	 * Die gesperrten Sammelaktionen.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function sammelaktionen(): array
	{
		return array(
			'Auswahl'                  => array('select'),
			'Mehrere bearbeiten'       => array('editAll'),
			'Überschreiben'            => array('overrideAll'),
			'Mehrere löschen'          => array('deleteAll'),
			'Mehrere kopieren'         => array('copyAll'),
			// Contao ruft $dc->$act() auf; PHP-Methodennamen sind von der
			// Schreibweise unabhängig, die Sperre muss es auch sein.
			'Auswahl groß'             => array('Select'),
			'Mehrere bearbeiten klein' => array('editall'),
			'Überschreiben groß'       => array('OVERRIDEALL'),
			'Mehrere löschen gemischt' => array('DeleteAll'),
			'Mehrere kopieren klein'   => array('copyall'),
		);
	}

	/**
	 * Verschieben ist gesperrt, auch über das Einfügen nach dem Ausschneiden.
	 *
	 * „notSortable“ allein ergäbe in Contao 4.13 HTTP 500 statt 403.
	 *
	 * @dataProvider verschiebeaktionen
	 *
	 * @param string      $act  Die Aktion
	 * @param string|null $modus Wert von „mode“
	 */
	public function testVerschiebenGesperrt(string $act, ?string $modus): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->listener(array('act' => $act) + (null === $modus ? array() : array('mode' => $modus)))->beimLaden($this->dc(3));
	}

	/**
	 * Die gesperrten Verschiebeaktionen.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function verschiebeaktionen(): array
	{
		return array(
			'Ausschneiden'               => array('cut', null),
			'Mehrere ausschneiden'       => array('cutAll', null),
			'Einfügen nach Ausschneiden' => array('paste', 'cut'),
			'Ausschneiden groß'          => array('Cut', null),
			'Mehrere ausschneiden klein' => array('cutall', null),
			'Einfügen gemischt'          => array('Paste', 'CUT'),
		);
	}

	/**
	 * Werte, die erst Contao 4.13 zu einer Aktion normalisiert (Input::get()
	 * dekodiert Entitäten und entfernt Steuerzeichen), ergeben 403.
	 *
	 * @dataProvider umgehungen
	 *
	 * @param string $act Roher Wert von „act“
	 */
	public function testUmgehungenVerweigert(string $act): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->listener(array('act' => $act))->beimLaden($this->dc(3));
	}

	/**
	 * Umgehungsversuche über die Normalisierung von Contao 4.13.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function umgehungen(): array
	{
		return array(
			'Nullbyte'        => array("cu\0t"),
			'Wagenrücklauf'   => array("cu\rt"),
			'Entität cut'     => array('cu&#116;'),
			'Entität editAll' => array('ed&#105;tAll'),
			'Entität copy'    => array('co&#112;y'),
			'Entität edit'    => array('ed&#105;t'),
		);
	}

	/**
	 * Auch ein einzelner Antrag lässt sich nicht kopieren.
	 *
	 * „notCopyable“ allein ergab im Prüfstand 4.13 HTTP 500 („Table
	 * "tl_kinderschutz_antrag" is not copyable.“), in 5.7 dagegen 403.
	 *
	 * @dataProvider kopieraktionen
	 *
	 * @param string $act Die Aktion
	 */
	public function testKopierenGesperrt(string $act): void
	{
		$this->expectException(AccessDeniedException::class);
		$this->listener(array('act' => $act))->beimLaden($this->dc(3));
	}

	/**
	 * Die gesperrten Kopieraktionen.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function kopieraktionen(): array
	{
		return array(
			'Kopieren'      => array('copy'),
			'Kopieren groß' => array('Copy'),
		);
	}

	/**
	 * Ein frisch angelegter Datensatz bekommt die Palette „direkt“.
	 */
	public function testNeuerDatensatzBekommtDiePaletteDirekt(): void
	{
		$this->listener(array('zeile' => array('status' => '', 'entschiedenAm' => 0)))->beimLaden($this->dc(3));

		$this->assertSame('{direkt_legend},member,wofuer', $GLOBALS['TL_DCA']['tl_kinderschutz_antrag']['palettes']['default']);
	}

	/**
	 * Nach der Entscheidung zeigt die Maske nur noch den Infokasten.
	 */
	public function testEntschiedenerAntragZeigtNurDenInfokasten(): void
	{
		$this->listener(array('zeile' => array('status' => 'abgelehnt', 'entschiedenAm' => 5)))->beimLaden($this->dc(3));

		$this->assertSame('{antrag_legend},info', $GLOBALS['TL_DCA']['tl_kinderschutz_antrag']['palettes']['default']);
	}

	/**
	 * Auch „act=Edit“ zeigt bei einem entschiedenen Antrag nur den Infokasten.
	 *
	 * Contao führt über $dc->$act() dieselbe Methode edit() aus; ohne
	 * Kleinschreibung bekäme die Maske wieder Stand und Begründung, und ein
	 * entschiedener Antrag ließe sich neu entscheiden.
	 */
	public function testEditInAndererSchreibweiseZeigtNurDenInfokasten(): void
	{
		$this->listener(array('act' => 'Edit', 'zeile' => array('status' => 'freigeschaltet', 'entschiedenAm' => 5)))->beimLaden($this->dc(3));

		$this->assertSame('{antrag_legend},info', $GLOBALS['TL_DCA']['tl_kinderschutz_antrag']['palettes']['default']);
	}

	/**
	 * Ein offener Antrag behält die Entscheidungsfelder.
	 */
	public function testOffenerAntragBehaeltDieEntscheidung(): void
	{
		$this->listener(array('zeile' => array('status' => 'offen', 'entschiedenAm' => 0)))->beimLaden($this->dc(3));

		$this->assertSame('{antrag_legend},info;{entscheidung_legend},status,grund', $GLOBALS['TL_DCA']['tl_kinderschutz_antrag']['palettes']['default']);
	}

	/**
	 * Die Liste (ohne act) lässt die Paletten in Ruhe.
	 */
	public function testListeLaesstPalettenInRuhe(): void
	{
		$this->listener(array('act' => '', 'zeile' => array('status' => 'abgelehnt', 'entschiedenAm' => 5)))->beimLaden($this->dc(3));

		$this->assertSame('{antrag_legend},info;{entscheidung_legend},status,grund', $GLOBALS['TL_DCA']['tl_kinderschutz_antrag']['palettes']['default']);
	}

	/**
	 * Ein Fehler der Eingabeprüfung erscheint als Text am Feld; die Prüfung
	 * bekommt die Begründung aus dem Formular und die aktuelle Zeit.
	 */
	public function testStatusPruefenWirftMitText(): void
	{
		$freischaltung = $this->createMock(Freischaltung::class);
		$freischaltung->expects($this->once())->method('pruefeEntscheidung')->with($this->anything(), 'abgelehnt', '', $this->isType('int'))->willReturn('antragGrundFehlt');

		$this->expectExceptionObject(new \RuntimeException('Grund fehlt'));
		$this->listener(array('freischaltung' => $freischaltung, 'zeile' => array('status' => 'offen', 'entschiedenAm' => 0)))->statusPruefen('abgelehnt', $this->dc(3));
	}

	/**
	 * Die Begründung wird aus dem Formular gelesen und der Wert unverändert
	 * durchgereicht, wenn die Prüfung nichts findet.
	 */
	public function testStatusPruefenLiestBegruendungAusDemFormular(): void
	{
		$freischaltung = $this->createMock(Freischaltung::class);
		$freischaltung->expects($this->once())->method('pruefeEntscheidung')->with($this->anything(), 'abgelehnt', 'Bitte erst den Verein fragen', $this->isType('int'))->willReturn(null);

		$wert = $this->listener(array('freischaltung' => $freischaltung, 'zeile' => array('status' => 'offen', 'entschiedenAm' => 0), 'post' => array('grund' => 'Bitte erst den Verein fragen')))->statusPruefen('abgelehnt', $this->dc(3));

		$this->assertSame('abgelehnt', $wert);
	}

	/**
	 * Maßgeblich ist die Begründung, die Contao über Input::post() liest.
	 *
	 * Contao 4.13 normalisiert POST-Namen; der Symfony-Request kann deshalb
	 * „x“ enthalten, während Contao „“ speichert. Die Attrappe liefert den
	 * Wert, den Contao sieht – hier leer –, und die Prüfung bekommt genau ihn.
	 */
	public function testStatusPruefenNimmtDieBegruendungAusDemInputAdapter(): void
	{
		$freischaltung = $this->createMock(Freischaltung::class);
		$freischaltung->expects($this->once())->method('pruefeEntscheidung')->with($this->anything(), 'abgelehnt', '', $this->isType('int'))->willReturn('antragGrundFehlt');

		$this->expectException(\RuntimeException::class);
		$this->listener(array('freischaltung' => $freischaltung, 'zeile' => array('status' => 'offen', 'entschiedenAm' => 0), 'post' => array('grund' => '')))->statusPruefen('abgelehnt', $this->dc(3));
	}

	/**
	 * Die Mitgliedsauswahl in „Mitglied freischalten“ meldet ihren Fehler am Feld.
	 */
	public function testMitgliedPruefenWirftMitText(): void
	{
		$freischaltung = $this->createMock(Freischaltung::class);
		$freischaltung->method('pruefeDirekt')->with(5, $this->isType('int'))->willReturn('schonFreigeschaltet');

		$this->expectExceptionObject(new \RuntimeException('Schon frei'));
		$this->listener(array('freischaltung' => $freischaltung))->mitgliedPruefen('5', $this->dc(3));
	}

	/**
	 * Zur Auswahl stehen die aktiven Mitglieder außerhalb der Freischaltgruppe.
	 */
	public function testMitgliedOptionen(): void
	{
		$einstellungen = $this->createMock(Einstellungen::class);
		$einstellungen->method('gruppe')->willReturn(7);
		$personen = $this->createMock(PersonenRepository::class);
		$personen->expects($this->once())->method('aktiveOhneGruppe')->with(7, $this->isType('int'))->willReturn(array(
			array('id' => 5, 'firstname' => 'Carla', 'lastname' => 'Cramer', 'email' => 'c@example.org'),
		));

		$this->assertSame(array(5 => 'Cramer, Carla (c@example.org)'), $this->listener(array('personen' => $personen, 'einstellungen' => $einstellungen))->mitgliedOptionen());
	}

	/**
	 * Der Infokasten maskiert Eingaben und nennt die Entscheidung samt Vermerk „direkt“.
	 */
	public function testInfokastenNenntEntscheidung(): void
	{
		$personen = $this->createMock(PersonenRepository::class);
		$personen->method('mitglied')->willReturn(array('id' => 5, 'firstname' => 'Carla', 'lastname' => 'Cramer', 'email' => 'c@example.org'));
		$personen->method('benutzerName')->with(2)->willReturn('Astrid');

		$html = $this->listener(array('personen' => $personen, 'zeile' => array(
			'status'          => 'freigeschaltet',
			'entschiedenAm'   => strtotime('2026-10-16 11:30'),
			'entschiedenVon'  => 2,
			'beantragtAm'     => strtotime('2026-10-15 09:00'),
			'wofuer'          => "Training <U12>\nund Turniere",
			'veranstaltungen' => null,
			'direkt'          => '1',
			'grund'           => '',
		)))->info($this->dc(3));

		$this->assertStringContainsString('Cramer, Carla (c@example.org)', $html);
		$this->assertStringContainsString('Training &lt;U12&gt;<br />', $html);
		$this->assertStringContainsString('<td>keine</td>', $html);
		$this->assertStringContainsString('freigeschaltet am 16.10.2026 11:30 von Astrid', $html);
		$this->assertStringContainsString('Direkt durch den Prüfer freigeschaltet', $html);
		$this->assertStringNotContainsString('Begründung', $html);
	}

	/**
	 * Eine Begründung erscheint nur bei einer Ablehnung.
	 *
	 * Contao 4.13 speichert die Felder einzeln: Scheitert die Prüfung des
	 * Stands („abgelehnt“ ohne echte Begründung), wird „grund“ trotzdem
	 * geschrieben. Im Prüfstand stand danach bei einem freigeschalteten
	 * Antrag „Begründung <b></b>“ im Kasten.
	 *
	 * @dataProvider staendeMitBegruendung
	 *
	 * @param string $stand    Gespeicherter Stand
	 * @param bool   $erwartet Ob die Begründung erscheinen soll
	 */
	public function testInfokastenZeigtBegruendungNurBeiAblehnung(string $stand, bool $erwartet): void
	{
		$personen = $this->createMock(PersonenRepository::class);
		$personen->method('benutzerName')->willReturn('Astrid');

		$html = $this->listener(array('personen' => $personen, 'zeile' => array(
			'status'          => $stand,
			'entschiedenAm'   => strtotime('2026-10-16 11:30'),
			'entschiedenVon'  => 2,
			'beantragtAm'     => strtotime('2026-10-15 09:00'),
			'wofuer'          => 'Training',
			'veranstaltungen' => null,
			'direkt'          => '',
			'grund'           => '&lt;b>Rest&lt;/b>',
		)))->info($this->dc(3));

		$this->assertSame($erwartet, str_contains($html, 'Begründung'));
	}

	/**
	 * Entschiedene Stände und ob die Begründung dazu erscheint.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function staendeMitBegruendung(): array
	{
		return array(
			'abgelehnt'      => array('abgelehnt', true),
			'freigeschaltet' => array('freigeschaltet', false),
		);
	}

	/**
	 * Scheitert die Mail, wird der Hinweis für die Folgeseite vorgemerkt.
	 */
	public function testNachSpeichernMerktHinweisBeiGescheiterterMail(): void
	{
		$freischaltung = $this->createMock(Freischaltung::class);
		$freischaltung->method('nachSpeichern')->willReturn(false);
		$meldungen = $this->createMock(MeldungListener::class);
		$meldungen->expects($this->once())->method('vormerken')->with('Mail weg');

		$this->listener(array('freischaltung' => $freischaltung, 'meldungen' => $meldungen))->nachSpeichern($this->dc(3));
	}

	/**
	 * Ohne Mailfehler gibt es keinen Hinweis; der Prüfer wird mitgegeben.
	 */
	public function testNachSpeichernOhneHinweisBeiErfolg(): void
	{
		$zugang = $this->createMock(Zugang::class);
		$zugang->method('benutzerId')->willReturn(2);
		$freischaltung = $this->createMock(Freischaltung::class);
		$freischaltung->expects($this->once())->method('nachSpeichern')->with(3, 2, $this->isType('int'))->willReturn(true);
		$meldungen = $this->createMock(MeldungListener::class);
		$meldungen->expects($this->never())->method('vormerken');

		$this->listener(array('zugang' => $zugang, 'freischaltung' => $freischaltung, 'meldungen' => $meldungen))->nachSpeichern($this->dc(3));
	}

	/**
	 * Die Liste nennt Name, Datum, Vermerk „direkt“ und den Anfang der Begründung.
	 */
	public function testBeschriftung(): void
	{
		$personen = $this->createMock(PersonenRepository::class);
		$personen->method('mitglied')->willReturn(array('id' => 5, 'firstname' => 'Carla', 'lastname' => 'Cramer', 'email' => 'c@example.org'));

		$text = $this->listener(array('personen' => $personen))->beschriftung(array('member' => 5, 'beantragtAm' => strtotime('2026-10-16 10:00'), 'direkt' => '1', 'wofuer' => 'Training <U12>'));

		$this->assertStringContainsString('<strong>Cramer, Carla</strong>', $text);
		$this->assertStringContainsString('16.10.2026', $text);
		$this->assertStringContainsString('direkt', $text);
		$this->assertStringContainsString('Training &lt;U12&gt;', $text);
	}

	/**
	 * Ein gelöschtes Mitglied erscheint mit seiner ID; kodiert gespeicherte
	 * Zeichen werden beim Kürzen nicht zerschnitten.
	 */
	public function testBeschriftungGeloeschtesMitgliedUndKodierterText(): void
	{
		$text = $this->listener()->beschriftung(array('member' => 9, 'beantragtAm' => 0, 'direkt' => '', 'wofuer' => str_repeat('x', 78).'&#40;U12&#41;'));

		$this->assertStringContainsString('<strong>Mitglied gelöscht (#9)</strong>', $text);
		$this->assertStringContainsString('(–)', $text);
		$this->assertStringEndsWith(str_repeat('x', 78).'(U', $text);
	}

	/**
	 * Eine Attrappe des Data Containers.
	 *
	 * @param int $id ID des Datensatzes
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
	 * Baut den Listener mit Attrappen.
	 *
	 * @param array<string, mixed> $ersatz Ersatz unter zugang, freischaltung,
	 *                                     meldungen, personen, einstellungen,
	 *                                     act (Aktion der Anfrage, Vorgabe
	 *                                     „edit“), post (Formularwerte) und
	 *                                     zeile (gespeicherter Antrag, Vorgabe
	 *                                     keiner)
	 *
	 * @return AntragListener Der Listener
	 */
	private function listener(array $ersatz = array()): AntragListener
	{
		$antraege = $this->createMock(AntragRepository::class);
		$antraege->method('zeile')->willReturn(isset($ersatz['zeile']) ? $ersatz['zeile'] + array('id' => 3, 'member' => 5) : null);

		$act = $ersatz['act'] ?? 'edit';
		$abfrage = ('' === $act ? array() : array('act' => $act)) + (isset($ersatz['mode']) ? array('mode' => $ersatz['mode']) : array());

		return new AntragListener(
			$ersatz['zugang'] ?? $this->createMock(Zugang::class),
			$antraege,
			$ersatz['personen'] ?? $this->createMock(PersonenRepository::class),
			$this->createMock(VeranstaltungRepository::class),
			$ersatz['einstellungen'] ?? $this->createMock(Einstellungen::class),
			$ersatz['freischaltung'] ?? $this->createMock(Freischaltung::class),
			$ersatz['meldungen'] ?? $this->createMock(MeldungListener::class),
			$this->eingabe($abfrage, $ersatz['post'] ?? array()),
		);
	}
}
