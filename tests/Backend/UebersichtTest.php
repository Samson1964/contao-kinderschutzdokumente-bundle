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
use Doctrine\DBAL\Connection;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Backend\Uebersicht;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\AntragRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\DokumentartRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\NachweisRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\PersonenRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Datenbank\VeranstaltungRepository;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Statusdienst;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Dienst\Zugang;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Kalender;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Nachweis;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Regeln\Zellstatus;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Datenbank as Testdatenbank;
use Schachbulle\ContaoKinderschutzdokumenteBundle\Tests\Dienst\Testhilfen;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prüft die Aufbereitung der Matrix für das Backend-Template: Zugang, Filter,
 * Zellen mit Symbol und Klasse sowie die Links.
 *
 * Statusdienst und Repositories sind echt (SQLite im Arbeitsspeicher), nur
 * Berechtigung, Router und Token-Manager sind Mocks. Ausgangslage am
 * 15.10.2026, drei Dokumentarten (Führungszeugnis und Ehrenkodex als Pflicht,
 * Schulung freiwillig) und sieben Personen, die zusammen jeden berechneten
 * Stand der Matrix zeigen.
 */
class UebersichtTest extends Testhilfen
{
	/** Stand je Person und Dokumentart (Führungszeugnis, Ehrenkodex, Schulung) */
	private const ERWARTETE_STAENDE = array(
		'Adler, Anna'    => array('gueltig', 'gueltig', 'fehlt'),
		'Bach, Bernd'    => array('laeuftAb', 'eingereicht', 'fehlt'),
		'Clausen, Carla' => array('gueltig', 'gueltig', 'fehlt'),
		'Dahl, Dora'     => array('fehlt', 'fehlt', 'fehlt'),
		'Eder, Emil'     => array('gueltig', 'abgelehnt', 'fehlt'),
		'Fuchs, Fritz'   => array('beanstandet', 'gueltig', 'fehlt'),
		'Gans, Gerd'     => array('abgelaufen', 'gueltig', 'fehlt'),
	);

	/** Symbol je Stand, unabhängig von der Konstante der Klasse aufgeschrieben */
	private const ERWARTETE_SYMBOLE = array(
		'fehlt'       => '–',
		'eingereicht' => '✉',
		'abgelehnt'   => '✖',
		'gueltig'     => '✔',
		'laeuftAb'    => '⏳',
		'abgelaufen'  => '✖',
		'beanstandet' => '⚠',
	);

	private Connection $db;

	private int $jetzt;

	private int $efz;

	private int $kodex;

	private int $schulung;

	private int $jwm;

	/** @var array<string, int> Mitglieds-ID je Nachname */
	private array $ids = array();

	/**
	 * Legt die Ausgangslage an und lädt die deutschen Texte.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		include __DIR__.'/../../src/Resources/contao/languages/de/default.php';

		$this->db = Testdatenbank::verbindung();
		$this->jetzt = strtotime('2026-10-15 06:00');
		$this->efz = Testdatenbank::dokumentart($this->db, 'Führungszeugnis', 24, true, true, 1);
		$this->kodex = Testdatenbank::dokumentart($this->db, 'Ehrenkodex', 0, false, true, 2);
		$this->schulung = Testdatenbank::dokumentart($this->db, 'Schulung', 0, false, false, 3);

		foreach (array('Adler' => 'Anna', 'Bach' => 'Bernd', 'Clausen' => 'Carla', 'Dahl' => 'Dora', 'Eder' => 'Emil', 'Fuchs' => 'Fritz', 'Gans' => 'Gerd') as $nachname => $vorname) {
			$this->ids[$nachname] = Testdatenbank::mitglied($this->db, $vorname, $nachname);
		}

		// Anna und Carla sind vollständig; Carlas Erneuerung wartet auf Prüfung
		$this->geprueft('Adler', $this->efz, '2026-01-10', '2028-01-09');
		$this->geprueft('Adler', $this->kodex, '2026-01-10', null);
		$this->geprueft('Clausen', $this->efz, '2026-01-10', '2028-01-09');
		$this->geprueft('Clausen', $this->kodex, '2026-01-10', null);
		$this->einreichung('Clausen', $this->efz, Nachweis::EINGEREICHT);

		// Bernds Führungszeugnis läuft am 30.11.2026 ab, sein Ehrenkodex wartet
		$this->geprueft('Bach', $this->efz, '2024-12-01', '2026-11-30');
		$this->einreichung('Bach', $this->kodex, Nachweis::EINGEREICHT);

		// Emils Ehrenkodex wurde abgelehnt
		$this->geprueft('Eder', $this->efz, '2026-01-10', '2028-01-09');
		$this->einreichung('Eder', $this->kodex, Nachweis::ABGELEHNT);

		// Fritz' Führungszeugnis hat einschlägige Einträge
		$this->geprueft('Fuchs', $this->efz, '2026-01-10', '2028-01-09', 'ja');
		$this->geprueft('Fuchs', $this->kodex, '2026-01-10', null);

		// Gerds Führungszeugnis ist seit dem 01.01.2026 abgelaufen
		$this->geprueft('Gans', $this->efz, '2024-01-01', '2025-12-31');
		$this->geprueft('Gans', $this->kodex, '2026-01-10', null);

		$this->jwm = Testdatenbank::veranstaltung($this->db, 'JWM Kreta 11/2026', '2026-11-02', '2026-11-12');
	}

	/**
	 * Ohne Prüferfreigabe liefert die Übersicht nichts.
	 *
	 * Die Prüfung steht vor der Berechnung; der Statusdienst wird nicht
	 * einmal gefragt. Fängt eine Übersicht ab, die die Freigabe vergisst und
	 * jedem Backend-Benutzer die Personenliste zeigte.
	 */
	public function testOhneFreigabeKeineDaten(): void
	{
		$zugang = $this->createMock(Zugang::class);
		$zugang->expects($this->once())->method('verlange')->willThrowException(new AccessDeniedException('Kein Zugang.'));
		$status = $this->createMock(Statusdienst::class);
		$status->expects($this->never())->method($this->anything());

		$this->expectException(AccessDeniedException::class);

		$this->uebersicht($zugang, $status)->daten(new Request(), $this->jetzt);
	}

	/**
	 * Ohne Filter erscheinen alle Personen, nach Namen sortiert, samt Zählern.
	 */
	public function testOhneFilterAllePersonenMitZaehlern(): void
	{
		$daten = $this->uebersicht()->daten(new Request(), $this->jetzt);

		$this->assertSame('', $daten['filter']);
		$this->assertSame(array_keys(self::ERWARTETE_STAENDE), array_column($daten['zeilen'], 'name'));
		$this->assertSame(array('vollstaendig' => 2, 'unvollstaendig' => 5, 'wartend' => 2), $daten['zaehler']);
		$this->assertSame(strtotime('2026-10-15 00:00'), $daten['stichtag']);
		$this->assertSame(0, $daten['gewaehlt']);
		$this->assertSame('/contao', $daten['aktion']);
		$this->assertCount(1, $daten['veranstaltungen']);
		$this->assertSame(array('Führungszeugnis', 'Ehrenkodex', 'Schulung'), array_map(static fn ($art): string => $art->name, $daten['arten']));
	}

	/**
	 * Der Filter „unvollstaendig“ blendet die vollständigen Personen aus.
	 *
	 * Anna und Carla haben alle Pflichtunterlagen; Carlas wartende Erneuerung
	 * ändert daran nichts. Übrig bleiben die fünf mit Lücke.
	 */
	public function testFilterUnvollstaendigBlendetVollstaendigeAus(): void
	{
		$daten = $this->uebersicht()->daten(new Request(array('filter' => 'unvollstaendig')), $this->jetzt);

		$this->assertSame('unvollstaendig', $daten['filter']);
		$this->assertSame(array('Bach, Bernd', 'Dahl, Dora', 'Eder, Emil', 'Fuchs, Fritz', 'Gans, Gerd'), array_column($daten['zeilen'], 'name'));
		$this->assertSame(array(false, false, false, false, false), array_column($daten['zeilen'], 'vollstaendig'));
	}

	/**
	 * Der Filter „wartend“ zeigt nur Personen mit einer Einreichung zur Prüfung.
	 *
	 * Dazu zählt auch Carla, deren Führungszeugnis gültig ist und nur eine
	 * Erneuerung wartet. Emils abgelehnter Kodex wartet nicht mehr.
	 */
	public function testFilterWartendZeigtNurWartende(): void
	{
		$daten = $this->uebersicht()->daten(new Request(array('filter' => 'wartend')), $this->jetzt);

		$this->assertSame('wartend', $daten['filter']);
		$this->assertSame(array('Bach, Bernd', 'Clausen, Carla'), array_column($daten['zeilen'], 'name'));
	}

	/**
	 * Ein unbekannter Filterwert wird zu '' und zeigt alle Personen.
	 *
	 * Der Wert kommt aus der Adresszeile. Er wird weder ausgewertet noch ins
	 * Template durchgereicht; auch andere Schreibweisen der erlaubten Werte
	 * zählen nicht.
	 */
	public function testUngueltigerFilterWirdZuLeer(): void
	{
		foreach (array('gibtesnicht', 'WARTEND', 'wartend ', '<script>') as $wert) {
			$daten = $this->uebersicht()->daten(new Request(array('filter' => $wert)), $this->jetzt);

			$this->assertSame('', $daten['filter'], 'Filter „'.$wert.'“');
			$this->assertCount(7, $daten['zeilen'], 'Filter „'.$wert.'“');
		}
	}

	/**
	 * Jede Zelle trägt Symbol, Klasse, Text und Daten passend zu ihrem Stand.
	 *
	 * Die erwarteten Stände sind oben als Tabelle aufgeschrieben, die Symbole
	 * unabhängig von der Konstante der Klasse. Ein Stand darf nie nur über
	 * die Farbe erkennbar sein: Jede Zelle hat ein Symbol, und die Klasse
	 * heißt „ks-“ plus Stand.
	 */
	public function testJedeZelleHatSymbolKlasseUndText(): void
	{
		$daten = $this->uebersicht()->daten(new Request(), $this->jetzt);

		foreach ($daten['zeilen'] as $zeile) {
			$this->assertCount(3, $zeile['zellen'], $zeile['name']);

			foreach ($zeile['zellen'] as $index => $zelle) {
				$stand = self::ERWARTETE_STAENDE[$zeile['name']][$index];
				$wo = $zeile['name'].', Spalte '.$index;

				$this->assertSame($stand, $zelle['status'], $wo);
				$this->assertSame(self::ERWARTETE_SYMBOLE[$stand], $zelle['symbol'], $wo);
				$this->assertSame(Uebersicht::SYMBOLE[$stand], $zelle['symbol'], $wo);
				$this->assertStringStartsWith('ks-'.$stand, $zelle['klasse'], $wo);
				$this->assertSame($GLOBALS['TL_LANG']['MSC']['kinderschutz_status'][$stand], $zelle['text'], $wo);
			}
		}

		// Jeder berechnete Stand hat ein Symbol, und die Tabelle der Klasse stimmt mit der Erwartung überein
		$this->assertSame(self::ERWARTETE_SYMBOLE, Uebersicht::SYMBOLE);
		$this->assertEqualsCanonicalizing(array_keys(self::ERWARTETE_SYMBOLE), array(
			Zellstatus::FEHLT, Zellstatus::EINGEREICHT, Zellstatus::ABGELEHNT, Zellstatus::GUELTIG,
			Zellstatus::LAEUFT_AB, Zellstatus::ABGELAUFEN, Zellstatus::BEANSTANDET,
		));
	}

	/**
	 * Fehlende freiwillige Unterlagen sind grau markiert, fehlende Pflicht nicht.
	 *
	 * Beide tragen den Stand „fehlt“; die Zusatzklasse „ks-freiwillig“ nimmt
	 * dem freiwilligen Fehlen die Alarmfarbe.
	 */
	public function testFehlendeFreiwilligeUnterlagenSindGekennzeichnet(): void
	{
		$zeilen = $this->zeilenNachName($this->uebersicht()->daten(new Request(), $this->jetzt));

		$this->assertSame('ks-fehlt', $zeilen['Dahl, Dora']['zellen'][0]['klasse']);
		$this->assertSame('ks-fehlt ks-freiwillig', $zeilen['Dahl, Dora']['zellen'][2]['klasse']);
		$this->assertSame('ks-gueltig', $zeilen['Adler, Anna']['zellen'][0]['klasse']);
		$this->assertSame('ks-laeuftAb', $zeilen['Bach, Bernd']['zellen'][0]['klasse']);
	}

	/**
	 * Die Zellen nennen das Datum und die wartende Einreichung.
	 *
	 * Ein befristeter Nachweis zeigt „gültig bis“, ein unbefristeter nicht.
	 * Neben einem gültigen Nachweis kann eine Erneuerung warten (Carla).
	 */
	public function testZellenNennenDatumUndWartend(): void
	{
		$zeilen = $this->zeilenNachName($this->uebersicht()->daten(new Request(), $this->jetzt));

		$this->assertSame(strtotime('2028-01-09 00:00'), $zeilen['Adler, Anna']['zellen'][0]['datum']);
		$this->assertNull($zeilen['Adler, Anna']['zellen'][1]['datum']);
		$this->assertFalse($zeilen['Adler, Anna']['zellen'][0]['wartet']);
		$this->assertTrue($zeilen['Clausen, Carla']['zellen'][0]['wartet']);
		$this->assertTrue($zeilen['Bach, Bernd']['zellen'][1]['wartet']);
		$this->assertFalse($zeilen['Eder, Emil']['zellen'][1]['wartet']);
	}

	/**
	 * Links führen zur Prüfung der wartenden Einreichung oder zum Anlegen,
	 * alle mit Anfragetoken.
	 *
	 * Wartet eine Einreichung, öffnet der Link sie zur Prüfung; sonst legt er
	 * einen Prüfvermerk für diese Person und Dokumentart an. Der Namenslink
	 * führt zu allen Nachweisen der Person.
	 */
	public function testLinksFuehrenZurPruefungOderZumAnlegen(): void
	{
		$zeilen = $this->zeilenNachName($this->uebersicht()->daten(new Request(), $this->jetzt));
		$kodexBach = (int) $this->db->fetchOne('SELECT id FROM tl_kinderschutz WHERE member = ? AND dokumentart = ?', array($this->ids['Bach'], $this->kodex));

		$pruefen = $zeilen['Bach, Bernd']['zellen'][1];
		$this->assertSame('/contao?do=kinderschutz_nachweise&act=edit&id='.$kodexBach.'&rt=tok', $pruefen['link']);
		$this->assertSame($GLOBALS['TL_LANG']['MSC']['kinderschutz_be']['pruefen'], $pruefen['titel']);

		$anlegen = $zeilen['Dahl, Dora']['zellen'][0];
		$this->assertSame('/contao?do=kinderschutz_nachweise&act=create&member='.$this->ids['Dahl'].'&dokumentart='.$this->efz.'&rt=tok', $anlegen['link']);
		$this->assertSame($GLOBALS['TL_LANG']['MSC']['kinderschutz_be']['neu'], $anlegen['titel']);

		$this->assertSame('/contao?do=kinderschutz_nachweise&member='.$this->ids['Dahl'].'&rt=tok', $zeilen['Dahl, Dora']['nachweise']);
	}

	/**
	 * Die Auswahl einer Veranstaltung rechnet zu ihrem letzten Tag und zeigt
	 * gelöschte Mitglieder mit ihrer ID.
	 *
	 * Teilnehmer sind Anna, Bernd und die gelöschte Mitglieds-ID 999. Eine
	 * unbekannte oder negative Veranstaltungs-ID fällt auf die Ansicht
	 * „alle Personen, Stichtag heute“ zurück.
	 */
	public function testVeranstaltungAuswaehlen(): void
	{
		(new VeranstaltungRepository($this->db))->setzeTeilnehmer($this->jwm, array($this->ids['Adler'], $this->ids['Bach'], 999), $this->jetzt);

		$daten = $this->uebersicht()->daten(new Request(array('veranstaltung' => (string) $this->jwm)), $this->jetzt);

		$this->assertSame($this->jwm, $daten['gewaehlt']);
		$this->assertSame(strtotime('2026-11-12 00:00'), $daten['stichtag']);
		$this->assertSame(array('Mitglied gelöscht (#999)', 'Adler, Anna', 'Bach, Bernd'), array_column($daten['zeilen'], 'name'));
		$this->assertSame('fehlt', $daten['zeilen'][0]['zellen'][0]['status']);

		foreach (array('99999', '-5', 'abc') as $wert) {
			$daten = $this->uebersicht()->daten(new Request(array('veranstaltung' => $wert)), $this->jetzt);

			$this->assertSame(0, $daten['gewaehlt'], 'Veranstaltung „'.$wert.'“');
			$this->assertSame(strtotime('2026-10-15 00:00'), $daten['stichtag'], 'Veranstaltung „'.$wert.'“');
			$this->assertCount(7, $daten['zeilen'], 'Veranstaltung „'.$wert.'“');
		}
	}

	/**
	 * Offene Anträge stehen mit Zahl und Link über der Matrix.
	 *
	 * Zwei Anträge sind offen, einer ist abgelehnt und zählt nicht mit. Der
	 * Link führt auf den Menüpunkt „Anträge“.
	 */
	public function testOffeneAntraege(): void
	{
		$antraege = new AntragRepository($this->db);
		$antraege->anlegen(4711, 'eins', array(), 1000);
		$antraege->anlegen(4712, 'zwei', array(), 1000);
		$entschieden = $antraege->anlegen(4713, 'drei', array(), 1000);
		$antraege->aendern($entschieden, array('status' => AntragRepository::ABGELEHNT));

		$daten = $this->uebersicht()->daten(new Request(), $this->jetzt);

		$this->assertSame(2, $daten['offeneAntraege']);
		$this->assertStringContainsString('do=kinderschutz_antraege', $daten['antraege']);
	}

	/**
	 * Baut die Übersicht mit echtem Statusdienst und Mocks für den Rest.
	 *
	 * Der Router liefert „/contao?“ plus die Parameter (ohne Parameter nur
	 * „/contao“), der Token-Manager immer den Tokenwert „tok“.
	 *
	 * @param Zugang|null       $zugang Mock für die Freigabe; ohne Angabe lässt er jeden durch
	 * @param Statusdienst|null $status Ersatz für den Statusdienst; ohne Angabe der echte
	 *
	 * @return Uebersicht Die Klasse unter Test
	 */
	private function uebersicht(?Zugang $zugang = null, ?Statusdienst $status = null): Uebersicht
	{
		$router = $this->createMock(UrlGeneratorInterface::class);
		$router->method('generate')->willReturnCallback(static function (string $route, array $parameter = array()): string {
			$query = http_build_query($parameter);

			return '/contao'.('' === $query ? '' : '?'.$query);
		});

		$tokenManager = $this->createMock(ContaoCsrfTokenManager::class);
		$tokenManager->method('getDefaultTokenValue')->willReturn('tok');

		return new Uebersicht(
			$status ?? new Statusdienst(new DokumentartRepository($this->db), new NachweisRepository($this->db), new PersonenRepository($this->db), new VeranstaltungRepository($this->db), $this->einstellungen()),
			new VeranstaltungRepository($this->db),
			$zugang ?? $this->createMock(Zugang::class),
			$router,
			$tokenManager,
			new AntragRepository($this->db),
		);
	}

	/**
	 * Ordnet die Zeilen einer Übersicht ihrem Anzeigenamen zu.
	 *
	 * @param array<string, mixed> $daten Ergebnis von Uebersicht::daten()
	 *
	 * @return array<string, array<string, mixed>> Zeilen nach Namen
	 */
	private function zeilenNachName(array $daten): array
	{
		return array_combine(array_column($daten['zeilen'], 'name'), $daten['zeilen']);
	}

	/**
	 * Legt einen geprüften Nachweis an.
	 *
	 * @param string      $person       Nachname der Person
	 * @param int         $art          ID der Dokumentart
	 * @param string      $ausgestellt  Ausstellungsdatum JJJJ-MM-TT
	 * @param string|null $gueltigBis   Letzter gültiger Tag oder null (unbegrenzt)
	 * @param string      $einschlaegig 'ja', 'nein' oder ''
	 */
	private function geprueft(string $person, int $art, string $ausgestellt, ?string $gueltigBis, string $einschlaegig = 'nein'): void
	{
		Testdatenbank::nachweis($this->db, array(
			'member'            => $this->ids[$person],
			'dokumentart'       => $art,
			'status'            => Nachweis::GEPRUEFT,
			'verarbeitet'       => Nachweis::GEPRUEFT,
			'ausstellungsdatum' => strtotime($ausgestellt),
			'geprueftAm'        => Kalender::plusTage(strtotime($ausgestellt), 1),
			'einschlaegig'      => $einschlaegig,
			'gueltigBis'        => null === $gueltigBis ? null : strtotime($gueltigBis.' 00:00'),
		));
	}

	/**
	 * Legt eine Einreichung ohne Prüfung an (wartend oder abgelehnt).
	 *
	 * @param string $person Nachname der Person
	 * @param int    $art    ID der Dokumentart
	 * @param string $status Nachweis::EINGEREICHT oder Nachweis::ABGELEHNT
	 */
	private function einreichung(string $person, int $art, string $status): void
	{
		Testdatenbank::nachweis($this->db, array('member' => $this->ids[$person], 'dokumentart' => $art, 'status' => $status));
	}
}
