<?php

declare(strict_types=1);

/*
 * Kinderschutzdokumente-Bundle: Kinderschutz-Unterlagen online einreichen
 * und prüfen — lauffähig unter Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\FrontendUser;
use Contao\ModuleModel;
use Contao\Template;
use Doctrine\DBAL\Connection;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Controller\FrontendModule\UnterlagenController;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\DokumentartRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Einreichung;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Statusdienst;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\UploadPruefer;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Datenbank as Testdatenbank;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Dienst\Testhilfen;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Testdateien;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Prüft das Verhalten des Frontend-Moduls „Kinderschutz: Meine Unterlagen“:
 * Anmeldung und Gruppe, Herkunft der Mitglieds-ID, Fehlermeldungen,
 * Umleitung nach Erfolg und das Ignorieren fremder Formulare.
 *
 * Datenbank (SQLite im Arbeitsspeicher), Statusdienst, Repositories und
 * Einstellungen sind echt. Gemockt oder nachgebaut sind nur die Teile, die
 * eine Contao-Laufzeit bräuchten: die Einreichung (sie legt Dateien ab und
 * verschickt Mails), die Anmeldung (ein Token mit einem FrontendUser ohne
 * Konstruktor), das Modul-Modell, der Anfragetoken-Manager und die Vorlage.
 * Die Vorlage ist eine Unterklasse von Contao\Template, die nur festhält, was
 * der Controller ihr zuweist; FragmentTemplate selbst ist final und nicht
 * nachbildbar, der Controller verlangt deshalb absichtlich nur Template.
 *
 * getResponse() ist protected und wird per Reflection aufgerufen. Das ist
 * der Einstieg, den auch Contao benutzt (über __invoke, das nur die Vorlage
 * anlegt und hierher weiterreicht).
 */
class UnterlagenControllerTest extends Testhilfen
{
	/** Inhalt der Antwort, die die nachgebaute Vorlage liefert */
	private const VORLAGE_ANTWORT = 'Vorlage gerendert';

	/** ID des Frontend-Moduls, an dessen Formularen der Controller seine Eingaben erkennt */
	private const MODUL = 7;

	/** Anfragetoken mit einem Anführungszeichen, damit auch die Weitergabe unverändert bleibt */
	private const ANFRAGETOKEN = 'tok"en';

	private Connection $db;

	/** Das angemeldete Mitglied, in einer kinderschutzpflichtigen Gruppe */
	private int $mitglied;

	/** Ein anderes Mitglied derselben Gruppe, dessen ID ein Angreifer ins Formular schreiben könnte */
	private int $fremder;

	/** ID der Dokumentart „Erweitertes Führungszeugnis“ */
	private int $efz;

	/** ID der Dokumentart „Ehrenkodex“ */
	private int $kodex;

	/** @var array<int, string>|null Merkt die CSS-Liste, die der Controller verlängert */
	private ?array $cssVorher = null;

	/**
	 * Legt Datenbank, Mitglieder und Dokumentarten an und lädt die Texte.
	 *
	 * Testhilfen::setUp() leert $GLOBALS['TL_LANG']; der Controller liest aber
	 * $GLOBALS['TL_LANG']['MSC']['kinderschutz_fe'], das Contao im Frontend
	 * mit der Sprachdatei „default“ bereitstellt. Deshalb wird die deutsche
	 * Sprachdatei des Bundles hier geladen. Die kinderschutzpflichtige
	 * Gruppe ist die Gruppe 1 (siehe Testhilfen).
	 */
	protected function setUp(): void
	{
		parent::setUp();

		include __DIR__.'/../../src/Resources/contao/languages/de/default.php';

		$this->cssVorher = $GLOBALS['TL_CSS'] ?? null;
		$GLOBALS['TL_CSS'] = array();

		$this->db = Testdatenbank::verbindung();
		$this->mitglied = Testdatenbank::mitglied($this->db, 'Erika', 'Muster', array(1));
		$this->fremder = Testdatenbank::mitglied($this->db, 'Frank', 'Fremd', array(1));
		$this->efz = Testdatenbank::dokumentart($this->db, 'Erweitertes Führungszeugnis', 60, true, true, 1);
		$this->kodex = Testdatenbank::dokumentart($this->db, 'Ehrenkodex', 0, false, true, 2);
	}

	/**
	 * Stellt die CSS-Liste wieder her und räumt die Globalen weg.
	 */
	protected function tearDown(): void
	{
		if (null === $this->cssVorher) {
			unset($GLOBALS['TL_CSS']);
		} else {
			$GLOBALS['TL_CSS'] = $this->cssVorher;
		}

		parent::tearDown();
	}

	/**
	 * Fälle, in denen niemand angemeldet ist.
	 *
	 * @return array<string, array{0: string}> Art der Anmeldung (siehe speicher())
	 */
	public static function ohneAnmeldung(): array
	{
		return array(
			'kein Token (Besucher)'                        => array('keiner'),
			'angemeldet, aber kein Frontend-Mitglied'      => array('fremdesKonto'),
			'Token ohne Benutzer'                          => array('ohneBenutzer'),
		);
	}

	/**
	 * Mitglieder, die nicht (mehr) zum Kreis der Kinderschutzpflichtigen gehören.
	 *
	 * @return array<string, array{0: string}> Fall (siehe mitgliedFuer())
	 */
	public static function ohneZugang(): array
	{
		return array(
			'nur in einer anderen Gruppe'    => array('andereGruppe'),
			'Konto deaktiviert'              => array('gesperrt'),
			'Konto bereits abgelaufen'       => array('abgelaufen'),
			'Konto noch nicht freigeschaltet' => array('zuFrueh'),
			'Mitglied existiert nicht mehr'  => array('geloescht'),
		);
	}

	/**
	 * Schlüssel der Upload-Fehler, die die Einreichung melden kann.
	 *
	 * @return array<string, array{0: string}> Schlüssel; der letzte ist einer, den die Sprachdatei nicht kennt
	 */
	public static function uploadFehler(): array
	{
		return array(
			'zu groß'          => array('zuGross'),
			'nicht angekommen' => array('upload'),
			'leer'             => array('leer'),
			'falscher Typ'     => array('typ'),
			'Datum'            => array('datum'),
			'Art nicht mehr angenommen' => array('art'),
			'unbekannter Schlüssel' => array('neuerSchluessel'),
		);
	}

	/**
	 * Höchstgrößen und ihre Schreibweise in den Meldungen.
	 *
	 * @return array<string, array{0: int, 1: string}> Höchstgröße in Bytes, erwartete Schreibweise
	 */
	public static function groessen(): array
	{
		return array(
			'ganze Megabyte'     => array(10 * 1048576, '10 MB'),
			'ein Megabyte'       => array(1048576, '1 MB'),
			'Nachkommastelle'    => array(2621440, '2.5 MB'),
			'unter einem Megabyte' => array(512 * 1024, '512 KB'),
		);
	}

	/**
	 * Eingaben für das Ausstellungsdatum und der Zeitstempel, den die Einreichung bekommt.
	 *
	 * @return array<string, array{0: string, 1: int}> Formularwert, erwarteter Zeitstempel (0 = unbrauchbar)
	 */
	public static function ausstellungsdaten(): array
	{
		return array(
			'gültiges Datum'       => array('2026-09-01', mktime(0, 0, 0, 9, 1, 2026)),
			'Schaltjahrtag'        => array('2024-02-29', mktime(0, 0, 0, 2, 29, 2024)),
			'leer'                 => array('', 0),
			'Unsinn'               => array('morgen', 0),
			'deutsche Schreibweise' => array('01.09.2026', 0),
		);
	}

	/**
	 * Formularkennungen, die nicht zu diesem Modul gehören.
	 *
	 * @return array<string, array{0: string}> Wert von FORM_SUBMIT
	 */
	public static function fremdeFormulare(): array
	{
		return array(
			'anderes Modul, Upload'        => array('kinderschutz_99_upload'),
			'anderes Modul, Veranstaltung' => array('kinderschutz_99_veranstaltung'),
			'Modul-ID nur ähnlich'         => array('kinderschutz_77_veranstaltung'),
			'eigenes Modul, falsche Aktion' => array('kinderschutz_7_sonstiges'),
			'Anmeldeformular'              => array('tl_login_7'),
			'leer'                         => array(''),
		);
	}

	/**
	 * Fehlgeschlagene An- und Abmeldungen.
	 *
	 * @return array<string, array{0: string, 1: string}> Fall (siehe veranstaltungFuer()), Aktion
	 */
	public static function misslungeneAnmeldungen(): array
	{
		return array(
			'Veranstaltung existiert nicht'      => array('unbekannt', 'anmelden'),
			'Veranstaltung nicht wählbar'        => array('nichtWaehlbar', 'anmelden'),
			'Veranstaltung schon vorbei'         => array('vorbei', 'anmelden'),
			'Abmeldung ohne Teilnahme'           => array('ohneTeilnahme', 'abmelden'),
			'Abmeldung von zugewiesener Veranstaltung' => array('vomBackendZugewiesen', 'abmelden'),
		);
	}

	/**
	 * Ohne Anmeldung gibt es nur den Hinweis; kein Dienst wird angefasst.
	 *
	 * Selbst wenn ein Besucher ein vollständiges, passendes Formular schickt
	 * (Veranstaltung anmelden), bleibt alles stehen: keine Einreichung, keine
	 * Abfrage von Status, Personen oder Veranstaltungen, kein Anfragetoken in
	 * der Vorlage und kein Stylesheet. Fängt den Fall ab, dass die
	 * Anmeldeprüfung hinter die Verarbeitung rutscht.
	 *
	 * @dataProvider ohneAnmeldung
	 *
	 * @param string $art Art der Anmeldung
	 */
	public function testOhneAnmeldungNurHinweisUndKeineDienstaufrufe(string $art): void
	{
		$nie = fn (string $klasse) => $this->strengesMock($klasse);
		$controller = $this->controller(array(
			'token'           => $this->speicher($art),
			'status'          => $nie(Statusdienst::class),
			'einreichung'     => $nie(Einreichung::class),
			'veranstaltungen' => $nie(VeranstaltungRepository::class),
			'personen'        => $nie(PersonenRepository::class),
		));
		$vorlage = $this->vorlage();
		$anfrage = $this->anfrage('POST', array(
			'FORM_SUBMIT'   => 'kinderschutz_7_veranstaltung',
			'veranstaltung' => '1',
			'aktion'        => 'anmelden',
		));

		$antwort = $this->ausfuehren($controller, $anfrage, $vorlage);

		$this->assertSame(self::VORLAGE_ANTWORT, $antwort->getContent());
		$this->assertSame($GLOBALS['TL_LANG']['MSC']['kinderschutz_fe']['nichtAngemeldet'], $vorlage->daten['hinweis']);
		$this->assertSame($GLOBALS['TL_LANG']['MSC']['kinderschutz_fe'], $vorlage->daten['texte']);
		$this->assertSame(array('texte', 'hinweis'), array_keys($vorlage->daten), 'Ohne Anmeldung darf die Vorlage nichts weiter bekommen (kein Anfragetoken, keine Unterlagen).');
		$this->assertSame(array(), $GLOBALS['TL_CSS'], 'Ohne Anmeldung wird kein Stylesheet eingebunden.');
		$this->assertSame(1, $vorlage->aufrufe);
	}

	/**
	 * Ein Mitglied außerhalb der kinderschutzpflichtigen Gruppen bekommt nur „keinZugang“.
	 *
	 * Geprüft werden fünf Wege nach draußen: andere Gruppe, deaktiviertes,
	 * abgelaufenes oder noch nicht freigeschaltetes Konto und ein Token zu
	 * einem Mitglied, das es nicht mehr gibt. In jedem Fall werden weder ein
	 * Upload an die Einreichung gereicht noch eine Teilnahme angelegt – auch
	 * dann nicht, wenn das Formular vollständig und passend abgeschickt wird.
	 * Das ist die einzige Sperre des Moduls: Fehlte sie, könnte jedes
	 * Mitglied mit Konto Unterlagen einreichen.
	 *
	 * @dataProvider ohneZugang
	 *
	 * @param string $fall Fall (siehe mitgliedFuer())
	 */
	public function testOhneKinderschutzGruppeNurKeinZugang(string $fall): void
	{
		$mitglied = $this->mitgliedFuer($fall);
		$veranstaltung = Testdatenbank::veranstaltung($this->db, 'Offener Cup', $this->tag(30));
		$datei = $this->pdfUpload();
		$einreichung = $this->strengesMock(Einreichung::class);

		$anfragen = array(
			$this->anfrage('POST', array(
				'FORM_SUBMIT'       => 'kinderschutz_7_veranstaltung',
				'veranstaltung'     => (string) $veranstaltung,
				'aktion'            => 'anmelden',
			)),
			$this->anfrage('POST', array(
				'FORM_SUBMIT'       => 'kinderschutz_7_upload',
				'dokumentart'       => (string) $this->efz,
				'ausstellungsdatum' => '2026-09-01',
			), array('datei' => $datei)),
		);

		foreach ($anfragen as $anfrage) {
			$vorlage = $this->vorlage();
			$antwort = $this->ausfuehren($this->controller(array('token' => $this->speicher('frontend', $mitglied), 'einreichung' => $einreichung)), $anfrage, $vorlage);

			$this->assertSame(self::VORLAGE_ANTWORT, $antwort->getContent());
			$this->assertSame($GLOBALS['TL_LANG']['MSC']['kinderschutz_fe']['keinZugang'], $vorlage->daten['hinweis']);
			$this->assertSame(array('texte', 'hinweis'), array_keys($vorlage->daten), 'Ohne Zugang darf die Vorlage keine Unterlagen und keinen Anfragetoken bekommen.');
			$this->assertSame(array(), $GLOBALS['TL_CSS']);
		}

		$this->assertSame(array(), $this->teilnahmen($veranstaltung), 'Ohne Zugang darf keine Teilnahme entstehen.');
	}

	/**
	 * Sind gar keine Gruppen als kinderschutzpflichtig gewählt, hat niemand Zugang.
	 *
	 * Auch dann nicht, wenn das Mitglied in irgendeiner Gruppe ist: Die leere
	 * Auswahl darf nicht als „alle“ gelesen werden.
	 */
	public function testOhneGewaehlteGruppenHatNiemandZugang(): void
	{
		$GLOBALS['TL_CONFIG']['kinderschutzGruppe'] = '';
		$vorlage = $this->vorlage();

		$this->ausfuehren($this->controller(array('token' => $this->speicher('frontend', $this->mitglied))), $this->anfrage(), $vorlage);

		$this->assertSame($GLOBALS['TL_LANG']['MSC']['kinderschutz_fe']['keinZugang'], $vorlage->daten['hinweis']);
	}

	/**
	 * Der Upload gehört dem Mitglied aus dem Token, nie dem aus dem Formular.
	 *
	 * Das Formular enthält mehrere Felder mit der ID eines anderen Mitglieds
	 * (member, mitglied, member_id, id, userId). Die Einreichung muss
	 * trotzdem mit der ID aus dem Token aufgerufen werden, zusammen mit
	 * Dokumentart, Datei und Ausstellungsdatum (Mitternacht) aus dem
	 * Formular. Sonst könnte jedes Mitglied Unterlagen für andere einreichen.
	 */
	public function testUploadNutztDieMitgliedsIdAusDemToken(): void
	{
		$datei = $this->pdfUpload();
		$einreichung = $this->createMock(Einreichung::class);
		$einreichung->expects($this->once())->method('einreichen')->with(
			$this->identicalTo($this->mitglied),
			$this->identicalTo($this->efz),
			$this->identicalTo($datei),
			$this->identicalTo(mktime(0, 0, 0, 9, 1, 2026)),
			$this->isType('int')
		)->willReturn(null);
		$controller = $this->controller(array('token' => $this->speicher('frontend', $this->mitglied), 'einreichung' => $einreichung));
		$anfrage = $this->anfrage('POST', array(
			'FORM_SUBMIT'       => 'kinderschutz_7_upload',
			'dokumentart'       => (string) $this->efz,
			'ausstellungsdatum' => '2026-09-01',
			'member'            => (string) $this->fremder,
			'mitglied'          => (string) $this->fremder,
			'member_id'         => (string) $this->fremder,
			'id'                => (string) $this->fremder,
			'userId'            => (string) $this->fremder,
		), array('datei' => $datei));

		$this->erwarteUmleitung($controller, $anfrage, $this->vorlage());
	}

	/**
	 * Die Anmeldung zu einer Veranstaltung gilt dem Mitglied aus dem Token.
	 *
	 * Ein Formularfeld „member“ mit der ID eines anderen Mitglieds wird
	 * überhört: In der Datenbank steht danach genau eine Teilnahme, nämlich
	 * die des angemeldeten Mitglieds, mit Herkunft „frontend“.
	 */
	public function testAnmeldungNutztDieMitgliedsIdAusDemToken(): void
	{
		$veranstaltung = Testdatenbank::veranstaltung($this->db, 'JWM Kreta', $this->tag(30), $this->tag(37));
		$controller = $this->controller(array('token' => $this->speicher('frontend', $this->mitglied)));
		$anfrage = $this->anfrage('POST', array(
			'FORM_SUBMIT'   => 'kinderschutz_7_veranstaltung',
			'veranstaltung' => (string) $veranstaltung,
			'aktion'        => 'anmelden',
			'member'        => (string) $this->fremder,
		));

		$this->erwarteUmleitung($controller, $anfrage, $this->vorlage());

		$this->assertSame(
			array(array('member' => (string) $this->mitglied, 'quelle' => 'frontend')),
			$this->teilnahmen($veranstaltung)
		);
	}

	/**
	 * Die Abmeldung betrifft nur das Mitglied aus dem Token.
	 *
	 * Beide Mitglieder sind angemeldet; das Formular des einen nennt die ID
	 * des anderen. Nach der Abmeldung ist nur die Teilnahme des Angemeldeten
	 * weg, die fremde bleibt stehen.
	 */
	public function testAbmeldungNutztDieMitgliedsIdAusDemToken(): void
	{
		$veranstaltung = Testdatenbank::veranstaltung($this->db, 'JWM Kreta', $this->tag(30));
		$repository = new VeranstaltungRepository($this->db);
		$this->assertTrue($repository->anmelden($veranstaltung, $this->mitglied, strtotime('today'), time()));
		$this->assertTrue($repository->anmelden($veranstaltung, $this->fremder, strtotime('today'), time()));

		$controller = $this->controller(array('token' => $this->speicher('frontend', $this->mitglied)));
		$anfrage = $this->anfrage('POST', array(
			'FORM_SUBMIT'   => 'kinderschutz_7_veranstaltung',
			'veranstaltung' => (string) $veranstaltung,
			'aktion'        => 'abmelden',
			'member'        => (string) $this->fremder,
		));

		$this->erwarteUmleitung($controller, $anfrage, $this->vorlage());

		$this->assertSame(
			array(array('member' => (string) $this->fremder, 'quelle' => 'frontend')),
			$this->teilnahmen($veranstaltung),
			'Es darf nur die eigene Teilnahme verschwinden.'
		);
	}

	/**
	 * Ein Fehlerschlüssel der Einreichung wird zur Meldung aus der Sprachdatei.
	 *
	 * Die Meldung steht unter der ID der Dokumentart in „fehler“, damit die
	 * Vorlage sie bei der richtigen Unterlage zeigt. Es gibt keine
	 * Umleitung, keine Erfolgsmeldung in der Sitzung, und die Seite wird mit
	 * den Unterlagen weiter aufgebaut. Unbekannte Schlüssel erscheinen
	 * unverändert, statt eine leere Meldung oder einen Fehler zu liefern.
	 *
	 * @dataProvider uploadFehler
	 *
	 * @param string $schluessel Fehlerschlüssel der Einreichung
	 */
	public function testUploadFehlerWirdZurMeldung(string $schluessel): void
	{
		$einreichung = $this->createMock(Einreichung::class);
		$einreichung->expects($this->once())->method('einreichen')->willReturn($schluessel);
		$session = new Session(new MockArraySessionStorage());
		$vorlage = $this->vorlage();
		$anfrage = $this->anfrage('POST', array(
			'FORM_SUBMIT'       => 'kinderschutz_7_upload',
			'dokumentart'       => (string) $this->kodex,
			'ausstellungsdatum' => '2026-09-01',
		), array('datei' => $this->pdfUpload()), $session);

		$antwort = $this->ausfuehren($this->controller(array('token' => $this->speicher('frontend', $this->mitglied), 'einreichung' => $einreichung)), $anfrage, $vorlage);

		$texte = $GLOBALS['TL_LANG']['MSC']['kinderschutz_fe']['fehler'];
		$erwartet = isset($texte[$schluessel]) ? sprintf($texte[$schluessel], '10 MB') : $schluessel;

		$this->assertSame(self::VORLAGE_ANTWORT, $antwort->getContent());
		$this->assertSame(array($this->kodex => $erwartet), $vorlage->daten['fehler']);
		$this->assertNotSame('', $erwartet);
		$this->assertSame(array(), $session->getFlashBag()->peekAll(), 'Bei einem Fehler darf keine Erfolgsmeldung in der Sitzung liegen.');
		$this->assertArrayHasKey('zellen', $vorlage->daten, 'Die Unterlagen werden trotz Fehler weiter angezeigt.');
	}

	/**
	 * Die Größenangabe in der Meldung folgt der wirksamen Höchstgröße.
	 *
	 * Ab einem Megabyte in MB mit einer Nachkommastelle (ohne überflüssige
	 * Null), darunter in KB. Dieselbe Schreibweise steht in der Vorlage
	 * (Beschriftung des Dateifelds).
	 *
	 * @dataProvider groessen
	 *
	 * @param int    $bytes    Höchstgröße des UploadPruefers
	 * @param string $erwartet Erwartete Schreibweise
	 */
	public function testGroessenangabeInDerMeldung(int $bytes, string $erwartet): void
	{
		$einreichung = $this->createMock(Einreichung::class);
		$einreichung->method('einreichen')->willReturn('zuGross');
		$vorlage = $this->vorlage();
		$anfrage = $this->anfrage('POST', array(
			'FORM_SUBMIT'       => 'kinderschutz_7_upload',
			'dokumentart'       => (string) $this->efz,
			'ausstellungsdatum' => '2026-09-01',
		), array('datei' => $this->pdfUpload()));

		$this->ausfuehren($this->controller(array(
			'token'       => $this->speicher('frontend', $this->mitglied),
			'einreichung' => $einreichung,
			'groesse'     => $bytes,
		)), $anfrage, $vorlage);

		$this->assertSame(array($this->efz => 'Die Datei ist zu groß; erlaubt sind höchstens '.$erwartet.'.'), $vorlage->daten['fehler']);
		$this->assertSame($erwartet, $vorlage->daten['hoechstgroesse']);
	}

	/**
	 * Fehlt die Datei im Formular, lautet die Meldung „upload“, ohne Aufruf der Einreichung.
	 *
	 * Das passiert zum Beispiel, wenn der Server den Upload wegen post_max_size
	 * verwirft: Dann kommt kein Dateifeld an. Ein gleichnamiges Textfeld
	 * ersetzt die Datei nicht.
	 */
	public function testFehlendeDateiMeldetUpload(): void
	{
		$einreichung = $this->strengesMock(Einreichung::class);
		$controller = $this->controller(array('token' => $this->speicher('frontend', $this->mitglied), 'einreichung' => $einreichung));

		foreach (array(array(), array('datei' => 'zeugnis.pdf')) as $zusatz) {
			$vorlage = $this->vorlage();
			$anfrage = $this->anfrage('POST', array_merge(array(
				'FORM_SUBMIT'       => 'kinderschutz_7_upload',
				'dokumentart'       => (string) $this->efz,
				'ausstellungsdatum' => '2026-09-01',
			), $zusatz));

			$this->ausfuehren($controller, $anfrage, $vorlage);

			$this->assertSame(
				array($this->efz => $GLOBALS['TL_LANG']['MSC']['kinderschutz_fe']['fehler']['upload']),
				$vorlage->daten['fehler']
			);
		}
	}

	/**
	 * Das Ausstellungsdatum wird als Mitternacht Ortszeit übergeben, Unbrauchbares als 0.
	 *
	 * Die Einreichung entscheidet bei 0 selbst auf den Fehler „datum“; der
	 * Controller darf kein „heute“ oder ein anderes Datum einsetzen.
	 *
	 * @dataProvider ausstellungsdaten
	 *
	 * @param string $eingabe   Wert des Formularfelds
	 * @param int    $erwartet  Zeitstempel, den die Einreichung bekommen muss
	 */
	public function testAusstellungsdatumWirdAlsMitternachtUebergeben(string $eingabe, int $erwartet): void
	{
		$einreichung = $this->createMock(Einreichung::class);
		$einreichung->expects($this->once())->method('einreichen')
			->with($this->anything(), $this->anything(), $this->anything(), $this->identicalTo($erwartet), $this->anything())
			->willReturn('datum');
		$anfrage = $this->anfrage('POST', array(
			'FORM_SUBMIT'       => 'kinderschutz_7_upload',
			'dokumentart'       => (string) $this->efz,
			'ausstellungsdatum' => $eingabe,
		), array('datei' => $this->pdfUpload()));

		$this->ausfuehren($this->controller(array('token' => $this->speicher('frontend', $this->mitglied), 'einreichung' => $einreichung)), $anfrage, $this->vorlage());
	}

	/**
	 * Nach erfolgreichem Upload geht es auf die Seite der Hauptanfrage, die Meldung liegt in der Sitzung.
	 *
	 * Der Controller läuft als Fragment: Die Anfrage, die er bekommt, ist die
	 * Unteranfrage auf die Fragment-Adresse; zurückkehren soll der Browser
	 * aber zur Seite (samt Abfrageparametern), die er aufgerufen hat. Die
	 * Umleitung geht deshalb auf die URI der Hauptanfrage (Status 303) und
	 * die Meldung nennt die Dokumentart.
	 */
	public function testErfolgreicherUploadLeitetAufDieHauptanfrageUm(): void
	{
		$einreichung = $this->createMock(Einreichung::class);
		$einreichung->method('einreichen')->willReturn(null);
		$session = new Session(new MockArraySessionStorage());
		$anfrage = $this->anfrage('POST', array(
			'FORM_SUBMIT'       => 'kinderschutz_7_upload',
			'dokumentart'       => (string) $this->efz,
			'ausstellungsdatum' => '2026-09-01',
		), array('datei' => $this->pdfUpload()), $session, 'https://example.org/_fragment?_path=x');
		$haupt = Request::create('https://example.org/unterlagen.html?tab=2');
		$stapel = new RequestStack();
		$stapel->push($haupt);
		$stapel->push($anfrage);

		$umleitung = $this->erwarteUmleitung(
			$this->controller(array('token' => $this->speicher('frontend', $this->mitglied), 'einreichung' => $einreichung, 'stapel' => $stapel)),
			$anfrage,
			$this->vorlage()
		);

		$this->assertSame('https://example.org/unterlagen.html?tab=2', $umleitung->getTargetUrl());
		$this->assertSame(303, $umleitung->getStatusCode());
		$this->assertSame(
			array(sprintf($GLOBALS['TL_LANG']['MSC']['kinderschutz_fe']['hochgeladen'], 'Erweitertes Führungszeugnis')),
			$session->getFlashBag()->peek('kinderschutz_meldung')
		);
	}

	/**
	 * Gibt es keine Hauptanfrage, geht die Umleitung auf die aktuelle Adresse.
	 *
	 * Nötig für den Fall, dass der Controller ohne Fragment-Unteranfrage
	 * läuft (zum Beispiel in Tests oder bei direktem Aufruf).
	 */
	public function testOhneHauptanfrageGehtDieUmleitungAufDieAktuelleAdresse(): void
	{
		$einreichung = $this->createMock(Einreichung::class);
		$einreichung->method('einreichen')->willReturn(null);
		$anfrage = $this->anfrage('POST', array(
			'FORM_SUBMIT'       => 'kinderschutz_7_upload',
			'dokumentart'       => (string) $this->efz,
			'ausstellungsdatum' => '2026-09-01',
		), array('datei' => $this->pdfUpload()), null, 'https://example.org/aktuell.html?a=1');

		$umleitung = $this->erwarteUmleitung(
			$this->controller(array('token' => $this->speicher('frontend', $this->mitglied), 'einreichung' => $einreichung)),
			$anfrage,
			$this->vorlage()
		);

		$this->assertSame('https://example.org/aktuell.html?a=1', $umleitung->getTargetUrl());
	}

	/**
	 * Anmeldung und Abmeldung enden mit Umleitung und Meldung mit dem Namen der Veranstaltung.
	 *
	 * Die Anmeldung legt die Teilnahme mit Herkunft „frontend“ an, die
	 * Abmeldung löscht sie wieder. In beiden Fällen geht es auf die
	 * Hauptanfrage, und die Meldung nennt die Veranstaltung beim Namen.
	 */
	public function testAnmeldungUndAbmeldungLeitenUmUndMelden(): void
	{
		$veranstaltung = Testdatenbank::veranstaltung($this->db, 'JWM Kreta', $this->tag(30));
		$session = new Session(new MockArraySessionStorage());
		$haupt = Request::create('https://example.org/unterlagen.html');
		$texte = $GLOBALS['TL_LANG']['MSC']['kinderschutz_fe'];

		foreach (array('anmelden' => array('angemeldet', 1), 'abmelden' => array('abgemeldet', 0)) as $aktion => list($schluessel, $anzahl)) {
			$anfrage = $this->anfrage('POST', array(
				'FORM_SUBMIT'   => 'kinderschutz_7_veranstaltung',
				'veranstaltung' => (string) $veranstaltung,
				'aktion'        => $aktion,
			), array(), $session);
			$stapel = new RequestStack();
			$stapel->push($haupt);
			$stapel->push($anfrage);

			$umleitung = $this->erwarteUmleitung(
				$this->controller(array('token' => $this->speicher('frontend', $this->mitglied), 'stapel' => $stapel)),
				$anfrage,
				$this->vorlage()
			);

			$this->assertSame('https://example.org/unterlagen.html', $umleitung->getTargetUrl(), $aktion);
			$this->assertSame(array(sprintf($texte[$schluessel], 'JWM Kreta')), $session->getFlashBag()->get('kinderschutz_meldung'), $aktion);
			$this->assertCount($anzahl, $this->teilnahmen($veranstaltung), $aktion);
		}
	}

	/**
	 * Eine misslungene An- oder Abmeldung zeigt den Fehler und ändert nichts.
	 *
	 * Fünf Fälle: Veranstaltung unbekannt, nicht wählbar oder vorbei,
	 * Abmeldung ohne Teilnahme und Abmeldung von einer Veranstaltung, der das
	 * Mitglied vom Backend aus zugeordnet wurde (die darf es nicht selbst
	 * verlassen). Die Meldung steht unter dem Schlüssel 0, es gibt weder
	 * Umleitung noch Erfolgsmeldung, und die Teilnahmen sind danach
	 * unverändert.
	 *
	 * @dataProvider misslungeneAnmeldungen
	 *
	 * @param string $fall   Fall (siehe veranstaltungFuer())
	 * @param string $aktion „anmelden“ oder „abmelden“
	 */
	public function testMisslungeneAnmeldungZeigtFehlerUndAendertNichts(string $fall, string $aktion): void
	{
		$id = $this->veranstaltungFuer($fall);
		$vorher = $this->alleTeilnahmen();
		$session = new Session(new MockArraySessionStorage());
		$vorlage = $this->vorlage();
		$anfrage = $this->anfrage('POST', array(
			'FORM_SUBMIT'   => 'kinderschutz_7_veranstaltung',
			'veranstaltung' => (string) $id,
			'aktion'        => $aktion,
		), array(), $session);

		$antwort = $this->ausfuehren($this->controller(array('token' => $this->speicher('frontend', $this->mitglied))), $anfrage, $vorlage);

		$this->assertSame(self::VORLAGE_ANTWORT, $antwort->getContent());
		$this->assertSame(array(0 => $GLOBALS['TL_LANG']['MSC']['kinderschutz_fe']['fehler']['anmeldung']), $vorlage->daten['fehler']);
		$this->assertSame(array(), $session->getFlashBag()->peekAll());
		$this->assertSame($vorher, $this->alleTeilnahmen());
	}

	/**
	 * Formulare anderer Module (und Anfragen ohne Formularkennung) werden nicht verarbeitet.
	 *
	 * Auf einer Seite können mehrere Formulare liegen, auch mehrere Module
	 * dieser Art. Der Controller reagiert nur auf FORM_SUBMIT mit seiner
	 * eigenen Modul-ID. Hier kommen Upload und Anmeldung mit allen
	 * passenden Feldern, aber mit fremder Kennung: Es entsteht keine
	 * Teilnahme, die Einreichung bleibt unberührt, es gibt keine Meldung und
	 * keine Umleitung.
	 *
	 * @dataProvider fremdeFormulare
	 *
	 * @param string $kennung Wert von FORM_SUBMIT
	 */
	public function testFremdesFormularWirdNichtVerarbeitet(string $kennung): void
	{
		$veranstaltung = Testdatenbank::veranstaltung($this->db, 'JWM Kreta', $this->tag(30));
		$einreichung = $this->strengesMock(Einreichung::class);
		$session = new Session(new MockArraySessionStorage());
		$vorlage = $this->vorlage();
		$anfrage = $this->anfrage('POST', array(
			'FORM_SUBMIT'       => $kennung,
			'veranstaltung'     => (string) $veranstaltung,
			'aktion'            => 'anmelden',
			'dokumentart'       => (string) $this->efz,
			'ausstellungsdatum' => '2026-09-01',
		), array('datei' => $this->pdfUpload()), $session);

		$antwort = $this->ausfuehren($this->controller(array('token' => $this->speicher('frontend', $this->mitglied), 'einreichung' => $einreichung)), $anfrage, $vorlage);

		$this->assertSame(self::VORLAGE_ANTWORT, $antwort->getContent());
		$this->assertSame(array(), $vorlage->daten['fehler']);
		$this->assertSame(array(), $this->teilnahmen($veranstaltung));
		$this->assertSame(array(), $session->getFlashBag()->peekAll());
	}

	/**
	 * Eine GET-Anfrage verarbeitet nie ein Formular, auch nicht mit passender Kennung im Link.
	 *
	 * Wer einen Link mit ?FORM_SUBMIT=…&aktion=anmelden aufruft, meldet
	 * sich nicht an: Zustandsändernde Aktionen laufen nur über POST (und
	 * damit über Contaos Anfragetoken-Prüfung).
	 */
	public function testGetMitFormularkennungAendertNichts(): void
	{
		$veranstaltung = Testdatenbank::veranstaltung($this->db, 'JWM Kreta', $this->tag(30));
		$vorlage = $this->vorlage();
		$anfrage = $this->anfrage('GET', array(
			'FORM_SUBMIT'   => 'kinderschutz_7_veranstaltung',
			'veranstaltung' => (string) $veranstaltung,
			'aktion'        => 'anmelden',
		));

		$antwort = $this->ausfuehren($this->controller(array('token' => $this->speicher('frontend', $this->mitglied))), $anfrage, $vorlage);

		$this->assertSame(self::VORLAGE_ANTWORT, $antwort->getContent());
		$this->assertSame(array(), $this->teilnahmen($veranstaltung));
	}

	/**
	 * Meldungen aus der Sitzung erscheinen genau einmal.
	 *
	 * Die Umleitung legt die Meldung in die Sitzung; die nächste Anzeige
	 * holt sie ab und leert sie, ein weiterer Aufruf zeigt sie nicht mehr.
	 * Ohne Sitzung in der Anfrage bleibt die Liste leer, statt zu scheitern.
	 */
	public function testMeldungenAusDerSitzungErscheinenEinmal(): void
	{
		$session = new Session(new MockArraySessionStorage());
		$session->getFlashBag()->add('kinderschutz_meldung', 'Vielen Dank, es ist angekommen.');
		$controller = $this->controller(array('token' => $this->speicher('frontend', $this->mitglied)));

		$erste = $this->vorlage();
		$this->ausfuehren($controller, $this->anfrage('GET', array(), array(), $session), $erste);
		$zweite = $this->vorlage();
		$this->ausfuehren($controller, $this->anfrage('GET', array(), array(), $session), $zweite);
		$ohneSitzung = $this->vorlage();
		$this->ausfuehren($controller, $this->anfrage('GET', array(), array(), null, 'https://example.org/x', false), $ohneSitzung);

		$this->assertSame(array('Vielen Dank, es ist angekommen.'), $erste->daten['meldungen']);
		$this->assertSame(array(), $zweite->daten['meldungen']);
		$this->assertSame(array(), $ohneSitzung->daten['meldungen']);
	}

	/**
	 * Ein berechtigtes Mitglied bekommt der Vorlage alle Daten, richtig gefiltert.
	 *
	 * Geprüft werden Formularkennung, Anfragetoken (unmaskiert, die Vorlage
	 * maskiert), Höchstgröße, heutiges Datum, Dokumentarten und der Stand je
	 * Art, die eigenen Veranstaltungen samt fehlenden Pflichtunterlagen und
	 * die wählbaren Veranstaltungen. Von denen fehlen die, bei denen das
	 * Mitglied schon angemeldet oder vom Backend zugeordnet ist, unwählbare
	 * und vergangene. Das Stylesheet des Bundes wird eingebunden.
	 */
	public function testBerechtigtesMitgliedBekommtAlleDatenFuerDieVorlage(): void
	{
		Testdatenbank::nachweis($this->db, array(
			'member'            => $this->mitglied,
			'dokumentart'       => $this->kodex,
			'status'            => 'geprueft',
			'ausstellungsdatum' => strtotime('2026-01-05 00:00'),
			'einschlaegig'      => '',
			'gueltigBis'        => null,
		));
		$eigene = Testdatenbank::veranstaltung($this->db, 'Eigene Fahrt', $this->tag(20));
		$zugewiesen = Testdatenbank::veranstaltung($this->db, 'Lehrgang', $this->tag(10), '', false);
		Testdatenbank::veranstaltung($this->db, 'Offener Cup', $this->tag(40));
		Testdatenbank::veranstaltung($this->db, 'Interner Termin', $this->tag(50), '', false);
		Testdatenbank::veranstaltung($this->db, 'Alter Cup', $this->tag(-30));
		$repository = new VeranstaltungRepository($this->db);
		$repository->anmelden($eigene, $this->mitglied, strtotime('today'), time());
		$repository->setzeTeilnehmer($zugewiesen, array($this->mitglied), time());
		$vorlage = $this->vorlage();

		$this->ausfuehren($this->controller(array('token' => $this->speicher('frontend', $this->mitglied))), $this->anfrage(), $vorlage);

		$daten = $vorlage->daten;

		$this->assertSame('', $daten['hinweis']);
		$this->assertSame('kinderschutz_7', $daten['formular']);
		$this->assertSame(self::ANFRAGETOKEN, $daten['requestToken']);
		$this->assertSame('10 MB', $daten['hoechstgroesse']);
		$this->assertSame(date('Y-m-d'), $daten['heute']);
		$this->assertSame(array(), $daten['meldungen']);
		$this->assertSame(array(), $daten['fehler']);
		$this->assertSame($GLOBALS['TL_LANG']['MSC']['kinderschutz_fe'], $daten['texte']);
		$this->assertSame(array('Erweitertes Führungszeugnis', 'Ehrenkodex'), array_map(static fn ($art) => $art->name, $daten['arten']));
		$this->assertSame('fehlt', $daten['zellen'][$this->efz]->status);
		$this->assertSame('gueltig', $daten['zellen'][$this->kodex]->status);
		$this->assertSame(array('Lehrgang', 'Eigene Fahrt'), array_map(static fn (array $eintrag) => $eintrag['veranstaltung']['name'], $daten['eigene']));
		$this->assertSame(array('Erweitertes Führungszeugnis'), array_map(static fn ($art) => $art->name, $daten['eigene'][0]['fehlend']));
		$this->assertSame(array('Offener Cup'), array_map(static fn (array $veranstaltung) => $veranstaltung['name'], $daten['waehlbare']));
		$this->assertContains('bundles/contaokinderschutzdokumente/frontend.css|static', $GLOBALS['TL_CSS']);
	}

	/**
	 * Alles, was die Vorlage liest, setzt der Controller auch.
	 *
	 * Die Vorlage ist ein PHP-Template ohne Typprüfung: Ein umbenanntes oder
	 * vergessenes Feld fiele erst im Browser auf (in Contao 5 wirft
	 * FragmentTemplate bei unbekannten Schlüsseln). Deshalb werden die Namen
	 * ($this->name) aus dem Quelltext der Vorlage gelesen und mit den
	 * Schlüsseln verglichen, die der Controller bei einem berechtigten
	 * Aufruf setzt. Methodenaufrufe der Vorlagenvererbung (extend, block,
	 * endblock) zählen nicht.
	 */
	public function testControllerSetztAlleVariablenDerVorlage(): void
	{
		$quelle = (string) file_get_contents(__DIR__.'/../../src/Resources/contao/templates/mod_kinderschutz_unterlagen.html5');
		preg_match_all('/\$this->(\w+)\b(?!\s*\()/', $quelle, $treffer);
		$gelesen = array_values(array_unique($treffer[1]));
		$vorlage = $this->vorlage();

		$this->ausfuehren($this->controller(array('token' => $this->speicher('frontend', $this->mitglied))), $this->anfrage(), $vorlage);

		$this->assertGreaterThanOrEqual(10, count($gelesen), 'Die Suche im Quelltext der Vorlage hat zu wenig Variablen gefunden.');
		$this->assertSame(array(), array_values(array_diff($gelesen, array_keys($vorlage->daten))), 'Die Vorlage liest Variablen, die der Controller nicht setzt.');
	}

	/**
	 * Baut den Controller mit echten Diensten über der Testdatenbank.
	 *
	 * Ersatz-Objekte lassen sich einzeln austauschen, damit Tests prüfen
	 * können, dass bestimmte Dienste nicht (oder genau einmal) aufgerufen
	 * werden.
	 *
	 * @param array<string, mixed> $ersatz Optionale Ersatzobjekte unter den Schlüsseln
	 *                                     status, einreichung, veranstaltungen, personen,
	 *                                     token (TokenStorage), stapel (RequestStack),
	 *                                     tokenManager und groesse (Höchstgröße in Bytes)
	 *
	 * @return UnterlagenController Der Controller unter Test
	 */
	private function controller(array $ersatz = array()): UnterlagenController
	{
		$veranstaltungen = $ersatz['veranstaltungen'] ?? new VeranstaltungRepository($this->db);
		$personen = $ersatz['personen'] ?? new PersonenRepository($this->db);
		$status = $ersatz['status'] ?? new Statusdienst(
			new DokumentartRepository($this->db),
			new NachweisRepository($this->db),
			new PersonenRepository($this->db),
			new VeranstaltungRepository($this->db),
			$this->einstellungen()
		);

		$tokenManager = $this->createMock(ContaoCsrfTokenManager::class);
		$tokenManager->method('getDefaultTokenValue')->willReturn(self::ANFRAGETOKEN);

		return new UnterlagenController(
			$status,
			$ersatz['einreichung'] ?? $this->createMock(Einreichung::class),
			$veranstaltungen,
			$personen,
			$this->einstellungen(),
			new UploadPruefer($ersatz['groesse'] ?? 10 * 1048576),
			$ersatz['token'] ?? $this->speicher('keiner'),
			$ersatz['stapel'] ?? new RequestStack(),
			$ersatz['tokenManager'] ?? $tokenManager
		);
	}

	/**
	 * Ruft die geschützte Methode getResponse() auf.
	 *
	 * @param UnterlagenController $controller Der Controller unter Test
	 * @param Request              $anfrage     Die Anfrage
	 * @param Template             $vorlage     Die nachgebaute Vorlage
	 * @param int                  $modul       ID des Frontend-Moduls
	 *
	 * @return Response Die Antwort der Vorlage
	 *
	 * @throws RedirectResponseException Nach erfolgreichem Absenden, unverändert weitergereicht
	 */
	private function ausfuehren(UnterlagenController $controller, Request $anfrage, Template $vorlage, int $modul = self::MODUL): Response
	{
		$methode = new \ReflectionMethod($controller, 'getResponse');

		return $methode->invoke($controller, $vorlage, $this->modell($modul), $anfrage);
	}

	/**
	 * Führt den Controller aus und verlangt, dass er umleitet.
	 *
	 * @param UnterlagenController $controller Der Controller unter Test
	 * @param Request              $anfrage     Die Anfrage
	 * @param Template             $vorlage     Die nachgebaute Vorlage
	 *
	 * @return RedirectResponse Die Umleitung aus der RedirectResponseException
	 */
	private function erwarteUmleitung(UnterlagenController $controller, Request $anfrage, Template $vorlage): RedirectResponse
	{
		try {
			$this->ausfuehren($controller, $anfrage, $vorlage);
		} catch (RedirectResponseException $ausnahme) {
			$antwort = $ausnahme->getResponse();
			$this->assertInstanceOf(RedirectResponse::class, $antwort);

			return $antwort;
		}

		$this->fail('Erwartet wurde eine Umleitung nach dem Absenden, die Seite wurde aber normal gerendert.');
	}

	/**
	 * Eine Vorlage, die festhält, was ihr zugewiesen wird.
	 *
	 * Sie erbt von Contao\Template, damit sie in die Methodensignatur des
	 * Controllers passt, nimmt aber nicht den Weg über Contaos Container:
	 * Zuweisungen landen in $daten, getResponse() zählt mit und liefert eine
	 * feste Antwort.
	 *
	 * @return Template Die Vorlage; öffentliche Felder: daten (zugewiesene Werte) und aufrufe
	 */
	private function vorlage(): Template
	{
		return new class extends Template {
			/** @var array<string, mixed> Alles, was der Controller zugewiesen hat */
			public array $daten = array();

			/** Wie oft getResponse() aufgerufen wurde */
			public int $aufrufe = 0;

			/**
			 * Ersetzt den Konstruktor von Template, der Contaos Container bräuchte.
			 */
			public function __construct()
			{
			}

			/**
			 * Merkt sich eine Zuweisung.
			 *
			 * @param string $schluessel Name der Variablen
			 * @param mixed  $wert       Wert
			 */
			public function __set($schluessel, $wert): void
			{
				$this->daten[$schluessel] = $wert;
			}

			/**
			 * Liefert eine gemerkte Zuweisung.
			 *
			 * @param string $schluessel Name der Variablen
			 *
			 * @return mixed Der Wert oder null
			 */
			public function __get($schluessel)
			{
				return $this->daten[$schluessel] ?? null;
			}

			/**
			 * Sagt, ob eine Variable zugewiesen wurde.
			 *
			 * @param string $schluessel Name der Variablen
			 *
			 * @return bool True, wenn zugewiesen und nicht null
			 */
			public function __isset($schluessel): bool
			{
				return isset($this->daten[$schluessel]);
			}

			/**
			 * Liefert eine feste Antwort statt zu rendern.
			 *
			 * @return Response Die Antwort mit dem Text „Vorlage gerendert“
			 */
			public function getResponse(): Response
			{
				++$this->aufrufe;

				return new Response('Vorlage gerendert');
			}
		};
	}

	/**
	 * Ein Modul-Modell, das nur die ID kennt.
	 *
	 * ModuleModel lässt sich ohne Contao-Laufzeit nicht befüllen (das
	 * Setzen von Feldern braucht das DCA). Der Controller liest nur „id“,
	 * deshalb genügt ein Mock mit __get().
	 *
	 * @param int $id ID des Moduls
	 *
	 * @return ModuleModel Das Mock
	 */
	private function modell(int $id): ModuleModel
	{
		$modell = $this->getMockBuilder(ModuleModel::class)->disableOriginalConstructor()->onlyMethods(array('__get'))->getMock();
		$modell->method('__get')->willReturnCallback(static fn (string $schluessel) => 'id' === $schluessel ? $id : null);

		return $modell;
	}

	/**
	 * Baut den Anmeldespeicher.
	 *
	 * @param string $art      „keiner“ (kein Token), „frontend“ (FrontendUser mit der ID $mitglied),
	 *                         „fremdesKonto“ (ein Benutzer, der kein FrontendUser ist) oder
	 *                         „ohneBenutzer“ (Token, dessen getUser() null liefert)
	 * @param int    $mitglied ID des Mitglieds bei „frontend“
	 *
	 * @return TokenStorage Der Speicher
	 */
	private function speicher(string $art, int $mitglied = 0): TokenStorage
	{
		$speicher = new TokenStorage();

		if ('keiner' === $art) {
			return $speicher;
		}

		$benutzer = null;

		if ('frontend' === $art) {
			// Der Konstruktor von FrontendUser lädt Daten aus der Datenbank; die ID liegt in User::$arrData
			$benutzer = (new \ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
			$benutzer->id = $mitglied;
		} elseif ('fremdesKonto' === $art) {
			$benutzer = new InMemoryUser('redakteur', null, array('ROLE_USER'));
		}

		$token = $this->createMock(TokenInterface::class);
		$token->method('getUser')->willReturn($benutzer);
		$speicher->setToken($token);

		return $speicher;
	}

	/**
	 * Baut eine Anfrage mit Sitzung.
	 *
	 * @param string                    $methode  HTTP-Methode
	 * @param array<string, string>     $formular Formularfelder (bei GET die Abfrageparameter)
	 * @param array<string, UploadedFile> $dateien Hochgeladene Dateien
	 * @param Session|null              $session  Vorhandene Sitzung oder null für eine neue
	 * @param string                    $adresse  Adresse der Anfrage
	 * @param bool                      $mitSitzung False lässt die Anfrage ganz ohne Sitzung
	 *
	 * @return Request Die Anfrage
	 */
	private function anfrage(string $methode = 'GET', array $formular = array(), array $dateien = array(), ?Session $session = null, string $adresse = 'https://example.org/unterlagen.html', bool $mitSitzung = true): Request
	{
		$anfrage = Request::create($adresse, $methode, $formular, array(), $dateien);

		if ($mitSitzung) {
			$anfrage->setSession($session ?? new Session(new MockArraySessionStorage()));
		}

		return $anfrage;
	}

	/**
	 * Ein hochgeladenes PDF, so wie PHP es an die Anfrage hängt.
	 *
	 * @return UploadedFile Die Datei im Testmodus (ohne is_uploaded_file-Prüfung)
	 */
	private function pdfUpload(): UploadedFile
	{
		return new UploadedFile(Testdateien::pdf(), 'zeugnis.pdf', 'application/pdf', UPLOAD_ERR_OK, true);
	}

	/**
	 * Ein Mock, bei dem jeder Aufruf den Test scheitern lässt.
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $klasse Die zu ersetzende Klasse
	 *
	 * @return T Das Mock
	 */
	private function strengesMock(string $klasse): object
	{
		$mock = $this->createMock($klasse);
		$mock->expects($this->never())->method($this->anything());

		return $mock;
	}

	/**
	 * Legt ein Mitglied an, das keinen Zugang haben darf, und nennt seine ID.
	 *
	 * @param string $fall andereGruppe, gesperrt, abgelaufen, zuFrueh oder geloescht
	 *
	 * @return int ID des Mitglieds; bei „geloescht“ eine ID, die es nicht gibt
	 */
	private function mitgliedFuer(string $fall): int
	{
		return match ($fall) {
			'andereGruppe' => Testdatenbank::mitglied($this->db, 'Gerd', 'Anders', array(2)),
			'gesperrt'     => Testdatenbank::mitglied($this->db, 'Gerda', 'Gesperrt', array(1), array('disable' => '1')),
			'abgelaufen'   => Testdatenbank::mitglied($this->db, 'Alf', 'Abgelaufen', array(1), array('stop' => (string) (time() - 3600))),
			'zuFrueh'      => Testdatenbank::mitglied($this->db, 'Fred', 'Früh', array(1), array('start' => (string) (time() + 86400))),
			default        => 4711,
		};
	}

	/**
	 * Legt für einen Fehlerfall die passende Veranstaltung an.
	 *
	 * @param string $fall unbekannt, nichtWaehlbar, vorbei, ohneTeilnahme oder vomBackendZugewiesen
	 *
	 * @return int ID der Veranstaltung; bei „unbekannt“ eine ID, die es nicht gibt
	 */
	private function veranstaltungFuer(string $fall): int
	{
		switch ($fall) {
			case 'nichtWaehlbar':
				return Testdatenbank::veranstaltung($this->db, 'Interner Termin', $this->tag(30), '', false);
			case 'vorbei':
				return Testdatenbank::veranstaltung($this->db, 'Alter Cup', $this->tag(-30));
			case 'ohneTeilnahme':
				return Testdatenbank::veranstaltung($this->db, 'Offener Cup', $this->tag(30));
			case 'vomBackendZugewiesen':
				$id = Testdatenbank::veranstaltung($this->db, 'Lehrgang', $this->tag(30), '', false);
				(new VeranstaltungRepository($this->db))->setzeTeilnehmer($id, array($this->mitglied), time());

				return $id;
			default:
				return 9999;
		}
	}

	/**
	 * Liest die Teilnahmen einer Veranstaltung.
	 *
	 * @param int $veranstaltung ID der Veranstaltung
	 *
	 * @return list<array{member: string, quelle: string}> Mitglied und Herkunft, nach ID sortiert
	 */
	private function teilnahmen(int $veranstaltung): array
	{
		return array_map(
			static fn (array $zeile): array => array('member' => (string) $zeile['member'], 'quelle' => (string) $zeile['quelle']),
			$this->db->fetchAllAssociative('SELECT member, quelle FROM tl_kinderschutz_teilnahme WHERE veranstaltung = ? ORDER BY id', array($veranstaltung))
		);
	}

	/**
	 * Liest alle Teilnahmen der Testdatenbank.
	 *
	 * @return list<array<string, mixed>> Alle Zeilen, nach ID sortiert
	 */
	private function alleTeilnahmen(): array
	{
		return $this->db->fetchAllAssociative('SELECT veranstaltung, member, quelle FROM tl_kinderschutz_teilnahme ORDER BY id');
	}

	/**
	 * Ein Kalendertag relativ zu heute als JJJJ-MM-TT.
	 *
	 * @param int $tage Tage ab heute, negativ für die Vergangenheit
	 *
	 * @return string Das Datum
	 */
	private function tag(int $tage): string
	{
		return date('Y-m-d', strtotime(sprintf('%+d days', $tage)));
	}
}
